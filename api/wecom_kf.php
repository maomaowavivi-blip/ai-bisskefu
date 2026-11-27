<?php
/**
 * api/wecom_kf.php
 *
 * v3.4 — 企业微信「微信客服」消息回调入口
 *
 * 与 api/wecom.php 的区别：
 *   wecom.php   = 企业微信「应用消息」回调（自建应用，加好友收发）
 *   wecom_kf.php = 企业微信「微信客服」回调（用户扫客服二维码/链接发起，无需加好友）
 *
 * 数据流：
 *   1. GET  — URL 验证（企微后台保存回调地址时触发）
 *   2. POST — 接收 kf_msg_or_event 事件，立即返空串
 *   3. POST 收到事件后，调用 sync_msg 接口拉取具体消息内容
 *   4. 拉取到文本消息后，调 ChatPipeline 分类 + AI 回复
 *   5. 通过 messages/send 接口发送主动回复
 *
 * 必备配置（platform_config 表）：
 *   - wecom.corpid            企业 CorpID
 *   - wecom.token             回调 Token
 *   - wecom.aes_key           回调消息加密 Key
 *   - wecom.corp_secret       企业 Secret（用于获取 access_token）
 *   - wecom.kf_open_kfid      客服账号 ID（wk 开头）
 *   - wecom.access_token_cache       access_token 缓存（自动写入）
 *   - wecom.access_token_expires_at access_token 过期时间（自动写入）
 *
 * 工作流：
 *   wecom_kf.php → ChatPipeline → Intent → Workflow → Reply
 *                                      ↓
 *                              send_message() → 微信客服
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wecom_kf_token.php'; // v3.35：getAccessToken/httpGet/日志函数搬到中立 include（§7.4.1）
require_once __DIR__ . '/wecom_kf_order_guide.php'; // v3.35：订单指引轮消息编排纯函数（§7.6.2）
require_once __DIR__ . '/OrderGuidePick.php'; // v3.35：选房载荷复用 guideTextFromSteps
// v3.35 媒体上传函数由 api/wecom_kf_media.php（另一路交付）提供。
// 发布窗口内该文件可能尚未到位 → is_file 守卫，缺失时图片/视频静默跳过、文字照发（§5.3）。
if (is_file(__DIR__ . '/wecom_kf_media.php')) {
    require_once __DIR__ . '/wecom_kf_media.php';
}
require_once __DIR__ . '/wecom_crypto.php'; // v3.4：crypto 函数抽出共用
require_once __DIR__ . '/wecom_kf_dedup.php'; // 去重工具（修复 95001）
require_once __DIR__ . '/chat_helpers.php';
require_once __DIR__ . '/Intent.php';
require_once __DIR__ . '/IntentClassifier.php';
require_once __DIR__ . '/SessionState.php';
require_once __DIR__ . '/IntentRouter.php';
require_once __DIR__ . '/ReplyRenderer.php';
require_once __DIR__ . '/ChatPipeline.php';
require_once __DIR__ . '/Workflow/AbstractWorkflow.php';
require_once __DIR__ . '/Workflow/YunfangkaCredentialWorkflow.php';
require_once __DIR__ . '/helpers/order_candidate.php';  // v3.15.4:统一订单号提取 + OCR
require_once __DIR__ . '/Workflow/RoomQueryWorkflow.php';
require_once __DIR__ . '/Workflow/OrderQueryWorkflow.php';
require_once __DIR__ . '/Workflow/KnowledgeWorkflow.php';
require_once __DIR__ . '/Workflow/SmallTalkWorkflow.php';
require_once __DIR__ . '/Workflow/UnknownWorkflow.php';
// v3.11:HandoffWorkflow.php 已删除,不再 require

// ──────────────────────────────────────────
// 配置加载
// ──────────────────────────────────────────

$db = getDB();
$corpId    = trim(pcGet($db, 'wecom.corpid', ''));
$token     = trim(pcGet($db, 'wecom.token', ''));
$aesKey    = trim(pcGet($db, 'wecom.aes_key', ''));
$corpSecret = trim(pcGet($db, 'wecom.corp_secret', ''));
$openKfId  = trim(pcGet($db, 'wecom.kf_open_kfid', ''));

if (!$corpId || !$token || !$aesKey || !$corpSecret || !$openKfId) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'WeCom KF not configured. Need: wecom.corpid, wecom.token, wecom.aes_key, wecom.corp_secret, wecom.kf_open_kfid';
    exit;
}

// ──────────────────────────────────────────
// 日志（复用 wecom_log from chat_helpers）
// ──────────────────────────────────────────
// v3.35：wecom_kf_log() / wecom_kf_response_error() / getAccessToken() / httpGet()
// 已搬到中立 include api/wecom_kf_token.php（§7.4.1 / P1-1），本文件顶部 require。
// 函数名与行为不变，以下调用点无需修改。

// ──────────────────────────────────────────
// 路由
// ──────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    handleVerifyUrl();
} elseif ($method === 'POST') {
    handleCallback();
} else {
    http_response_code(405);
    exit('Method not allowed');
}

// ──────────────────────────────────────────
// GET — URL 验证
// ──────────────────────────────────────────

function handleVerifyUrl(): void
{
    global $token, $aesKey, $corpId;

    header('Content-Type: text/plain; charset=utf-8');

    $signature = $_GET['msg_signature'] ?? '';
    $timestamp = $_GET['timestamp'] ?? '';
    $nonce     = $_GET['nonce'] ?? '';
    $echostr   = $_GET['echostr'] ?? '';

    wecom_kf_log('GET verify request received');

    if (!$signature || !$timestamp || !$nonce || !$echostr) {
        wecom_kf_log('→ 400 Invalid params');
        http_response_code(400);
        exit;
    }

    // 验签
    if (sha1Sort($token, $timestamp, $nonce, $echostr) !== $signature) {
        wecom_kf_log('→ 403 Signature mismatch');
        http_response_code(403);
        exit('Signature mismatch');
    }

    // 解密 echostr
    $plaintext = decrypt($echostr, $aesKey);
    if ($plaintext === null) {
        wecom_kf_log('→ 500 Decrypt failed');
        http_response_code(500);
        exit('Decrypt failed');
    }

    $msg = extractMsg($plaintext, $corpId);
    if ($msg === null) {
        wecom_kf_log('→ 403 CorpID mismatch');
        http_response_code(403);
        exit('CorpID mismatch');
    }

    wecom_kf_log('→ 200 OK (URL verified)');
    echo $msg;
    exit;
}

// ──────────────────────────────────────────
// POST — 回调处理
// ──────────────────────────────────────────

function handleCallback(): void
{
    global $db, $token, $aesKey, $corpId, $openKfId;

    $timestamp = $_GET['timestamp'] ?? '';
    $nonce     = $_GET['nonce'] ?? '';
    $signature = $_GET['msg_signature'] ?? '';

    $input = file_get_contents('php://input');
    $xml = @simplexml_load_string($input);
    if ($xml === false || !isset($xml->Encrypt)) {
        wecom_kf_log('→ 400 Invalid XML');
        http_response_code(400);
        exit('Invalid XML');
    }

    $encrypt = (string)$xml->Encrypt;

    // 验签
    if (sha1Sort($token, $timestamp, $nonce, $encrypt) !== $signature) {
        wecom_kf_log('→ 403 Signature mismatch (POST)');
        http_response_code(403);
        exit('Signature mismatch');
    }

    // 解密
    $plaintext = decrypt($encrypt, $aesKey);
    if ($plaintext === null) {
        wecom_kf_log('→ 500 Decrypt failed (POST)');
        http_response_code(500);
        exit('Decrypt failed');
    }

    $msgXml = extractMsg($plaintext, $corpId);
    if ($msgXml === null) {
        wecom_kf_log('→ 403 CorpID mismatch (POST)');
        http_response_code(403);
        exit('CorpID mismatch');
    }

    $msg = simplexml_load_string($msgXml);
    if ($msg === false) {
        http_response_code(400);
        exit('Bad decrypted XML');
    }

    // 关键字段
    $msgType  = (string)($msg->MsgType ?? '');
    $event    = (string)($msg->Event ?? '');
    $eventToken = (string)($msg->Token ?? '');  // 用于 sync_msg
    $callbackOpenKfId = (string)($msg->OpenKfId ?? '');

    wecom_kf_log('POST: MsgType=' . $msgType . ' Event=' . $event . ' openKfHash=' . substr(sha1($callbackOpenKfId), 0, 10));

    // 立即返空串（企业微信不要求 5 秒内回复）
    http_response_code(200);
    echo '';
    // 关闭连接，继续异步处理（fastcgi_finish_request 可选）
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // 只处理 kf_msg_or_event 事件
    if ($msgType !== 'event' || $event !== 'kf_msg_or_event') {
        wecom_kf_log("Ignored: not kf_msg_or_event");
        return;
    }

    if (empty($eventToken)) {
        wecom_kf_log('Missing event Token, cannot sync_msg');
        return;
    }

    processKfEvent($eventToken, $callbackOpenKfId ?: $openKfId);
}

// ──────────────────────────────────────────
// 事件处理：拉消息 → AI 回复 → 发送
// ──────────────────────────────────────────

function processKfEvent(string $eventToken, string $useOpenKfId): void
{
    global $db;

    $lockFile = __DIR__ . '/../logs/wecom_kf_processing.lock';
    $lockHandle = @fopen($lockFile, 'c');
    if (!$lockHandle || !@flock($lockHandle, LOCK_EX)) {
        wecom_kf_log('process lock unavailable');
        if (is_resource($lockHandle)) @fclose($lockHandle);
        return;
    }

    try {

        // 1. 拉消息（用持久化的 cursor 增量拉，避免一次拉回历史未消费消息）
        $cursorFile = __DIR__ . '/../logs/wecom_kf_cursor_' . substr(sha1($useOpenKfId), 0, 8) . '.txt';
        $savedCursor = file_exists($cursorFile) ? trim(file_get_contents($cursorFile)) : '';
        wecom_kf_log('sync_msg started');

        $syncResult = syncMsg($useOpenKfId, $eventToken, $savedCursor);
        if ($syncResult === null) {
            wecom_kf_log('sync_msg failed');
            return;
        }
        $messages = $syncResult['messages'];
        $nextCursor = $syncResult['next_cursor'];
        $batchHandled = true;
        wecom_kf_log('sync_msg returned ' . count($messages) . ' messages');

        foreach ($messages as $msg) {
        // v3.15.4:放过 image/voice/file,只 skip event
        $msgType = $msg['msgtype'] ?? '';
        $from    = $msg['external_userid'] ?? '';

        // v3.23:进入会话事件 — 客户首次接入自动发欢迎语。
        // 必须写在本行的 event 丢弃分支之前,否则永不执行。
        // 注意:enter_session 的 external_userid / open_kfid / welcome_code
        //      全部嵌套在 event 内,不是顶层字段(顶层取不到,$from 会是空)。
        if ($msgType === 'event') {
            $evt       = $msg['event'] ?? [];
            $eventType = (string)($evt['event_type'] ?? '');
            if ($eventType === 'enter_session') {
                handleEnterSession($db, $evt, (string)($evt['open_kfid'] ?? $useOpenKfId));
            } else {
                wecom_kf_log('Skip event type=' . $eventType);
            }
            continue;
        }

        if (empty($from)) {
            wecom_kf_log('Skip message without external_userid, type=' . $msgType);
            continue;
        }

        // 提取文本: text 直接取 / image 调本地 RapidOCR / voice/file 暂不接
        $extractedText = '';
        if ($msgType === 'text') {
            $extractedText = $msg['text']['content'] ?? '';
        } elseif ($msgType === 'image') {
            $picUrl = $msg['image']['pic_url'] ?? '';
            if ($picUrl !== '') {
                $extractedText = ocrImageFromUrl($db, $picUrl, $from);
            }
        }
        // voice/file 暂不接 — v3.15.4 范围不包含

        if ($extractedText === '') {
            wecom_kf_log('Skip empty content type=' . $msgType);
            continue;
        }

        // 去重：同 msgid 24 小时内只处理一次，覆盖游标回退重试。
        $msgId = $msg['msgid'] ?? '';
        if ($msgId !== '' && isMsgProcessed($msgId)) {
            wecom_kf_log('Skip processed msg=' . substr(sha1($msgId), 0, 10));
            continue;
        }

        // 2. ChatPipeline 处理
        $sessionId = 'wecom_kf_' . substr(sha1($from), 0, 16);
        $trimmedMsg = trim($extractedText);

        // v3.15.4:用统一入口 extractOrderCandidate 替代内联正则
        // 文本(text 原文 / OCR 识别结果 / 后续 ASR)统一过此闸门
        $orderCandidate  = extractOrderCandidate($trimmedMsg);
        $isOrderMessage  = ($orderCandidate !== '');

        try {
            $result = ChatPipeline::process(
                $sessionId,
                $extractedText,   // v3.15.4:text 原文 / OCR 文本统一进 Pipeline
                [],
                $db,
                'wechat_kf',   // channel：Pipeline 不带 rich_content
                $from,
                requestClientIp()
            );
        } catch (Throwable $e) {
            if ($isOrderMessage) {
                // 订单号的核心交付是 A 样式房卡，不能被 AI 回复异常拦截。
                $result = ['code' => 500, 'data' => null];
                wecom_kf_log('ChatPipeline failed; continue roomcard delivery');
            } else {
                $batchHandled = false;
                wecom_kf_log('ChatPipeline failed: ' . get_class($e));
                continue;
            }
        }

        if (intval($result['code'] ?? 500) !== 0 || !is_array($result['data'] ?? null)) {
            if ($isOrderMessage) {
                $replyText = '这边暂时没有查到准确信息，建议您联系前台确认。';
                wecom_kf_log('ChatPipeline returned failure; continue roomcard delivery');
            } else {
                $batchHandled = false;
                wecom_kf_log('ChatPipeline returned failure');
                continue;
            }
        } else {
            $replyText = $result['data']['reply'] ?? '这边暂时没有查到准确信息，建议您联系前台确认。';
        }

        // 截断（微信客服单条 600 字限制）
        $replyText = mb_substr($replyText, 0, 600);

        // 3. 发送前：把会话状态转为「由智能助手接待」(1)
        //    修复 95018：客服账号绑定了接待人员(自建应用自动成为接待者)，会话状态=3(已接待)，
        //    直接 send_msg 会被拒。先 trans 到 state=1 即可发送。
        transKfServiceState($useOpenKfId, $from, 1);

        // v3.35 — 订单指引载荷（蓝图 §7.6.3 / P1-2）：进入房卡分支之前存进**独立变量**。
        // $result 稍后在发卡循环里被 sendKfMiniprogramMessageEx() **原地覆盖**（只剩 ok/errcode），
        // 届时 data 不再存在 → 指引图与视频会静默不发。此后一律读 $orderGuide，不再读 $result['data']。
        $orderGuide = null;
        if (is_array($result['data'] ?? null) && is_array($result['data']['order_guide'] ?? null)) {
            $orderGuide = $result['data']['order_guide'];
        }
        // P0（CodeBuddy 方案审）：跳过房卡分支的条件是「本轮是选房轮」（mode=pick）。
        // 绝不能写成 !empty(order_guide)——order_guide 在任何启用场景都存在（含入住期间/退房后），
        // 照那种写法入住期间会跳过整个房卡分支：客人收不到云房卡、收不到视频、还会重复收到订单信息。
        $isPickRound = is_array($orderGuide) && (($orderGuide['mode'] ?? 'order') === 'pick');
        if ($isOrderMessage && $isPickRound) {
            $isOrderMessage = false; // 只有选房轮才跳过房卡分支；订单轮永远不跳过发卡
        }

        // v3.15.2 — 订单号固定发送 A 样式原生小程序卡片。
        // appid 和 pagepath 原样使用宿家当前房卡返回值,本地不拼接、不改写。
        // v3.16 — 一单多房:宿家 cards 数组有几张就发几张。
        $roomCardSent = false;
        if ($isOrderMessage) {
            require_once __DIR__ . '/wecom_kf_roomcard_v37.php';
            // v3.32：$cardBizMsg = 中台业务原因（如「入住当天才能自助生成云房卡哦…」）
            $cardErr = null;
            $cardBizMsg = null;
            $deliveries = buildRoomCardDeliveries($db, $orderCandidate, $cardErr, $cardBizMsg);
            $cardCount = count($deliveries);

            // v3.30：兜底写 session↔订单绑定(写点3，蓝图 §3.2-3)。
            // ChatPipeline 炸掉时写点1未执行，这里补一条；uk 去重，重复写无害。
            // room_no 从 delivery title 提取房号，拿不到留空。helper 全 catch + 外层双保险。
            try {
                require_once __DIR__ . '/helpers/session_order_link.php';
                $linkRoomNos = [];
                foreach ($deliveries as $d) {
                    if (preg_match('/您的房间为\s*([A-Za-z0-9_\-]+)/u', (string)($d['title'] ?? ''), $rm) === 1) {
                        $linkRoomNos[] = $rm[1];
                    }
                }
                linkSessionOrder(
                    $db,
                    $sessionId,
                    $orderCandidate,
                    implode(',', $linkRoomNos),
                    'wechat_kf',
                    'roomcard'
                );
            } catch (\Throwable $e) {
                error_log('[wecom_kf] session_order_link failed: ' . $e->getMessage());
            }

            // v3.34（苏鸣 2026-11-20）：订单信息与云房卡一起发。
            // 消息决策统一交给 roomCardTextPlan（纯函数，可单测）：
            //   有卡   → 先发订单信息文本（卡片之前），再逐张发卡
            //   无卡   → 订单信息 + 原因提示两条（= v3.32 行为不变）
            // v3.35 启用场景（order_guide 存在）：上面的文本计划**由 M1 替代**（qa D3），
            //   $replyText 不再单独发——引导文字/房卡原因/选房问句/间数引导全部并入编排，
            //   总条数由 buildOrderGuideMessages 保证 ≤5（§5.2.1），超预算按降级顺序砍。
            $hasCards = ($cardCount > 0);
            $textFailed = false;
            $orderGuideMsgs = [];   // v3.35 编排结果（含 'cards' 占位）；非启用场景保持空
            $ogButlerMode  = false; // v3.35 管家模式（§5.2.4）：cardCount>=5 → 不发卡，改引导联系管家
            $ogCut = 0;             // 'cards' 占位下标（卡前/卡后分界）
            $guideTextSent = 0;     // 事件行统计（chat_logs §7.6.5）
            $guideImgSent = 0;
            $videoSent = false;
            $imageDeadline = 0.0;   // 单轮图片媒体预算 20s（§5.3 / B5）

            if (is_array($orderGuide)) {
                // ══ v3.35 启用场景编排（§7.6.2 / §7.6.3）══
                // 管家模式判定（§5.2.4）：5 条预算里 M1 占 1 条 → 只剩 4 条给卡 → cardCount>=5 装不下
                $ogButlerMode = ($cardCount >= 5);
                $ogButlerContact = '';
                if ($ogButlerMode) {
                    // 管家联系方式唯一取值入口：PromptEngine::handoffReply()（读 agent.handoff.reply）
                    // ⚠️ 禁止硬编码号码（AGENTS.md §10）；PromptEngine 已随 IntentClassifier 加载
                    try {
                        $ogButlerContact = trim((string)\PromptEngine::handoffReply(null, $db));
                    } catch (\Throwable $e) {
                        error_log('[wecom_kf] butler contact read failed: ' . $e->getMessage());
                    }
                    if ($ogButlerContact === '') $ogButlerContact = '请联系管家';
                }
                $roomCountText = ($cardCount >= 2 && !$ogButlerMode)
                    ? sprintf('您一共订了 %d 间房,以下是各房间的云房卡～', $cardCount)
                    : '';
                $cardReason = '';
                if ($cardCount === 0) {
                    // 房卡原因并入 M1 末尾（§5.2 启用场景统一规则）；中台无原因时用通用兜底
                    $cardReason = centerRoomCardReply($cardBizMsg);
                    if ($cardReason === '') $cardReason = ORDER_NOT_FOUND_REPLY;
                }
                $guideText   = '';
                $guideImages = [];
                if (($orderGuide['stage'] ?? '') === 'precheckin' && empty($orderGuide['multi'])) {
                    $ogSteps = (isset($orderGuide['rooms'][0]['steps']) && is_array($orderGuide['rooms'][0]['steps']))
                        ? $orderGuide['rooms'][0]['steps'] : [];
                    $guideText = OrderGuidePick::guideTextFromSteps($ogSteps);
                    foreach ($ogSteps as $ogSt) {
                        foreach ((array)($ogSt['images'] ?? []) as $ogU) {
                            if (is_string($ogU) && $ogU !== '') $guideImages[] = $ogU;
                        }
                    }
                }
                // 视频条件（§5.1）：order_guide 存在（=lead 非空）&& stage=during && 源文件存在
                $ogVideoPath = '';
                if (($orderGuide['stage'] ?? '') === 'during') {
                    $ogVideoPath = trim((string)pcGet($db, 'roomcard.guide_video.path', ''));
                    if ($ogVideoPath === '' || !is_file($ogVideoPath)) $ogVideoPath = ''; // §5.3 缺源文件→不发视频、其余正常
                }
                $orderGuideMsgs = buildOrderGuideMessages([
                    'stage'         => (string)($orderGuide['stage'] ?? ''),
                    'lead'          => (string)($orderGuide['lead'] ?? ''),
                    'orderText'     => (string)$replyText,
                    'cardReason'    => $cardReason,
                    'cardCount'     => $cardCount,
                    'roomCountText' => $roomCountText,
                    'roomPickText'  => (string)($orderGuide['pick_text'] ?? ''),
                    'guideText'     => $guideText,
                    'guideImages'   => $guideImages,
                    'videoPath'     => $ogVideoPath,
                    'butlerContact' => $ogButlerContact,
                ]);

                // 统一消息发送器（卡前与卡后共用；图片/视频 fail-open 不阻断文字）
                $imageDeadline = microtime(true) + 20.0;
                $sendOgMsg = function (array $gm) use (
                    $db, $from, $useOpenKfId, $imageDeadline, $sessionId,
                    &$textFailed, &$guideTextSent, &$guideImgSent, &$videoSent
                ): void {
                    $type = (string)($gm['type'] ?? '');
                    if ($type === 'text') {
                        if (sendKfMessage($from, (string)($gm['content'] ?? ''), $useOpenKfId)) {
                            if (($gm['meta'] ?? '') === 'guide') $guideTextSent++;
                        } else {
                            $textFailed = true; // 文字失败：不断发卡、留给微信重试
                            wecom_kf_log('order guide text send failed meta=' . (string)($gm['meta'] ?? ''));
                        }
                    } elseif ($type === 'image') {
                        if (sendKfGuideImage($db, $from, (string)($gm['url'] ?? ''), $useOpenKfId, $imageDeadline)) {
                            $guideImgSent++;
                        }
                    } elseif ($type === 'video') {
                        if (sendKfGuideVideo($db, $from, (string)($gm['path'] ?? ''), $useOpenKfId)) {
                            $videoSent = true;
                            writeGuideChatEvent($db, $sessionId, '已发送云房卡使用指引视频', $from, requestClientIp()); // §7.6.5
                        }
                    }
                };

                // 'cards' 占位之前的消息先于发卡发送
                $ogCut = count($orderGuideMsgs);
                foreach ($orderGuideMsgs as $gi => $gm) {
                    if (($gm['type'] ?? '') === 'cards') { $ogCut = $gi; break; }
                    $sendOgMsg($gm);
                }
            } else {
                $plan = roomCardTextPlan($hasCards, $hasCards ? null : $cardBizMsg, (string)$replyText);
                foreach ($plan as $text) {
                    if (!sendKfMessage($from, $text, $useOpenKfId)) $textFailed = true;
                }
                if ($textFailed) {
                    // 文本失败不断发卡（卡是核心交付）；只记日志
                    wecom_kf_log('roomcard accompanying text send failed');
                }

                // 2 张及以上:加文字引导(避免客户不知道有几间房)
                if ($cardCount >= 2) {
                    sendKfMessage(
                        $from,
                        sprintf('您一共订了 %d 间房,以下是各房间的云房卡～', $cardCount),
                        $useOpenKfId
                    );
                }
            }

            $sentCount = 0;
            foreach ($deliveries as $delivery) {
                if ($ogButlerMode) break; // v3.35 管家模式（§5.2.4）：不发卡（5 张必然超 5 条上限）
                $result = sendKfMiniprogramMessageEx($from, [
                    'appid'          => $delivery['appid'],
                    'title'          => $delivery['title'],
                    'pagepath'       => $delivery['pagepath'],
                    'thumb_media_id' => $delivery['thumb_media_id'],
                ], $useOpenKfId);

                // v3.21:40007 = thumb_media_id 无效 → 现场自愈
                // (强制删缓存重传新 thumb,用新值重发这一张;消息未投递,重发不会双卡)
                if (!$result['ok'] && $result['errcode'] === 40007) {
                    wecom_kf_log('thumb 40007 self-healing: force refresh and retry');
                    $newThumb = forceRefreshThumbCache();
                    if ($newThumb && $newThumb !== $delivery['thumb_media_id']) {
                        $delivery['thumb_media_id'] = $newThumb;
                        @pcSet($db, 'ai.roomcard.thumb_media_id', $newThumb);
                        // 后续卡直接复用新 thumb
                        foreach ($deliveries as &$d) { $d['thumb_media_id'] = $newThumb; }
                        unset($d);
                        $result = sendKfMiniprogramMessageEx($from, [
                            'appid'          => $delivery['appid'],
                            'title'          => $delivery['title'],
                            'pagepath'       => $delivery['pagepath'],
                            'thumb_media_id' => $newThumb,
                        ], $useOpenKfId);
                    }
                }

                if ($result['ok']) $sentCount++;
            }
            $roomCardSent = $sentCount > 0;

            // v3.34 qa修复(P2)：有卡但一张都没发出（中台有卡、企微发送全败）→
            // plan 只含订单信息，客户对失败零感知。补一条通用提示，保持 v3.32 的客户体验不回退。
            // v3.35 启用场景：**不补**这条独立提示——房卡原因已并入 M1（含全败可感知的通用兜底），
            // 再补一条会多占 1 条企微额度（5 条上限）。代价：有卡全败时客人只看到 M1 里的兜底原因，
            // 不会再单独收到「暂时没有查找到您的云房卡」那一句。这是刻意取舍，不是漏写。
            if ($hasCards && !$roomCardSent && !is_array($orderGuide)) {
                $failTip = $plan[1] ?? '暂时没有查找到您的云房卡，请稍后重试或联系管家。';
                if (!sendKfMessage($from, $failTip, $useOpenKfId)) $textFailed = true;
            }

            // v3.34：发卡事件写 chat_logs（成败都写），房管端「客人咨询记录」可见
            // v3.35 管家模式：中台确实有卡但我们主动不发 → 事件原因写清楚，别让房管误判为发卡失败
            $ogCardBizMsg = $roomCardSent ? null : $cardBizMsg;
            if ($ogButlerMode) {
                $ogCardBizMsg = sprintf('订单含 %d 间房，云房卡未发送，已引导客户联系管家', $cardCount);
            }
            writeRoomCardChatEvent(
                $db,
                $sessionId,
                $roomCardSent,
                $deliveries,
                $ogCardBizMsg,
                $from,   // qa P3 修复：与 ChatPipeline 写 visitor_hash 的口径一致（原样 $from）
                requestClientIp(),
                $sentCount
            );

            // v3.35：'cards' 占位之后的消息（视频 / 卡后才发的指引媒体）在发卡完成后追加。
            // 视频必须在发卡成功后发（§7.6.3-3，发卡本身代码未动）；图片/视频失败只记日志，
            // 不阻断文字、不置 batchHandled（§5.3 媒体失败 = 文字照常送达）。
            if (is_array($orderGuide) && $ogCut < count($orderGuideMsgs)) {
                foreach (array_slice($orderGuideMsgs, $ogCut + 1) as $gm) {
                    $sendOgMsg($gm);
                }
            }

            // v3.35 chat_logs 事件行（§7.6.5）：指引发送成功写 assistant 事件，内容只用展示名
            if (is_array($orderGuide) && ($guideTextSent > 0 || $guideImgSent > 0)) {
                $ogWhat = [];
                if ($guideTextSent > 0) $ogWhat[] = '文字';
                if ($guideImgSent > 0)  $ogWhat[] = $guideImgSent . ' 图';
                $ogRoom = trim((string)($orderGuide['rooms'][0]['display_name'] ?? ''));
                writeGuideChatEvent(
                    $db, $sessionId,
                    '已发送入住指引：' . implode(' + ', $ogWhat) . ($ogRoom !== '' ? '（房间：' . $ogRoom . '）' : ''),
                    $from, requestClientIp()
                );
            }

            // 保持 v3.32 语义：计划文本已全部发出 → 视为「本次已回复」(continue)，
            // 避免下面把 $replyText 再发一遍；任一文本失败 → $batchHandled=false 留给微信重试。
            if (!$roomCardSent && !$textFailed) $roomCardSent = true;
            if (!$roomCardSent && $textFailed) {
                $batchHandled = false;
                wecom_kf_log('roomcard reason reply failed');
            }
        }

        if ($roomCardSent) {
            if ($msgId !== '') markMsgProcessed($msgId);
            continue;
        }

        // 4. 发送（必须用会话对应的 open_kfid，不能用配置里的固定值——
        //    用户扫码进的是事件里的 OpenKfId，跟 platform_config 配置的可能是不同账号）
        //
        // v3.35 P2（qa 独立验收发现）：选房轮遇到「有图无文字」的房源（实测 ≥185 间）时，
        // 指引文字 replyText 是空串 → 企微拒绝空内容 → sendKfMessage 失败 →
        // 图片分支（在 if($sent) 内）不会执行，且 batchHandled=false → 游标不推进 → 微信重投
        // → 下一轮走正常流程 → **该房指引图片彻底丢失**。
        // → 空文字时**跳过发送**并视为已处理，直接进图片分支。
        $replyForSend = trim((string)$replyText);
        $skipEmptyReply = ($isPickRound && $replyForSend === '');
        if ($skipEmptyReply) {
            wecom_kf_log('pick round: guide text empty (image-only room), skip empty text send');
        }
        $sent = $skipEmptyReply ? true : sendKfMessage($from, $replyText, $useOpenKfId);
        if ($sent) {
            if ($msgId !== '') markMsgProcessed($msgId);
            wecom_kf_log('Replied to user=' . substr(sha1($from), 0, 10));

            // v3.35 选房轮（P1-5 / §5.4.2）：指引文字就是上面的 $replyText，已经由常规路径发出
            // ——通道层在选房轮**只补发选中房源的图片，不另发文字**（否则双发）。
            // 指引文字 >600 字接受 :362 mb_substr(600) 截断，选房轮不拆条。
            if ($isPickRound && is_array($orderGuide)) {
                $ogSteps = (isset($orderGuide['rooms'][0]['steps']) && is_array($orderGuide['rooms'][0]['steps']))
                    ? $orderGuide['rooms'][0]['steps'] : [];
                $pickUrls = [];
                foreach ($ogSteps as $ogSt) {
                    foreach ((array)($ogSt['images'] ?? []) as $ogU) {
                        if (is_string($ogU) && $ogU !== '') $pickUrls[] = $ogU;
                    }
                }
                $pickDeadline = microtime(true) + 20.0; // 单轮图片媒体预算 20s（§5.3）
                $pickImgSent = 0;
                foreach (array_slice($pickUrls, 0, 3) as $pickUrl) {
                    if (sendKfGuideImage($db, $from, $pickUrl, $useOpenKfId, $pickDeadline)) $pickImgSent++;
                }
                if ($pickImgSent > 0) { // chat_logs 事件行 §7.6.5（只用展示名）
                    $pickRoom = trim((string)($orderGuide['rooms'][0]['display_name'] ?? ''));
                    writeGuideChatEvent(
                        $db, $sessionId,
                        '已发送入住指引（选房轮）：' . $pickImgSent . ' 图' . ($pickRoom !== '' ? '（房间：' . $pickRoom . '）' : ''),
                        $from, requestClientIp()
                    );
                }
            }
        } else {
            $batchHandled = false;
            wecom_kf_log('send_kf_message failed user=' . substr(sha1($from), 0, 10));
        }
        }

        // 只有本批消息全部成功处理，才推进游标；失败时保留旧游标等待微信重试。
        if ($batchHandled && $nextCursor !== '') {
            @file_put_contents($cursorFile, $nextCursor, LOCK_EX);
            wecom_kf_log('sync_msg cursor advanced');
        } elseif (!$batchHandled) {
            wecom_kf_log('cursor not advanced because batch handling failed');
        }
    } finally {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}

// ──────────────────────────────────────────
// service_state/trans — 变更会话状态
// ──────────────────────────────────────────

function transKfServiceState(string $openKfId, string $externalUserId, int $serviceState): bool
{
    $accessToken = getAccessToken();
    if ($accessToken === null) return false;

    $url = "https://qyapi.weixin.qq.com/cgi-bin/kf/service_state/trans?access_token=" . urlencode($accessToken);

    $resp = httpPostJson($url, [
        'open_kfid' => $openKfId,
        'external_userid' => $externalUserId,
        'service_state' => $serviceState,
    ]);

    if (!$resp || !isset($resp['errcode'])) {
        wecom_kf_log('service_state/trans: no response');
        return false;
    }
    if ($resp['errcode'] !== 0) {
        wecom_kf_log('service_state/trans error: ' . wecom_kf_response_error($resp));
        return false;
    }
    wecom_kf_log('service_state/trans OK user=' . substr(sha1($externalUserId), 0, 10) . ' state=' . $serviceState);
    return true;
}

// ──────────────────────────────────────────
// sync_msg —拉取具体消息
// ──────────────────────────────────────────

function syncMsg(string $openKfId, string $token, string $cursor = ''): ?array
{
    $accessToken = getAccessToken();
    if ($accessToken === null) return null;

    $url = "https://qyapi.weixin.qq.com/cgi-bin/kf/sync_msg?access_token=" . urlencode($accessToken);

    $resp = httpPostJson($url, [
        'cursor' => $cursor,
        'token' => $token,
        'limit' => 100,
        'open_kfid' => $openKfId,
        'voice_format' => 0,
    ]);

    if (!$resp || !isset($resp['errcode'])) {
        wecom_kf_log('sync_msg: no response');
        return null;
    }
    if ($resp['errcode'] !== 0) {
        wecom_kf_log('sync_msg error: ' . wecom_kf_response_error($resp));
        return null;
    }

    // 由 processKfEvent 在消息成功发送后统一持久化，失败时必须保留旧游标。
    return [
        'messages' => $resp['msg_list'] ?? [],
        'next_cursor' => (string)($resp['next_cursor'] ?? ''),
    ];
}

// ──────────────────────────────────────────
// messages/send —发送消息
// ──────────────────────────────────────────

/**
 * v3.23 进入会话欢迎语。
 *
 * 企业微信下发 welcome_code 的条件(官方原文):
 *   「用户在过去48小时里未收过欢迎语,且未向客服发过消息」
 * 也就是说「顾客一进来就直接发订单号」时平台不会给 welcome_code,
 * 本函数自然跳过 —— 需求里的「不是直接发订单才发欢迎语」由平台判定,
 * 代码不做任何订单号判断。
 *
 * 幂等:code 仅可使用一次且 20 秒过期,平台会自动拒绝重复使用,
 *       游标回退重放时接口返回非 0,记日志跳过即可。不建去重表。
 *
 * @return bool 是否成功发送
 */
function handleEnterSession(PDO $db, array $evt, string $useOpenKfId): bool
{
    $externalUserId = trim((string)($evt['external_userid'] ?? ''));
    $welcomeCode    = trim((string)($evt['welcome_code'] ?? ''));

    if ($externalUserId === '') {
        wecom_kf_log('enter_session: missing external_userid, skip');
        return false;
    }

    if ($welcomeCode === '') {
        // 48h 内收过欢迎语,或顾客已主动发过消息(含直接发订单号)
        wecom_kf_log('enter_session: no welcome_code, skip (already messaged or welcomed within 48h)');
        return false;
    }

    $text = trim((string)pcGet($db, 'wecom.welcome_text', ''));
    if ($text === '') {
        $text = '欢迎您咨询柚光民宿AI客服，我是小柚。您想办理自助入住，请发送完整的订单号码';
    }
    // 官方上限:2048 字节(UTF-8)
    if (strlen($text) > 2048) {
        $text = function_exists('mb_strcut')
            ? mb_strcut($text, 0, 2048, 'UTF-8')
            : substr($text, 0, 2048);
        wecom_kf_log('enter_session: welcome text truncated to 2048 bytes');
    }

    $accessToken = getAccessToken();
    if ($accessToken === null) {
        wecom_kf_log('enter_session: getAccessToken failed, welcome not sent');
        return false;
    }

    $url  = 'https://qyapi.weixin.qq.com/cgi-bin/kf/send_msg_on_event?access_token=' . urlencode($accessToken);
    $resp = httpPostJson($url, [
        'code'    => $welcomeCode,
        'msgtype' => 'text',
        'text'    => ['content' => $text],
    ]);

    if (!$resp || !isset($resp['errcode'])) {
        wecom_kf_log('enter_session: send_msg_on_event no response');
        return false;
    }
    if ((int)$resp['errcode'] !== 0) {
        // 预期路径:code 已用过 / 超过20秒 / 权限未开通,均不影响正常对话
        wecom_kf_log('enter_session: send_msg_on_event errcode=' . (int)$resp['errcode']
            . ' errmsg=' . (string)($resp['errmsg'] ?? ''));
        return false;
    }

    wecom_kf_log('enter_session: welcome sent, kf=' . substr(sha1($useOpenKfId), 0, 10)
        . ' msgid=' . (string)($resp['msgid'] ?? ''));
    return true;
}

function sendKfMessage(string $externalUserId, string $content, string $useOpenKfId = ''): bool
{
    $accessToken = getAccessToken();
    if ($accessToken === null) return false;

    // 优先用调用方传进来的 open_kfid（来自事件，最准确），
    // 传空才回退到配置（防止配置写错或失效）
    $openKfId = $useOpenKfId !== '' ? $useOpenKfId : getConfiguredOpenKfId();

    $url = "https://qyapi.weixin.qq.com/cgi-bin/kf/send_msg?access_token=" . urlencode($accessToken);

    $resp = httpPostJson($url, [
        'touser' => $externalUserId,
        'open_kfid' => $openKfId,
        'msgtype' => 'text',
        'text' => ['content' => $content],
    ]);

    if (!$resp || !isset($resp['errcode'])) {
        return false;
    }
    if ($resp['errcode'] !== 0) {
        wecom_kf_log('send_msg error: ' . wecom_kf_response_error($resp));
        return false;
    }
    return true;
}

/**
 * v3.7 — 发送 link 类型消息(图文链接卡片)
 * 用于云房卡跳转:title + desc + url + thumb_media_id
 */
function sendKfLinkMessage(string $externalUserId, array $link, string $useOpenKfId = ''): bool
{
    $accessToken = getAccessToken();
    if ($accessToken === null) return false;

    $openKfId = $useOpenKfId !== '' ? $useOpenKfId : getConfiguredOpenKfId();
    $url = "https://qyapi.weixin.qq.com/cgi-bin/kf/send_msg?access_token=" . urlencode($accessToken);

    $payload = [
        'touser' => $externalUserId,
        'open_kfid' => $openKfId,
        'msgtype' => 'link',
        'link' => [
            'title' => mb_substr((string)($link['title'] ?? '云房卡'), 0, 128),
            'desc'  => mb_substr((string)($link['desc'] ?? ''), 0, 512),
            'url'   => (string)($link['url'] ?? ''),
            'thumb_media_id' => (string)($link['thumb_media_id'] ?? ''),
        ],
    ];

    if ($payload['link']['url'] === '' || $payload['link']['thumb_media_id'] === '') {
        wecom_kf_log('send_kf_link skip: missing url or thumb_media_id');
        return false;
    }

    $resp = httpPostJson($url, $payload);
    if (!$resp || !isset($resp['errcode'])) {
        return false;
    }
    if ($resp['errcode'] !== 0) {
        wecom_kf_log('send_kf_link error: ' . wecom_kf_response_error($resp));
        return false;
    }
    wecom_kf_log('Sent roomcard link message');
    return true;
}

/**
 * v3.9 — 发送 miniprogram 类型消息(小程序卡片)
 * 1 步直达:客户点 → 直接打开小程序 → 看到云房卡
 * 区别于 link:link 走中间页(要再点"打开小程序"),miniprogram 直接进
 */
/**
 * v3.21:sendKfMiniprogramMessage 的扩展版,返回 ['ok'=>bool, 'errcode'=>int]。
 * 40007 自愈路径需要区分"thumb 无效"与其他错误。
 */
function sendKfMiniprogramMessageEx(string $externalUserId, array $mini, string $useOpenKfId = ''): array
{
    $accessToken = getAccessToken();
    if ($accessToken === null) return ['ok' => false, 'errcode' => -1];

    $openKfId = $useOpenKfId !== '' ? $useOpenKfId : getConfiguredOpenKfId();
    $url = "https://qyapi.weixin.qq.com/cgi-bin/kf/send_msg?access_token=" . urlencode($accessToken);

    $payload = [
        'touser' => $externalUserId,
        'open_kfid' => $openKfId,
        'msgtype' => 'miniprogram',
        'miniprogram' => [
            'appid' => (string)($mini['appid'] ?? ''),
            'title' => mb_substr((string)($mini['title'] ?? '云房卡'), 0, 64),
            'pagepath' => (string)($mini['pagepath'] ?? ''),
            'thumb_media_id' => (string)($mini['thumb_media_id'] ?? ''),
        ],
    ];

    if ($payload['miniprogram']['appid'] === ''
        || $payload['miniprogram']['pagepath'] === ''
        || $payload['miniprogram']['thumb_media_id'] === '') {
        wecom_kf_log('send_kf_mini skip: missing appid/pagepath/thumb_media_id');
        return ['ok' => false, 'errcode' => -2];
    }

    $resp = httpPostJson($url, $payload);
    if (!$resp || !isset($resp['errcode'])) {
        return ['ok' => false, 'errcode' => -3];
    }
    $errcode = intval($resp['errcode']);
    if ($errcode !== 0) {
        wecom_kf_log('send_kf_mini error: ' . wecom_kf_response_error($resp));
        return ['ok' => false, 'errcode' => $errcode];
    }
    wecom_kf_log('Sent roomcard miniprogram message');
    return ['ok' => true, 'errcode' => 0];
}

function sendKfMiniprogramMessage(string $externalUserId, array $mini, string $useOpenKfId = ''): bool
{
    $accessToken = getAccessToken();
    if ($accessToken === null) return false;

    $openKfId = $useOpenKfId !== '' ? $useOpenKfId : getConfiguredOpenKfId();
    $url = "https://qyapi.weixin.qq.com/cgi-bin/kf/send_msg?access_token=" . urlencode($accessToken);

    $payload = [
        'touser' => $externalUserId,
        'open_kfid' => $openKfId,
        'msgtype' => 'miniprogram',
        'miniprogram' => [
            'appid' => (string)($mini['appid'] ?? ''),
            'title' => mb_substr((string)($mini['title'] ?? '云房卡'), 0, 64),
            'pagepath' => (string)($mini['pagepath'] ?? ''),
            'thumb_media_id' => (string)($mini['thumb_media_id'] ?? ''),
        ],
    ];

    if ($payload['miniprogram']['appid'] === ''
        || $payload['miniprogram']['pagepath'] === ''
        || $payload['miniprogram']['thumb_media_id'] === '') {
        wecom_kf_log('send_kf_mini skip: missing appid/pagepath/thumb_media_id');
        return false;
    }

    $resp = httpPostJson($url, $payload);
    if (!$resp || !isset($resp['errcode'])) {
        return false;
    }
    if ($resp['errcode'] !== 0) {
        wecom_kf_log('send_kf_mini error: ' . wecom_kf_response_error($resp));
        return false;
    }
    $pagepath = $payload['miniprogram']['pagepath'];
    wecom_kf_log('Sent roomcard miniprogram message appid_len=' . strlen($payload['miniprogram']['appid'])
        . ' pagepath_len=' . strlen($pagepath)
        . ' pagepath_has_query=' . (strpos($pagepath, '?') !== false ? '1' : '0'));
    return true;
}

function getConfiguredOpenKfId(): string
{
    global $openKfId;
    return $openKfId;
}

// ──────────────────────────────────────────
// access_token 缓存
// ──────────────────────────────────────────
// v3.35：getAccessToken() 已搬到 api/wecom_kf_token.php（原样，缓存键不变）。

// ──────────────────────────────────────────
// HTTP helper（httpGet 已搬到 api/wecom_kf_token.php，此处仅剩 httpPostJson）
// ──────────────────────────────────────────

function httpPostJson(string $url, array $data): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $httpCode !== 200) {
        wecom_kf_log("HTTP POST failed: code=$httpCode err=$err");
        return null;
    }
    return json_decode($resp, true);
}

// ──────────────────────────────────────────
// v3.35 — image / video 消息发送与媒体自愈（蓝图 §7.6.1 / qa B3 / P2-6）
// ──────────────────────────────────────────

/**
 * 发送 image 类型消息。返回值对齐 sendKfMiniprogramMessageEx 先例：
 * ['ok'=>bool,'errcode'=>int,'errmsg'=>string]（qa B3：返 bool 拿不到 errcode 就无法自愈）。
 */
function sendKfImageMessage(string $externalUserId, string $mediaId, string $useOpenKfId = ''): array
{
    return sendKfMediaMessage($externalUserId, 'image', $mediaId, $useOpenKfId);
}

/** 发送 video 类型消息（官方 body 只有 media_id，无 title/description，§3.2）。同上返回结构。 */
function sendKfVideoMessage(string $externalUserId, string $mediaId, string $useOpenKfId = ''): array
{
    return sendKfMediaMessage($externalUserId, 'video', $mediaId, $useOpenKfId);
}

function sendKfMediaMessage(string $externalUserId, string $msgType, string $mediaId, string $useOpenKfId = ''): array
{
    $accessToken = getAccessToken();
    if ($accessToken === null) return ['ok' => false, 'errcode' => -1, 'errmsg' => 'access_token unavailable'];
    if ($mediaId === '' || !in_array($msgType, ['image', 'video'], true)) {
        wecom_kf_log('send_kf_media skip: missing media_id or bad msgtype=' . $msgType);
        return ['ok' => false, 'errcode' => -2, 'errmsg' => 'missing media_id or bad msgtype'];
    }

    $openKfId = $useOpenKfId !== '' ? $useOpenKfId : getConfiguredOpenKfId();
    $url = "https://qyapi.weixin.qq.com/cgi-bin/kf/send_msg?access_token=" . urlencode($accessToken);
    $payload = [
        'touser'   => $externalUserId,
        'open_kfid' => $openKfId,
        'msgtype'  => $msgType,
        $msgType   => ['media_id' => $mediaId],
    ];

    $resp = httpPostJson($url, $payload);
    if (!$resp || !isset($resp['errcode'])) {
        return ['ok' => false, 'errcode' => -3, 'errmsg' => 'no response'];
    }
    $errcode = intval($resp['errcode']);
    $errmsg  = (string)($resp['errmsg'] ?? '');
    if ($errcode !== 0) {
        wecom_kf_log('send_kf_' . $msgType . ' error: ' . wecom_kf_response_error($resp) . ' ' . $errmsg);
        return ['ok' => false, 'errcode' => $errcode, 'errmsg' => $errmsg];
    }
    wecom_kf_log('Sent kf ' . $msgType . ' message');
    return ['ok' => true, 'errcode' => 0, 'errmsg' => ''];
}

/**
 * v3.35 — 发一张指引图片：缓存 → uploadMediaFromUrl → send；任何非 0 errcode 自愈一次。
 *
 * P2-6（CodeBuddy）：企微对 image/video 素材过期的 errcode 未实测（不只 40007/40008），
 * 故**任何非 0 errcode** 都按「素材可能失效」自愈：mediaCacheClear → 重新 upload → 重发一次，
 * 仍失败则跳过该张并记 errcode（§5.3：跳过该张继续发后面的，文字照常送达）。
 * $deadline：单轮图片媒体预算（§5.3/B5 下载 5s + 上传 15s，调用方给 20s 总闸），超时放弃。
 * 媒体函数缺失（api/wecom_kf_media.php 未到位的发布窗口）→ 静默跳过（fail-open）。
 */
function sendKfGuideImage(PDO $db, string $externalUserId, string $url, string $useOpenKfId, float $deadline = 0.0): bool
{
    if ($deadline > 0.0 && microtime(true) > $deadline) {
        wecom_kf_log('guide image skipped: media budget exceeded'); // §5.3 放弃剩余媒体，文字照发
        return false;
    }
    if (!function_exists('uploadMediaFromUrl') || !function_exists('mediaCacheKey')
        || !function_exists('mediaCacheGet') || !function_exists('mediaCacheSet')
        || !function_exists('mediaCacheClear')) {
        wecom_kf_log('guide image skipped: wecom_kf_media functions unavailable');
        return false;
    }
    try {
        $key = mediaCacheKey($url);
        $mediaId = mediaCacheGet($key);
        if ($mediaId === null || $mediaId === '') {
            $up = uploadMediaFromUrl($db, $url, 'image');
            if (empty($up['ok']) || empty($up['media_id'])) {
                wecom_kf_log('guide image upload failed: ' . wecom_kf_response_error(is_array($up) ? $up : [])
                    . ' ' . (string)($up['errmsg'] ?? '') . ' user=' . substr(sha1($externalUserId), 0, 10));
                return false;
            }
            $mediaId = (string)$up['media_id'];
            mediaCacheSet($key, $mediaId);
        }
        $r = sendKfImageMessage($externalUserId, (string)$mediaId, $useOpenKfId);
        if ($r['ok']) return true;

        // 自愈：删缓存 → 重新 upload → 重发一次（只一次，避免重试放大）
        wecom_kf_log('guide image self-healing: errcode=' . $r['errcode']);
        mediaCacheClear($key);
        $up2 = uploadMediaFromUrl($db, $url, 'image');
        if (!empty($up2['ok']) && !empty($up2['media_id'])) {
            $mediaId2 = (string)$up2['media_id'];
            mediaCacheSet($key, $mediaId2);
            $r2 = sendKfImageMessage($externalUserId, $mediaId2, $useOpenKfId);
            if ($r2['ok']) return true;
            $r = $r2;
        }
        wecom_kf_log('guide image skipped after self-heal: errcode=' . $r['errcode'] . ' ' . $r['errmsg']);
        return false;
    } catch (\Throwable $e) {
        error_log('[wecom_kf] guide image failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * v3.35 — 发一条指引视频：按源文件路径现取 media_id（media_id 3 天必过期，§3.2 不长期缓存策略
 * 由 mediaCache* 的 60h TTL 承接，另一路实现），发送失败自愈一次（同图片口径）。
 */
function sendKfGuideVideo(PDO $db, string $externalUserId, string $path, string $useOpenKfId): bool
{
    if ($path === '' || !is_file($path)) {
        wecom_kf_log('guide video skipped: source file missing'); // §5.3 缺源文件 → 不发视频其余正常
        return false;
    }
    if (!function_exists('uploadMediaFromFile') || !function_exists('mediaCacheKey')
        || !function_exists('mediaCacheGet') || !function_exists('mediaCacheSet')
        || !function_exists('mediaCacheClear')) {
        wecom_kf_log('guide video skipped: wecom_kf_media functions unavailable');
        return false;
    }
    try {
        // ⚠️ type 必须与 uploadMediaFromFile 内部及 verify.php 的清理一致（'video'），
        // 否则读不到自己写的缓存（每次都重传 10MB 视频），且删视频时清不掉这条缓存。
        $key = mediaCacheKey($path, 'video');
        $mediaId = mediaCacheGet($key);
        if ($mediaId === null || $mediaId === '') {
            $up = uploadMediaFromFile($db, $path, 'video'); // 上传超时 30s 在媒体函数内控制（§5.3）
            if (empty($up['ok']) || empty($up['media_id'])) {
                wecom_kf_log('guide video upload failed: ' . wecom_kf_response_error(is_array($up) ? $up : [])
                    . ' ' . (string)($up['errmsg'] ?? ''));
                return false;
            }
            $mediaId = (string)$up['media_id'];
            mediaCacheSet($key, $mediaId);
        }
        $r = sendKfVideoMessage($externalUserId, (string)$mediaId, $useOpenKfId);
        if ($r['ok']) return true;

        wecom_kf_log('guide video self-healing: errcode=' . $r['errcode']); // 40007/40008 及任何非 0（P2-6）
        mediaCacheClear($key);
        $up2 = uploadMediaFromFile($db, $path, 'video');
        if (!empty($up2['ok']) && !empty($up2['media_id'])) {
            $mediaId2 = (string)$up2['media_id'];
            mediaCacheSet($key, $mediaId2);
            $r2 = sendKfVideoMessage($externalUserId, $mediaId2, $useOpenKfId);
            if ($r2['ok']) return true;
            $r = $r2;
        }
        wecom_kf_log('guide video skipped after self-heal: errcode=' . $r['errcode'] . ' ' . $r['errmsg']);
        return false;
    } catch (\Throwable $e) {
        error_log('[wecom_kf] guide video failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * v3.35 — 指引/视频送达事件写 chat_logs（qa B11 / 蓝图 §7.6.5），房管端「客人咨询记录」可见。
 * 先例：wecom_kf_roomcard_v37.php writeRoomCardChatEvent（同样的列组合与 catch 风格）。
 * $content 由调用方保证只用展示名（display_name / room_code），不含敏感字段（黑名单红线）。
 * 全 catch：写失败只打日志，绝不阻断消息发送流程。
 */
function writeGuideChatEvent(PDO $db, string $sessionId, string $content, string $visitorHash, string $ip): void
{
    try {
        // §3.7 硬红线：客人可见内容/日志里绝不出现这些字段的值（默认不信中台全字段）
        $content = preg_replace(
            '/(lock_password|cleaning_password|room_password|owner|lock_user_name)\s*[:=：]\s*[^\s,，;；]+/iu',
            '$1=[已屏蔽]',
            (string)$content
        );
        $stmt = $db->prepare(
            'INSERT INTO chat_logs (session_id, channel, workflow, rendered_type, role, content, has_verified, visitor_hash, source_ip, tokens)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            'wechat_kf',
            'OrderGuideDelivery',
            'guide',
            'assistant',
            $content,
            0,
            $visitorHash,
            $ip,
            0,
        ]);
    } catch (\Throwable $e) {
        error_log('[wecom_kf] guide chat_logs event failed: ' . $e->getMessage());
    }
}

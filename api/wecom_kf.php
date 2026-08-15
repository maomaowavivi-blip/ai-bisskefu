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

function wecom_kf_log(string $msg): void
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] [wecom_kf] ' . $msg . "\n";
    @file_put_contents($logDir . '/wecom_kf.log', $line, FILE_APPEND);
}

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

    wecom_kf_log("GET verify: sig=" . substr($signature, 0, 10) . "... ts=$timestamp");

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

    wecom_kf_log("POST: MsgType=$msgType Event=$event openKfId=$callbackOpenKfId");

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

    // 1. 拉消息（用持久化的 cursor 增量拉，避免一次拉回历史未消费消息）
    $cursorFile = __DIR__ . '/../logs/wecom_kf_cursor_' . substr(sha1($useOpenKfId), 0, 8) . '.txt';
    $savedCursor = file_exists($cursorFile) ? trim(file_get_contents($cursorFile)) : '';
    wecom_kf_log('sync_msg cursor=' . substr($savedCursor, 0, 30));

    $messages = syncMsg($useOpenKfId, $eventToken, $savedCursor);
    if ($messages === null) {
        wecom_kf_log('sync_msg failed');
        return;
    }
    wecom_kf_log('sync_msg returned ' . count($messages) . ' messages');

    foreach ($messages as $msg) {
        // 只处理客户发来的文本消息（external_userid 开头 + msgtype=text）
        $msgType = $msg['msgtype'] ?? '';
        $from    = $msg['external_userid'] ?? '';
        $content = $msg['text']['content'] ?? '';

        if ($msgType !== 'text' || empty($from) || empty($content)) {
            wecom_kf_log("Skip msg: type=$msgType from=$from content=" . substr($content, 0, 20));
            continue;
        }

        // 去重：同 msgid 5 分钟内只处理一次（修复 95001 限流）
        $msgId = $msg['msgid'] ?? '';
        if ($msgId !== '' && isMsgProcessed($msgId)) {
            wecom_kf_log("Skip dup msgid=$msgId from=$from");
            continue;
        }
        if ($msgId !== '') markMsgProcessed($msgId);

        // 2. ChatPipeline 处理
        $sessionId = 'wecom_kf_' . substr(sha1($from), 0, 16);

        try {
            $result = ChatPipeline::process(
                $sessionId,
                $content,
                [],
                $db,
                'wechat_kf',   // channel：Pipeline 不带 rich_content
                $from,
                'wecom_kf_callback'
            );
        } catch (Throwable $e) {
            wecom_kf_log('ChatPipeline failed: ' . $e->getMessage());
            continue;
        }

        $replyText = $result['data']['reply'] ?? '这边暂时没有查到准确信息，建议您联系前台确认。';

        // 截断（微信客服单条 600 字限制）
        $replyText = mb_substr($replyText, 0, 600);

        // 3. 发送前：把会话状态转为「由智能助手接待」(1)
        //    修复 95018：客服账号绑定了接待人员(自建应用自动成为接待者)，会话状态=3(已接待)，
        //    直接 send_msg 会被拒。先 trans 到 state=1 即可发送。
        transKfServiceState($useOpenKfId, $from, 1);

        // v3.14 — 订单号 → 发送 A 样式原生小程序卡片。
        // appid 和 pagepath 均使用宿家当前房卡返回值，本地不拼接订单参数。
        $trimmedMsg = trim($content);
        $roomCardSent = false;
        if (preg_match('/^\d{8,30}$/', $trimmedMsg)) {
            require_once __DIR__ . '/wecom_kf_roomcard_v37.php';
            $delivery = buildRoomCardDelivery($db, $trimmedMsg);

            if ($delivery) {
                $roomCardSent = sendKfMiniprogramMessage($from, [
                    'appid' => $delivery['appid'],
                    'title' => $delivery['title'],
                    'pagepath' => $delivery['pagepath'],
                    'thumb_media_id' => $delivery['thumb_media_id'],
                ], $useOpenKfId);
            }

            if (!$roomCardSent) {
                $roomCardSent = sendKfMessage(
                    $from,
                    '暂时未能打开您的云房卡，请稍后重试或联系前台。',
                    $useOpenKfId
                );
            }
        }

        if ($roomCardSent) continue;

        // 4. 发送（必须用会话对应的 open_kfid，不能用配置里的固定值——
        //    用户扫码进的是事件里的 OpenKfId，跟 platform_config 配置的可能是不同账号）
        $sent = sendKfMessage($from, $replyText, $useOpenKfId);
        if ($sent) {
            wecom_kf_log("Replied to $from: " . mb_substr($replyText, 0, 40));
        } else {
            wecom_kf_log("send_kf_message failed for $from");
        }
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
        wecom_kf_log('service_state/trans error: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
        return false;
    }
    wecom_kf_log("service_state/trans OK: $openKfId $externalUserId -> state=$serviceState");
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
        wecom_kf_log('sync_msg error: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
        return null;
    }

    // 持久化 next_cursor（按 open_kfid 分文件，避免不同客服账号串扰）
    if (isset($resp['next_cursor']) && $resp['next_cursor'] !== '') {
        $cursorFile = __DIR__ . '/../logs/wecom_kf_cursor_' . substr(sha1($openKfId), 0, 8) . '.txt';
        @file_put_contents($cursorFile, $resp['next_cursor']);
        wecom_kf_log('sync_msg next_cursor saved: ' . substr($resp['next_cursor'], 0, 30));
    }

    return $resp['msg_list'] ?? [];
}

// ──────────────────────────────────────────
// messages/send —发送消息
// ──────────────────────────────────────────

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
        wecom_kf_log('send_msg error: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
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
        wecom_kf_log('send_kf_link error: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
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
        wecom_kf_log('send_kf_mini error: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
        return false;
    }
    wecom_kf_log('Sent roomcard miniprogram message');
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

function getAccessToken(): ?string
{
    global $db;

    // 检查缓存
    $cached = trim(pcGet($db, 'wecom.access_token_cache', ''));
    $expiresAt = (int)pcGet($db, 'wecom.access_token_expires_at', 0);
    if ($cached && $expiresAt > time() + 60) {  // 提前 60 秒过期避免临界
        return $cached;
    }

    // 调接口
    $corpSecret = trim(pcGet($db, 'wecom.corp_secret', ''));
    $corpId     = trim(pcGet($db, 'wecom.corpid', ''));

    $url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=" . urlencode($corpId) . "&corpsecret=" . urlencode($corpSecret);
    $resp = httpGet($url);
    if (!$resp || !isset($resp['access_token'])) {
        wecom_kf_log('gettoken failed: ' . json_encode($resp ?? [], JSON_UNESCAPED_UNICODE));
        return null;
    }

    $token = $resp['access_token'];
    $expiresIn = (int)($resp['expires_in'] ?? 7200);

    // 写缓存
    pcSet($db, 'wecom.access_token_cache', $token);
    pcSet($db, 'wecom.access_token_expires_at', (string)(time() + $expiresIn));

    return $token;
}

// ──────────────────────────────────────────
// HTTP helper
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

function httpGet(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $httpCode !== 200) {
        wecom_kf_log("HTTP GET failed: code=$httpCode err=$err");
        return null;
    }
    return json_decode($resp, true);
}

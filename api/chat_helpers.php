<?php
/**
 * api/chat_helpers.php
 *
 * v3.3 PR3 — chat.php 全局函数集中文件
 *
 * 之前这些函数直接定义在 chat.php 里，Pipeline 想调用但无法 require（chat.php 头部有副作用）。
 * 抽出来后 Pipeline 和 chat.php 都可以 require_once 这个文件。
 *
 * 包含：
 * - chatResponse() — 统一响应出口（自动加 elapsed_ms）
 * - checkInputSafety() — 政治/色情等敏感词拦截
 * - shouldTriggerHandoff() — LLM 回复是否触发转人工
 * - respondDirectHandoff() — 直转人工响应
 * - isYunfangkaCardFollowUp() — 查单后云房卡追问识别
 * - respondDirectKb() — KB 直答响应
 * - callGateway() — PMS 网关调用
 * - queryRoomLocal() — 本地房间查询
 *
 * 与 chat.php 的关系：chat.php require_once 本文件后行为不变。
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function chatResponse($code, $msg = '', $data = null) {
    global $chatRequestStart;
    if ($chatRequestStart !== null && is_array($data)) {
        $data['elapsed_ms'] = (int) round((microtime(true) - $chatRequestStart) * 1000);
    }
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 输入安全拦截（命中直接返回，不消耗 AI token）
 * 返回拦截回复文字，安全则返回 null
 */
function checkInputSafety(string $msg, ?AgentConfig $config = null): ?string {
    $political = ($config) ? $config->getJson('agent.safety.political', []) : [];
    if (empty($political)) {
        $political = ['法轮功', '天安门', '六四事件', '台独', '藏独', '港独'];
    }
    static $adult     = ['色情', '裸体', '性爱', '做爱', '强奸', '卖淫', '自杀方式', '杀人方法'];
    static $injection = [
        '忽略上面', '忽略之前', '忘记上面', '忘记之前',
        '你现在是', '从现在起你是', '扮演一个',
        'ignore previous', 'ignore all', 'ignore the above',
        'system prompt', 'disregard',
    ];

    foreach ($political as $kw) {
        if (mb_strpos($msg, $kw) !== false) {
            return '这个话题我不太方便讨论，有其他可以帮您的吗~';
        }
    }
    foreach ($adult as $kw) {
        if (mb_strpos($msg, $kw) !== false) {
            return '抱歉，这类内容我无法回应~';
        }
    }
    foreach ($injection as $kw) {
        if (mb_stripos($msg, $kw) !== false) {
            return '您好，有什么可以帮您的吗~';
        }
    }
    return null;
}

/**
 * v3.11: shouldTriggerHandoff 已废弃（不再触发转人工，统一走 400 兜底）
 * 保留函数签名作为兼容层，永远返 false
 */
function shouldTriggerHandoff(string $reply, string $message, PDO $db): bool
{
    return false;
}

/**
 * v3.11: respondDirectHandoff 已废弃（handoff 整套删除）
 * 保留函数签名作为 noop，新代码应直接走 KnowledgeWorkflow 400 fast path
 */
function respondDirectHandoff(PDO $db, string $sessionId, string $message, string $visitorHash, string $ip): void
{
    // noop - v3.11: handoff 机制已废弃
}

/** 查单展示云房卡卡片后，客人追问「这是什么 / 不会用」等 */
function isYunfangkaCardFollowUp(PDO $db, string $sessionId, string $message): bool {
    $msg = trim($message);
    if ($msg === '') {
        return false;
    }
    if (preg_match('/云房卡|电子房卡/u', $msg)) {
        return true;
    }
    try {
        $st = $db->prepare('SELECT 1 FROM order_context_cache WHERE session_id = ? AND expires_at > NOW() LIMIT 1');
        $st->execute([$sessionId]);
        if (!$st->fetch()) {
            return false;
        }
    } catch (Exception $e) {
        return false;
    }
    return (bool) preg_match(
        '/^(这是什么|这什么|这是啥|不会用|不知道怎么用|怎么用|如何使用|怎么操作|你发的是什么|给我发的是什么|发我的是什么|发的是什么|上面是什么|下面是什么|卡片是什么|那个是什么)/u',
        $msg
    ) || (bool) preg_match('/(你给我发|给我发的|发的是啥|什么卡片|什么链接|怎么点|点哪里|如何使用|查看云房卡|云房卡)/u', $msg);
}

/** 通用政策 KB 直答（不走 LLM / Sidecar / 转人工） */
function respondDirectKb(PDO $db, string $sessionId, string $message, string $reply, string $visitorHash, string $ip): void {
    $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, 0, ?, ?, 0)');
    $stmt->execute([$sessionId, 'user', $message, $visitorHash, $ip]);
    $stmt->execute([$sessionId, 'assistant', $reply, $visitorHash, $ip]);
    chatResponse(0, 'ok', ['reply' => $reply, 'is_verified' => false, 'handoff_status' => -1]);
}

/**
 * 统一网关调用
 */
function callGateway($db, $action, $params) {
    $gatewayUrl = pcGet($db, 'gateway.api_url', '');
    $gatewayKey = pcGet($db, 'gateway.api_key', '');
    if (!$gatewayUrl) $gatewayUrl = pcGet($db, 'order.api_url', '');
    if (!$gatewayKey) $gatewayKey = pcGet($db, 'order.api_key', '');
    if (!$gatewayUrl || !$gatewayKey) return null;

    $payload = ['action' => $action, 'params' => $params, 'timestamp' => time()];
    try {
        $ch = curl_init($gatewayUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . (strpos($gatewayKey, 'Bearer ') === 0 ? $gatewayKey : 'Bearer ' . $gatewayKey),
            ],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp && $httpCode === 200) {
            $result = json_decode($resp, true);
            if (isset($result['code']) && intval($result['code']) === 200 && isset($result['data'])) {
                return $result['data'];
            }
        }
        // PMS 返回 404（未找到房间信息）时，保留 msg 供调用方判断
        if ($resp) {
            $result = json_decode($resp, true);
            if (isset($result['code']) && intval($result['code']) === 404) {
                return ['_not_found' => true, 'msg' => $result['msg'] ?? '未找到房间信息'];
            }
        }
    } catch (Throwable $e) {}
    return null;
}

/**
 * 本地 Sidecar 房间查询（替代远程 query_room 网关）
 */
function queryRoomLocal(PDO $db, string $sessionId, string $roomId, string $question, bool $isVerified = false): ?array {
    if (!$isVerified) {
        try {
            $st = $db->prepare('SELECT 1 FROM order_context_cache WHERE session_id = ? AND expires_at > NOW() LIMIT 1');
            $st->execute([$sessionId]);
            if ($st->fetchColumn()) {
                $isVerified = true;
            }
        } catch (Exception $e) {}
        if (!$isVerified) {
            try {
                $st = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE session_id = ? AND status = 1 AND verified_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
                $st->execute([$sessionId]);
                $isVerified = intval($st->fetchColumn() ?: 0) > 0;
            } catch (Exception $e) {}
        }
    }
    return RoomQueryService::query($roomId, $question, $isVerified, $sessionId);
}


<?php
// api/openapi.php
// 外部 API 接口 - 用于企业微信/第三方系统集成
//
// POST /api/openapi.php
// Headers:
//   X-API-Key: {api_key}
//   Content-Type: application/json
//
// Body:
// {
//   "session_id": "可选，不传则自动生成",
//   "message": "用户消息内容",
//   "history": []  // 可选，历史消息数组
// }
//
// 成功响应：
// {
//   "code": 0,
//   "data": {
//     "session_id": "sess_xxx",
//     "reply": "AI回复内容"
//   }
// }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PromptEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('仅支持POST请求', 405);
}

// 验证 API Key
$apiKey = '';
$headerKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($headerKey) {
    $apiKey = $headerKey;
} elseif ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $apiKey = trim($m[1]);
}

if (!$apiKey) fail('缺少API密钥', 401);

$db = getDB();
$config = new AgentConfig($db);
$stmt = $db->prepare("SELECT id, name FROM api_keys WHERE api_key = ? AND enabled = 1 LIMIT 1");
$stmt->execute([$apiKey]);
$keyRecord = $stmt->fetch();

if (!$keyRecord) fail('API密钥无效', 403);

// 更新最后使用时间
$db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$keyRecord['id']]);

$body = getBody();

$sessionId = trim($body['session_id'] ?? '');
$message   = trim($body['message'] ?? '');
$history   = $body['history'] ?? [];

if (!$sessionId) {
    $sessionId = 'openapi_' . date('Ymd') . '_' . bin2hex(random_bytes(8));
}
if (!$message) fail('消息不能为空', 400);

// 安全拦截
$safetyReply = checkInputSafety($message, $config);
if ($safetyReply !== null) {
    ok(['session_id' => $sessionId, 'reply' => $safetyReply]);
}

// 验证状态
$stmt = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE session_id = ? AND status = 1 AND verified_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
$stmt->execute([$sessionId]);
$isVerified = intval($stmt->fetchColumn() ?: 0) > 0;

// 人设
$stmt = $db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
$persona = $stmt->fetch() ?: [];

// 知识库检索（混合：语义检索 + 全文检索）
$rewrittenQuery = $message;
try {
    $rewrittenQuery = PromptEngine::rewriteQuery($message, $history, $sessionId);
} catch (Exception $e) {
    error_log('问题改写异常: ' . $e->getMessage());
}

$kbItems = [];
try {
    // 先尝试语义搜索
    $embeddingFile = __DIR__ . '/embedding.php';
    if (file_exists($embeddingFile)) {
        $ch = curl_init('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/embedding.php?action=search');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $rewrittenQuery, 'limit' => 3, 'threshold' => 0.5]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp && $httpCode === 200) {
            $result = json_decode($resp, true);
            if (isset($result['data']['list']) && !empty($result['data']['list'])) {
                $kbItems = $result['data']['list'];
            }
        }
    }
} catch (Exception $e) {
    error_log('语义搜索异常: ' . $e->getMessage());
}

// 语义搜索无结果时降级到全文检索
if (empty($kbItems)) {
    try {
        $kbItems = PromptEngine::searchKnowledge($db, $rewrittenQuery, PromptEngine::KB_MAX_ITEMS);
    } catch (Exception $e) {
        error_log('知识库检索异常: ' . $e->getMessage());
    }
}

// 组装
$messages = PromptEngine::buildMessages($persona, $history, $message, $kbItems, $sessionId, $rewrittenQuery, $config);

try {
    $temperature = floatval(pcGet($db, 'ai.temperature', '0.5'));
    $result = callAI($messages, [
        'max_tokens'  => $config->getInt('agent.llm.max_tokens', 150),
        'temperature' => $temperature,
    ]);
    $reply  = $result['content'];
    $reply  = PromptEngine::filterReply($reply, $message, $db, $config);
    $reply  = PromptEngine::finalizeReply($reply, $kbItems, $config);

    // 记录日志
    $stmt = $db->prepare('INSERT INTO chat_logs (session_id, channel, role, content, has_verified, source_ip, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt->execute([$sessionId, 'api', 'user', $message, $isVerified ? 1 : 0, $ip, 0]);
    $stmt->execute([$sessionId, 'api', 'assistant', $reply, $isVerified ? 1 : 0, $ip, intval($result['usage']['completion_tokens'] ?? 0)]);

    // 检查是否需要转人工
    try {
        if (shouldTriggerHandoff($reply, $message)) {
            createHandoffIfNeeded($db, $sessionId, 'AI无法回答触发转人工');
        }
    } catch (Exception $e) {
        error_log('Handoff check error: ' . $e->getMessage());
    }

    ok(['session_id' => $sessionId, 'reply' => $reply]);
} catch (Exception $e) {
    fail('AI调用失败：' . $e->getMessage(), 500);
}

// ── 辅助函数 ──

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
        if (mb_strpos($msg, $kw) !== false) return '这个话题我不太方便讨论，有其他可以帮您的吗~';
    }
    foreach ($adult as $kw) {
        if (mb_strpos($msg, $kw) !== false) return '抱歉，这类内容我无法回应~';
    }
    foreach ($injection as $kw) {
        if (mb_stripos($msg, $kw) !== false) return '您好，有什么可以帮您的吗~';
    }
    return null;
}

function shouldTriggerHandoff(string $reply, string $message): bool {
    $keywords = [
        '无法回答', '不清楚', '不知道', '没有相关信息',
        '建议联系', '转人工', '联系客服', '无法处理',
        '无法查询', '暂时无法', '不在我范围内',
    ];
    foreach ($keywords as $kw) {
        if (mb_strpos($reply, $kw) !== false) return true;
    }
    if (mb_strpos($reply, '这个我帮您核实一下') !== false) return true;
    if (mb_strpos($reply, '建议您联系') !== false) return true;

    // 从 DB 读取客户触发词（静态缓存，避免重复查询）
    static $triggerKeywords = null;
    if ($triggerKeywords === null) {
        try {
            $db = getDB();
            $stmt = $db->query("SELECT keyword FROM handoff_triggers");
            $triggerKeywords = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $triggerKeywords = [];
        }
    }
    foreach ($triggerKeywords as $kw) {
        if (mb_strpos($message, $kw) !== false) return true;
    }

    return false;
}

function createHandoffIfNeeded(PDO $db, string $sessionId, string $reason): void {
    $stmt = $db->prepare("SELECT id, status FROM human_handoffs WHERE session_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sessionId]);
    $existing = $stmt->fetch();

    if ($existing && intval($existing['status']) !== 2) return;

    $stmt = $db->prepare("INSERT INTO human_handoffs (session_id, status, reason) VALUES (?, 0, ?)");
    $stmt->execute([$sessionId, $reason]);
}

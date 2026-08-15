<?php
// api/config.php
// 数据库/密钥/公共函数 + AI 调用

// PHP 7 兼容：模拟 str_starts_with
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

$_ENV_STORE = [];

function loadDotEnv($path) {
    global $_ENV_STORE;
    if (!is_file($path) || !is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;
        $key = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));
        if ($key === '') continue;
        $_ENV_STORE[$key] = $val;
    }
}

function envVal($key, $default = null) {
    global $_ENV_STORE;
    if (isset($_ENV_STORE[$key]) && $_ENV_STORE[$key] !== '') return $_ENV_STORE[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    return $default;
}

loadDotEnv(dirname(__DIR__) . '/.env');

define('DB_HOST', envVal('DB_HOST', 'localhost'));
define('DB_PORT', envVal('DB_PORT', '3306'));
define('DB_NAME', envVal('DB_NAME', 'aibisskefu_com'));
define('DB_USER', envVal('DB_USER', 'root'));
define('DB_PASS', envVal('DB_PASS', ''));
define('JWT_SECRET', envVal('JWT_SECRET', 'change_this_secret'));

define('SIDECAR_DB_HOST', envVal('SIDECAR_DB_HOST', DB_HOST));
define('SIDECAR_DB_PORT', envVal('SIDECAR_DB_PORT', DB_PORT));
define('SIDECAR_DB_NAME', envVal('SIDECAR_DB_NAME', 'sujia_ai_sidecar_dev'));
define('SIDECAR_DB_USER', envVal('SIDECAR_DB_USER', DB_USER));
define('SIDECAR_DB_PASS', envVal('SIDECAR_DB_PASS', DB_PASS));

header('Content-Type: application/json; charset=utf-8');
$requestOrigin = trim($_SERVER['HTTP_ORIGIN'] ?? '');
$allowedOrigins = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', (string)envVal('CORS_ALLOWED_ORIGINS', '')))));
if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['code' => 500, 'msg' => '数据库连接失败']);
            exit();
        }
    }
    return $pdo;
}

function getSidecarDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . SIDECAR_DB_HOST . ';port=' . SIDECAR_DB_PORT . ';dbname=' . SIDECAR_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, SIDECAR_DB_USER, SIDECAR_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function ok($data = [], $msg = 'ok') {
    echo json_encode(['code' => 0, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit();
}

function fail($msg = '请求失败', $code = 400) {
    http_response_code($code);
    echo json_encode(['code' => $code, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit();
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

/**
 * 只信任 Web 服务器实际收到的来源 IP。
 * X-Forwarded-For 不能由客户端直接决定，除非前置代理已明确配置并清洗它。
 */
function requestClientIp($fallback = '') {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? $fallback));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function validateRequestId($value, $field = '请求标识', $maxLength = 128) {
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > $maxLength || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $value)) {
        fail($field . '格式不正确');
    }
    return $value;
}

// ══════════════════════════════════════════
// JWT 认证（管理员用）
// ══════════════════════════════════════════

function makeToken($userId, $role) {
    $payload = [
        'uid' => $userId,
        'role' => $role,
        'exp' => time() + 86400 * 7,
    ];
    $data = base64_encode(json_encode($payload));
    $sign = hash_hmac('sha256', $data, JWT_SECRET);
    return $data . '.' . $sign;
}

function authToken() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$auth || !preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
        fail('未登录', 401);
    }
    $token = trim($matches[1]);
    $parts = explode('.', $token);
    if (count($parts) !== 2) fail('Token无效', 401);
    $data = $parts[0];
    $sign = $parts[1];
    $expected = hash_hmac('sha256', $data, JWT_SECRET);
    if (!hash_equals($expected, $sign)) fail('Token签名错误', 401);
    $decoded = base64_decode($data, true);
    $payload = $decoded === false ? null : json_decode($decoded, true);
    if (!$payload || !isset($payload['exp']) || intval($payload['exp']) < time()) fail('Token已过期', 401);
    return $payload;
}

function adminGuard() {
    $auth = authToken();
    if (intval($auth['role'] ?? 0) !== 3) {
        fail('仅管理员可操作', 403);
    }
    return $auth;
}

// ══════════════════════════════════════════
// platform_config 辅助函数
// ══════════════════════════════════════════

function pcGet(PDO $db, $key, $default = null) {
    try {
        $stmt = $db->prepare("SELECT `value` FROM platform_config WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) return $default;
        $val = $row['value'];
        if ($val === null || $val === '') return $default;
        return $val;
    } catch (Exception $e) {
        return $default;
    }
}

function pcGetInt(PDO $db, $key, $default = 0) {
    $v = pcGet($db, $key, null);
    if ($v === null) return $default;
    return is_numeric($v) ? intval($v) : $default;
}

/**
 * v3.4：写入 platform_config 配置项（upsert）
 * 用于 wecom_kf.php 等需要缓存 access_token 等场景
 */
function pcSet(PDO $db, $key, $value): bool
{
    try {
        $stmt = $db->prepare(
            "INSERT INTO platform_config (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        );
        $stmt->execute([$key, (string)$value]);
        return true;
    } catch (Exception $e) {
        error_log('[pcSet] failed for key=' . $key . ': ' . $e->getMessage());
        return false;
    }
}

// ══════════════════════════════════════════
// AI 调用
// ══════════════════════════════════════════
// 设计：单一 Key 多 Provider
//   - 后台只暴露一个「AI API Key」输入框（存 platform_config.ai.api_key）
//   - 根据 model 名自动选择 endpoint（含 "deepseek" 走 DeepSeek，否则走 MiniMax）
//   - 默认主聊天模型 = deepseek-v4-flash
//   - .env 可覆盖单独 provider 的 key（DEEPSEEK_API_KEY / MINIMAX_API_KEY）
// ══════════════════════════════════════════

define('DEFAULT_MODEL', 'deepseek-v4-flash');
define('DEFAULT_API_URL', 'https://api.deepseek.com/v1/chat/completions');
define('MINIMAX_API_URL_DEFAULT', 'https://api.minimaxi.com/v1/text/chatcompletion_v2');

function callAI($messages, $opts = []) {
    $model = $opts['model'] ?? envVal('AI_MODEL', DEFAULT_MODEL);

    // 根据 model 名选 endpoint
    $isDeepSeek = (strpos($model, 'deepseek') !== false);
    $apiUrl = $isDeepSeek
        ? envVal('DEEPSEEK_API_URL', DEFAULT_API_URL)
        : envVal('MINIMAX_API_URL', MINIMAX_API_URL_DEFAULT);

    // Key 查找优先级：opts > provider 专属 env > 统一 ai.api_key (DB)
    $apiKey = $opts['api_key'] ?? '';
    if (!$apiKey) {
        $apiKey = $isDeepSeek
            ? envVal('DEEPSEEK_API_KEY', '')
            : envVal('MINIMAX_API_KEY', '');
    }
    if (!$apiKey) {
        try { $db = getDB(); $apiKey = trim(strval(pcGet($db, 'ai.api_key', ''))); } catch (Exception $e) {}
    }
    if (!$apiKey) {
        throw new Exception('AI API Key 未配置：请到管理后台「设置」页面填入 AI API Key');
    }

    $timeout = intval($opts['timeout'] ?? envVal('AI_TIMEOUT', 60));

    $maxTokens   = min(intval($opts['max_tokens'] ?? 150), 1024);
    $temperature = floatval($opts['temperature'] ?? 0.5);
    if ($temperature < 0.01) $temperature = 0.01;
    if ($temperature > 1.0)  $temperature = 1.0;

    $cleanMessages = array();
    foreach ($messages as $msg) {
        $role    = $msg['role'] ?? '';
        $content = is_string($msg['content'] ?? '') ? trim($msg['content']) : '';
        if (!$content || !in_array($role, array('system', 'user', 'assistant'), true)) continue;
        $cleanMessages[] = array('role' => $role, 'content' => $content);
    }
    if (empty($cleanMessages)) throw new Exception('AI请求内容为空');

    $payload = array(
        'model'                 => $model,
        'messages'              => $cleanMessages,
        'temperature'           => $temperature,
        'top_p'                 => 0.95,
        'max_completion_tokens' => $maxTokens,
    );

    // DeepSeek V4 系推理模型支持 thinking 控制（Anthropic 兼容协议）
    //  - {"type":"disabled"} 关闭推理，省 90% completion tokens
    //  - {"type":"enabled","budget_tokens":N} 启用并限制推理预算
    // 普通模型会忽略此字段
    if (isset($opts['thinking']) && is_array($opts['thinking'])) {
        $payload['thinking'] = $opts['thinking'];
    }
    // 或使用 OpenAI 兼容字段 reasoning_effort: low/medium/high/max/xhigh
    if (isset($opts['reasoning_effort']) && is_string($opts['reasoning_effort'])) {
        $payload['reasoning_effort'] = $opts['reasoning_effort'];
    }

    $ch = curl_init($apiUrl);
    if (!$ch) throw new Exception('curl初始化失败');

    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ),
    ));

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errStr = curl_error($ch);
    curl_close($ch);

    if (!$resp || $errNo) {
        throw new Exception('AI请求失败：' . $errStr);
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new Exception('AI返回非JSON：' . substr($resp, 0, 200));
    }

    $choice = isset($json['choices'][0]['message']) ? $json['choices'][0]['message'] : null;
    $textContent = '';
    if (is_array($choice) && isset($choice['content'])) {
        $textContent = is_string($choice['content']) ? $choice['content'] : '';
    }
    $textContent = trim($textContent);
    if (!$textContent && isset($json['reply'])) {
        $textContent = is_string($json['reply']) ? trim($json['reply']) : '';
    }
    if (!$textContent) {
        throw new Exception('AI返回内容为空');
    }

    $textContent = sanitizeReply($textContent);

    $usage = $json['usage'] ?? array();
    return array('content' => $textContent, 'usage' => $usage);
}

/**
 * 清理 AI 回复中的乱码和系统提示词泄漏
 */
function sanitizeReply($text) {
    if (!is_string($text) || $text === '') return $text;

    $original = $text;

    // 1. 去除尾部重复的 ] } 0 等垃圾字符（至少连续3个以上才清理，避免误伤正常内容）
    $text = preg_replace('/[\]\}0]{3,}$/', '', $text);

    // 2. 如果清理后为空，回退
    $text = trim($text);
    if ($text === '') return $original;

    // 3. 去除可能泄漏的【】系统标记段落（保留正文内容）
    //    · 如果整段都是系统标记则去掉
    $text = preg_replace('/^【[^】]*】[\s]*$/', '', $text);

    // 4. 若回复包含不完整/多余的 markdown 代码块标记，清理
    $text = preg_replace('/```[\s]*$/', '', $text);

    // 5. 限制最大长度（500字符）
    if (mb_strlen($text) > 500) {
        $text = mb_substr($text, 0, 500);
    }

    return trim($text);
}

<?php
// api/config.php
// 数据库/密钥/公共函数 + AI 调用

// PHP 7 兼容：模拟 str_starts_with
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

function loadDotEnv($path) {
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
        $_ENV[$key] = $val;
        putenv($key . '=' . $val);
    }
}

function envVal($key, $default = null) {
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;
    return $default;
}

loadDotEnv(dirname(__DIR__) . '/.env');

define('DB_HOST', envVal('DB_HOST', 'localhost'));
define('DB_PORT', envVal('DB_PORT', '3306'));
define('DB_NAME', envVal('DB_NAME', 'aibisskefu_com'));
define('DB_USER', envVal('DB_USER', 'root'));
define('DB_PASS', envVal('DB_PASS', ''));
define('JWT_SECRET', envVal('JWT_SECRET', 'change_this_secret'));

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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
    if (!$auth || !str_starts_with($auth, 'Bearer ')) {
        fail('未登录', 401);
    }
    $token = substr($auth, 7);
    $parts = explode('.', $token);
    if (count($parts) !== 2) fail('Token无效', 401);
    $data = $parts[0];
    $sign = $parts[1];
    if (hash_hmac('sha256', $data, JWT_SECRET) !== $sign) fail('Token签名错误', 401);
    $payload = json_decode(base64_decode($data), true);
    if (!$payload || $payload['exp'] < time()) fail('Token已过期', 401);
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

// ══════════════════════════════════════════
// AI 调用（MiniMax M2-her）
// ══════════════════════════════════════════

define('DEFAULT_MODEL', 'M2-her');
define('DEFAULT_API_URL', 'https://api.minimaxi.com/v1/text/chatcompletion_v2');

function callAI($messages, $opts = []) {
    $apiKey = envVal('MINIMAX_API_KEY', '');
    if (!$apiKey) {
        try { $db = getDB(); $apiKey = trim(strval(pcGet($db, 'ai.api_key', ''))); } catch (Exception $e) {}
    }
    if (!$apiKey) {
        throw new Exception('AI未配置：请在 .env 或 platform_config 配置 MINIMAX_API_KEY');
    }

    $model   = envVal('MINIMAX_MODEL', DEFAULT_MODEL);
    $apiUrl  = envVal('MINIMAX_API_URL', DEFAULT_API_URL);
    $timeout = intval(envVal('MINIMAX_TIMEOUT', 60));

    $maxTokens   = min(intval($opts['max_tokens'] ?? 300), 1024);
    $temperature = floatval($opts['temperature'] ?? 0.8);
    if ($temperature < 0.01) $temperature = 0.01;
    if ($temperature > 1.0) $temperature = 1.0;

    $systemContent = '';
    $dialogParts = array();
    foreach ($messages as $msg) {
        $role = $msg['role'] ?? '';
        $content = is_string($msg['content'] ?? '') ? trim($msg['content']) : '';
        if (!$content) continue;
        if ($role === 'system') {
            $systemContent .= $content . "\n\n";
        } else {
            $dialogParts[] = $content;
        }
    }
    $merged = trim($systemContent);
    if (!empty($dialogParts)) {
        $merged .= ($merged ? "\n\n---\n\n" : '') . implode("\n\n", $dialogParts);
    }
    if (!$merged) throw new Exception('AI请求内容为空');

    $payload = array(
        'model' => $model,
        'messages' => array(array('role' => 'user', 'content' => $merged)),
        'temperature' => $temperature,
        'top_p' => 0.95,
        'max_completion_tokens' => $maxTokens,
    );

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

    $usage = $json['usage'] ?? array();
    return array('content' => $textContent, 'usage' => $usage);
}

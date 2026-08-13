<?php
// api/wecom.php
// 企业微信消息回调适配层
//
// GET  — URL 验证（企业微信后台保存回调地址时触发）
// POST — 接收用户消息，调用 AI 回复

require_once __DIR__ . '/config.php';

$db = getDB();
$corpId = pcGet($db, 'wecom.corpid', '');
$token  = pcGet($db, 'wecom.token', '');
$aesKey = pcGet($db, 'wecom.aes_key', '');

if (!$token || !$aesKey || !$corpId) {
    http_response_code(500);
    echo 'WeCom not configured';
    exit;
}

function wecom_log(string $msg): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($logDir . '/wecom.log', $line, FILE_APPEND);
}

// ── URL 验证 ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');

    $signature = $_GET['msg_signature'] ?? '';
    $timestamp = $_GET['timestamp'] ?? '';
    $nonce     = $_GET['nonce'] ?? '';
    $echostr   = $_GET['echostr'] ?? '';

    wecom_log("GET verify: sig=$signature ts=$timestamp nonce=$nonce echostr_len=" . strlen($echostr));

    if (!$signature || !$timestamp || !$nonce || !$echostr) {
        wecom_log('→ 400 Invalid params');
        http_response_code(400);
        exit;
    }

    if (sha1Sort($token, $timestamp, $nonce, $echostr) !== $signature) {
        wecom_log('→ 403 Signature mismatch (my_sig=' . sha1Sort($token, $timestamp, $nonce, $echostr) . ')');
        http_response_code(403);
        exit('Signature mismatch');
    }

    $plaintext = decrypt($echostr, $aesKey);
    if ($plaintext === null) {
        wecom_log('→ 500 Decrypt failed');
        http_response_code(500);
        exit('Decrypt failed');
    }

    $msg = extractMsg($plaintext, $corpId);
    if ($msg === null) {
        wecom_log('→ 403 CorpID mismatch');
        http_response_code(403);
        exit('CorpID mismatch');
    }

    wecom_log("→ 200 OK, reply=" . substr($msg, 0, 50));
    echo $msg;
    exit;
}

// ── 接收消息 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $timestamp = $_GET['timestamp'] ?? '';
    $nonce     = $_GET['nonce'] ?? '';
    $signature = $_GET['msg_signature'] ?? '';

    $input = file_get_contents('php://input');
    $xml = @simplexml_load_string($input);
    if ($xml === false || !isset($xml->Encrypt)) {
        http_response_code(400);
        exit('Invalid XML');
    }

    $encrypt = (string)$xml->Encrypt;

    if (sha1Sort($token, $timestamp, $nonce, $encrypt) !== $signature) {
        http_response_code(403);
        exit('Signature mismatch');
    }

    $plaintext = decrypt($encrypt, $aesKey);
    if ($plaintext === null) {
        echo encryptSuccess($token, $aesKey, $corpId);
        exit;
    }

    $msgXml = extractMsg($plaintext, $corpId);
    if ($msgXml === null) {
        echo encryptSuccess($token, $aesKey, $corpId);
        exit;
    }

    $msg = simplexml_load_string($msgXml);
    if ($msg === false) {
        echo encryptSuccess($token, $aesKey, $corpId);
        exit;
    }

    $fromUser = (string)$msg->FromUserName;
    $toUser   = (string)$msg->ToUserName;
    $content  = trim((string)$msg->Content);
    $msgType  = (string)$msg->MsgType;

    if ($msgType !== 'text' || $content === '') {
        echo encryptSuccess($token, $aesKey, $corpId);
        exit;
    }

    $reply = callOpenAPI($content, $fromUser, $db);
    if ($reply === null) {
        echo encryptSuccess($token, $aesKey, $corpId);
        exit;
    }

    $reply = mb_substr($reply, 0, 600);

    $respXml = buildReplyXml($fromUser, $toUser, $reply);
    $encrypted = encryptReply($respXml, $aesKey, $corpId);
    if ($encrypted === null) {
        echo encryptSuccess($token, $aesKey, $corpId);
        exit;
    }

    $ts = (string)time();
    $nonce2 = bin2hex(random_bytes(8));
    $sig = sha1Sort($token, $ts, $nonce2, $encrypted);

    header('Content-Type: application/xml; charset=utf-8');
    echo '<xml>';
    echo '<Encrypt><![CDATA[' . $encrypted . ']]></Encrypt>';
    echo '<MsgSignature><![CDATA[' . $sig . ']]></MsgSignature>';
    echo '<TimeStamp>' . $ts . '</TimeStamp>';
    echo '<Nonce><![CDATA[' . $nonce2 . ']]></Nonce>';
    echo '</xml>';
    exit;
}

http_response_code(405);
exit('Method not allowed');

// ══════════════════════════════════════════
//  加密/解密（企业微信 AES-256-CBC）
// ══════════════════════════════════════════

function decrypt(string $encryptedBase64, string $aesKey): ?string {
    $key = base64_decode($aesKey . '=');
    $iv = substr($key, 0, 16);
    $encrypted = base64_decode($encryptedBase64);
    if ($encrypted === false) return null;
    $decrypted = @openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($decrypted === false) return null;
    return pkcs7Unpad($decrypted);
}

function pkcs7Unpad(string $text): string {
    $pad = ord(substr($text, -1));
    if ($pad < 1 || $pad > 32) $pad = 0;
    $len = strlen($text);
    if ($pad >= $len) return $text;
    return substr($text, 0, $len - $pad);
}

function encryptReply(string $plaintext, string $aesKey, string $corpId): ?string {
    $key = base64_decode($aesKey . '=');
    $iv = substr($key, 0, 16);
    $random = random_bytes(16);
    $msgLen = pack('N', strlen($plaintext));
    $data = $random . $msgLen . $plaintext . $corpId;
    $padded = pkcs7Pad($data);
    $encrypted = @openssl_encrypt($padded, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    return $encrypted === false ? null : base64_encode($encrypted);
}

function pkcs7Pad(string $data): string {
    $blockSize = 32;
    $pad = $blockSize - (strlen($data) % $blockSize);
    return $data . str_repeat(chr($pad), $pad);
}

function extractMsg(string $plaintext, string $corpId): ?string {
    $content = substr($plaintext, 16);
    if (strlen($content) < 4) return null;
    $len = unpack('N', substr($content, 0, 4))[1];
    $msg = substr($content, 4, $len);
    $actualCorpId = substr($content, 4 + $len);
    if ($actualCorpId !== $corpId) return null;
    return $msg;
}

// ══════════════════════════════════════════
//  签名
// ══════════════════════════════════════════

function sha1Sort(...$parts): string {
    sort($parts, SORT_STRING);
    return sha1(implode($parts));
}

// ══════════════════════════════════════════
//  消息构建
// ══════════════════════════════════════════

function buildReplyXml(string $from, string $to, string $content): string {
    return '<xml>' .
        '<ToUserName><![CDATA[' . $from . ']]></ToUserName>' .
        '<FromUserName><![CDATA[' . $to . ']]></FromUserName>' .
        '<CreateTime>' . time() . '</CreateTime>' .
        '<MsgType><![CDATA[text]]></MsgType>' .
        '<Content><![CDATA[' . $content . ']]></Content>' .
        '</xml>';
}

function encryptSuccess(string $token, string $aesKey, string $corpId): string {
    $encrypted = encryptReply('success', $aesKey, $corpId);
    if ($encrypted === null) return 'success';
    $ts = (string)time();
    $nonce = bin2hex(random_bytes(8));
    $sig = sha1Sort($token, $ts, $nonce, $encrypted);
    return '<xml>' .
        '<Encrypt><![CDATA[' . $encrypted . ']]></Encrypt>' .
        '<MsgSignature><![CDATA[' . $sig . ']]></MsgSignature>' .
        '<TimeStamp>' . $ts . '</TimeStamp>' .
        '<Nonce><![CDATA[' . $nonce . ']]></Nonce>' .
        '</xml>';
}

// ══════════════════════════════════════════
//  调用内部 openapi
// ══════════════════════════════════════════

function callOpenAPI(string $message, string $fromUser, PDO $db): ?string {
    $stmt = $db->query('SELECT api_key FROM api_keys WHERE enabled = 1 LIMIT 1');
    $keyRow = $stmt->fetch();
    if (!$keyRow) return null;

    $sessionId = 'wecom_' . $fromUser;

    $ch = curl_init('http://localhost:8888/aibisskefu/api/openapi.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'session_id' => $sessionId,
            'message' => $message,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . $keyRow['api_key'],
        ],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return null;

    $result = json_decode($resp, true);
    if (!isset($result['code']) || intval($result['code']) !== 0) return null;

    return $result['data']['reply'] ?? null;
}

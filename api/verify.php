<?php
// api/verify.php
// 验证网关 - SMS验证码发送/校验 + 企业订单API调用
//
// POST /api/verify.php?action=send_code   发送验证码
// POST /api/verify.php?action=verify_code  校验验证码 + 查订单
// POST /api/verify.php?action=query_order  已验证后查订单

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$body   = getBody();
$db     = getDB();

// ══════════════════════════════════════════
// 发送验证码
// ══════════════════════════════════════════
if ($action === 'send_code') {
    $phone    = trim($body['phone'] ?? '');
    $orderNo  = trim($body['order_no'] ?? '');
    $sessionId = trim($body['session_id'] ?? '');

    if (!$phone || !preg_match('/^1[3-9]\d{9}$/', $phone)) {
        fail('请输入正确的手机号');
    }
    if (!$orderNo) fail('请输入订单号');
    if (!$sessionId) fail('session_id不能为空');

    $phoneHash = hash('sha256', $phone);
    $phoneMask = substr($phone, 0, 3) . '****' . substr($phone, -4);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // 频率限制
    $stmt = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE source_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    $stmt->execute([$ip]);
    if (intval($stmt->fetchColumn() ?: 0) >= 3) {
        fail('发送过于频繁，请1分钟后再试', 429);
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE phone_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $stmt->execute([$phoneHash]);
    if (intval($stmt->fetchColumn() ?: 0) >= 5) {
        fail('该手机号今天已达上限，请明天再试', 429);
    }

    // 生成验证码
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = hash('sha256', $otp);

    // 存入数据库
    $stmt = $db->prepare('INSERT INTO sms_verify_logs (phone_hash, phone_mask, otp_hash, session_id, source_ip, status, expires_at) VALUES (?, ?, ?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
    $stmt->execute([$phoneHash, $phoneMask, $otpHash, $sessionId, $ip]);

    // TODO: MVP 阶段先 mock 不真发短信，在日志中输出验证码用于测试
    // 正式上线时改为调用阿里云短信 API
    error_log("[SMS MOCK] 验证码发送: {$phoneMask} → {$otp}");

    ok([
        'phone_mask' => $phoneMask,
        'expires_in' => 300,
    ], '验证码已发送（测试环境，请查看服务器日志）');
}

// ══════════════════════════════════════════
// 校验验证码
// ══════════════════════════════════════════
if ($action === 'verify_code') {
    $phone    = trim($body['phone'] ?? '');
    $otp      = trim($body['otp'] ?? '');
    $orderNo  = trim($body['order_no'] ?? '');
    $sessionId = trim($body['session_id'] ?? '');

    if (!$phone || !$otp) fail('参数不完整');
    if (!$sessionId) fail('session_id不能为空');

    $phoneHash = hash('sha256', $phone);

    // 查找最近的未使用验证码
    $stmt = $db->prepare('
        SELECT id, otp_hash, status, expires_at 
        FROM sms_verify_logs 
        WHERE phone_hash = ? AND session_id = ? AND status = 0
        ORDER BY created_at DESC LIMIT 1
    ');
    $stmt->execute([$phoneHash, $sessionId]);
    $record = $stmt->fetch();

    if (!$record) fail('请先获取验证码');

    if (strtotime($record['expires_at']) < time()) {
        $db->prepare('UPDATE sms_verify_logs SET status = 2 WHERE id = ?')->execute([$record['id']]);
        fail('验证码已过期，请重新获取');
    }

    if (hash('sha256', $otp) !== $record['otp_hash']) {
        fail('验证码错误');
    }

    // 标记已验证
    $db->prepare('UPDATE sms_verify_logs SET status = 1, verified_at = NOW() WHERE id = ?')->execute([$record['id']]);

    // 调用企业订单 API 查询
    // 从 platform_config 获取企业配置的订单查询接口地址
    $orderApiUrl = pcGet($db, 'order.api_url', '');
    $orderApiKey = pcGet($db, 'order.api_key', '');

    $orderData = null;

    if ($orderApiUrl && $orderApiKey) {
        try {
            $ch = curl_init($orderApiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'order_no' => $orderNo,
                    'phone' => $phone,
                    'timestamp' => time(),
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $orderApiKey,
                ],
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp && $httpCode === 200) {
                $result = json_decode($resp, true);
                if (isset($result['code']) && intval($result['code']) === 0 && isset($result['data'])) {
                    $orderData = $result['data'];
                }
            }
        } catch (Throwable $e) {
            error_log('订单API调用失败: ' . $e->getMessage());
        }
    }

    ok([
        'verified'   => true,
        'order_data' => $orderData,
    ], '验证通过');
}

// ══════════════════════════════════════════
// 配置订单查询接口（管理后台用）
// ══════════════════════════════════════════
if ($action === 'save_order_api') {
    adminGuard();

    $url = trim($body['api_url'] ?? '');
    $key = trim($body['api_key'] ?? '');

    if (!$url || !$key) fail('参数不完整');

    // 使用 INSERT ... ON DUPLICATE KEY UPDATE
    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['order.api_url', $url]);
    $stmt->execute(['order.api_key', $key]);

    ok([], '保存成功');
}

if ($action === 'get_order_api') {
    adminGuard();
    ok([
        'api_url' => pcGet($db, 'order.api_url', ''),
        'api_key' => pcGet($db, 'order.api_key', '') ? '已配置' : '',
    ]);
}

fail('未知操作');

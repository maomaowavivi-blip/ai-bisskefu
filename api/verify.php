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
    fail('短信验证暂未开放', 503);
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

    ok([
        'phone_mask' => $phoneMask,
        'expires_in' => 300,
    ], '验证码已发送（测试环境，请查看服务器日志）');
}

// ══════════════════════════════════════════
// 校验验证码
// ══════════════════════════════════════════
if ($action === 'verify_code') {
    fail('短信验证暂未开放', 503);
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

    // v3.26：旧「企业订单 API」已随数据中台迁移下线，不再在此调用。
    // 订单查询统一走 CenterApi::orderByChannelOrderId()（api/CenterApi.php）。

    ok([
        'verified'   => true,
        'order_data' => null,
    ], '验证通过');
}

// ══════════════════════════════════════════
// 数据中台配置（管理后台用，v3.26 取代旧「统一网关」）
//
// 存 platform_config:
//   center.api_url    中台基址，如 https://center.youguang8488.com/api/relay
//   center.username   账号
//   center.password   密码（只存 DB，不进源码/.env/commit）
//   center.tenant_id  租户 ID
// ══════════════════════════════════════════
if ($action === 'save_center_config') {
    adminGuard();

    $url      = trim($body['api_url'] ?? '');
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');
    $tenantId = trim($body['tenant_id'] ?? '');

    if (!$url || !$username) fail('参数不完整');
    if ($tenantId === '') $tenantId = '1';

    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['center.api_url', rtrim($url, '/')]);
    $stmt->execute(['center.username', $username]);
    $stmt->execute(['center.tenant_id', $tenantId]);
    // 密码留空 = 不改动已存密码（后台每次保存不覆盖真实密码）
    if ($password !== '') {
        $stmt->execute(['center.password', $password]);
    }

    ok([], '数据中台配置已保存');
}

if ($action === 'get_center_config') {
    adminGuard();
    ok([
        'api_url'   => pcGet($db, 'center.api_url', ''),
        'username'  => pcGet($db, 'center.username', ''),
        'password'  => pcGet($db, 'center.password', '') ? '已配置' : '',
        'tenant_id' => pcGet($db, 'center.tenant_id', '1'),
    ]);
}

if ($action === 'clear_center_config') {
    adminGuard();
    $stmt = $db->prepare('DELETE FROM platform_config WHERE `key` IN (?, ?, ?, ?)');
    $stmt->execute(['center.api_url', 'center.username', 'center.password', 'center.tenant_id']);
    ok([], '数据中台配置已清除');
}

// ══════════════════════════════════════════
// 房间查询触发关键词（管理后台用）
//
// 键名保持 gateway.room_keywords 不变 —— SidecarIntent::getEntryKeywords()
// 实时读它做房间意图扩展，改键名会导致后台配置静默失效。
// ══════════════════════════════════════════
if ($action === 'save_room_keywords') {
    adminGuard();

    // 请求体畸形时 getBody() 返回 []，若直接 ?? '' 会把关键词静默清空。
    // 这里用 array_key_exists 区分「参数缺失」与「显式清空」：缺失则拒绝，显式空串则允许
    //（空串 = 只用代码内置的基础词表）。
    if (!array_key_exists('room_keywords', $body)) {
        fail('参数不完整');
    }

    $keywords = trim((string)$body['room_keywords']);
    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['gateway.room_keywords', $keywords]);

    ok([], '房间查询关键词已保存');
}

if ($action === 'get_room_keywords') {
    adminGuard();
    ok(['room_keywords' => pcGet($db, 'gateway.room_keywords', '')]);
}

// ══════════════════════════════════════════
// AI API Key 配置（管理后台用）
// ══════════════════════════════════════════
if ($action === 'save_ai_api') {
    adminGuard();

    $apiKey = trim($body['api_key'] ?? '');

    if (!$apiKey) fail('API Key 不能为空');

    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['ai.api_key', $apiKey]);

    ok([], 'AI API Key 保存成功');
}

if ($action === 'get_ai_api') {
    adminGuard();
    $saved = pcGet($db, 'ai.api_key', '');
    $temp  = pcGet($db, 'ai.temperature', '0.5');
    ok([
        'api_key' => $saved ? '已配置' : '',
        'has_key' => $saved ? true : false,
        'temperature' => floatval($temp),
    ]);
}

// ══════════════════════════════════════════
// AI 温度调节
// ══════════════════════════════════════════
if ($action === 'save_temperature') {
    adminGuard();
    $temperature = floatval($body['temperature'] ?? 0.5);
    if ($temperature < 0.01) $temperature = 0.01;
    if ($temperature > 1.0)  $temperature = 1.0;
    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['ai.temperature', (string)$temperature]);
    ok([], 'AI 温度已保存');
}
if ($action === 'get_temperature') {
    adminGuard();
    $temp = pcGet($db, 'ai.temperature', '0.5');
    ok(['temperature' => floatval($temp)]);
}

// ══════════════════════════════════════════
// 企业微信对接配置（管理后台用）
// ══════════════════════════════════════════
if ($action === 'save_wecom_config') {
    adminGuard();

    $corpId = trim($body['corpid'] ?? '');
    $token  = trim($body['token'] ?? '');
    $aesKey = trim($body['aes_key'] ?? '');

    if (!$corpId || !$token || !$aesKey) fail('参数不完整');

    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['wecom.corpid', $corpId]);
    $stmt->execute(['wecom.token', $token]);
    $stmt->execute(['wecom.aes_key', $aesKey]);

    ok([], '企业微信配置保存成功');
}

if ($action === 'get_wecom_config') {
    adminGuard();
    ok([
        'corpid'  => pcGet($db, 'wecom.corpid', ''),
        'token'   => pcGet($db, 'wecom.token', ''),
        'aes_key' => pcGet($db, 'wecom.aes_key', ''),
    ]);
}

// ══════════════════════════════════════════
// 微信客服 API 配置（v3.4，用户扫客服二维码）
// ══════════════════════════════════════════
if ($action === 'save_wecom_kf_config') {
    adminGuard();

    $corpSecret = trim($body['corp_secret'] ?? '');
    $openKfId   = trim($body['open_kfid'] ?? '');

    if (!$corpSecret || !$openKfId) fail('参数不完整');

    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['wecom.corp_secret', $corpSecret]);
    $stmt->execute(['wecom.kf_open_kfid', $openKfId]);

    ok([], '微信客服配置保存成功');
}

if ($action === 'get_wecom_kf_config') {
    adminGuard();
    ok([
        'corp_secret' => pcGet($db, 'wecom.corp_secret', ''),
        'open_kfid'   => pcGet($db, 'wecom.kf_open_kfid', ''),
        'configured'  => !empty(pcGet($db, 'wecom.corp_secret', '')) && !empty(pcGet($db, 'wecom.kf_open_kfid', '')),
    ]);
}

// ══════════════════════════════════════════
// API 密钥管理（管理后台用）
// ══════════════════════════════════════════
if ($action === 'create_api_key') {
    adminGuard();

    $name = trim($body['name'] ?? '未命名');
    if (mb_strlen($name) > 100) fail('名称过长');

    $key = 'neb_' . bin2hex(random_bytes(24));

    $stmt = $db->prepare('INSERT INTO api_keys (name, api_key, enabled) VALUES (?, ?, 1)');
    $stmt->execute([$name, $key]);

    ok(['id' => intval($db->lastInsertId()), 'api_key' => $key]);
}

if ($action === 'list_api_keys') {
    adminGuard();

    $stmt = $db->query('SELECT id, name, api_key, enabled, last_used_at, created_at FROM api_keys ORDER BY created_at DESC');
    $list = $stmt->fetchAll();

    ok(['list' => $list]);
}

if ($action === 'toggle_api_key') {
    adminGuard();

    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');

    $stmt = $db->prepare('UPDATE api_keys SET enabled = 1 - enabled WHERE id = ?');
    $stmt->execute([$id]);

    ok([], '操作成功');
}

if ($action === 'delete_api_key') {
    adminGuard();

    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');

    $stmt = $db->prepare('DELETE FROM api_keys WHERE id = ?');
    $stmt->execute([$id]);

    ok([], '已删除');
}

fail('未知操作');

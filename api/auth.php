<?php
// api/auth.php
// POST /api/auth.php?action=login  管理员登录
// GET  /api/auth.php?action=me     当前管理员信息

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$body   = getBody();

if ($action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username) fail('请输入用户名');
    if (!$password) fail('请输入密码');
    if (strlen($username) > 64 || strlen($password) > 256) fail('用户名或密码格式错误');

    $db = getDB();
    $loginRateKey = 'login_' . substr(hash('sha256', requestClientIp() . '|' . strtolower($username)), 0, 58);
    $rateStmt = $db->prepare('SELECT `count`, window_start, window_start > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AS in_window FROM rate_limits WHERE key_str = ? LIMIT 1');
    $rateStmt->execute([$loginRateKey]);
    $rateRow = $rateStmt->fetch();
    if ($rateRow && intval($rateRow['in_window']) === 1 && intval($rateRow['count']) >= 5) {
        fail('登录尝试过于频繁，请15分钟后再试', 429);
    }
    $recordFailedLogin = static function () use ($db, $loginRateKey): void {
        $stmt = $db->prepare(
            'INSERT INTO rate_limits (key_str, `count`, window_start) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE `count` = IF(window_start > DATE_SUB(NOW(), INTERVAL 15 MINUTE), `count` + 1, 1), window_start = IF(window_start > DATE_SUB(NOW(), INTERVAL 15 MINUTE), window_start, NOW())'
        );
        $stmt->execute([$loginRateKey]);
    };
    $stmt = $db->prepare('SELECT id, username, password, role, status FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        $recordFailedLogin();
        fail('用户名或密码错误');
    }
    if (!password_verify($password, $user['password'])) {
        $recordFailedLogin();
        fail('用户名或密码错误');
    }
    if (intval($user['status'] ?? 1) === 0) {
        $recordFailedLogin();
        fail('账号已被封禁');
    }

    $db->prepare('DELETE FROM rate_limits WHERE key_str = ?')->execute([$loginRateKey]);

    $token = makeToken($user['id'], $user['role']);

    ok([
        'token'    => $token,
        'user_id'  => $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
    ], '登录成功');
}

if ($action === 'me') {
    $auth = authToken();
    $db = getDB();
    $stmt = $db->prepare('SELECT id, username, role, status, created_at FROM users WHERE id = ?');
    $stmt->execute([$auth['uid']]);
    $user = $stmt->fetch();
    if (!$user) fail('用户不存在', 404);
    ok($user);
}

// ══════════════════════════════════════════
// 管理员用户管理（以下均需 adminGuard）
// ══════════════════════════════════════════

if ($action === 'list_users') {
    adminGuard();
    $db = getDB();
    $stmt = $db->query('SELECT id, username, role, status, created_at FROM users ORDER BY id ASC');
    $list = $stmt->fetchAll();
    ok(['list' => $list]);
}

if ($action === 'create_user') {
    adminGuard();
    $body = getBody();
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        fail('用户名需3-20位字母数字或下划线');
    }
    if (strlen($password) < 6) {
        fail('密码至少6位');
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) fail('用户名已存在');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password, role, status) VALUES (?, ?, 3, 1)');
    $stmt->execute([$username, $hash]);
    ok(['id' => intval($db->lastInsertId())], '创建成功');
}

if ($action === 'update_user') {
    adminGuard();
    $body = getBody();
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');

    $auth = authToken();
    $currentUid = intval($auth['uid']);

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) fail('用户不存在');

    // 更新密码
    if (!empty($body['new_password'])) {
        $newPwd = $body['new_password'];
        if (strlen($newPwd) < 6) fail('密码至少6位');
        $hash = password_hash($newPwd, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $id]);
        ok([], '密码已重置');
    }

    // 更新状态
    if (isset($body['status'])) {
        if ($id === $currentUid) fail('不能操作自己的状态');
        $status = intval($body['status']) ? 1 : 0;
        $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);
        ok([], $status ? '账号已启用' : '账号已禁用');
    }

    fail('参数错误');
}

if ($action === 'delete_user') {
    adminGuard();
    $body = getBody();
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');

    $auth = authToken();
    if ($id === intval($auth['uid'])) fail('不能删除自己的账号');

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) fail('用户不存在');

    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    ok([], '已删除');
}

fail('未知操作');

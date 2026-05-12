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

    $db = getDB();
    $stmt = $db->prepare('SELECT id, username, password, role, status FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) fail('用户名或密码错误');
    if (!password_verify($password, $user['password'])) fail('用户名或密码错误');
    if (intval($user['status'] ?? 1) === 0) fail('账号已被封禁');

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
    $stmt = $db->prepare('SELECT id, username, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$auth['uid']]);
    $user = $stmt->fetch();
    if (!$user) fail('用户不存在', 404);
    ok($user);
}

fail('未知操作');

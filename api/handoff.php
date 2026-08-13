<?php
// api/handoff.php
// 人工接管接口
//
// GET    /api/handoff.php?action=status&session_id=xxx      客户轮询（无认证）
// POST   /api/handoff.php?action=pending_list               管理员：待处理列表
// POST   /api/handoff.php?action=take_over                  管理员：接管
// POST   /api/handoff.php?action=send_message               管理员：发送消息
// POST   /api/handoff.php?action=release                     管理员：放弃接管（退回待处理）
// POST   /api/handoff.php?action=end                        管理员/系统：结束

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/HandoffTriggers.php';

$action = $_GET['action'] ?? '';
$body   = getBody();
$db     = getDB();

// ══════════════════════════════════════════
// 客户轮询 - 查询当前接管状态（无需认证）
// ══════════════════════════════════════════
if ($action === 'status') {
    $sessionId = trim($_GET['session_id'] ?? $body['session_id'] ?? '');
    if (!$sessionId) fail('session_id不能为空');

    $stmt = $db->prepare("SELECT id, status, taken_at FROM human_handoffs WHERE session_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sessionId]);
    $handoff = $stmt->fetch();

    if (!$handoff) {
        ok(['status' => -1, 'messages' => [], 'handoff_id' => 0]);
    }

    $handoffId = intval($handoff['id']);
    $status = intval($handoff['status']);

    // 获取消息
    $msgStmt = $db->prepare("SELECT id, role, content, created_at FROM handoff_messages WHERE handoff_id = ? ORDER BY created_at ASC");
    $msgStmt->execute([$handoffId]);
    $messages = $msgStmt->fetchAll();

    ok([
        'handoff_id' => $handoffId,
        'status' => $status,
        'taken_at' => $handoff['taken_at'],
        'messages' => $messages,
    ]);
}

// ══════════════════════════════════════════
// 管理员 - 待处理列表
// ══════════════════════════════════════════
if ($action === 'pending_list') {
    adminGuard();

    $type = $body['type'] ?? 'pending';

    if ($type === 'pending') {
        // 自动过期超过30分钟未接管的请求
        $db->exec("UPDATE human_handoffs SET status = 2, ended_at = NOW() WHERE status = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");

        $stmt = $db->query("
            SELECT h.*, 
                   (SELECT content FROM chat_logs WHERE session_id = h.session_id ORDER BY created_at DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM chat_logs WHERE session_id = h.session_id ORDER BY created_at DESC LIMIT 1) AS last_time
            FROM human_handoffs h 
            WHERE h.status = 0 
            ORDER BY h.created_at DESC 
            LIMIT 50
        ");
    } elseif ($type === 'active') {
        $adminId = intval(authToken()['uid'] ?? 0);
        $stmt = $db->prepare("
            SELECT h.*, 
                   (SELECT content FROM handoff_messages WHERE handoff_id = h.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM handoff_messages WHERE handoff_id = h.id ORDER BY created_at DESC LIMIT 1) AS last_time
            FROM human_handoffs h 
            WHERE h.status = 1 AND h.taken_by = ?
            ORDER BY h.taken_at DESC 
            LIMIT 50
        ");
        $stmt->execute([$adminId]);
    } else {
        $stmt = $db->query("
            SELECT h.*, 
                   (SELECT content FROM handoff_messages WHERE handoff_id = h.id ORDER BY created_at DESC LIMIT 1) AS last_message
            FROM human_handoffs h 
            WHERE h.status = 2 
            ORDER BY h.ended_at DESC 
            LIMIT 50
        ");
    }

    $list = $stmt->fetchAll();
    ok(['list' => $list, 'type' => $type]);
}

// ══════════════════════════════════════════
// 管理员 - 接管
// ══════════════════════════════════════════
if ($action === 'take_over') {
    $auth = adminGuard();
    $adminId = intval($auth['uid'] ?? 0);
    $handoffId = intval($body['handoff_id'] ?? 0);

    if (!$handoffId) fail('参数错误');

    $stmt = $db->prepare("SELECT id, status, session_id FROM human_handoffs WHERE id = ?");
    $stmt->execute([$handoffId]);
    $handoff = $stmt->fetch();
    if (!$handoff) fail('记录不存在');

    if (intval($handoff['status']) !== 0) fail('该请求已被其他管理员处理');

    $stmt = $db->prepare("UPDATE human_handoffs SET status = 1, taken_by = ?, taken_at = NOW() WHERE id = ? AND status = 0");
    $stmt->execute([$adminId, $handoffId]);

    if ($stmt->rowCount() === 0) fail('接管失败，请刷新后重试');

    // 插入系统消息
    $stmt = $db->prepare("INSERT INTO handoff_messages (handoff_id, role, content) VALUES (?, 'system', '您好，专属客服已为您服务，有什么可以帮您的吗？')");
    $stmt->execute([$handoffId]);

    ok(['handoff_id' => $handoffId, 'session_id' => $handoff['session_id']], '已接管该会话');
}

// ══════════════════════════════════════════
// 管理员 - 发送消息
// ══════════════════════════════════════════
if ($action === 'send_message') {
    $auth = adminGuard();
    $handoffId = intval($body['handoff_id'] ?? 0);
    $content = trim($body['content'] ?? '');

    if (!$handoffId || !$content) fail('参数错误');

    $stmt = $db->prepare("SELECT id, status, session_id FROM human_handoffs WHERE id = ?");
    $stmt->execute([$handoffId]);
    $handoff = $stmt->fetch();
    if (!$handoff || intval($handoff['status']) !== 1) fail('会话未处于接管状态');

    $stmt = $db->prepare("INSERT INTO handoff_messages (handoff_id, role, content) VALUES (?, 'admin', ?)");
    $stmt->execute([$handoffId, $content]);

    ok([], '发送成功');
}

// ══════════════════════════════════════════
// 结束接管
// ══════════════════════════════════════════
if ($action === 'end') {
    $adminId = 0;
    try {
        $auth = adminGuard();
        $adminId = intval($auth['uid'] ?? 0);
    } catch (Throwable $e) {}

    $handoffId = intval($body['handoff_id'] ?? $_GET['handoff_id'] ?? 0);
    $sessionId = trim($body['session_id'] ?? $_GET['session_id'] ?? '');

    if (!$handoffId && !$sessionId) fail('参数错误');

    if ($handoffId) {
        $stmt = $db->prepare("UPDATE human_handoffs SET status = 2, ended_at = NOW() WHERE id = ? AND status = 1");
        $stmt->execute([$handoffId]);
    } elseif ($sessionId) {
        $stmt = $db->prepare("UPDATE human_handoffs SET status = 2, ended_at = NOW() WHERE session_id = ? AND status = 1");
        $stmt->execute([$sessionId]);
    }

    if ($handoffId) {
        $stmt = $db->prepare("INSERT INTO handoff_messages (handoff_id, role, content) VALUES (?, 'system', '已回到智能客服接待')");
        $stmt->execute([$handoffId]);
    }

    ok([], '已结束接管');
}

// ══════════════════════════════════════════
// 管理员 - 放弃接管（退回待处理队列）
// ══════════════════════════════════════════
if ($action === 'release') {
    $auth = adminGuard();
    $adminId = intval($auth['uid'] ?? 0);
    $handoffId = intval($body['handoff_id'] ?? 0);

    if (!$handoffId) fail('参数错误');

    $stmt = $db->prepare("SELECT id, status, taken_by, session_id FROM human_handoffs WHERE id = ?");
    $stmt->execute([$handoffId]);
    $handoff = $stmt->fetch();
    if (!$handoff) fail('记录不存在');

    if (intval($handoff['status']) !== 1) fail('该会话未处于接管状态');
    if (intval($handoff['taken_by']) !== $adminId) fail('你不是该会话的接管人');

    $stmt = $db->prepare("UPDATE human_handoffs SET status = 0, taken_by = NULL, taken_at = NULL WHERE id = ? AND status = 1 AND taken_by = ?");
    $stmt->execute([$handoffId, $adminId]);

    if ($stmt->rowCount() === 0) fail('放弃接管失败，请刷新后重试');

    // 插入系统消息
    $stmt = $db->prepare("INSERT INTO handoff_messages (handoff_id, role, content) VALUES (?, 'system', '客服暂时离开，继续由智能客服为您服务')");
    $stmt->execute([$handoffId]);

    ok(['handoff_id' => $handoffId], '已放弃接管');
}

// ══════════════════════════════════════════
// 管理员 - 会话消息详情
// ══════════════════════════════════════════
if ($action === 'messages') {
    adminGuard();

    $handoffId = intval($body['handoff_id'] ?? $_GET['handoff_id'] ?? 0);
    $sessionId = trim($body['session_id'] ?? '');

    if ($handoffId) {
        $stmt = $db->prepare("SELECT id, session_id FROM human_handoffs WHERE id = ?");
        $stmt->execute([$handoffId]);
        $handoff = $stmt->fetch();
        if (!$handoff) fail('记录不存在');
        $sessionId = $handoff['session_id'];
    } elseif (!$sessionId) {
        fail('参数错误');
    }

    // 获取客户历史对话
    $stmt = $db->prepare("SELECT id, role, content, created_at FROM chat_logs WHERE session_id = ? ORDER BY created_at ASC LIMIT 100");
    $stmt->execute([$sessionId]);
    $chatLogs = $stmt->fetchAll();

    // 获取接管对话
    $handoffMessages = [];
    if ($handoffId) {
        $stmt = $db->prepare("SELECT id, role, content, created_at FROM handoff_messages WHERE handoff_id = ? ORDER BY created_at ASC");
        $stmt->execute([$handoffId]);
        $handoffMessages = $stmt->fetchAll();
    }

    ok([
        'session_id' => $sessionId,
        'chat_logs' => $chatLogs,
        'handoff_messages' => $handoffMessages,
    ]);
}

// ══════════════════════════════════════════
// 获取待处理数量（管理员导航badge）
// ══════════════════════════════════════════
if ($action === 'pending_count') {
    adminGuard();

    $stmt = $db->query("SELECT COUNT(*) FROM human_handoffs WHERE status = 0");
    $count = intval($stmt->fetchColumn());

    ok(['count' => $count]);
}

// ══════════════════════════════════════════
// 管理员 - 获取转人工触发词列表
// ══════════════════════════════════════════
if ($action === 'get_triggers') {
    adminGuard();

    HandoffTriggers::ensureSeeded($db);

    $stmt = $db->query("SELECT id, keyword, priority FROM handoff_triggers ORDER BY priority ASC, id ASC");
    $list = $stmt->fetchAll();

    // 按优先级分组
    $grouped = [];
    foreach ($list as $row) {
        $p = intval($row['priority']);
        if (!isset($grouped[$p])) $grouped[$p] = [];
        $grouped[$p][] = ['id' => intval($row['id']), 'keyword' => $row['keyword']];
    }

    ok(['list' => $list, 'grouped' => $grouped, 'meta' => HandoffTriggers::priorityMeta()]);
}

// ══════════════════════════════════════════
// 管理员 - 补全/对齐系统默认词库
// ══════════════════════════════════════════
if ($action === 'sync_defaults') {
    adminGuard();

    try {
        $stats = HandoffTriggers::syncDefaultLibrary($db);
        ok($stats, '系统默认词库已同步');
    } catch (Exception $e) {
        fail('同步失败：' . $e->getMessage());
    }
}

// ══════════════════════════════════════════
// 管理员 - 保存转人工触发词（全量替换）
// ══════════════════════════════════════════
if ($action === 'save_triggers') {
    adminGuard();

    $keywords = $body['keywords'] ?? [];
    if (!is_array($keywords)) fail('参数格式错误');

    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM handoff_triggers");

        $stmt = $db->prepare("INSERT INTO handoff_triggers (keyword, priority) VALUES (?, ?)");
        foreach ($keywords as $item) {
            $kw = trim($item['keyword'] ?? '');
            $priority = intval($item['priority'] ?? 4);
            if ($kw === '') continue;
            $stmt->execute([$kw, $priority]);
        }

        $db->commit();
        HandoffTriggers::clearCache();
        ok(['count' => count($keywords)], '保存成功');
    } catch (Exception $e) {
        $db->rollBack();
        fail('保存失败：' . $e->getMessage());
    }
}

fail('未知操作');

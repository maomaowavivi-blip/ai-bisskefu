<?php
// api/chat.php
// 客服聊天接口
// POST /api/chat.php?action=chat  发送消息 + AI 回复

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PromptEngine.php';

$action = $_GET['action'] ?? '';
header('Content-Type: application/json; charset=utf-8');

function chatResponse($code, $msg = '', $data = null) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'chat') {
    $body = getBody();
    $sessionId = trim($body['session_id'] ?? '');
    $message   = trim($body['message'] ?? '');
    $history   = $body['history'] ?? [];

    if (!$sessionId) chatResponse(400, 'session_id不能为空');
    if (!$message) chatResponse(400, '消息不能为空');

    $db = getDB();

    // 检查是否已验证身份（30分钟内有效的验证记录）
    $stmt = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE session_id = ? AND status = 1 AND verified_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $stmt->execute([$sessionId]);
    $isVerified = intval($stmt->fetchColumn() ?: 0) > 0;

    // 检测是否涉及订单
    $orderKeywords = ['订单', '快递', '物流', '发货', '到哪', '配送', '签收', '退款', '退货', '查单', '物流信息'];
    $isOrderQuery = false;
    foreach ($orderKeywords as $kw) {
        if (mb_strpos($message, $kw) !== false) { $isOrderQuery = true; break; }
    }

    // 获取人设配置
    $stmt = $db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
    $persona = $stmt->fetch() ?: [];

    // ★ 关键修复：每条消息都去检索知识库，不再靠关键词白名单
    try {
        $kbItems = PromptEngine::searchKnowledge($db, $message, 5);
    } catch (Exception $e) {
        $kbItems = [];
        error_log('知识库检索异常: ' . $e->getMessage());
    }

    // ★ 置信度过滤：搜不到相关知识 → 直接拒答，不调AI
    if (count($kbItems) < 1) {
        $reply = '抱歉，这个我不太清楚，建议您联系我们的在线客服咨询~';
        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, tokens) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user', $message, $isVerified ? 1 : 0, 0]);
        $stmt->execute([$sessionId, 'assistant', $reply, $isVerified ? 1 : 0, 0]);
        chatResponse(0, 'ok', [
            'reply'       => $reply,
            'is_verified' => $isVerified,
        ]);
    }

    // 构建系统提示词
    $systemPrompt = PromptEngine::build([
        'persona'   => $persona,
        'knowledge' => $kbItems,
    ]);

    if ($isVerified) {
        $systemPrompt .= "\n\n【当前状态】客户已验证身份，可以协助查询订单。";
    }

    // 构建对话
    $historyWithMsg = array_merge($history, [['role' => 'user', 'content' => $message]]);
    $dialogueContent = PromptEngine::buildDialogueContent($historyWithMsg, $persona['name'] ?? '客服');

    try {
        $result = callAI([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $dialogueContent],
        ], ['max_tokens' => 300, 'temperature' => 0.8]);

        $reply = $result['content'];

        // 保存对话记录
        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, tokens) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user', $message, $isVerified ? 1 : 0, 0]);
        $stmt->execute([$sessionId, 'assistant', $reply, $isVerified ? 1 : 0, intval($result['usage']['completion_tokens'] ?? 0)]);

        chatResponse(0, 'ok', [
            'reply'       => $reply,
            'is_verified' => $isVerified,
        ]);
    } catch (Exception $e) {
        error_log('chat AI error: ' . $e->getMessage());
        chatResponse(500, $e->getMessage());
    }
}

if ($action === 'persona') {
    $db = getDB();
    $stmt = $db->query('SELECT name, greeting FROM persona_config ORDER BY id DESC LIMIT 1');
    $persona = $stmt->fetch();
    ok($persona ?: ['name' => '客服', 'greeting' => '您好~ 很高兴为您服务！']);
}

chatResponse(404, '未知操作');

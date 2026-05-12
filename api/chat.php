<?php
// api/chat.php
// 客服聊天接口
//
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

    $sessionId    = trim($body['session_id'] ?? '');
    $message      = trim($body['message'] ?? '');
    $history      = $body['history'] ?? [];

    if (!$sessionId) chatResponse(400, 'session_id不能为空');
    if (!$message) chatResponse(400, '消息不能为空');

    $db = getDB();

    // 检查是否已验证（session 维度）
    $stmt = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE session_id = ? AND status = 1 AND verified_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $stmt->execute([$sessionId]);
    $isVerified = intval($stmt->fetchColumn() ?: 0) > 0;

    // 获取人设配置
    $stmt = $db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
    $persona = $stmt->fetch() ?: [];

    // 尝试匹配知识库（判断是否有知识库相关的问法）
    $kbItems = [];
    $kbMatched = false;

    // 使用 AI 判断是否需要检索知识库，简单策略：根据关键词匹配
    $kbKeywords = ['价格', '多少钱', '怎么退款', '怎么退货', '什么时候', '营业时间', '地址', '电话', '介绍', '功能', '套餐', '活动', '优惠', '怎么用', '如何', '能不能', '支持'];
    foreach ($kbKeywords as $kw) {
        if (mb_strpos($message, $kw) !== false) {
            $kbMatched = true;
            break;
        }
    }

    if ($kbMatched) {
        try {
            $kbItems = PromptEngine::searchKnowledge($db, $message, 5);
        } catch (Throwable $e) {
            $kbItems = [];
        }
    }

    // 如果问题包含订单相关关键词，且未验证，AI 会引导验证
    $orderKeywords = ['订单', '快递', '物流', '发货', '到哪', '配送', '签收', '退款', '退货'];
    $isOrderQuery = false;
    foreach ($orderKeywords as $kw) {
        if (mb_strpos($message, $kw) !== false) {
            $isOrderQuery = true;
            break;
        }
    }

    // 构建系统提示词
    $systemPrompt = PromptEngine::build([
        'persona'   => $persona,
        'knowledge' => $kbItems,
    ]);

    // 如果已验证，注入已验证信息，AI 可以引导查订单
    if ($isVerified) {
        $systemPrompt .= "\n\n【当前状态】客户已通过短信验证码验证身份，可以协助查询订单信息。但订单数据需要通过企业订单API获取，请引导客户提供订单号。";
    }

    // 构建对话内容
    $historyWithMsg = array_merge($history, [['role' => 'user', 'content' => $message]]);

    $dialogueContent = PromptEngine::buildDialogueContent($historyWithMsg, $persona['name'] ?? '客服');

    $aiMessages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $dialogueContent],
    ];

    try {
        $result = callAI($db, $aiMessages, [
            'max_tokens'  => 300,
            'temperature' => 0.8,
        ]);

        $reply = $result['content'];

        // 保存对话记录
        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, tokens) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user', $message, $isVerified ? 1 : 0, 0]);
        $stmt->execute([$sessionId, 'assistant', $reply, $isVerified ? 1 : 0, intval($result['usage']['completion_tokens'] ?? 0)]);

        chatResponse(0, 'ok', [
            'reply'       => $reply,
            'usage'       => $result['usage'],
            'is_verified' => $isVerified,
            'has_order_query' => $isOrderQuery,
        ]);

    } catch (Throwable $e) {
        error_log('chat AI error: ' . $e->getMessage());
        chatResponse(500, $e->getMessage());
    }
}

if ($action === 'persona') {
    $db = getDB();
    $stmt = $db->query('SELECT name, greeting FROM persona_config ORDER BY id DESC LIMIT 1');
    $persona = $stmt->fetch();
    ok($persona ?: [
        'name' => '客服',
        'greeting' => '您好~ 很高兴为您服务！',
    ]);
}

chatResponse(404, '未知操作');

<?php
// api/chat.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PromptEngine.php';
require_once __DIR__ . '/sidecar/RoomQueryService.php';
require_once __DIR__ . '/RoomQueryFlow.php';
require_once __DIR__ . '/HandoffTriggers.php';
require_once __DIR__ . '/chat_helpers.php'; // v3.3 PR3：全局函数集中

// v3.3 PR3：Intent 架构相关
require_once __DIR__ . '/Intent.php';
require_once __DIR__ . '/IntentClassifier.php';
require_once __DIR__ . '/SessionState.php';
require_once __DIR__ . '/IntentRouter.php';
require_once __DIR__ . '/ReplyRenderer.php';
require_once __DIR__ . '/ChatPipeline.php';
require_once __DIR__ . '/Workflow/AbstractWorkflow.php';
require_once __DIR__ . '/Workflow/YunfangkaCredentialWorkflow.php';
require_once __DIR__ . '/Workflow/RoomQueryWorkflow.php';
require_once __DIR__ . '/Workflow/OrderQueryWorkflow.php';
require_once __DIR__ . '/Workflow/KnowledgeWorkflow.php';
require_once __DIR__ . '/Workflow/SmallTalkWorkflow.php';
require_once __DIR__ . '/Workflow/UnknownWorkflow.php';

// v3.15：运行时不再执行 DDL。请先执行 sql/migration_v3.15_security.sql。

$action = $_GET['action'] ?? '';
header('Content-Type: application/json; charset=utf-8');
$chatRequestStart = null;


// ══════════════════════════════════════════
// POST /api/chat.php?action=chat（v3.3 Intent 架构路径）
// ══════════════════════════════════════════

if ($action === 'chat') {
    $chatRequestStart = microtime(true);
    $body      = getBody();
    $sessionId = trim($body['session_id'] ?? '');
    $message   = trim($body['message']    ?? '');
    $history   = $body['history']         ?? [];
    $visitorHash = trim($body['visitor_hash'] ?? '');
    $channel = trim($body['channel'] ?? 'web');

    if ($sessionId === '' || strlen($sessionId) > 128 || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $sessionId)) {
        chatResponse(400, 'session_id格式不正确');
    }
    if ($message === '' || mb_strlen($message) > 2000) {
        chatResponse(400, '消息不能为空或过长');
    }
    if ($visitorHash !== '' && (strlen($visitorHash) > 128 || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $visitorHash))) {
        chatResponse(400, 'visitor_hash格式不正确');
    }
    if (!in_array($channel, ['web', 'wechat_kf', 'wechat_mp', 'wechat_msg', 'openapi', 'api'], true)) {
        chatResponse(400, 'channel格式不正确');
    }

    // v3.3：Pipeline 开关分流
    $pipelineEnabled = false;
    try {
        $dbCheck = getDB();
        $stmtCheck = $dbCheck->query("SELECT value FROM platform_config WHERE `key` = 'pipeline.enabled' LIMIT 1");
        $valCheck = $stmtCheck->fetchColumn();
        $pipelineEnabled = ($valCheck === 'true');
    } catch (Throwable $e) {
        $pipelineEnabled = false;
    }

    if ($pipelineEnabled) {
        $ip = requestClientIp();
        try {
            $db = getDB();
            $pipelineResult = ChatPipeline::process(
                $sessionId,
                $message,
                is_array($history) ? $history : [],
                $db,
                $channel,
                $visitorHash,
                $ip
            );
            $code = $pipelineResult['code'] ?? 0;
            $msg  = $pipelineResult['msg']  ?? 'ok';
            $data = $pipelineResult['data'];
            if (is_array($data)) {
                chatResponse($code, $msg, $data);
            } else {
                chatResponse($code, $msg, null);
            }
        } catch (Throwable $e) {
            error_log('[chat.php] Pipeline failed: ' . $e->getMessage());
            $pipelineEnabled = false;
        }
    }

    // Pipeline 关闭时返回明确提示（PR4 阶段旧逻辑已删除，全部走 Pipeline）
    if (!$pipelineEnabled) {
        chatResponse(503, 'Pipeline 未启用。请在 platform_config 表中设置 pipeline.enabled = true，或等待 PR4 完成后开启。');
    }
}

// ══════════════════════════════════════════
// GET /api/chat.php?action=persona
// ══════════════════════════════════════════

if ($action === 'persona') {
    $db   = getDB();
    $stmt = $db->query('SELECT name, greeting, description, avatar_url FROM persona_config ORDER BY id DESC LIMIT 1');
    $persona = $stmt->fetch();
    ok($persona ?: ['name' => '客服', 'greeting' => '您好~ 很高兴为您服务！', 'description' => '', 'avatar_url' => '']);
}

// ══════════════════════════════════════════
// GET /api/chat.php?action=stats（需管理员token）
// ══════════════════════════════════════════

if ($action === 'stats') {
    adminGuard();
    $db = getDB();

    $todaySessions  = $db->query("SELECT COUNT(DISTINCT session_id) FROM chat_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $weekSessions   = $db->query("SELECT COUNT(DISTINCT session_id) FROM chat_logs WHERE YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)")->fetchColumn();
    $totalSessions  = $db->query("SELECT COUNT(DISTINCT session_id) FROM chat_logs")->fetchColumn();
    $totalMessages  = $db->query("SELECT COUNT(*) FROM chat_logs")->fetchColumn();
    $totalTokens    = $db->query("SELECT COALESCE(SUM(tokens),0) FROM chat_logs")->fetchColumn();

    ok([
        'today_sessions'  => intval($todaySessions),
        'week_sessions'   => intval($weekSessions),
        'total_sessions'  => intval($totalSessions),
        'total_messages'  => intval($totalMessages),
        'total_tokens'    => intval($totalTokens),
    ]);
}

// ══════════════════════════════════════════
// GET /api/chat.php?action=logs（需管理员token）
// ══════════════════════════════════════════

if ($action === 'logs') {
    adminGuard();
    $db = getDB();
    $page     = max(1, intval($_GET['page'] ?? 1));
    $size     = max(1, min(100, intval($_GET['size'] ?? 20)));
    $q        = trim($_GET['q'] ?? '');
    $sessionId = trim($_GET['session_id'] ?? '');

    // 如果指定了 session_id，返回该会话的所有消息明细
    if ($sessionId) {
        $stmt = $db->prepare("SELECT id, session_id, channel, role, content, has_verified, visitor_hash, source_ip, tokens, created_at FROM chat_logs WHERE session_id = ? ORDER BY created_at ASC");
        $stmt->execute([$sessionId]);
        $messages = $stmt->fetchAll();
        ok(['session_id' => $sessionId, 'messages' => $messages]);
    }

    // 否则返回会话列表（按 session_id 分组聚合）
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE c.content LIKE ?';
        $params[] = '%' . $q . '%';
    }

    // 统计总记录数
    $countSql = "SELECT COUNT(DISTINCT c.session_id) FROM chat_logs c $where";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = intval($countStmt->fetchColumn());

    $offset = ($page - 1) * $size;

    // 分页查询会话列表：关联 sms_verify_logs 获取脱敏手机号
    $listSql = "SELECT
                  c.session_id,
                  COUNT(*) AS msg_count,
                  MAX(c.created_at) AS last_time,
                  MAX(c.has_verified) AS has_verified,
                  COALESCE(SUM(c.tokens),0) AS total_tokens,
                  MAX(v.phone_mask) AS phone_mask,
                  MAX(c.visitor_hash) AS visitor_hash,
                  MAX(c.source_ip) AS source_ip
                FROM chat_logs c
                LEFT JOIN sms_verify_logs v ON v.session_id = c.session_id AND v.status = 1
                $where
                GROUP BY c.session_id
                ORDER BY last_time DESC
                LIMIT ? OFFSET ?";
    $listStmt = $db->prepare($listSql);
    $listParams = array_merge($params, [$size, $offset]);
    $listStmt->execute($listParams);
    $list = $listStmt->fetchAll();

    ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
}

chatResponse(404, '未知操作');

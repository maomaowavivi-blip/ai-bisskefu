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
require_once __DIR__ . '/Workflow/HandoffWorkflow.php';

// 自动建表/迁移（每条独立 try-catch，避免低版本 MySQL 不支持某语法导致阻塞）
$dbInit = getDB();
try { $dbInit->exec("CREATE TABLE IF NOT EXISTS rate_limits (
    key_str VARCHAR(64) PRIMARY KEY,
    count INT DEFAULT 1,
    window_start DATETIME NOT NULL,
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) { error_log('migration rate_limits: ' . $e->getMessage()); }
try { $dbInit->exec("ALTER TABLE chat_logs ADD COLUMN `visitor_hash` varchar(64) NOT NULL DEFAULT '' COMMENT '设备指纹' AFTER `has_verified`"); } catch (Exception $e) {}
try { $dbInit->exec("ALTER TABLE chat_logs ADD COLUMN `source_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '来源IP' AFTER `visitor_hash`"); } catch (Exception $e) {}
try { $dbInit->exec("ALTER TABLE chat_logs ADD INDEX `idx_visitor` (`visitor_hash`)"); } catch (Exception $e) {}
try { $dbInit->exec("CREATE TABLE IF NOT EXISTS order_verify_sessions (
    session_id VARCHAR(64) PRIMARY KEY,
    order_no VARCHAR(64) NOT NULL DEFAULT '',
    phone VARCHAR(20) NOT NULL DEFAULT '',
    phone_hash VARCHAR(64) NOT NULL DEFAULT '',
    phone_mask VARCHAR(13) NOT NULL DEFAULT '',
    step TINYINT NOT NULL DEFAULT 0 COMMENT '0=none 1=wait_order 2=wait_phone 3=wait_code 4=verified',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单验证会话'"); } catch (Exception $e) { error_log('migration order_verify_sessions: ' . $e->getMessage()); }
try { $dbInit->exec("ALTER TABLE order_verify_sessions MODIFY COLUMN `order_no` VARCHAR(512) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
try { $dbInit->exec("CREATE TABLE IF NOT EXISTS room_query_sessions (
    session_id VARCHAR(64) PRIMARY KEY,
    room_id VARCHAR(64) NOT NULL DEFAULT '',
    question TEXT NOT NULL,
    step TINYINT NOT NULL DEFAULT 0 COMMENT '0=none 1=wait_room_id',
    order_no VARCHAR(64) NOT NULL DEFAULT '' COMMENT '关联订单号',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='房间查询会话'"); } catch (Exception $e) { error_log('migration room_query_sessions: ' . $e->getMessage()); }
try { $dbInit->exec("ALTER TABLE room_query_sessions ADD COLUMN `order_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '关联订单号' AFTER `step`"); } catch (Exception $e) {}
try { $dbInit->exec("ALTER TABLE room_query_sessions ADD COLUMN `sidecar_room_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sidecar ai_room_profile.id' AFTER `order_no`"); } catch (Exception $e) {}
try { $dbInit->exec("ALTER TABLE room_query_sessions ADD COLUMN `room_candidates` MEDIUMTEXT NULL COMMENT 'step=2 候选 JSON' AFTER `sidecar_room_id`"); } catch (Exception $e) {}
try { $dbInit->exec("ALTER TABLE room_query_sessions ADD COLUMN `bound_at` DATETIME NULL COMMENT 'step=3 绑定时间' AFTER `room_candidates`"); } catch (Exception $e) {}
try { $dbInit->exec("ALTER TABLE room_query_sessions ADD COLUMN `expires_at` DATETIME NULL COMMENT '会话过期' AFTER `bound_at`"); } catch (Exception $e) {}
try { $dbInit->exec("ALTER TABLE room_query_sessions MODIFY COLUMN `step` TINYINT NOT NULL DEFAULT 0 COMMENT '0=idle 1=wait_order 2=wait_room_pick 3=bound'"); } catch (Exception $e) {}
try { $dbInit->exec("CREATE TABLE IF NOT EXISTS human_handoffs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(500) DEFAULT '',
    taken_by INT UNSIGNED DEFAULT NULL,
    taken_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_session (session_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人工接管记录'"); } catch (Exception $e) { error_log('migration human_handoffs: ' . $e->getMessage()); }
try { $dbInit->exec("CREATE TABLE IF NOT EXISTS handoff_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    handoff_id INT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_handoff (handoff_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='接管对话消息'"); } catch (Exception $e) { error_log('migration handoff_messages: ' . $e->getMessage()); }
try { $dbInit->exec("CREATE TABLE IF NOT EXISTS handoff_triggers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    keyword VARCHAR(100) NOT NULL COMMENT '触发词',
    priority TINYINT NOT NULL DEFAULT 0 COMMENT '0=P0紧急 1=P1高 2=P2中 3=P3常规 4=兜底',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_keyword (keyword),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='转人工触发词'"); } catch (Exception $e) { error_log('migration handoff_triggers: ' . $e->getMessage()); }

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

    if (!$sessionId) chatResponse(400, 'session_id不能为空');
    if (!$message)   chatResponse(400, '消息不能为空');

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
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $ip = $xff ? trim(explode(',', $xff)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
        $visitorHash = trim($body['visitor_hash'] ?? '');
        $channel = $body['channel'] ?? 'web';

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

<?php
// api/chat.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PromptEngine.php';
require_once __DIR__ . '/sidecar/RoomQueryService.php';
require_once __DIR__ . '/RoomQueryFlow.php';
require_once __DIR__ . '/HandoffTriggers.php';

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

function chatResponse($code, $msg = '', $data = null) {
    global $chatRequestStart;
    if ($chatRequestStart !== null && is_array($data)) {
        $data['elapsed_ms'] = (int) round((microtime(true) - $chatRequestStart) * 1000);
    }
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 输入安全拦截（命中直接返回，不消耗 AI token）
 * 返回拦截回复文字，安全则返回 null
 */
function checkInputSafety(string $msg, ?AgentConfig $config = null): ?string {
    $political = ($config) ? $config->getJson('agent.safety.political', []) : [];
    if (empty($political)) {
        $political = ['法轮功', '天安门', '六四事件', '台独', '藏独', '港独'];
    }
    static $adult     = ['色情', '裸体', '性爱', '做爱', '强奸', '卖淫', '自杀方式', '杀人方法'];
    static $injection = [
        '忽略上面', '忽略之前', '忘记上面', '忘记之前',
        '你现在是', '从现在起你是', '扮演一个',
        'ignore previous', 'ignore all', 'ignore the above',
        'system prompt', 'disregard',
    ];

    foreach ($political as $kw) {
        if (mb_strpos($msg, $kw) !== false) {
            return '这个话题我不太方便讨论，有其他可以帮您的吗~';
        }
    }
    foreach ($adult as $kw) {
        if (mb_strpos($msg, $kw) !== false) {
            return '抱歉，这类内容我无法回应~';
        }
    }
    foreach ($injection as $kw) {
        if (mb_stripos($msg, $kw) !== false) {
            return '您好，有什么可以帮您的吗~';
        }
    }
    return null;
}

/**
 * 检测 AI 回复或用户消息是否触发转人工
 */
function shouldTriggerHandoff(string $reply, string $message, PDO $db): bool {
    $keywords = [
        '无法回答', '不清楚', '不知道', '没有相关信息',
        '建议联系', '转人工', '联系客服', '无法处理',
        '无法查询', '暂时无法', '不在我范围内',
    ];
    foreach ($keywords as $kw) {
        if (mb_strpos($reply, $kw) !== false) return true;
    }
    if (mb_strpos($reply, '这个我帮您核实一下') !== false) return true;
    if (mb_strpos($reply, '建议您联系') !== false) return true;

    return HandoffTriggers::matchesMessage($db, $message);
}

/** 命中转人工触发词：固定回复并写入待接管队列 */
function respondDirectHandoff(PDO $db, string $sessionId, string $message, string $visitorHash, string $ip): void {
    try {
        $db->prepare('DELETE FROM room_query_sessions WHERE session_id = ?')->execute([$sessionId]);
    } catch (Exception $e) {}
    $handoffReply = '正在为您转接人工客服，请稍候。';
    $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, 0, ?, ?, 0)');
    $stmt->execute([$sessionId, 'user', $message, $visitorHash, $ip]);
    $stmt->execute([$sessionId, 'assistant', $handoffReply, $visitorHash, $ip]);
    try {
        $stmt = $db->prepare("SELECT id, status FROM human_handoffs WHERE session_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sessionId]);
        $existing = $stmt->fetch();
        if (!$existing || intval($existing['status']) === 2) {
            $reason = '触发词转人工';
            $hit = HandoffTriggers::matchKeyword($db, $message);
            if ($hit) {
                $reason = '触发词：' . $hit['keyword'] . ' (P' . $hit['priority'] . ')';
            }
            $stmt = $db->prepare("INSERT INTO human_handoffs (session_id, status, reason) VALUES (?, 0, ?)");
            $stmt->execute([$sessionId, $reason]);
        }
    } catch (Exception $e) {
        error_log('Direct handoff insert error: ' . $e->getMessage());
    }
    chatResponse(0, 'ok', ['reply' => $handoffReply, 'is_verified' => false, 'handoff_status' => 0]);
}

/** 查单展示云房卡卡片后，客人追问「这是什么 / 不会用」等 */
function isYunfangkaCardFollowUp(PDO $db, string $sessionId, string $message): bool {
    $msg = trim($message);
    if ($msg === '') {
        return false;
    }
    if (preg_match('/云房卡|电子房卡/u', $msg)) {
        return true;
    }
    try {
        $st = $db->prepare('SELECT 1 FROM order_context_cache WHERE session_id = ? AND expires_at > NOW() LIMIT 1');
        $st->execute([$sessionId]);
        if (!$st->fetch()) {
            return false;
        }
    } catch (Exception $e) {
        return false;
    }
    return (bool) preg_match(
        '/^(这是什么|这什么|这是啥|不会用|不知道怎么用|怎么用|如何使用|怎么操作|你发的是什么|给我发的是什么|发我的是什么|发的是什么|上面是什么|下面是什么|卡片是什么|那个是什么)/u',
        $msg
    ) || (bool) preg_match('/(你给我发|给我发的|发的是啥|什么卡片|什么链接|怎么点|点哪里|如何使用|查看云房卡|云房卡)/u', $msg);
}

/** 通用政策 KB 直答（不走 LLM / Sidecar / 转人工） */
function respondDirectKb(PDO $db, string $sessionId, string $message, string $reply, string $visitorHash, string $ip): void {
    $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, 0, ?, ?, 0)');
    $stmt->execute([$sessionId, 'user', $message, $visitorHash, $ip]);
    $stmt->execute([$sessionId, 'assistant', $reply, $visitorHash, $ip]);
    chatResponse(0, 'ok', ['reply' => $reply, 'is_verified' => false, 'handoff_status' => -1]);
}

/**
 * 统一网关调用
 */
function callGateway($db, $action, $params) {
    $gatewayUrl = pcGet($db, 'gateway.api_url', '');
    $gatewayKey = pcGet($db, 'gateway.api_key', '');
    if (!$gatewayUrl) $gatewayUrl = pcGet($db, 'order.api_url', '');
    if (!$gatewayKey) $gatewayKey = pcGet($db, 'order.api_key', '');
    if (!$gatewayUrl || !$gatewayKey) return null;

    $payload = ['action' => $action, 'params' => $params, 'timestamp' => time()];
    try {
        $ch = curl_init($gatewayUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . (strpos($gatewayKey, 'Bearer ') === 0 ? $gatewayKey : 'Bearer ' . $gatewayKey),
            ],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp && $httpCode === 200) {
            $result = json_decode($resp, true);
            if (isset($result['code']) && intval($result['code']) === 200 && isset($result['data'])) {
                return $result['data'];
            }
        }
        // PMS 返回 404（未找到房间信息）时，保留 msg 供调用方判断
        if ($resp) {
            $result = json_decode($resp, true);
            if (isset($result['code']) && intval($result['code']) === 404) {
                return ['_not_found' => true, 'msg' => $result['msg'] ?? '未找到房间信息'];
            }
        }
    } catch (Throwable $e) {}
    return null;
}

/**
 * 本地 Sidecar 房间查询（替代远程 query_room 网关）
 */
function queryRoomLocal(PDO $db, string $sessionId, string $roomId, string $question, bool $isVerified = false): ?array {
    if (!$isVerified) {
        try {
            $st = $db->prepare('SELECT 1 FROM order_context_cache WHERE session_id = ? AND expires_at > NOW() LIMIT 1');
            $st->execute([$sessionId]);
            if ($st->fetchColumn()) {
                $isVerified = true;
            }
        } catch (Exception $e) {}
        if (!$isVerified) {
            try {
                $st = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE session_id = ? AND status = 1 AND verified_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
                $st->execute([$sessionId]);
                $isVerified = intval($st->fetchColumn() ?: 0) > 0;
            } catch (Exception $e) {}
        }
    }
    return RoomQueryService::query($roomId, $question, $isVerified, $sessionId);
}

// ══════════════════════════════════════════
// POST /api/chat.php?action=chat
// ══════════════════════════════════════════

if ($action === 'chat') {
    $chatRequestStart = microtime(true);
    $body      = getBody();
    $sessionId = trim($body['session_id'] ?? '');
    $message   = trim($body['message']    ?? '');
    $history   = $body['history']         ?? [];

    if (!$sessionId) chatResponse(400, 'session_id不能为空');
    if (!$message)   chatResponse(400, '消息不能为空');

    // 速率限制：同一IP每60秒最多20次请求
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $ip = $xff ? trim(explode(',', $xff)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
    $rateKey = 'rl:' . $ip;
    $db = getDB();
    $config = new AgentConfig($db);
    $now = date('Y-m-d H:i:s');
    $st = $db->prepare("SELECT count, window_start FROM rate_limits WHERE key_str = ?");
    $st->execute([$rateKey]);
    $rl = $st->fetch();
    if ($rl && strtotime($rl['window_start']) > time() - 60) {
        $db->prepare("UPDATE rate_limits SET count = count + 1 WHERE key_str = ?")->execute([$rateKey]);
    } else {
        $db->prepare("INSERT INTO rate_limits (key_str, count, window_start) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE count = 1, window_start = ?")->execute([$rateKey, $now, $now]);
    }
    $stmt = $db->prepare("SELECT count FROM rate_limits WHERE key_str = ?");
    $stmt->execute([$rateKey]);
    $currentCount = intval($stmt->fetchColumn());
    if ($currentCount > 20) {
        chatResponse(429, '请求过于频繁，请稍后再试');
    }

    // 第一道防线：输入安全拦截
    $safetyReply = checkInputSafety($message, $config);
    if ($safetyReply !== null) {
        chatResponse(0, 'ok', ['reply' => $safetyReply, 'is_verified' => false]);
    }

    // 人设配置
    $stmt    = $db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
    $persona = $stmt->fetch() ?: [];
    $visitorHash = trim($body['visitor_hash'] ?? '');

    $isOrderQueryCmd = (bool) preg_match('/^order_query:/', $message);
    $isHandoffMsg    = HandoffTriggers::matchesMessage($db, $message);
    $isYfkCredential = $config->isCredentialQuery($message);

    // 极速路径：KB 关键词直答（跳过改写 / 向量 / LLM）
    if (!$isOrderQueryCmd) {
        $earlyKb = PromptEngine::directReplyFromKb($message, [], $db, $config);
        if ($earlyKb !== null) {
            respondDirectKb($db, $sessionId, $message, $earlyKb, $visitorHash, $ip);
        }
    }

    // ── 问题改写：消解代词（订单/转人工/云房卡凭证类跳过）──
    $rewrittenQuery = $message;
    if (!$isOrderQueryCmd && !$isHandoffMsg && !$isYfkCredential) {
        try {
            $rewrittenQuery = PromptEngine::rewriteQuery($message, $history, $sessionId);
        } catch (Exception $e) {
            error_log('问题改写异常: ' . $e->getMessage());
        }
    }

    // 知识库检索：关键词优先；语义向量仅 LLM 兜底场景
    $kbItems = [];
    $sidecarEnabled = $config ? ($config->get('plugin.sidecar.enabled', '1') === '1') : true;
    $isRoomIntentPreview = false;
    if ($sidecarEnabled) {
        $roomKeywordsPreview = RoomQueryFlow::getRoomKeywords($db);
        $isRoomIntentPreview = RoomQueryFlow::isRoomIntent($message, $roomKeywordsPreview);
    }
    $skipSemanticKb = $isOrderQueryCmd || $isHandoffMsg || $isYfkCredential || $isRoomIntentPreview;

    try {
        $kbItems = PromptEngine::searchKnowledge($db, $message, PromptEngine::KB_MAX_ITEMS);
    } catch (Exception $e) {
        error_log('知识库检索异常: ' . $e->getMessage());
    }
    if ($rewrittenQuery !== $message) {
        try {
            $msgKb = PromptEngine::searchKnowledge($db, $rewrittenQuery, PromptEngine::KB_MAX_ITEMS);
            if (!empty($msgKb)) {
                $kbItems = array_merge($kbItems, $msgKb);
            }
        } catch (Exception $e) {
            error_log('知识库检索异常: ' . $e->getMessage());
        }
    }
    if (!$skipSemanticKb && empty($kbItems)) {
        require_once __DIR__ . '/embedding.php';
        try {
            $semantic = kbSemanticSearch($db, $rewrittenQuery, 3, 0.5);
            if (!empty($semantic['list'])) {
                $kbItems = $semantic['list'];
            }
        } catch (Exception $e) {
            error_log('语义搜索异常: ' . $e->getMessage());
        }
    }

    $richContent = [];

    // ── 检测意图 ──

    // 特殊命令：前端弹窗发来的订单查询（绕过 AI，确保走流程）
    if (preg_match('/^order_query:(.+)$/', $message, $m)) {
        $orderNo = trim($m[1]);
        $orderData = callGateway($db, 'query_order', ['order_no' => $orderNo]);
        if ($orderData) {
            $reply = "✅ 查询成功！订单信息如下：\n\n";
            $lastInfo = null;
            foreach ((array)$orderData as $entry) {
                $info = $entry['order_info'] ?? $entry;
                $lastInfo = $info;
                $reply .= "🏠 房间：{$info['room']}\n";
                $reply .= "📅 入住：{$info['check_in']}\n";
                $reply .= "📅 离店：{$info['check_out']}\n";
                $reply .= "🔑 订单号：{$info['order_no']}\n";
                $reply .= "\n";
                if (!empty($entry['yunfangka_image'])) {
                    $richContent[] = [
                        'type' => 'image_link',
                        'image_url' => $entry['yunfangka_image'],
                        'link_url' => $entry['yunfangka_url'] ?? '#',
                        'title' => '查看云房卡',
                        'description' => "订单 {$info['order_no']} 的电子房卡",
                    ];
                }
            }
            $reply .= "您可以继续咨询其他问题";
            // 保存订单上下文，后续房间查询可关联
            if ($lastInfo) {
                $orderCtx = json_encode(['order_no' => $lastInfo['order_no'], 'room' => $lastInfo['room'], 'room_id' => $lastInfo['room_id'] ?? ''], JSON_UNESCAPED_UNICODE);
                $db->prepare("INSERT INTO order_verify_sessions (session_id, step, order_no) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE step = VALUES(step), order_no = VALUES(order_no)")->execute([$sessionId, $orderCtx]);

                // 写入 order_context_cache（安全方案：后端验证状态，不信任前端 room_id）
                $allRooms = [];
                $primaryRoomId = '';
                foreach ((array)$orderData as $entry) {
                    $ri = trim($entry['order_info']['room_id'] ?? $entry['order_info']['room'] ?? '');
                    if ($ri) {
                        $allRooms[] = $ri;
                        if (!$primaryRoomId) $primaryRoomId = $ri;
                    }
                }
                if ($allRooms) {
                    $roomList = implode(',', $allRooms);
                    $db->prepare("INSERT INTO order_context_cache (session_id, order_no, room_id, room_list, verified_at, expires_at)
                        VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))
                        ON DUPLICATE KEY UPDATE order_no = VALUES(order_no), room_id = VALUES(room_id), room_list = VALUES(room_list), verified_at = VALUES(verified_at), expires_at = VALUES(expires_at)")
                        ->execute([$sessionId, $lastInfo['order_no'], $primaryRoomId, $roomList]);
                }
            }
        } else {
            $reply = '❌ 未查询到订单信息，请检查订单号是否正确，或联系客服处理';
        }
        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user',      $message, 0, $visitorHash, $ip, 0]);
        $stmt->execute([$sessionId, 'assistant', $reply, 0, $visitorHash, $ip, 0]);
        $resData = ['reply' => $reply, 'is_verified' => false, 'handoff_status' => -1];
        if (!empty($richContent)) $resData['rich_content'] = $richContent;
        chatResponse(0, 'ok', $resData);
    }

    // ── 调试：检查会话状态 ──
    if (strpos($message, 'debug_session:') === 0) {
        $sid = trim(substr($message, 15));
        $st = $db->prepare("SELECT * FROM order_verify_sessions WHERE session_id = ?");
        $st->execute([$sid]);
        $row = $st->fetch();
        $cacheQ = $db->prepare("SELECT * FROM order_context_cache WHERE session_id = ?");
        $cacheQ->execute([$sid]);
        $cacheRow = $cacheQ->fetch();
        // 模拟 callGateway 返回
        $gwResult = null;
        $gwRaw = null;
        try {
            $gatewayUrl = pcGet($db, 'gateway.api_url', '');
            $gatewayKey = pcGet($db, 'gateway.api_key', '');
            $payload = ['action' => 'query_order', 'params' => ['order_no' => '1094913365180824016'], 'timestamp' => time()];
            $ch2 = curl_init($gatewayUrl);
            curl_setopt_array($ch2, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . trim(str_replace('Bearer ', '', $gatewayKey))],
            ]);
            $gwRaw = curl_exec($ch2);
            $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            $gwResult = json_decode($gwRaw, true);
        } catch (Exception $e) {}
        $colInfo = $db->query("DESC order_verify_sessions")->fetchAll(PDO::FETCH_ASSOC);
        chatResponse(0, 'ok', [
            'reply' => 'DEBUG order_verify: ' . json_encode($row, JSON_UNESCAPED_UNICODE)
                . ' | cache: ' . json_encode($cacheRow, JSON_UNESCAPED_UNICODE)
                . ' | gateway HTTP: ' . intval($httpCode)
                . ' | gateway raw: ' . ($gwRaw ?: 'null'),
            'is_verified' => false,
            'handoff_status' => -1
        ]);
    }

    // 通用政策 KB 直答：优先于 Sidecar 房间流与转人工（14:00/12:00、宠物禁烟、平台退改等）
    $policyReply = PromptEngine::directReplyFromKb($message, $kbItems, $db, $config);
    if ($policyReply !== null) {
        respondDirectKb($db, $sessionId, $message, $policyReply, $visitorHash, $ip);
    }

    // 查单后展示云房卡卡片，客人追问用途/不会用
    if (isYunfangkaCardFollowUp($db, $sessionId, $message)) {
        $cardReply = PromptEngine::directReplyFromKb('云房卡是什么', $kbItems, $db, $config);
        if ($cardReply === null) {
            $cardReply = '刚才下方卡片是云房卡，是您订单的电子入住凭证。请点击「查看云房卡」进入，可办理公安刷脸核验、在线交押金，并查看WiFi密码与门锁密码。';
        } else {
            $cardReply = '刚才下方卡片是云房卡。' . $cardReply . ' 请点击卡片进入办理。';
        }
        respondDirectKb($db, $sessionId, $message, $cardReply, $visitorHash, $ip);
    }

    $isRoomIntent = false;
    if ($sidecarEnabled) {
        $roomKeywords = RoomQueryFlow::getRoomKeywords($db);
        $isRoomIntent = RoomQueryFlow::isRoomIntent($message, $roomKeywords);
    }
    $isOrderIntent = preg_match('/^(🔍\s*)?(我要|我想)?\s*(查|查询|想查)\s*(订单|快递|物流|入住)/u', $message)
        || preg_match('/^(我要|我想)\s*入住/u', $message);

    // ── 房间查询状态机（Sidecar，与 order_query 冻结块解耦）──
    if ($sidecarEnabled) {
        if ($isOrderIntent) {
            try {
                $db->prepare('DELETE FROM room_query_sessions WHERE session_id = ?')->execute([$sessionId]);
            } catch (Exception $e) {}
        } else {
            $flowResult = RoomQueryFlow::handle($db, $sessionId, $message, $roomKeywords, $visitorHash, $ip);
            if ($flowResult !== null && !empty($flowResult['handled'])) {
                $flowReply = (string)($flowResult['reply'] ?? '');
                $flowVerified = !empty($flowResult['is_verified']) ? 1 : 0;
                $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$sessionId, 'user', $message, $flowVerified, $visitorHash, $ip, 0]);
                $stmt->execute([$sessionId, 'assistant', $flowReply, $flowVerified, $visitorHash, $ip, 0]);
                $resData = [
                    'reply' => $flowReply,
                    'is_verified' => (bool)$flowVerified,
                    'handoff_status' => intval($flowResult['handoff_status'] ?? -1),
                ];
                if (isset($flowResult['room_query_step'])) {
                    $resData['room_query_step'] = intval($flowResult['room_query_step']);
                }
                if (!empty($flowResult['rich_content'])) {
                    $resData['rich_content'] = $flowResult['rich_content'];
                }
                chatResponse(0, 'ok', $resData);
            }
        }
    }

    // 后台维护的转人工触发词（Sidecar 未命中时生效，避免拦截停车/WiFi 等房间问答）
    if (HandoffTriggers::matchesMessage($db, $message)) {
        respondDirectHandoff($db, $sessionId, $message, $visitorHash, $ip);
    }

    // ── 订单查询流程（文本「我要查订单」，与房间流独立）──
    $verifyState = null;
    try {
        $st = $db->prepare('SELECT * FROM order_verify_sessions WHERE session_id = ?');
        $st->execute([$sessionId]);
        $verifyState = $st->fetch();
    } catch (Exception $e) {}

    $verifyReply = null;

    if ($isOrderIntent && !$verifyState) {
        $db->prepare('INSERT INTO order_verify_sessions (session_id, step) VALUES (?, 1) ON DUPLICATE KEY UPDATE step = 1, order_no = \'\'')->execute([$sessionId]);
        $verifyReply = '📋 请输入订单号，我帮您查询';
    }

    if ($verifyState) {
        $step = intval($verifyState['step'] ?? 0);
        if ($step === 1) {
            if (preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9\-_#\/\.]{4,50}$/u', $message) && !$isOrderIntent) {
                $orderData = callGateway($db, 'query_order', ['order_no' => $message]);
                if ($orderData) {
                    $reply = "✅ 查询成功！订单信息如下：\n\n";
                    $lastInfo = null;
                    foreach ((array)$orderData as $entry) {
                        $info = $entry['order_info'] ?? $entry;
                        $lastInfo = $info;
                        $reply .= "🏠 房间：{$info['room']}\n";
                        $reply .= "📅 入住：{$info['check_in']}\n";
                        $reply .= "📅 离店：{$info['check_out']}\n";
                        $reply .= "🔑 订单号：{$info['order_no']}\n";
                        $reply .= "\n";
                        if (!empty($entry['yunfangka_image'])) {
                            $richContent[] = [
                                'type' => 'image_link',
                                'image_url' => $entry['yunfangka_image'],
                                'link_url' => $entry['yunfangka_url'] ?? '#',
                                'title' => '查看云房卡',
                                'description' => "订单 {$info['order_no']} 的电子房卡",
                            ];
                        }
                    }
                    $reply .= '您可以继续咨询其他问题';
                    if ($lastInfo) {
                        $orderCtx = json_encode(['order_no' => $lastInfo['order_no'], 'room' => $lastInfo['room'], 'room_id' => $lastInfo['room_id'] ?? ''], JSON_UNESCAPED_UNICODE);
                        $db->prepare('UPDATE order_verify_sessions SET step=0, order_no=? WHERE session_id=?')->execute([$orderCtx, $sessionId]);
                    } else {
                        $db->prepare('DELETE FROM order_verify_sessions WHERE session_id = ?')->execute([$sessionId]);
                    }
                } else {
                    $db->prepare('DELETE FROM order_verify_sessions WHERE session_id = ?')->execute([$sessionId]);
                    $reply = '❌ 未查询到订单信息，请检查订单号是否正确，或联系客服处理';
                }
                $verifyReply = $reply;
            } elseif ($isOrderIntent) {
                $db->prepare('UPDATE order_verify_sessions SET step = 1, order_no = \'\' WHERE session_id = ?')->execute([$sessionId]);
                $verifyReply = '📋 请输入订单号，我帮您查询';
            } elseif (!$isOrderIntent) {
                $verifyReply = '请输入完整的订单号';
            }
        } else {
            $db->prepare('DELETE FROM order_verify_sessions WHERE session_id = ?')->execute([$sessionId]);
        }
    }

    if ($verifyReply !== null) {
        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user', $message, 0, $visitorHash, $ip, 0]);
        $stmt->execute([$sessionId, 'assistant', $verifyReply, 0, $visitorHash, $ip, 0]);
        $resData = ['reply' => $verifyReply, 'is_verified' => false, 'handoff_status' => -1];
        if (!empty($richContent)) {
            $resData['rich_content'] = $richContent;
        }
        chatResponse(0, 'ok', $resData);
    }

    // 纯订单号（无房间关键词）→ 引导订单查询，禁止 LLM 编造地址
    if (RoomQueryFlow::looksLikeOrderNo($message) && !$isRoomIntent && !$isOrderIntent) {
        $guideReply = $config->get('agent.order.guide_reply', '如需查询订单，请点击下方「订单查询」按钮，或直接发送 order_query:订单号～');
        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user', $message, 0, $visitorHash, $ip, 0]);
        $stmt->execute([$sessionId, 'assistant', $guideReply, 0, $visitorHash, $ip, 0]);
        chatResponse(0, 'ok', ['reply' => $guideReply, 'is_verified' => false, 'handoff_status' => -1]);
    }

    // 房间类意图未命中 Sidecar 流 → 固定话术，禁止 LLM（关 Sidecar 时 $isRoomIntent 恒为 false，不会进入）
    if ($isRoomIntent) {
        $missReply = '暂未找到该房间的相关资料，请联系前台确认～';
        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user', $message, 0, $visitorHash, $ip, 0]);
        $stmt->execute([$sessionId, 'assistant', $missReply, 0, $visitorHash, $ip, 0]);
        chatResponse(0, 'ok', ['reply' => $missReply, 'is_verified' => false, 'handoff_status' => -1]);
    }

    // ── 普通聊天 → 走 AI ──
    $directKbReply = PromptEngine::directReplyFromKb($message, $kbItems, $db, $config);
    if ($directKbReply !== null) {
        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user', $message, 0, $visitorHash, $ip, 0]);
        $stmt->execute([$sessionId, 'assistant', $directKbReply, 0, $visitorHash, $ip, 0]);
        chatResponse(0, 'ok', ['reply' => $directKbReply, 'is_verified' => false, 'handoff_status' => -1]);
    }

    $messages = PromptEngine::buildMessages($persona, $history, $message, $kbItems, $sessionId, $rewrittenQuery, $config);

    try {
        $temperature = floatval(pcGet($db, 'ai.temperature', '0.5'));
        // 客服回复关推理：实测对比无质量差，速度快 5 倍、成本省 15 倍
        $result = callAI($messages, [
            'max_tokens'  => $config->getInt('agent.llm.max_tokens', 150),
            'temperature' => $temperature,
            'thinking'    => ['type' => 'disabled'],
        ]);
        $reply  = $result['content'];
        $reply  = PromptEngine::filterReply($reply, $message, $db, $config);
        $reply  = PromptEngine::finalizeReply($reply, $kbItems, $config);

        $stmt = $db->prepare('INSERT INTO chat_logs (session_id, role, content, has_verified, visitor_hash, source_ip, tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$sessionId, 'user',      $message, 0, $visitorHash, $ip, 0]);
        $stmt->execute([$sessionId, 'assistant', $reply,   0, $visitorHash, $ip, intval($result['usage']['completion_tokens'] ?? 0)]);

        $handoffStatus = -1;
        try {
            if (shouldTriggerHandoff($reply, $message, $db)) {
                $stmt = $db->prepare("SELECT id, status FROM human_handoffs WHERE session_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$sessionId]);
                $existing = $stmt->fetch();

                if (!$existing || intval($existing['status']) === 2) {
                    $stmt = $db->prepare("INSERT INTO human_handoffs (session_id, status, reason) VALUES (?, 0, 'AI无法回答触发转人工')");
                    $stmt->execute([$sessionId]);
                    $handoffStatus = 0;
                } else {
                    $handoffStatus = intval($existing['status']);
                }
            }
        } catch (Exception $e) {
            error_log('Handoff check error: ' . $e->getMessage());
        }

        chatResponse(0, 'ok', ['reply' => $reply, 'is_verified' => false, 'handoff_status' => $handoffStatus]);

    } catch (Exception $e) {
        error_log('chat AI error: ' . $e->getMessage());
        chatResponse(500, $e->getMessage());
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

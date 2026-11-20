<?php
// api/openapi_room_chats.php
// v3.30 — 房管系统「昨日AI聊天」查询接口（只读）
//
// POST /api/openapi_room_chats.php
// Headers:
//   X-API-Key: {api_key}   （或 Authorization: Bearer {api_key}）
//   Content-Type: application/json
//
// Body:
// {
//   "order_nos": ["1128148162721995", "..."],   // 必填,1-200 项,每项 ^[A-Za-z0-9_\-]{4,64}$
//   "date": "2026-11-18"                        // 可选。不传 = 昨天0点→现在；传了 = 指定整天
// }
//
// 成功响应（一次返回对话列表 + 每对话完整消息流,点开不再二次请求）：
// {
//   "code": 0, "msg": "ok",
//   "data": {
//     "date": "2026-11-19",
//     "window_mode": "recent",
//     "window_start": "2026-11-19 00:00:00",
//     "window_end": "2026-11-20 10:05:00",
//     "list": [
//       {"order_no":"...","room_no":"10002","session_id":"wecom_kf_x","channel":"wechat_kf",
//        "message_count":12,"first_time":"...","last_time":"...","truncated":false,
//        "messages":[{"role":"user","content":"...","created_at":"..."}]}
//     ]
//   }
// }
//
// 时间窗口（v3.31 房管侧反馈后调整）：
//   - 不传 date（日常用法）：[昨天 00:00:00, 现在) —— 右边界是「房管打开页面的那一刻」。
//     原先取「昨天整天」，导致凌晨 2 点发生的聊天（落在今天）查不到，而那正是房管最该看到的。
//   - 传 date（查历史某天）：[date 00:00:00, date+1天 00:00:00) 整天窗口。
//   - 窗口计算全部用 MySQL 钟：chat_logs.created_at 出自 MySQL DEFAULT CURRENT_TIMESTAMP，
//     且生产 php-fpm 是 UTC 而 MySQL 是 CST，用 PHP 钟会差 8 小时。
//
// 说明：
//   - 一个 session 绑多个订单 → 每订单一一条,消息流重复,由房管系统按订单分组
//   - channel 字段取自 chat_logs 行自身的 channel,不依赖绑定表（蓝图 §3.3 审计定稿）
//   - 每对话 messages 上限 500 条(按时间取最前 500,超限置 truncated:true)
//   - 当天无消息的 session 丢弃
//   - 不设主动限流：调用频率 = 房管点击频率,IN 列表封顶 200

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('仅支持POST请求', 405);
}

// ── 验证 API Key（与 openapi.php:33-52 同款鉴权块）──
$apiKey = '';
$headerKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($headerKey) {
    $apiKey = $headerKey;
} elseif ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $apiKey = trim($m[1]);
}

if (!$apiKey) fail('缺少API密钥', 401);

$db = getDB();
$stmt = $db->prepare("SELECT id, name FROM api_keys WHERE api_key = ? AND enabled = 1 LIMIT 1");
$stmt->execute([$apiKey]);
$keyRecord = $stmt->fetch();

if (!$keyRecord) fail('API密钥无效', 403);

// 更新最后使用时间
$db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$keyRecord['id']]);

// ── 入参校验（蓝图 §3.3）──
$body       = getBody();
$orderNosRaw = $body['order_nos'] ?? null;

if (!is_array($orderNosRaw) || count($orderNosRaw) < 1 || count($orderNosRaw) > 200) {
    fail('order_nos必须为1-200项的数组', 400);
}
$orderNos = [];
foreach ($orderNosRaw as $no) {
    if (!is_string($no)) fail('order_nos每一项必须为字符串', 400);
    $no = trim($no);
    if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/D', $no)) {
        fail('order_nos存在格式不合法的项', 400);
    }
    $orderNos[$no] = true; // 去重
}
$orderNos = array_keys($orderNos);

// ── 时间窗口（v3.31：窗口计算全部交给 MySQL 钟）──
// 背景 1：全仓无 date_default_timezone_set，生产 php-fpm 是 UTC 而 MySQL 是 CST，
//         用 PHP 钟算「昨天/现在」会差 8 小时。
// 背景 2：chat_logs.created_at 出自 MySQL DEFAULT CURRENT_TIMESTAMP，只有与写库钟同源才准。
// 两种模式：
//   A. 不传 date（房管日常用法）：[昨天 00:00:00, 现在)  —— 右边界是「打开页面的那一刻」，
//      否则凌晨 2 点发生的聊天（落在「今天」）永远查不到。
//   B. 传 date（查历史某天）：[date 00:00:00, date+1天 00:00:00) 整天窗口。
$date = trim((string)($body['date'] ?? ''));
if ($date === '') {
    $row = $db->query(
        "SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '%Y-%m-%d %H:%i:%s') AS ws,
                DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS we"
    )->fetch();
    $windowStart = (string)$row['ws'];
    $windowEnd   = (string)$row['we'];
    $dateLabel   = substr($windowStart, 0, 10);
    $windowMode  = 'recent';   // 昨天零点 → 现在
} else {
    if (!preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/D', $date, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
        // v3.30 qa修正：补 checkdate，堵掉 2026-02-31 这类「正则过但日历非法、strtotime 滑窗」的暗坑
        fail('date格式应为YYYY-MM-DD', 400);
    }
    $st = $db->prepare(
        "SELECT DATE_FORMAT(CAST(? AS DATETIME), '%Y-%m-%d %H:%i:%s') AS ws,
                DATE_FORMAT(DATE_ADD(CAST(? AS DATE), INTERVAL 1 DAY), '%Y-%m-%d %H:%i:%s') AS we"
    );
    $st->execute([$date . ' 00:00:00', $date]);
    $row = $st->fetch();
    $windowStart = (string)$row['ws'];
    $windowEnd   = (string)$row['we'];
    $dateLabel   = $date;
    $windowMode  = 'day';      // 整天
}

// ── 查询 1：订单 → 绑定会话 ──
$ph = implode(',', array_fill(0, count($orderNos), '?'));
$stmt = $db->prepare(
    "SELECT session_id, order_no, room_no, channel FROM session_order_links WHERE order_no IN ($ph)"
);
$stmt->execute($orderNos);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$links) {
    ok([
        'date'         => $dateLabel,
        'window_mode'  => $windowMode,
        'window_start' => $windowStart,
        'window_end'   => $windowEnd,
        'list'         => [],
    ]);
}

// ── 查询 2：会话 → 窗口内完整消息流 ──
$sessionIds = array_values(array_unique(array_column($links, 'session_id')));
$ph2 = implode(',', array_fill(0, count($sessionIds), '?'));
$stmt = $db->prepare(
    "SELECT session_id, channel, role, content, created_at
       FROM chat_logs
      WHERE session_id IN ($ph2) AND created_at >= ? AND created_at < ?
      ORDER BY session_id, id ASC"
);
$stmt->execute(array_merge($sessionIds, [$windowStart, $windowEnd]));
$msgRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bySession = [];
foreach ($msgRows as $row) {
    $bySession[$row['session_id']][] = $row;
}

// ── 组装响应 ──
const ROOMCHAT_MSG_LIMIT = 500;

$list = [];
foreach ($links as $link) {
    $sid = (string)$link['session_id'];
    if (empty($bySession[$sid])) {
        continue; // 当天无消息的 session 丢弃
    }
    $msgs = $bySession[$sid];

    $truncated = false;
    if (count($msgs) > ROOMCHAT_MSG_LIMIT) {
        $msgs      = array_slice($msgs, 0, ROOMCHAT_MSG_LIMIT); // 按时间取最前 500
        $truncated = true;
    }

    $messages = [];
    foreach ($msgs as $m) {
        $messages[] = [
            'role'       => (string)$m['role'],
            'content'    => (string)$m['content'],
            'created_at' => (string)$m['created_at'],
        ];
    }

    $list[] = [
        'order_no'      => (string)$link['order_no'],
        'room_no'       => (string)$link['room_no'],
        'session_id'    => $sid,
        // channel 取自 chat_logs 行自身,不依赖绑定表(蓝图审计定稿)
        'channel'       => (string)($msgs[0]['channel'] ?? ''),
        'message_count' => count($messages),
        'first_time'    => (string)($msgs[0]['created_at'] ?? ''),
        'last_time'     => (string)(end($msgs)['created_at'] ?? ''),
        'truncated'     => $truncated,
        'messages'      => $messages,
    ];
}

ok([
    'date'         => $dateLabel,
    'window_mode'  => $windowMode,   // recent = 昨天零点→现在；day = 指定整天
    'window_start' => $windowStart,  // 实际查询窗口左边界（含）
    'window_end'   => $windowEnd,    // 实际查询窗口右边界（不含）
    'list'         => $list,
]);

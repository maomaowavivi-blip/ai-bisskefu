#!/usr/bin/env php
<?php
/**
 * scripts/backfill_session_order_links.php
 *
 * v3.30 — 回填 session_order_links（幂等：跑两遍,第二遍新增 0）
 * 用法：php scripts/backfill_session_order_links.php
 *
 * 三源互补(蓝图 §3.4)：
 *   源1 order_context_cache(order_no != '')   —— 覆盖成功查单,room_no=room_list
 *   源2 room_query_sessions(order_no != '')   —— 覆盖房间流,room_no=candidates 里 baoyu_room_no 逗号拼
 *   源3 chat_logs(intent='ORDER_QUERY')       —— 从 slots.original_message 提取订单号,
 *                                                覆盖 cache 写失败 / 房卡发放但 Pipeline 没成的场景,room_no 留空
 *
 * 全部走 linkSessionOrder()；已存在的 (session_id, order_no) 只计数跳过、不再写,
 * 保证先写入的 room_no 不被后一源的留空值覆盖。
 */

$root = dirname(__DIR__);
require $root . '/api/config.php';
require $root . '/api/helpers/session_order_link.php';
require $root . '/api/helpers/order_candidate.php';

$db = getDB();

$new  = 0;
$skip = 0;

/** channel 由 session_id 前缀映射(蓝图 §3.4) */
function backfillChannelFromSession(string $sessionId): string
{
    if (str_starts_with($sessionId, 'wecom_kf_')) return 'wechat_kf';
    if (str_starts_with($sessionId, 'openapi_')) return 'api';
    return 'web';
}

/** 已存在的绑定只跳过不重写,避免空 room_no 覆盖已有值 */
function backfillLink(PDO $db, string $sessionId, string $orderNo, string $roomNo, string $channel, string $source, int &$new, int &$skip): void
{
    $sessionId = trim($sessionId);
    $orderNo   = trim($orderNo);
    if ($sessionId === '' || $orderNo === '') {
        return;
    }
    $st = $db->prepare('SELECT 1 FROM session_order_links WHERE session_id = ? AND order_no = ? LIMIT 1');
    $st->execute([$sessionId, $orderNo]);
    if ($st->fetchColumn()) {
        $skip++;
        return;
    }
    linkSessionOrder($db, $sessionId, $orderNo, $roomNo, $channel, $source);
    $new++;
}

// ── 源1: order_context_cache ──
$rows = $db->query("SELECT session_id, order_no, room_list FROM order_context_cache WHERE order_no != ''")
           ->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    backfillLink(
        $db,
        (string)$r['session_id'],
        (string)$r['order_no'],
        (string)($r['room_list'] ?? ''),
        backfillChannelFromSession((string)$r['session_id']),
        'backfill',
        $new,
        $skip
    );
}
echo "[source1 order_context_cache] scanned=" . count($rows) . "\n";

// ── 源2: room_query_sessions ──
$rows = $db->query("SELECT session_id, order_no, room_candidates FROM room_query_sessions WHERE order_no != ''")
           ->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $roomNo = '';
    $json   = (string)($r['room_candidates'] ?? '');
    if ($json !== '') {
        $cands = json_decode($json, true);
        if (is_array($cands)) {
            $roomNos = [];
            foreach ($cands as $c) {
                if (!is_array($c)) continue;
                $bn = trim((string)($c['baoyu_room_no'] ?? ''));
                if ($bn !== '' && !in_array($bn, $roomNos, true)) {
                    $roomNos[] = $bn;
                }
            }
            $roomNo = implode(',', $roomNos);
        }
    }
    backfillLink(
        $db,
        (string)$r['session_id'],
        (string)$r['order_no'],
        $roomNo,
        backfillChannelFromSession((string)$r['session_id']),
        'backfill',
        $new,
        $skip
    );
}
echo "[source2 room_query_sessions] scanned=" . count($rows) . "\n";

// ── 源3: chat_logs intent=ORDER_QUERY,从 slots.original_message 提取订单号 ──
$rows = $db->query(
    "SELECT session_id, channel, JSON_UNQUOTE(JSON_EXTRACT(slots, '\$.original_message')) AS om
       FROM chat_logs
      WHERE intent IN ('ORDER_QUERY') AND slots IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $orderNo = extractOrderCandidate((string)($r['om'] ?? ''));
    if ($orderNo === '') continue;
    backfillLink(
        $db,
        (string)$r['session_id'],
        $orderNo,
        '', // 该源无房间信息,留空
        (string)($r['channel'] ?? ''), // channel 用行自身值(蓝图)
        'backfill',
        $new,
        $skip
    );
}
echo "[source3 chat_logs ORDER_QUERY] scanned=" . count($rows) . "\n";

echo "新增 $new / 跳过 $skip\n";

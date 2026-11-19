<?php
/**
 * api/helpers/session_order_link.php
 *
 * v3.30 — 会话↔订单绑定 helper（写 session_order_links，append-only）
 *
 * 调用方全部在主链路（订单查询 / 房间流 / 房卡发放），
 * 因此整个函数 try/catch：任何异常只 error_log 绝不上抛，
 * 最坏丢一条绑定记录，绝不能炸客户对话回复。
 *
 * 去重：uk_session_order (session_id, order_no)，重复写无害（刷新 room_no/source）。
 */

declare(strict_types=1);

if (!function_exists('linkSessionOrder')) {
    /**
     * 写入(或刷新)一条会话↔订单绑定。
     *
     * @param \PDO   $db        数据库连接
     * @param string $sessionId 会话 ID
     * @param string $orderNo   订单号(中台 channel_order_id)
     * @param string $roomNo    逗号分隔房号(来自订单 room_ids)，拿不到传空串
     * @param string $channel   渠道(web / wechat_kf / api ...)
     * @param string $source    来源(order_query|room_flow|roomcard|backfill)
     */
    function linkSessionOrder(
        \PDO $db,
        string $sessionId,
        string $orderNo,
        string $roomNo,
        string $channel,
        string $source
    ): void {
        try {
            $sessionId = substr(trim($sessionId), 0, 64);
            $orderNo   = substr(trim($orderNo), 0, 64);
            if ($sessionId === '' || $orderNo === '') {
                return;
            }
            $roomNo  = substr($roomNo, 0, 100);
            $channel = substr($channel, 0, 20);
            $source  = substr($source, 0, 20);

            $stmt = $db->prepare(
                'INSERT INTO session_order_links (session_id, channel, order_no, room_no, source)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   room_no = IF(VALUES(room_no) = \'\' AND room_no <> \'\', room_no, VALUES(room_no)),
                   source = VALUES(source)'
            );
            $stmt->execute([$sessionId, $channel, $orderNo, $roomNo, $source]);
        } catch (\Throwable $e) {
            // 日志不落订单号/会话原文,只落哈希,避免 PII
            error_log('[session_order_link] write failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' | session_hash=' . substr(sha1($sessionId), 0, 10)
                . ' | order_hash=' . substr(sha1($orderNo), 0, 10)
                . ' | source=' . $source);
        }
    }
}

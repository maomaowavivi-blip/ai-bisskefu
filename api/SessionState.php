<?php
/**
 * api/SessionState.php
 *
 * v3.3 PR1 — 三表合一视图（仅 load，不写）
 *
 * 设计原则（修正 7）：
 * - SessionState 不合并写，仅 load 视图
 * - 写仍走 RoomQueryFlow/HandoffTriggers 原函数，避免重构爆炸
 * - 让 Classifier 和 Workflow 有一个统一的会话状态入口
 *
 * 涉及表（不改结构）：
 * - room_query_sessions（step / order_no / sidecar_room_id / bound_at / expires_at）
 * - order_context_cache（order_no / room_id / room_list / expires_at）
 * - human_handoffs（status / assigned_to）
 */

declare(strict_types=1);

require_once __DIR__ . '/Intent.php';

final class SessionState
{
    /**
     * 加载完整会话状态
     *
     * @param \PDO $db
     * @param string $sessionId
     * @return array {
     *   room_session: ?array,
     *   order_cache: ?array,
     *   handoff: ?array,
     *   meta: array
     * }
     */
    public static function load(\PDO $db, string $sessionId): array
    {
        return [
            'room_session' => self::loadRoomSession($db, $sessionId),
            'order_cache'  => self::loadOrderCache($db, $sessionId),
            'handoff'      => self::loadHandoff($db, $sessionId),
            'meta'         => self::loadMeta($db, $sessionId),
        ];
    }

    /**
     * 房间流状态（room_query_sessions）
     *
     * @return ?array {
     *   step: int,
     *   order_no: string,
     *   sidecar_room_id: int,
     *   room_candidates: array,
     *   bound_at: ?string,
     *   expires_at: ?string
     * }
     */
    private static function loadRoomSession(\PDO $db, string $sessionId): ?array
    {
        try {
            $stmt = $db->prepare(
                'SELECT step, order_no, sidecar_room_id, room_candidates, bound_at, expires_at
                 FROM room_query_sessions
                 WHERE session_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch();
            if (!$row) return null;

            return [
                'step' => (int)($row['step'] ?? 0),
                'order_no' => trim($row['order_no'] ?? ''),
                'sidecar_room_id' => (int)($row['sidecar_room_id'] ?? 0),
                'room_candidates' => json_decode($row['room_candidates'] ?? '[]', true) ?: [],
                'bound_at' => $row['bound_at'] ?? null,
                'expires_at' => $row['expires_at'] ?? null,
            ];
        } catch (\Exception $e) {
            error_log('[SessionState] loadRoomSession failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 订单缓存（order_context_cache）
     * 修正 23：明确 SELECT 字段，不返 raw *
     *
     * @return ?array {
     *   order_no: string,
     *   room_id: int,
     *   room_list: array
     * }
     */
    private static function loadOrderCache(\PDO $db, string $sessionId): ?array
    {
        try {
            $stmt = $db->prepare(
                'SELECT order_no, room_id, room_list
                 FROM order_context_cache
                 WHERE session_id = ? AND expires_at > NOW()
                 LIMIT 1'
            );
            $stmt->execute([$sessionId]);
            $cache = $stmt->fetch();
            if (!$cache) return null;

            return [
                'order_no'  => trim($cache['order_no'] ?? ''),
                'room_id'   => (int)($cache['room_id'] ?? 0),
                'room_list' => json_decode($cache['room_list'] ?? '[]', true) ?: [],
            ];
        } catch (\Exception $e) {
            error_log('[SessionState] loadOrderCache failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 人工接管状态（human_handoffs）
     * 只返回当前活跃的（status != ended）
     *
     * @return ?array {
     *   id: int,
     *   status: string,
     *   assigned_to: ?string,
     *   created_at: string
     * }
     */
    private static function loadHandoff(\PDO $db, string $sessionId): ?array
    {
        // v3.15：人工接管已停用，不能访问已下线的历史表。
        return null;
    }

    /**
     * 会话元信息（首末次消息时间、消息总数）
     */
    private static function loadMeta(\PDO $db, string $sessionId): array
    {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS msg_count,
                        MIN(created_at) AS first_at,
                        MAX(created_at) AS last_at
                 FROM chat_logs
                 WHERE session_id = ?'
            );
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch();
            return [
                'msg_count' => (int)($row['msg_count'] ?? 0),
                'first_at'  => $row['first_at'] ?? null,
                'last_at'   => $row['last_at'] ?? null,
            ];
        } catch (\Exception $e) {
            return ['msg_count' => 0, 'first_at' => null, 'last_at' => null];
        }
    }
}

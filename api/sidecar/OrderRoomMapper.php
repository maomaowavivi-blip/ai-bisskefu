<?php
/**
 * api/sidecar/OrderRoomMapper.php
 *
 * v3.27 — 订单 → 房间映射，数据源改为数据中台
 *
 * 链路（中台口径，已闭环验证）：
 *   订单.room_ids（逗号分隔，可多房） = 中台 baoyu_room_no
 *   → 中台 s4e3a9 房源 → 房源 id
 *
 * 原则（苏鸣 2026-10-22）：中台写什么就输出什么，不推断、不修正。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/CenterRoom.php';

class OrderRoomMapper {
    /**
     * 把订单映射为中台房源候选
     *
     * @param array $orderData 订单记录（含 room_ids）
     * @return array[] 去重后的候选（room_id = 中台房源 id）
     */
    public static function mapOrderData(array $orderData): array {
        $roomIds = trim(strval($orderData['room_ids'] ?? ''));
        if ($roomIds === '') {
            return [];
        }

        try {
            $db = getDB();
        } catch (Throwable $e) {
            error_log('[OrderRoomMapper] db: ' . $e->getMessage());
            return [];
        }

        $candidates = [];
        $seen = [];

        foreach (explode(',', $roomIds) as $rid) {
            $rid = trim($rid);
            if ($rid === '') continue;

            try {
                $room = CenterRoom::loadByBaoyuRoomNo($db, $rid);
            } catch (Throwable $e) {
                error_log('[OrderRoomMapper] CenterRoom lookup failed: ' . $e->getMessage());
                continue;
            }
            if (!$room) continue;

            $roomId = intval($room['id'] ?? 0);
            if ($roomId <= 0) continue;
            if (isset($seen[$roomId])) continue;
            $seen[$roomId] = true;

            $candidates[] = [
                'room_id'       => $roomId,
                'baoyu_room_no' => $rid,
                'room_code'     => trim((string)($room['room_code'] ?? '')),
                'display_name'  => CenterRoom::displayName($room),
                'title'         => CenterRoom::displayName($room),
                'description'   => CenterRoom::addressText($room),
                'confidence'    => 'high',
                'room_index'    => count($candidates) + 1,
            ];
        }

        return $candidates;
    }
}

<?php

require_once dirname(__DIR__) . '/SidecarConfig.php';
require_once dirname(__DIR__) . '/CenterApi.php';

class OrderRoomMapper {
    /**
     * 将 PMS order_info 映射为 Sidecar 房源候选
     *
     * @return array|null sidecar_room_id, room_code, display_name, title, description, confidence
     */
    public static function mapOrderEntry(PDO $db, array $orderInfo): ?array {
        $roomText = trim((string)($orderInfo['room'] ?? ''));
        $roomId = trim((string)($orderInfo['room_id'] ?? ''));
        $baoyuId = trim((string)($orderInfo['baoyu_room_id'] ?? ''));

        if ($roomId !== '') {
            $hit = self::resolveByIdentifier($db, $roomId);
            if ($hit) return self::enrichCandidate($db, $hit, 'high');
        }

        if ($baoyuId !== '') {
            $hit = self::resolveByIdentifier($db, $baoyuId);
            if ($hit) return self::enrichCandidate($db, $hit, 'high');
        }

        if ($roomText !== '') {
            if (preg_match('/(\d{3,4})/u', $roomText, $m)) {
                $hit = self::resolveByIdentifier($db, $m[1]);
                if ($hit) return self::enrichCandidate($db, $hit, 'high');
            }

            $hit = self::resolveByIdentifier($db, $roomText);
            if ($hit) return self::enrichCandidate($db, $hit, 'medium');

            $fuzzy = self::fuzzyMatch($db, $roomText);
            if ($fuzzy) return $fuzzy;
        }

        return null;
    }

    /**
     * @param array $orderData 订单数据
     *   v3.26 新结构（数据中台）：单个订单记录，含 room_ids（逗号分隔的 baoyu_room_no）
     *   旧结构（自建网关）：订单条目数组，每项含 order_info
     * @return array[] 去重后的候选（含 sidecar_room_id）
     */
    public static function mapOrderData(array $orderData): array {
        try {
            $db = getSidecarDB();
        } catch (Exception $e) {
            error_log('OrderRoomMapper db: ' . $e->getMessage());
            return [];
        }

        $candidates = [];
        $seen = [];

        // v3.26 新结构：room_ids 逗号分隔的 baoyu_room_no（一单多房）
        $roomIds = trim(strval($orderData['room_ids'] ?? ''));
        if ($roomIds !== '') {
            foreach (explode(',', $roomIds) as $rid) {
                $rid = trim($rid);
                if ($rid === '') continue;
                $mapped = self::mapBaoyuRoomNo($db, $rid);
                if (!$mapped || empty($mapped['sidecar_room_id'])) continue;
                $id = (int)$mapped['sidecar_room_id'];
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                $mapped['room_index'] = count($candidates) + 1;
                $candidates[] = $mapped;
            }
            if (!empty($candidates)) {
                return $candidates;
            }
        }

        // 旧结构：逐条 order_info
        foreach ((array)$orderData as $entry) {
            if (!is_array($entry)) continue;
            $info = $entry['order_info'] ?? $entry;
            if (!is_array($info)) continue;
            $mapped = self::mapOrderEntry($db, $info);
            if (!$mapped || empty($mapped['sidecar_room_id'])) continue;
            $id = (int)$mapped['sidecar_room_id'];
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $mapped['room_index'] = count($candidates) + 1;
            $candidates[] = $mapped;
        }
        return $candidates;
    }

    /**
     * v3.26:baoyu_room_no → sidecar 房源候选
     *
     * 链路（已实测闭环）：
     *   订单.room_ids = 中台 baoyu_room_no = sidecar ai_room_profile.baoyu_room_id
     *   中台房源.id = sidecar ai_room_profile.sujia_room_id
     *
     * 1) 先查 sidecar ai_room_profile.baoyu_room_id（650/921 命中，纯 DB，无需外呼）
     * 2) 未命中则调数据中台房源接口换 id，再按 sujia_room_id 精确查
     */
    private static function mapBaoyuRoomNo(PDO $db, string $baoyuRoomNo): ?array {
        $hit = self::resolveByIdentifier($db, $baoyuRoomNo);
        if ($hit) return self::enrichCandidate($db, $hit, 'high');

        // fallback：数据中台房源接口 → 房源.id → sujia_room_id
        try {
            $room = CenterApi::roomByBaoyuRoomNo(getDB(), $baoyuRoomNo);
        } catch (Throwable $e) {
            error_log('[OrderRoomMapper] CenterApi room lookup failed: ' . $e->getMessage());
            return null;
        }
        if (!$room || empty($room['id'])) return null;

        // 注意：不能用 resolveByIdentifier —— 它是 OR 宽匹配，
        // 实测 sidecar 有 577 行存在 id 与他人 sujia_room_id 撞号（如 id=1 的 sujia_room_id=127，
        // 而 id=127 是另一间房），按 id 查会匹配到错误房源。
        $hit = self::resolveBySujiaRoomId($db, (string)$room['id']);
        if (!$hit) return null;
        return self::enrichCandidate($db, $hit, 'medium');
    }

    /** 只按 sujia_room_id 精确查（= 数据中台房源.id），避开 id 撞号 */
    private static function resolveBySujiaRoomId(PDO $db, string $sujiaRoomId): ?array {
        $id = trim($sujiaRoomId);
        if ($id === '') return null;
        $stmt = $db->prepare('SELECT * FROM ai_room_profile WHERE CAST(sujia_room_id AS CHAR) = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function resolveByIdentifier(PDO $db, string $identifier): ?array {
        $id = trim($identifier);
        if ($id === '') return null;

        $stmt = $db->prepare('SELECT * FROM ai_room_profile WHERE room_code = ? OR baoyu_room_id = ? OR CAST(sujia_room_id AS CHAR) = ? OR CAST(id AS CHAR) = ? LIMIT 1');
        $stmt->execute([$id, $id, $id, $id]);
        $row = $stmt->fetch();
        if ($row) return $row;

        $stmt = $db->prepare('SELECT p.* FROM ai_room_profile p INNER JOIN ai_room_identifier_map m ON m.ai_room_id = p.id WHERE m.id_value = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function fuzzyMatch(PDO $db, string $roomText): ?array {
        $needle = mb_strtolower(preg_replace('/\s+/u', '', $roomText));
        if ($needle === '') return null;

        $rows = $db->query("SELECT p.*, l.toponym, l.address FROM ai_room_profile p LEFT JOIN ai_room_location l ON l.ai_room_id = p.id WHERE p.data_status != 'disabled'")->fetchAll() ?: [];
        $best = null;
        $bestScore = 0.0;

        foreach ($rows as $row) {
            $hay = mb_strtolower(trim(
                ($row['short_name'] ?? '') . ($row['room_code'] ?? '') . ($row['toponym'] ?? '') . ($row['address'] ?? '')
            ));
            if ($hay === '') continue;

            similar_text($needle, $hay, $pct);
            $score = $pct / 100.0;

            if (mb_strlen($needle) >= 2) {
                foreach (['万象城', '星宿', '永凯', '春晖', '西乡塘'] as $token) {
                    if (mb_strpos($roomText, $token) !== false && mb_strpos($hay, mb_strtolower($token)) !== false) {
                        $score = max($score, 0.65);
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if ($best && $bestScore >= 0.6) {
            $conf = $bestScore >= 0.85 ? 'high' : 'medium';
            return self::enrichCandidate($db, $best, $conf);
        }
        return null;
    }

    private static function enrichCandidate(PDO $db, array $profile, string $confidence): array {
        $aiRoomId = (int)$profile['id'];
        $roomCode = trim((string)($profile['room_code'] ?? ''));
        $shortName = trim((string)($profile['short_name'] ?? ''));

        $loc = null;
        try {
            $stmt = $db->prepare('SELECT toponym, address FROM ai_room_location WHERE ai_room_id = ? LIMIT 1');
            $stmt->execute([$aiRoomId]);
            $loc = $stmt->fetch() ?: null;
        } catch (Exception $e) {}

        $displayName = trim($shortName . ($roomCode !== '' ? $roomCode : ''));
        if ($displayName === '') $displayName = $roomCode ?: ('房间' . $aiRoomId);

        $title = $displayName;
        if ($roomCode !== '' && mb_strpos($title, $roomCode) === false) {
            $title = trim($shortName . ' ' . $roomCode);
        }

        $descParts = array_filter([
            trim((string)($loc['toponym'] ?? $profile['toponym'] ?? '')),
            trim((string)($loc['address'] ?? $profile['address'] ?? '')),
        ]);
        $description = implode(' · ', $descParts);

        return [
            'sidecar_room_id' => $aiRoomId,
            'room_code' => $roomCode,
            'display_name' => $displayName,
            'title' => $title,
            'description' => $description,
            'confidence' => $confidence,
        ];
    }
}

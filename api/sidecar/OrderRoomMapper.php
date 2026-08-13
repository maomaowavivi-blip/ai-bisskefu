<?php

require_once dirname(__DIR__) . '/SidecarConfig.php';

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
     * @param array $orderData callGateway query_order 返回
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
        foreach ((array)$orderData as $entry) {
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

<?php

require_once dirname(__DIR__) . '/SidecarConfig.php';

class ChunkBuilder {
    public static function plainText($html) {
        if ($html === null || $html === '') return '';
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/p\s*>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    public static function stats(PDO $db): array {
        $chunks = (int)$db->query('SELECT COUNT(*) FROM ai_knowledge_chunk')->fetchColumn();
        $pending = (int)$db->query("SELECT COUNT(*) FROM ai_knowledge_chunk WHERE embedding_status = 'pending'")->fetchColumn();
        $done = (int)$db->query("SELECT COUNT(*) FROM ai_knowledge_chunk WHERE embedding_status = 'done'")->fetchColumn();
        $rooms = (int)$db->query('SELECT COUNT(*) FROM ai_room_profile WHERE data_status != "disabled"')->fetchColumn();
        return compact('chunks', 'pending', 'done', 'rooms');
    }

    public static function rebuildAll(PDO $db, ?int $roomId = null): array {
        self::ensureSchema($db);
        $statuses = SidecarConfig::chunkEligibleStatuses();
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT id, room_code, short_name FROM ai_room_profile WHERE data_status IN ($in)";
        $params = $statuses;
        if ($roomId) {
            $sql .= ' AND id = ?';
            $params[] = $roomId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll();
        $count = 0;
        foreach ($rooms as $room) {
            $count += self::rebuildRoom($db, (int)$room['id'], $room);
        }
        return ['rooms' => count($rooms), 'chunks' => $count];
    }

    public static function rebuildRoom(PDO $db, int $aiRoomId, ?array $profile = null): int {
        if (!$profile) {
            $stmt = $db->prepare('SELECT id, room_code, short_name FROM ai_room_profile WHERE id = ?');
            $stmt->execute([$aiRoomId]);
            $profile = $stmt->fetch();
        }
        if (!$profile) return 0;

        $label = trim(($profile['short_name'] ?: '') . ' ' . ($profile['room_code'] ?: ''));
        $n = 0;

        $loc = self::fetchOne($db, 'SELECT * FROM ai_room_location WHERE ai_room_id = ?', [$aiRoomId]);
        if ($loc) {
            $text = trim(($loc['toponym'] ?? '') . "\n" . ($loc['address'] ?? '') . "\n" . ($loc['building_name'] ?? '') . "\n" . ($loc['location_note'] ?? ''));
            if ($text !== '') {
                $n += self::upsertChunk($db, $aiRoomId, 'location', (int)($loc['id'] ?? 0), '地址与位置', $label, $text, SidecarConfig::PERM_PUBLIC);
            }
        }

        $access = self::fetchOne($db, 'SELECT * FROM ai_room_access WHERE ai_room_id = ?', [$aiRoomId]);
        if ($access) {
            $parts = [];
            foreach (['wifi_name' => 'WiFi名称', 'wifi_key' => 'WiFi密码', 'door_key' => '门禁密码', 'public_door_password' => '公共门密码', 'key_code' => '密码锁编号'] as $k => $t) {
                if (!empty($access[$k])) $parts[] = $t . '：' . $access[$k];
            }
            if ($parts) {
                $n += self::upsertChunk($db, $aiRoomId, 'access', (int)($access['id'] ?? 0), 'WiFi与门禁', $label, implode("\n", $parts), SidecarConfig::PERM_GUEST);
            }
        }

        $park = self::fetchOne($db, 'SELECT * FROM ai_room_parking WHERE ai_room_id = ? ORDER BY id LIMIT 1', [$aiRoomId]);
        if ($park) {
            $text = trim(($park['parking_lot_name'] ?? '') . "\n" . ($park['address_park'] ?? '') . "\n" . ($park['parking_lot_navigation_text'] ?? '') . "\n" . ($park['parking_fee_note'] ?? '') . "\n" . ($park['parking_rule_note'] ?? ''));
            if ($text !== '') {
                $n += self::upsertChunk($db, $aiRoomId, 'parking', (int)($park['id'] ?? 0), '停车信息', $label, $text, SidecarConfig::PERM_PUBLIC);
            }
        }

        $guides = self::fetchAll($db, 'SELECT * FROM ai_room_guide WHERE ai_room_id = ?', [$aiRoomId]);
        foreach ($guides as $g) {
            $text = trim($g['content_text'] ?? '') ?: self::plainText($g['content_html'] ?? '');
            if ($text === '') continue;
            $type = 'guide_' . ($g['guide_type'] ?? 'other');
            $title = $g['title'] ?? $g['guide_type'];
            $n += self::upsertChunk($db, $aiRoomId, $type, (int)$g['id'], $title, $label, $text, SidecarConfig::PERM_PUBLIC);
        }

        $devices = self::fetchAll($db, 'SELECT * FROM ai_room_device_guide WHERE ai_room_id = ?', [$aiRoomId]);
        foreach ($devices as $d) {
            $text = trim(($d['device_type'] ?? '') . "\n位置：" . ($d['device_location'] ?? '') . "\n" . ($d['usage_steps'] ?? '') . "\n" . ($d['troubleshooting'] ?? ''));
            if (trim($text) === '') continue;
            $n += self::upsertChunk($db, $aiRoomId, 'device', (int)$d['id'], $d['device_type'] ?? '设备说明', $label, $text, SidecarConfig::PERM_PUBLIC);
        }

        return $n;
    }

    private static function upsertChunk(PDO $db, int $aiRoomId, string $sourceType, int $sourceId, string $title, string $roomLabel, string $text, string $perm): int {
        $chunkText = "[房间:{$roomLabel}]\n[类型:{$title}]\n" . $text;
        $existing = self::fetchOne(
            $db,
            'SELECT id FROM ai_knowledge_chunk WHERE ai_room_id = ? AND source_type = ? AND source_id = ? LIMIT 1',
            [$aiRoomId, $sourceType, $sourceId]
        );
        if ($existing) {
            $db->prepare('UPDATE ai_knowledge_chunk SET chunk_title = ?, chunk_text = ?, permission_level = ?, embedding_status = "pending", updated_at = NOW() WHERE id = ?')
                ->execute([$title, $chunkText, $perm, $existing['id']]);
            return 1;
        }
        $db->prepare('INSERT INTO ai_knowledge_chunk (ai_room_id, source_type, source_id, chunk_title, chunk_text, permission_level, embedding_status) VALUES (?,?,?,?,?,?,"pending")')
            ->execute([$aiRoomId, $sourceType, $sourceId, $title, $chunkText, $perm]);
        return 1;
    }

    private static function ensureSchema(PDO $db): void {
        try {
            $db->exec("ALTER TABLE ai_knowledge_chunk ADD COLUMN embedding_vector JSON NULL AFTER chunk_text");
        } catch (Exception $e) {}
        try {
            $db->exec("ALTER TABLE ai_knowledge_chunk ADD COLUMN embedding_model VARCHAR(50) NULL AFTER embedding_vector");
        } catch (Exception $e) {}
        try {
            $db->exec("ALTER TABLE ai_knowledge_chunk ADD COLUMN embedding_updated_at DATETIME NULL AFTER embedding_model");
        } catch (Exception $e) {}
    }

    private static function fetchOne(PDO $db, string $sql, array $params) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function fetchAll(PDO $db, string $sql, array $params): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }
}

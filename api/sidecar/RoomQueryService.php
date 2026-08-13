<?php

require_once dirname(__DIR__) . '/SidecarConfig.php';
require_once __DIR__ . '/SidecarSearch.php';
require_once __DIR__ . '/ChunkBuilder.php';
require_once __DIR__ . '/SidecarIntent.php';

class RoomQueryService {
    public static function queryBySidecarId(int $aiRoomId, string $question, bool $isVerified, string $sessionId = ''): ?array {
        if ($aiRoomId <= 0) {
            return ['_not_found' => true, 'msg' => '未找到房间信息'];
        }
        try {
            $db = getSidecarDB();
        } catch (Exception $e) {
            error_log('sidecar db: ' . $e->getMessage());
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM ai_room_profile WHERE id = ? LIMIT 1');
        $stmt->execute([$aiRoomId]);
        $room = $stmt->fetch();
        if (!$room) {
            return ['_not_found' => true, 'msg' => '未找到房间信息'];
        }
        return self::queryResolved($db, $room, $question, $isVerified, $sessionId);
    }

    public static function query($roomIdentifier, string $question, bool $isVerified, string $sessionId = ''): ?array {
        try {
            $db = getSidecarDB();
        } catch (Exception $e) {
            error_log('sidecar db: ' . $e->getMessage());
            return null;
        }

        $room = self::resolveRoom($db, (string)$roomIdentifier);
        if (!$room) {
            return ['_not_found' => true, 'msg' => '未找到房间信息'];
        }

        return self::queryResolved($db, $room, $question, $isVerified, $sessionId);
    }

    private static function queryResolved(PDO $db, array $room, string $question, bool $isVerified, string $sessionId): ?array {
        $aiRoomId = (int)$room['id'];
        $q = trim($question);

        if (SidecarIntent::isYunfangkaCredentialQuery($q)) {
            self::logQuery($db, $sessionId, $aiRoomId, $q, 'yunfangka', SidecarConfig::PERM_GUEST);
            return ['reply' => SidecarIntent::yunfangkaCredentialReply(), 'images' => []];
        }

        if (self::matchKw($q, SidecarIntent::addressKeywords())) {
            $loc = self::fetchOne($db, 'SELECT toponym, address, building_name, location_note FROM ai_room_location WHERE ai_room_id = ?', [$aiRoomId]);
            if ($loc) {
                $text = trim(($loc['toponym'] ?? '') . ' ' . ($loc['address'] ?? '') . ' ' . ($loc['location_note'] ?? ''));
                if ($text !== '') {
                    self::logQuery($db, $sessionId, $aiRoomId, $q, 'location', SidecarConfig::PERM_PUBLIC);
                    return ['reply' => $text, 'images' => self::mediaFor($db, $aiRoomId, 'guide_traffic')];
                }
            }
        }

        if (self::matchKw($q, SidecarIntent::parkingKeywords())) {
            $parking = self::answerParking($db, $aiRoomId, $q, $isVerified, $sessionId);
            if ($parking) {
                return $parking;
            }
        }

        if (self::matchKw($q, SidecarIntent::tipsKeywords())) {
            $tips = self::answerTips($db, $aiRoomId, $q, $isVerified, $sessionId);
            if ($tips) {
                return $tips;
            }
        }

        if (SidecarIntent::isDeviceQuery($q)) {
            return self::answerDevice($db, $aiRoomId, $q, $isVerified, $sessionId);
        }

        $hits = SidecarSearch::search($db, $aiRoomId, $q, $isVerified, 3);
        if (empty($hits)) {
            return ['_not_found' => true, 'msg' => '该房间暂无相关设施信息'];
        }

        $parts = [];
        $sources = [];
        foreach ($hits as $h) {
            $body = self::extractBody($h['chunk_text']);
            if ($body !== '' && !in_array($body, $parts, true)) {
                $parts[] = $body;
                $sources[] = $h['source_type'] ?? '';
            }
        }
        if (empty($parts)) {
            return ['_not_found' => true, 'msg' => '该房间暂无相关设施信息'];
        }

        self::logQuery($db, $sessionId, $aiRoomId, $q, implode(',', $sources), SidecarConfig::PERM_PUBLIC);
        $reply = mb_strlen(implode("\n\n", $parts)) > 400 ? $parts[0] : implode("\n\n", array_slice($parts, 0, 2));
        $imgType = strpos(implode(',', $sources), 'device') !== false ? 'device' : 'guide_checkin';
        return ['reply' => $reply, 'images' => self::mediaFor($db, $aiRoomId, $imgType)];
    }

    private static function answerParking(PDO $db, int $aiRoomId, string $q, bool $isVerified, string $sessionId): ?array {
        $park = self::fetchOne($db, 'SELECT parking_lot_name, address_park, parking_fee_note, parking_rule_note, parking_lot_navigation_text FROM ai_room_parking WHERE ai_room_id = ? LIMIT 1', [$aiRoomId]);
        if (!$park) {
            return null;
        }

        if (self::isParkingRouteQuery($q)) {
            $reply = self::formatParkingRoute($db, $aiRoomId, $q, $park, $isVerified);
        } elseif (self::isParkingFeeQuery($q)) {
            $reply = self::formatParkingFee($park);
        } else {
            // 「有停车场吗」及默认停车咨询：只答有无 + 位置 + 收费规则，不附带找房路线
            $reply = self::formatParkingSummary($park);
        }

        if ($reply === '') {
            return null;
        }

        self::logQuery($db, $sessionId, $aiRoomId, $q, 'parking', SidecarConfig::PERM_PUBLIC);
        return ['reply' => $reply, 'images' => self::mediaFor($db, $aiRoomId, 'guide_parking')];
    }

    /** 有没有停车场 / 能停车吗 */
    private static function isParkingExistenceQuery(string $q): bool {
        return (bool)preg_match('/(有.{0,4}停车|有没有停车|能否停车|可以停车|能不能停车)/u', $q);
    }

    /** 怎么停 / 停哪 / 入口 */
    private static function isParkingRouteQuery(string $q): bool {
        if (self::isParkingExistenceQuery($q)) {
            return false;
        }
        return (bool)preg_match('/(怎么停|如何停|停哪|车放|停车入口|停车场入口|开到哪里)/u', $q)
            || (mb_strpos($q, '怎么') !== false && mb_strpos($q, '停车') !== false);
    }

    /** 停车费 / 是否免费 */
    private static function isParkingFeeQuery(string $q): bool {
        return (bool)preg_match('/(停车费|收费|免费停|免费停车)/u', $q);
    }

    private static function formatParkingSummary(array $park): string {
        $nav = trim((string)($park['parking_lot_navigation_text'] ?? ''));
        $fee = trim((string)($park['parking_fee_note'] ?? ''));
        $rule = trim((string)($park['parking_rule_note'] ?? ''));

        $sentences = ['有停车场'];
        if ($nav !== '') {
            $sentences[] = '位于' . $nav;
        }
        $policy = array_filter([$fee, $rule]);
        if (!empty($policy)) {
            $sentences[] = implode('，', $policy);
        }
        return self::joinSentences($sentences);
    }

    private static function formatParkingFee(array $park): string {
        $fee = trim((string)($park['parking_fee_note'] ?? ''));
        $rule = trim((string)($park['parking_rule_note'] ?? ''));
        $policy = array_filter([$fee, $rule]);
        if (empty($policy)) {
            return self::formatParkingSummary($park);
        }
        return self::joinSentences($policy);
    }

    private static function formatParkingRoute(PDO $db, int $aiRoomId, string $q, array $park, bool $isVerified): string {
        $lines = [];
        $nav = trim((string)($park['parking_lot_navigation_text'] ?? ''));
        if ($nav !== '') {
            $lines[] = $nav;
        }

        $routeLines = self::fetchParkingRouteLines($db, $aiRoomId, $q, $isVerified);
        foreach ($routeLines as $line) {
            if (!in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }

        if (empty($lines)) {
            return self::formatParkingSummary($park);
        }
        return implode("\n", $lines);
    }

    /** 从交通指引中提取与停车相关的行，不含找房/电梯/房间号 */
    private static function fetchParkingRouteLines(PDO $db, int $aiRoomId, string $q, bool $isVerified): array {
        $hits = SidecarSearch::search($db, $aiRoomId, $q, $isVerified, 5);
        $lines = [];
        foreach ($hits as $h) {
            if (($h['source_type'] ?? '') !== 'guide_traffic') {
                continue;
            }
            $body = self::extractBody($h['chunk_text']);
            foreach (preg_split('/\r\n|\r|\n/u', $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (!self::isParkingRouteLine($line) && !self::isParkingEntryFollowLine($line, $lines)) {
                    continue;
                }
                if (!in_array($line, $lines, true)) {
                    $lines[] = $line;
                }
            }
        }
        return $lines;
    }

    private static function isParkingRouteLine(string $line): bool {
        if (mb_strpos($line, '停车') === false) {
            return false;
        }
        foreach (['电梯', '房间号', '芙德洛', '按房间', '几号房', '上楼'] as $skip) {
            if (mb_strpos($line, $skip) !== false) {
                return false;
            }
        }
        return true;
    }

    /** 紧接「停车场入口」的下一行（酒店入口说明），仍属停车路线 */
    private static function isParkingEntryFollowLine(string $line, array $existing): bool {
        if (empty($existing)) {
            return false;
        }
        $last = $existing[count($existing) - 1];
        if ($last !== '停车场入口') {
            return false;
        }
        return (bool)preg_match('/(酒店入口|停车场|开进|驶入)/u', $line);
    }

    private static function joinSentences(array $parts): string {
        $parts = array_values(array_filter(array_map('trim', $parts)));
        if (empty($parts)) {
            return '';
        }
        $text = implode('。', $parts);
        if (!preg_match('/[。！？]$/u', $text)) {
            $text .= '。';
        }
        return $text;
    }

    private static function answerDevice(PDO $db, int $aiRoomId, string $q, bool $isVerified, string $sessionId): array {
        $devices = self::fetchAllDevices($db, $aiRoomId);
        $targets = SidecarIntent::matchedDeviceKeywords($q);

        if (empty($devices)) {
            $label = !empty($targets) ? $targets[0] : '该设备';
            self::logQuery($db, $sessionId, $aiRoomId, $q, 'device', SidecarConfig::PERM_PUBLIC);
            return [
                'reply' => self::deviceMissingReply($label, $q),
                'images' => [],
            ];
        }

        if (empty($targets)) {
            $names = array_values(array_unique(array_filter(array_map(
                fn($d) => trim((string)($d['device_type'] ?? '')),
                $devices
            ))));
            if (count($names) === 1) {
                $reply = '该房间配有' . $names[0] . '。';
            } else {
                $reply = '该房间配有：' . implode('、', array_slice($names, 0, 6)) . '。';
            }
            self::logQuery($db, $sessionId, $aiRoomId, $q, 'device', SidecarConfig::PERM_PUBLIC);
            return ['reply' => $reply, 'images' => self::mediaFor($db, $aiRoomId, 'device')];
        }

        $matched = [];
        foreach ($devices as $device) {
            $type = trim((string)($device['device_type'] ?? ''));
            foreach ($targets as $target) {
                if ($type !== '' && (mb_stripos($type, $target) !== false || mb_stripos($target, $type) !== false)) {
                    $matched[] = $device;
                    break;
                }
            }
        }

        if (empty($matched)) {
            self::logQuery($db, $sessionId, $aiRoomId, $q, 'device', SidecarConfig::PERM_PUBLIC);
            return [
                'reply' => self::deviceMissingReply($targets[0], $q),
                'images' => [],
            ];
        }

        $device = $matched[0];
        $reply = self::formatDeviceAnswer($device, $q);
        self::logQuery($db, $sessionId, $aiRoomId, $q, 'device', SidecarConfig::PERM_PUBLIC);
        return ['reply' => $reply, 'images' => self::mediaFor($db, $aiRoomId, 'device')];
    }

    private static function fetchAllDevices(PDO $db, int $aiRoomId): array {
        $stmt = $db->prepare('SELECT device_type, device_location, usage_steps, troubleshooting FROM ai_room_device_guide WHERE ai_room_id = ? ORDER BY id');
        $stmt->execute([$aiRoomId]);
        return $stmt->fetchAll() ?: [];
    }

    private static function formatDeviceAnswer(array $device, string $q): string {
        $type = trim((string)($device['device_type'] ?? '设备'));
        $location = trim((string)($device['device_location'] ?? ''));
        $usage = trim((string)($device['usage_steps'] ?? ''));

        if (self::isDeviceUsageQuery($q)) {
            if ($usage !== '') {
                return self::compactDeviceText($usage, 280);
            }
            if ($location !== '') {
                return $type . '位于' . $location . '。';
            }
            return '该房间配有' . $type . '。';
        }

        if (self::isDeviceExistenceQuery($q)) {
            $sentences = ['有的，该房间配有' . $type];
            if ($location !== '') {
                $sentences[] = '位于' . $location;
            }
            return self::joinSentences($sentences);
        }

        $parts = [$type];
        if ($location !== '') {
            $parts[] = '位于' . $location;
        }
        if ($usage !== '') {
            $parts[] = self::compactDeviceText($usage, 160);
        }
        return self::joinSentences($parts);
    }

    private static function isDeviceExistenceQuery(string $q): bool {
        return (bool)preg_match('/(有|有没有|是否|能不能用|能用吗).{0,8}(洗衣机|空调|电视|冰箱|热水|微波炉|电磁炉|吹风机|设备|设施)/u', $q)
            || (bool)preg_match('/(洗衣机|空调|电视|冰箱|热水|微波炉|电磁炉|吹风机).{0,6}(吗|么|？|\?)/u', $q);
    }

    private static function isDeviceUsageQuery(string $q): bool {
        return (bool)preg_match('/(怎么用|如何使用|怎么开|如何使用|如何开|不会用|用法)/u', $q);
    }

    private static function compactDeviceText(string $text, int $maxLen): string {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        $sentences = preg_split('/(?<=[。！？])/u', $text);
        $sentences = array_values(array_filter(array_map('trim', $sentences)));
        if (empty($sentences)) {
            return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen) . '…' : $text;
        }
        $out = $sentences[0];
        if (mb_strlen($out) > $maxLen) {
            $out = mb_substr($out, 0, $maxLen) . '…';
        }
        return $out;
    }

    private static function deviceMissingReply(string $deviceLabel, string $q): string {
        if (self::isDeviceExistenceQuery($q)) {
            return '该房间资料未标注' . $deviceLabel . '，如需确认请联系前台。';
        }
        return '暂未查到该房间' . $deviceLabel . '的相关说明，请联系前台确认。';
    }

    private static function answerTips(PDO $db, int $aiRoomId, string $q, bool $isVerified, string $sessionId): ?array {
        $text = self::fetchTipsText($db, $aiRoomId);
        if ($text === '') {
            return null;
        }
        $reply = self::extractTipsAnswer($text, $q);
        if ($reply === '') {
            return null;
        }
        self::logQuery($db, $sessionId, $aiRoomId, $q, 'guide_tips', SidecarConfig::PERM_PUBLIC);
        return ['reply' => $reply, 'images' => self::mediaFor($db, $aiRoomId, 'guide_tips')];
    }

    private static function fetchTipsText(PDO $db, int $aiRoomId): string {
        $guide = self::fetchOne($db, 'SELECT content_text FROM ai_room_guide WHERE ai_room_id = ? AND guide_type = ? LIMIT 1', [$aiRoomId, 'tips']);
        if (!empty($guide['content_text'])) {
            return trim((string)$guide['content_text']);
        }
        $stmt = $db->prepare("SELECT chunk_text FROM ai_knowledge_chunk WHERE ai_room_id = ? AND source_type = 'guide_tips' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$aiRoomId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['chunk_text'])) {
            return self::extractBody((string)$row['chunk_text']);
        }
        return '';
    }

    private static function extractTipsAnswer(string $body, string $q): string {
        $body = preg_replace('/_{3,}/u', "\n", $body);
        $sections = preg_split('/(?=\d+\.\s*)/u', $body);
        $matched = [];
        foreach ($sections as $sec) {
            $sec = trim(preg_replace('/^\d+\.\s*/u', '', $sec));
            if ($sec === '' || mb_strpos($sec, '温馨提示') === 0) {
                continue;
            }
            if (self::tipsSectionMatchesQuery($sec, $q)) {
                $matched[] = self::compactTipsSection($sec, $q);
            }
        }
        if (empty($matched)) {
            return '';
        }
        return $matched[0];
    }

    private static function tipsSectionMatchesQuery(string $section, string $q): bool {
        $rules = [
            ['垃圾', ['垃圾']],
            ['保洁', ['保洁', '卫生', '清洁', '打扫']],
            ['退房', ['退房', '延迟']],
            ['一次性', ['一次性']],
            ['wifi', ['wifi', 'WiFi', '无线']],
        ];
        foreach ($rules as [$qKw, $secKws]) {
            if (mb_stripos($q, $qKw) === false) {
                continue;
            }
            foreach ($secKws as $sk) {
                if (mb_stripos($section, $sk) !== false) {
                    return true;
                }
            }
        }
        foreach (SidecarIntent::tipsKeywords() as $kw) {
            if (mb_strpos($q, $kw) !== false && mb_strpos($section, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function compactTipsSection(string $section, string $q = ''): string {
        $section = preg_replace('/\s+/u', ' ', trim($section));
        $sentences = preg_split('/(?<=[。！？])/u', $section);
        $sentences = array_values(array_filter(array_map('trim', $sentences)));

        if ($q !== '') {
            $focused = self::pickTipsSentences($sentences, $q);
            if (!empty($focused)) {
                return self::joinSentences($focused);
            }
        }

        if (count($sentences) === 1) {
            return self::joinSentences([$sentences[0]]);
        }
        return self::joinSentences([$sentences[0] ?? $section]);
    }

    private static function pickTipsSentences(array $sentences, string $q): array {
        $focusKws = [];
        $rules = [
            '垃圾' => ['垃圾'],
            '保洁' => ['保洁', '卫生', '清洁', '打扫', '一客一扫'],
            '退房' => ['退房', '延迟'],
            '一次性' => ['一次性'],
        ];
        foreach ($rules as $qKw => $kws) {
            if (mb_strpos($q, $qKw) !== false) {
                $focusKws = array_merge($focusKws, $kws);
            }
        }
        if (empty($focusKws)) {
            foreach (SidecarIntent::tipsKeywords() as $kw) {
                if (mb_strpos($q, $kw) !== false) {
                    $focusKws[] = $kw;
                }
            }
        }
        if (empty($focusKws)) {
            return [];
        }

        $picked = [];
        foreach ($sentences as $sentence) {
            foreach ($focusKws as $kw) {
                if (mb_strpos($sentence, $kw) !== false) {
                    $picked[] = $sentence;
                    break;
                }
            }
        }
        return array_slice($picked, 0, 2);
    }

    public static function resolveRoom(PDO $db, string $identifier): ?array {
        $id = trim($identifier);
        if ($id === '') {
            return null;
        }

        $stmt = $db->prepare('SELECT * FROM ai_room_profile WHERE room_code = ? OR baoyu_room_id = ? OR CAST(sujia_room_id AS CHAR) = ? OR CAST(id AS CHAR) = ? LIMIT 1');
        $stmt->execute([$id, $id, $id, $id]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        $stmt = $db->prepare('SELECT p.* FROM ai_room_profile p INNER JOIN ai_room_identifier_map m ON m.ai_room_id = p.id WHERE m.id_value = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function extractBody(string $chunk): string {
        $chunk = preg_replace('/^\[房间:[^\]]+\]\s*/u', '', $chunk);
        $chunk = preg_replace('/^\[类型:[^\]]+\]\s*/u', '', $chunk);
        return trim($chunk);
    }

    private static function matchKw(string $q, array $kws): bool {
        foreach ($kws as $kw) {
            if (mb_stripos($q, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function mediaFor(PDO $db, int $aiRoomId, string $relatedType): array {
        $like = '%' . $relatedType . '%';
        $stmt = $db->prepare('SELECT media_url, caption FROM ai_room_media WHERE ai_room_id = ? AND related_type LIKE ? ORDER BY sort, id LIMIT 3');
        $stmt->execute([$aiRoomId, $like]);
        $rows = $stmt->fetchAll() ?: [];
        $images = [];
        foreach ($rows as $r) {
            if (empty($r['media_url'])) {
                continue;
            }
            $images[] = [
                'url' => $r['media_url'],
                'title' => $r['caption'] ?: '查看图片',
                'link_url' => $r['media_url'],
            ];
        }
        return $images;
    }

    private static function logQuery(PDO $db, string $sessionId, int $aiRoomId, string $question, string $source, string $perm): void {
        try {
            $db->prepare('INSERT INTO ai_query_log (session_id, ai_room_id, question_text, matched_source, permission_level, answer_status) VALUES (?,?,?,?,?,?)')
                ->execute([$sessionId ?: null, $aiRoomId, mb_substr($question, 0, 500), mb_substr($source, 0, 100), $perm, 'success']);
        } catch (Exception $e) {}
    }

    private static function fetchOne(PDO $db, string $sql, array $params): ?array {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

<?php
/**
 * api/sidecar/RoomQueryService.php
 *
 * v3.27 — 房间查询：数据源从 sidecar 知识库切到数据中台
 *
 * 原则（苏鸣 2026-10-22）：**中台写什么我们就输出什么。中台写错也是中台的事。**
 *   - 不推断、不修正、不替中台做判断
 *   - 中台没填的字段 = 该房间没有这个东西
 *   - 只做展示层处理（HTML 去标签），不改中台的文字内容
 *
 * 变更要点：
 *   - 不再读 sidecar（ai_room_profile / ai_room_location / ai_room_parking /
 *     ai_room_device_guide / ai_room_guide / ai_room_media / ai_knowledge_chunk）
 *   - 不再调用 SidecarSearch 向量检索兜底
 *   - 房间身份 = 数据中台房源 id
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/CenterRoom.php';
require_once dirname(__DIR__) . '/SidecarConfig.php';
require_once __DIR__ . '/SidecarIntent.php';

class RoomQueryService
{
    /**
     * 按中台房源 id 查询
     */
    public static function queryByRoomId(int $centerRoomId, string $question, bool $isVerified, string $sessionId = ''): ?array
    {
        if ($centerRoomId <= 0) {
            return ['_not_found' => true, 'msg' => '未找到房间信息'];
        }
        try {
            $room = CenterRoom::loadById(getDB(), $centerRoomId);
        } catch (Throwable $e) {
            error_log('[RoomQueryService] CenterRoom::loadById failed: ' . $e->getMessage());
            return null;
        }
        if (!$room) {
            return ['_not_found' => true, 'msg' => '未找到房间信息'];
        }
        return self::queryResolved($room, $question, $isVerified, $sessionId);
    }

    /**
     * 按房间标识查询（baoyu_room_no 或中台房源 id）
     */
    public static function query($roomIdentifier, string $question, bool $isVerified, string $sessionId = ''): ?array
    {
        try {
            $room = self::resolveRoom(getDB(), (string)$roomIdentifier);
        } catch (Throwable $e) {
            error_log('[RoomQueryService] resolveRoom failed: ' . $e->getMessage());
            return null;
        }
        if (!$room) {
            return ['_not_found' => true, 'msg' => '未找到房间信息'];
        }
        return self::queryResolved($room, $question, $isVerified, $sessionId);
    }

    /**
     * 解析房间标识 → 中台房源记录
     * 纯数字按中台房源 id 查，其余按 baoyu_room_no 查
     */
    public static function resolveRoom(PDO $db, string $identifier): ?array
    {
        $id = trim($identifier);
        if ($id === '') return null;

        if (preg_match('/^\d+$/', $id)) {
            $room = CenterRoom::loadById($db, intval($id));
            if ($room) return $room;
        }
        return CenterRoom::loadByBaoyuRoomNo($db, $id);
    }

    /**
     * v3.27.1:判断问题是否属于「房间资料类」(地址/交通/停车/攻略/设备/凭证)。
     *
     * 用途:房间流 step=3 追问时,先判房间类再看通用 KB。
     * 否则「附近有地铁吗」会被 isGeneralKbQuestion() 的「附近」二字吞掉,
     * 明明中台 guide_parking_json 里写着地铁指引,却落兜底话术。
     */
    public static function isRoomSpecificQuery(string $question): bool
    {
        $q = trim($question);
        if ($q === '') return false;

        if (SidecarIntent::isYunfangkaCredentialQuery($q)) return true;
        if (self::matchKw($q, SidecarIntent::addressKeywords())) return true;
        if (self::matchKw($q, SidecarIntent::parkingKeywords())) return true;
        if (self::matchKw($q, SidecarIntent::tipsKeywords())) return true;
        if (SidecarIntent::isDeviceQuery($q)) return true;

        return false;
    }

    /**
     * @param array $room 中台房源记录
     */
    private static function queryResolved(array $room, string $question, bool $isVerified, string $sessionId): ?array
    {
        $q = trim($question);

        // 凭证类（WiFi/门锁密码/押金/公安刷脸）→ 引导去云房卡，不直报
        if (SidecarIntent::isYunfangkaCredentialQuery($q)) {
            return ['reply' => SidecarIntent::yunfangkaCredentialReply(), 'images' => []];
        }

        if (self::matchKw($q, SidecarIntent::addressKeywords())) {
            $r = self::answerAddress($room);
            if ($r) return $r;
        }

        if (self::matchKw($q, SidecarIntent::parkingKeywords())) {
            $r = self::answerParking($room, $q);
            if ($r) return $r;
        }

        if (self::matchKw($q, SidecarIntent::tipsKeywords())) {
            $r = self::answerTips($room, $q);
            if ($r) return $r;
        }

        if (SidecarIntent::isDeviceQuery($q)) {
            return self::answerDevice($room, $q);
        }

        // 兜底：入住/续房指引（中台写了就给）
        $r = self::answerCheckin($room);
        if ($r) return $r;

        return null;
    }

    /**
     * 地址 + 交通指引
     * 中台 location_address / community_name / guide_parking_json
     */
    private static function answerAddress(array $room): ?array
    {
        $parts = [];
        $images = [];

        $address = CenterRoom::addressText($room);
        if ($address !== '') {
            $parts[] = $address;
        }

        foreach (CenterRoom::traffic($room) as $step) {
            $parts[] = $step['text'];
            $images = array_merge($images, self::imagesOf($step['images']));
        }

        if (empty($parts)) {
            return ['reply' => '该房间未登记地址信息。', 'images' => []];
        }
        if (empty($images)) {
            $images = self::imagesOf(CenterRoom::photos($room));
        }
        return ['reply' => implode("\n", $parts), 'images' => $images];
    }

    /**
     * 停车 —— 中台 parking_lot_name / park_charge_*
     * 中台没填 → 明确说没登记
     */
    private static function answerParking(array $room, string $q): ?array
    {
        $name = CenterRoom::parkingName($room);
        if ($name === '') {
            return ['reply' => '该房间未登记停车场信息。', 'images' => self::imagesOf(CenterRoom::photos($room))];
        }

        $sentences = ['有停车场'];
        if ($name !== '') {
            $sentences[] = $name;
        }
        $charge = CenterRoom::parkCharge($room);
        if ($charge !== '') {
            $sentences[] = $charge;
        }

        $images = [];
        foreach (CenterRoom::traffic($room) as $step) {
            $images = array_merge($images, self::imagesOf($step['images']));
        }
        if (empty($images)) {
            $images = self::imagesOf(CenterRoom::photos($room));
        }

        return ['reply' => self::joinSentences($sentences), 'images' => $images];
    }

    /**
     * 攻略/温馨提示 —— 中台 tips（HTML 去标签，文字不改）
     */
    private static function answerTips(array $room, string $q): ?array
    {
        $text = CenterRoom::tipsText($room);
        if ($text === '') return null;

        $reply = self::extractTipsAnswer($text, $q);
        if ($reply === '') {
            // 挑不出相关段落就整段给（中台写了什么就输出什么）
            $reply = mb_strlen($text) > 400 ? mb_substr($text, 0, 400) . '…' : $text;
        }
        return ['reply' => $reply, 'images' => self::imagesOf(CenterRoom::photos($room))];
    }

    /**
     * 设备/家电 —— 中台 guide_appliance_json.items[]
     * 中台没登记该设备 = 该房间没有这个设备
     */
    private static function answerDevice(array $room, string $q): array
    {
        $appliances = CenterRoom::appliances($room);
        $targets = SidecarIntent::matchedDeviceKeywords($q);

        if (empty($appliances)) {
            $label = !empty($targets) ? $targets[0] : '家电设备';
            return ['reply' => '该房间没有登记' . $label . '。', 'images' => []];
        }

        if (empty($targets)) {
            $names = array_values(array_unique(array_map(
                fn($a) => trim((string)($a['name'] ?? '')),
                $appliances
            )));
            $reply = count($names) === 1
                ? '该房间配有' . $names[0] . '。'
                : '该房间配有：' . implode('、', array_slice($names, 0, 6)) . '。';
            $images = [];
            foreach ($appliances as $a) {
                $images = array_merge($images, self::imagesOf($a['images']));
            }
            return ['reply' => $reply, 'images' => $images];
        }

        $matched = [];
        foreach ($appliances as $a) {
            $name = trim((string)($a['name'] ?? ''));
            foreach ($targets as $target) {
                if ($name !== '' && (mb_stripos($name, $target) !== false || mb_stripos($target, $name) !== false)) {
                    $matched[] = $a;
                    break;
                }
            }
        }

        if (empty($matched)) {
            // 中台没登记 → 就是没有这个设备
            return ['reply' => '该房间没有' . $targets[0] . '。', 'images' => []];
        }

        $device = $matched[0];
        $reply = '该房间配有' . $device['name'] . '。';
        if ($device['text'] !== '') {
            $reply .= $device['text'];
        }
        return ['reply' => $reply, 'images' => self::imagesOf($device['images'])];
    }

    /**
     * 入住/续房指引 —— 中台 guide_checkin_json.steps[]
     */
    private static function answerCheckin(array $room): ?array
    {
        $steps = CenterRoom::checkin($room);
        if (empty($steps)) return null;

        $parts = [];
        $images = [];
        foreach ($steps as $step) {
            $parts[] = $step['text'];
            $images = array_merge($images, self::imagesOf($step['images']));
        }
        if (empty($images)) {
            $images = self::imagesOf(CenterRoom::photos($room));
        }
        return ['reply' => implode("\n", $parts), 'images' => $images];
    }

    // ─────────────────────────── 纯文本工具（沿用原实现） ───────────────────────────

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
            if (mb_stripos($q, $qKw) !== false) {
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
        foreach ($sentences as $s) {
            foreach ($focusKws as $kw) {
                if (mb_stripos($s, $kw) !== false) {
                    $picked[] = $s;
                    break;
                }
            }
        }
        return $picked;
    }

    private static function joinSentences(array $parts): string {
        $parts = array_values(array_filter(array_map('trim', $parts)));
        if (empty($parts)) return '';
        if (count($parts) === 1) return rtrim($parts[0], '。') . '。';
        return implode('，', array_map(function ($p) {
            return rtrim($p, '。');
        }, $parts)) . '。';
    }

    private static function matchKw(string $q, array $kws): bool {
        foreach ($kws as $kw) {
            if ($kw !== '' && mb_strpos($q, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 图片 URL 列表 → 统一图片结构
     * @return array[]
     */
    private static function imagesOf(array $images): array
    {
        $out = [];
        foreach ($images as $img) {
            if (!is_string($img)) continue;
            $img = trim($img);
            if ($img === '') continue;
            $out[] = ['url' => $img, 'title' => '查看图片', 'link_url' => $img];
        }
        return $out;
    }
}

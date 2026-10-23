<?php
/**
 * api/CenterRoom.php
 *
 * v3.27 — 数据中台房源读取与解析层
 *
 * 原则（苏鸣 2026-10-22）：**中台写什么我们就输出什么。中台写错也是中台的事。**
 *   - 不推断、不修正、不替中台做判断
 *   - 中台没填的字段 = 该房间没有这个东西
 *   - 只做展示层处理（HTML 去标签），不改中台的文字内容
 *
 * 数据源：中台 s4e3a9（房源），经 CenterApi 封装。
 * 房间查询链路从此不再读 sidecar 知识库。
 */

declare(strict_types=1);

require_once __DIR__ . '/CenterApi.php';

class CenterRoom
{
    /** 按 baoyu_room_no 取房源（订单 room_ids 就是这个值） */
    public static function loadByBaoyuRoomNo(PDO $db, string $baoyuRoomNo): ?array
    {
        return CenterApi::roomByBaoyuRoomNo($db, $baoyuRoomNo);
    }

    /** 按中台房源 id 取房源（会话已绑定时用） */
    public static function loadById(PDO $db, int $roomId): ?array
    {
        return CenterApi::roomById($db, $roomId);
    }

    /**
     * 家电/设备清单 —— guide_appliance_json.items[]
     * @return array[] [['name'=>string,'text'=>string,'images'=>string[]]]
     */
    public static function appliances(array $room): array
    {
        $raw = $room['guide_appliance_json'] ?? null;
        if (empty($raw)) return [];

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach (($decoded['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') continue;
            $out[] = [
                'name'   => $name,
                'text'   => trim((string)($item['text'] ?? '')),
                'images' => self::stringList($item['images'] ?? []),
            ];
        }
        return $out;
    }

    /**
     * 交通/位置指引 —— guide_parking_json.steps[]
     * @return array[] [['text'=>string,'title'=>string,'images'=>string[]]]
     */
    public static function traffic(array $room): array
    {
        return self::steps($room, 'guide_parking_json');
    }

    /**
     * 入住/续房指引 —— guide_checkin_json.steps[]
     * @return array[] [['text'=>string,'title'=>string,'images'=>string[]]]
     */
    public static function checkin(array $room): array
    {
        return self::steps($room, 'guide_checkin_json');
    }

    /**
     * 房间照片 —— room_photos_json.photos[]
     * @return array[] [['url'=>string,'title'=>string,'link_url'=>string]]
     */
    public static function photos(array $room): array
    {
        $raw = $room['room_photos_json'] ?? null;
        if (empty($raw)) return [];

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach (($decoded['photos'] ?? []) as $photo) {
            if (!is_array($photo)) continue;
            $title = trim((string)($photo['text'] ?? ''));
            foreach (self::stringList($photo['images'] ?? []) as $url) {
                $out[] = [
                    'url'      => $url,
                    'title'    => $title !== '' ? $title : '查看图片',
                    'link_url' => $url,
                ];
            }
        }
        return $out;
    }

    /**
     * 攻略/温馨提示 —— tips（中台是 HTML 文本）
     * 只去标签换行，不改一个字
     */
    public static function tipsText(array $room): string
    {
        $tips = trim((string)($room['tips'] ?? ''));
        if ($tips === '') return '';
        return self::toPlainText($tips);
    }

    /**
     * 展示名：short_name 为空时用 community_name + room_code
     * 不自己编名字
     */
    public static function displayName(array $room): string
    {
        $short     = trim((string)($room['short_name'] ?? ''));
        $community = trim((string)($room['community_name'] ?? ''));
        $code      = trim((string)($room['room_code'] ?? ''));

        $name = $short !== '' ? $short : $community;
        if ($code !== '' && mb_strpos($name, $code) === false) {
            $name = trim($name . ' ' . $code);
        }
        if ($name === '') {
            $name = '房间' . intval($room['id'] ?? 0);
        }
        return $name;
    }

    /**
     * 地址：community_name + location_address
     * 两个都空 → 空串（由调用方回「未登记地址」）
     */
    public static function addressText(array $room): string
    {
        $community = trim((string)($room['community_name'] ?? ''));
        $address   = trim((string)($room['location_address'] ?? ''));

        if ($community !== '' && $address !== '') return $community . ' ' . $address;
        if ($address !== '') return $address;
        return $community;
    }

    /**
     * 停车场名 —— parking_lot_name
     *
     * 说明：全量 1046 房源里 has_parking_lot=1 只有 4 个，而 parking_lot_name 非空有 280 个，
     * 两者大面积矛盾。按「中台写什么就输出什么」的原则，这里只取中台实际填了信息的字段
     * （名称），不去用自相矛盾的标志位替中台做判断。
     */
    public static function parkingName(array $room): string
    {
        return trim((string)($room['parking_lot_name'] ?? ''));
    }

    /**
     * 停车收费说明 —— park_charge_status/type/amount
     * 中台没填收费信息 → 空串（由调用方说"未登记收费"）
     */
    public static function parkCharge(array $room): string
    {
        if (intval($room['park_charge_status'] ?? 0) !== 1) return '';

        $amount = trim((string)($room['park_charge_amount'] ?? ''));
        $type   = intval($room['park_charge_type'] ?? 0);
        if ($amount === '' || floatval($amount) <= 0) return '';

        // park_charge_type: 1=按次 其它=按天/按晚（仅按中台原值描述，不推断）
        $unit = ($type === 1) ? '元/次' : '元';
        return '停车收费 ' . $amount . ' ' . $unit;
    }

    /**
     * HTML → 纯文本（只去标签，文字一字不改）
     */
    public static function toPlainText(string $html): string
    {
        if ($html === '') return '';

        $text = preg_replace('#<br\s*/?>#i', "\n", $html);
        $text = preg_replace('#</p\s*>#i', "\n", $text);
        $text = preg_replace('#<p[^>]*>#i', '', (string)$text);
        $text = strip_tags((string)$text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /** @return string[] */
    private static function steps(array $room, string $key): array
    {
        $raw = $room[$key] ?? null;
        if (empty($raw)) return [];

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach (($decoded['steps'] ?? []) as $step) {
            if (!is_array($step)) continue;
            $text = trim((string)($step['text'] ?? ''));
            if ($text === '') continue;
            $out[] = [
                'text'   => $text,
                'title'  => trim((string)($step['title'] ?? '')),
                'images' => self::stringList($step['images'] ?? []),
            ];
        }
        return $out;
    }

    /** @return string[] */
    private static function stringList($raw): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $v) {
            if (!is_string($v)) continue;
            $v = trim($v);
            if ($v === '') continue;
            $out[] = $v;
        }
        return $out;
    }
}

<?php
/**
 * api/CenterApi.php
 *
 * v3.26 — 数据中台客户端(center.youguang8488.com)
 *
 * 鉴权方式(实测)：GET + query string，参数 username / password，必须带 tenant_id
 *   ❌ 已排除：pwd / account / user / Basic Auth / Authorization: Bearer / POST body
 *
 * 三个 relay 端点(实测 2026-10-22)：
 *   s4e1a8  订单查询    过滤 channel_order_id    返回 {total, list[], sum_price_paid}
 *   s4e3a9  房源        过滤 baoyu_room_no / id  返回 {total, list[]}
 *   s4e2a10 间夜列表    过滤 room_id             返回 {total, list[]}
 *
 * 凭据来源：DB platform_config（center.api_url / center.username / center.password / center.tenant_id）
 *   按项目红线，密码只存生产 DB，不进源码 / .env / commit。
 *
 * ⚠️ sum_price_paid 语义：它是「当前命中集合的客户实付合计」，不是订单级字段。
 *   按 channel_order_id 过滤后 total=1，此时该值即为该单实付额（已用 3 个真实订单
 *   与旧接口 price_paid 交叉验证逐笔一致）。
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class CenterApi
{
    /** 订单查询 */
    public const RELAY_ORDER = 's4e1a8';
    /** 房源 */
    public const RELAY_ROOM  = 's4e3a9';
    /** 间夜列表 */
    public const RELAY_NIGHT = 's4e2a10';

    private const TIMEOUT        = 10;
    private const CONNECT_TIMEOUT = 5;

    /**
     * 读取中台配置
     * @return array{url:string,username:string,password:string,tenant_id:string}|null
     */
    public static function config(PDO $db): ?array
    {
        try {
            $url      = rtrim(trim(strval(pcGet($db, 'center.api_url', ''))), '/');
            $username = trim(strval(pcGet($db, 'center.username', '')));
            $password = trim(strval(pcGet($db, 'center.password', '')));
            $tenantId = trim(strval(pcGet($db, 'center.tenant_id', '1')));
        } catch (Throwable $e) {
            return null;
        }

        if ($url === '' || $username === '' || $password === '') return null;
        if ($tenantId === '') $tenantId = '1';

        return [
            'url'       => $url,
            'username'  => $username,
            'password'  => $password,
            'tenant_id' => $tenantId,
        ];
    }

    /** 中台是否已配置 */
    public static function isConfigured(PDO $db): bool
    {
        return self::config($db) !== null;
    }

    /**
     * 底层请求
     * @return array|null 成功返回 data 层；失败返回 null
     */
    private static function request(PDO $db, string $relay, array $params = []): ?array
    {
        $cfg = self::config($db);
        if ($cfg === null) {
            error_log('[CenterApi] 未配置 center.api_url / center.username / center.password');
            return null;
        }

        $query = [
            'username'  => $cfg['username'],
            'password'  => $cfg['password'],
            'tenant_id' => $cfg['tenant_id'],
        ];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') continue;
            $query[$k] = (string)$v;
        }

        $url = $cfg['url'] . '/' . $relay . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) {
            error_log('[CenterApi] ' . $relay . ' HTTP fail: code=' . $httpCode . ' err=' . $err);
            return null;
        }

        $json = json_decode($resp, true);
        if (!is_array($json) || intval($json['code'] ?? 0) !== 1) {
            $msg = substr(strval($json['msg'] ?? 'unknown'), 0, 120);
            error_log('[CenterApi] ' . $relay . ' biz fail: ' . $msg);
            return null;
        }

        $data = $json['data'] ?? null;
        return is_array($data) ? $data : [];
    }

    /**
     * 按渠道订单号查订单
     *
     * @return array{order:array,paid_amount:string}|null
     *   order      订单记录（12 字段）
     *   paid_amount 客户实付金额（字符串，如 "1751.20"）
     */
    public static function orderByChannelOrderId(PDO $db, string $channelOrderId): ?array
    {
        $channelOrderId = trim($channelOrderId);
        if ($channelOrderId === '') return null;

        $data = self::request($db, self::RELAY_ORDER, [
            'channel_order_id' => $channelOrderId,
            'limit'            => 1,
        ]);
        if ($data === null) return null;

        $list = $data['list'] ?? [];
        if (!is_array($list) || empty($list)) return null;

        $total = intval($data['total'] ?? count($list));
        if ($total > 1) {
            error_log('[CenterApi] channel_order_id=' . $channelOrderId . ' 命中 ' . $total . ' 单，实付金额取的是集合合计');
        }

        $order = $list[0];
        if (!is_array($order)) return null;

        return [
            'order'       => $order,
            'paid_amount' => trim(strval($data['sum_price_paid'] ?? '0.00')),
        ];
    }

    /**
     * 按 baoyu_room_no 查房源
     * @return array|null 房源记录
     */
    public static function roomByBaoyuRoomNo(PDO $db, string $baoyuRoomNo): ?array
    {
        $baoyuRoomNo = trim($baoyuRoomNo);
        if ($baoyuRoomNo === '') return null;

        $data = self::request($db, self::RELAY_ROOM, [
            'baoyu_room_no' => $baoyuRoomNo,
            'limit'         => 1,
        ]);
        if ($data === null) return null;

        $list = $data['list'] ?? [];
        if (!is_array($list) || empty($list)) return null;

        $room = $list[0];
        return is_array($room) ? $room : null;
    }

    /**
     * 按中台房源 id 查房源
     * @return array|null 房源记录
     */
    public static function roomById(PDO $db, int $roomId): ?array
    {
        if ($roomId <= 0) return null;

        $data = self::request($db, self::RELAY_ROOM, [
            'id'   => $roomId,
            'limit' => 1,
        ]);
        if ($data === null) return null;

        $list = $data['list'] ?? [];
        if (!is_array($list) || empty($list)) return null;

        $room = $list[0];
        return is_array($room) ? $room : null;
    }

    /**
     * 按 room_id 查间夜列表
     * @return array[] 间夜记录
     */
    public static function nightsByRoomId(PDO $db, string $roomId, int $limit = 50): array
    {
        $roomId = trim($roomId);
        if ($roomId === '') return [];

        $data = self::request($db, self::RELAY_NIGHT, [
            'room_id' => $roomId,
            'limit'   => $limit,
        ]);
        if ($data === null) return [];

        $list = $data['list'] ?? [];
        return is_array($list) ? $list : [];
    }
}

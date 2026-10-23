<?php
/**
 * api/wecom_kf_roomcard_v37.php
 *
 * v3.12 — 云房卡跳转参数生成
 * v3.28 — 主链路切到数据中台房卡接口(v3.28-roomcard-center-relay-blueprint.md)
 * v3.29 — 旧宿家接口与旧凭据下线(v3.29-roomcard-legacy-cleanup-blueprint.md)
 *
 * 流程:
 *   1. 调中台 room_card/byOrder 取房卡(GET + query string,凭据同数据中台)
 *   2. 有卡 → 用 qrCodeId + ai.miniprogram.appid/pagepath 组 A 样式原生小程序卡片
 *   3. 中台取卡失败 → 不发卡(由调用方兜底提示客户联系管家),不回退旧接口
 *   4. 固定封面上传为 thumb_media_id,不生成订单图片
 *
 * 凭证来自 DB platform_config:
 *   - center.username / center.password / center.tenant_id  (中台账号)
 *   - roomcard.api_url                                     (房卡接口地址,可覆盖默认值)
 *   - ai.miniprogram.appid / ai.miniprogram.pagepath        (A 样式小程序参数)
 */

declare(strict_types=1);

// 宿家云房卡固定封面图(不含订单动态文字)
define('ROOMCARD_THUMB_URL', 'https://gongan-1331464141.cos.ap-guangzhou.myqcloud.com/file/08010fd4a1aefe1eab90365e82e4ded9.jpg');

// v3.28:数据中台房卡接口(可用 platform_config.roomcard.api_url 覆盖)
define('ROOMCARD_CENTER_API_URL', 'https://business.youguang8488.com/api/relay/room_card/byOrder');
function roomCardApiUrl(PDO $db): string
{
    $url = trim((string)pcGet($db, 'roomcard.api_url', ''));
    if ($url !== '') return $url;
    return defined('ROOMCARD_CENTER_API_URL') ? (string)ROOMCARD_CENTER_API_URL : '';
}

/**
 * v3.28:调数据中台房卡接口 room_card/byOrder(GET + query string)。
 * 鉴权参数与数据中台一致:username / password(/ tenant_id)。
 *
 * @param string|null $err null = 调用成功(含"有 0 张卡");非 null = 调用失败,可回退旧接口
 * @return array cards[] 原样返回中台的卡记录数组
 */
function fetchCenterRoomCards(PDO $db, string $channelOrderId, ?string &$err = null): array
{
    $err = null;

    $url = roomCardApiUrl($db);
    if ($url === '') {
        $err = 'roomcard.api_url not configured';
        error_log('[roomcard] ' . $err);
        return [];
    }

    $user = trim((string)pcGet($db, 'center.username', ''));
    $pwd  = trim((string)pcGet($db, 'center.password', ''));
    if ($user === '' || $pwd === '') {
        $err = 'center.username/password not configured';
        error_log('[roomcard] ' . $err);
        return [];
    }
    $tenantId = trim((string)pcGet($db, 'center.tenant_id', ''));

    // 密码不进日志,只进 query
    $query = 'username=' . urlencode($user)
        . '&password=' . rawurlencode($pwd)
        . '&channel_order_id=' . urlencode($channelOrderId);
    if ($tenantId !== '') $query .= '&tenant_id=' . urlencode($tenantId);

    $ch = curl_init($url . '?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) {
        $err = 'http ' . $httpCode . ' ' . $curlErr;
        error_log('[roomcard] center api HTTP fail: ' . $err);
        return [];
    }

    $data = json_decode((string)$resp, true);
    if (!is_array($data) || intval($data['code'] ?? 0) !== 1) {
        $code = isset($data['code']) ? $data['code'] : '?';
        $msg = isset($data['msg']) ? substr((string)$data['msg'], 0, 60) : '';
        $err = 'biz code=' . $code . ' msg=' . $msg;
        error_log('[roomcard] center api biz fail: ' . $err);
        return [];
    }

    $cards = $data['data']['cards'] ?? [];
    return is_array($cards) ? $cards : [];
}

/**
 * v3.28:中台房卡记录 → 企微 A 样式原生小程序卡片参数。
 *
 * 中台不返回 share_bundle,所以 appid 取自 ai.miniprogram.appid,
 * pagepath 由 ai.miniprogram.pagepath + qrCodeId 拼出(与旧宿家 share_bundle 同构:
 * pages/index/index.html?qrCodeId=xxx,客户点得开的形式)。
 */
function parseCenterRoomCard(PDO $db, array $card): ?array
{
    $qrCodeId = trim((string)($card['qrCodeId'] ?? ''));
    if ($qrCodeId === '') return null;

    $appid = trim((string)pcGet($db, 'ai.miniprogram.appid', ''));
    if ($appid === '') {
        error_log('[roomcard] ai.miniprogram.appid not configured');
        return null;
    }

    $base = trim((string)pcGet($db, 'ai.miniprogram.pagepath', 'pages/index/index'));
    if ($base === '') $base = 'pages/index/index';
    if (substr($base, -5) !== '.html') $base .= '.html';
    $pagepath = $base . '?qrCodeId=' . rawurlencode($qrCodeId);

    $roomCode = trim((string)($card['roomCode'] ?? ''));
    $title = $roomCode !== '' ? ('您的房间为 ' . $roomCode) : '云房卡';

    return [
        'title'    => $title,
        'desc'     => trim((string)($card['toponym'] ?? '')),
        'appid'    => $appid,
        'pagepath' => $pagepath,
        'url'      => '', // v3.28:中台接口不返回 urlLink,B 样式兜底不可用
    ];
}

/**
 * 调企微上传临时素材接口,获取 media_id
 * (image 类型,5MB 以内,JPG/PNG)
 */
function uploadImageToWeCom(string $accessToken, string $imgUrl): ?string
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'thumb_');
    $ch = curl_init($imgUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $imgData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || !$imgData) {
        @unlink($tmpFile);
        return null;
    }
    file_put_contents($tmpFile, $imgData);

    $url = "https://qyapi.weixin.qq.com/cgi-bin/media/upload?access_token=" . urlencode($accessToken) . "&type=image";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['media' => new CURLFile($tmpFile, mime_content_type($tmpFile) ?: 'image/jpeg', basename($tmpFile))],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    @unlink($tmpFile);

    if (!$resp) return null;
    $data = json_decode($resp, true);
    return $data['media_id'] ?? null;
}

/**
 * 获取缩略图 media_id(文件缓存 + 60 小时自动刷新)
 * v3.15.5:企微临时素材有效期约 72 小时,60 小时阈值强制重传
 */
function getThumbMediaIdCached(): ?string
{
    $cacheFile = __DIR__ . '/../logs/roomcard_thumb_media_id.txt';

    if (file_exists($cacheFile)) {
        $raw = trim(file_get_contents($cacheFile));
        // v3.15.5:尝试解析 JSON 格式 {media_id, created_at}
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['media_id'], $decoded['created_at'])) {
            // 60 小时内复用(留 12 小时缓冲:72-60=12)
            if (time() < (int)$decoded['created_at'] + 60 * 3600) {
                return (string)$decoded['media_id'];
            }
            // 过期 → 删除走重新上传路径
            @unlink($cacheFile);
        } elseif (preg_match('/^[A-Za-z0-9_=-]+$/', $raw) && strlen($raw) >= 40) {
            // v3.15.5:兼容旧格式(只有 media_id 一行),直接当成过期删掉重新上传
            // 保留向后兼容 1 次,后续稳定
            @unlink($cacheFile);
        }
    }

    // 重新上传
    $token = getAccessToken();
    if (!$token) return null;

    $mediaId = uploadImageToWeCom($token, ROOMCARD_THUMB_URL);
    if ($mediaId) {
        @file_put_contents($cacheFile, json_encode([
            'media_id'   => $mediaId,
            'created_at' => time(),
        ]));
    }
    return $mediaId;
}

/**
 * v3.21:强制刷新 thumb 缓存(删缓存→重传→写缓存+DB)。
 * 40007 自愈路径专用。
 */
function forceRefreshThumbCache(): ?string
{
    $cacheFile = __DIR__ . '/../logs/roomcard_thumb_media_id.txt';
    @unlink($cacheFile);
    return getThumbMediaIdCached();
}
/**
 * 一次返回当前订单的全部房卡 delivery 数组。
 * 中台一单多房时返 N 张(N>=1),每张都是独立云房卡。
 * @return array[] 每个元素是 parseCenterRoomCard + thumb_media_id 的结果
 */
function buildRoomCardDeliveries(PDO $db, string $channelOrderId): array
{
    // v3.28:主链路 = 数据中台 room_card/byOrder
    $err = null;
    $cards = fetchCenterRoomCards($db, $channelOrderId, $err);

    $deliveries = [];
    if ($err === null) {
        // 中台说没有就是没有(0 张卡不回退,避免旧接口生成旧卡)
        foreach ($cards as $card) {
            if (!is_array($card)) continue;
            $parsed = parseCenterRoomCard($db, $card);
            if (!$parsed) continue;
            $deliveries[] = $parsed;
        }
    } else {
        // v3.29:旧宿家接口与旧凭据已按苏鸣要求下线,不再有回退路径。
        // 中台不可用时房卡发不出 → 由 api/wecom_kf.php 兜底发
        // 「暂时没有查找到您的云房卡,请稍后重试或联系管家。」
        error_log('[roomcard] center api unavailable, no card sent: ' . $err);
    }

    if (!$deliveries) return [];

    // v3.21:一律走 getThumbMediaIdCached() 的 60h 过期检查+自动重传,
    // 不再先读 DB(旧逻辑 DB 非空即短路,导致过期检查永远不执行 → 40007)
    $thumb = getThumbMediaIdCached() ?? '';
    if ($thumb === '') return [];
    // 同步写回 DB,仅作运维排查副本,不作读取源
    @pcSet($db, 'ai.roomcard.thumb_media_id', $thumb);

    foreach ($deliveries as &$d) {
        $d['thumb_media_id'] = $thumb;
    }
    unset($d);

    return $deliveries;
}
<?php
/**
 * api/wecom_kf_roomcard_v37.php
 *
 * v3.12 — 云房卡跳转参数生成
 * v3.28 — 主链路切到数据中台房卡接口(v3.28-roomcard-center-relay-blueprint.md)
 *
 * 流程(v3.28):
 *   1. 调中台 room_card/byOrder 取房卡(GET + query string,凭据同数据中台)
 *   2. 有卡 → 用 qrCodeId + ai.miniprogram.appid/pagepath 组 A 样式原生小程序卡片
 *   3. 新接口调用失败(HTTP/鉴权/订单不存在) → 回退旧宿家接口,业务不中断
 *   4. 固定封面上传为 thumb_media_id,不生成订单图片
 *
 * 凭证来自 DB platform_config:
 *   - center.username / center.password / center.tenant_id  (v3.28 新接口,复用中台账号)
 *   - roomcard.username / roomcard.password                 (旧宿家接口,回退用,保留)
 *   - ai.miniprogram.appid / ai.miniprogram.pagepath        (A 样式小程序参数)
 */

declare(strict_types=1);

// 宿家云房卡固定封面图(不含订单动态文字)
define('ROOMCARD_THUMB_URL', 'https://gongan-1331464141.cos.ap-guangzhou.myqcloud.com/file/08010fd4a1aefe1eab90365e82e4ded9.jpg');

// v3.28:数据中台房卡接口(可用 platform_config.roomcard.api_url 覆盖)
define('ROOMCARD_CENTER_API_URL', 'https://business.youguang8488.com/api/relay/room_card/byOrder');

/**
 * 查云房卡(根据 channel_order_id)
 * 返回 list 中第一条卡记录(空时返 null)
 */
function getRoomCard(PDO $db, string $channelOrderId): ?array
{
    $user = trim(pcGet($db, 'roomcard.username', ''));
    $pwd  = trim(pcGet($db, 'roomcard.password', ''));
    if ($user === '' || $pwd === '') {
        error_log('[roomcard] roomcard.username/password not configured');
        return null;
    }

    $url = "https://apicenter.sujia365.com/index.php/openapi/room_card/byChannelOrder"
        . "?username=" . urlencode($user)
        . "&pwd=" . $pwd  // 密码已经是 URL-encoded 形式，urlencode 会双重编码导致 401
        . "&channel_order_id=" . urlencode($channelOrderId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return null;
    $data = json_decode($resp, true);
    if (!isset($data['code']) || intval($data['code']) !== 1 || empty($data['data']['list'])) {
        return null;
    }
    return $data['data']['list'][0];
}

/**
 * 生成云房卡(根据 channel_order_id,POST + query 参数)
 * 返回 cards 中第一条卡记录(空时返 null)
 */
function generateRoomCard(PDO $db, string $channelOrderId): ?array
{
    $user = trim(pcGet($db, 'roomcard.username', ''));
    $pwd  = trim(pcGet($db, 'roomcard.password', ''));
    if ($user === '' || $pwd === '') return null;

    $url = "https://apicenter.sujia365.com/index.php/openapi/room_card/generateByChannelOrder"
        . "?username=" . urlencode($user)
        . "&pwd=" . $pwd  // 密码已编码，不再二次 urlencode
        . "&channel_order_id=" . urlencode($channelOrderId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) {
        error_log('[roomcard] generate HTTP fail: ' . $httpCode . ' err=' . $err);
        return null;
    }
    $data = json_decode($resp, true);
    if (!isset($data['code']) || intval($data['code']) !== 1) return null;
    if (empty($data['data']['cards'])) return null;
    return $data['data']['cards'][0];
}

/**
 * 统一生成小程序卡片及 link 兜底参数。
 *
 * 原生小程序卡片必须使用 appid + pagepath；link 兜底原样使用 urlLink。
 * 两种跳转信息都由宿家针对当前房卡生成，本地不再拼接订单号。
 */
function parseRoomCardDelivery(array $card): ?array
{
    $cardData = isset($card['card']) && is_array($card['card']) ? $card['card'] : $card;
    $bundleRaw = $cardData['share_bundle'] ?? ($card['share_bundle'] ?? '');
    $bundle = is_array($bundleRaw) ? $bundleRaw : json_decode((string)$bundleRaw, true);
    if (!is_array($bundle) || !isset($bundle['miniapps'][0]) || !is_array($bundle['miniapps'][0])) {
        return null;
    }

    $mini = $bundle['miniapps'][0];
    $appid = trim((string)($mini['appid'] ?? ''));
    $pagepath = trim((string)($mini['path'] ?? ''));
    $urlLink = trim((string)($mini['urlLink'] ?? ''));
    $shareText = (string)($mini['shareText'] ?? ($bundle['shareText'] ?? ''));
    $roomNo = extractDisplayRoomNo($shareText);
    if ($roomNo === '') {
        $roomNo = trim((string)($cardData['baoyu_room_no'] ?? ''));
    }

    if (!preg_match('#^weixin://dl/business/\?t=[A-Za-z0-9_-]+$#D', $urlLink)) {
        return null;
    }

    return [
        'title' => $roomNo !== '' ? '您的房间为 ' . $roomNo : '宿家云房卡',
        'desc' => $shareText !== '' ? $shareText : '点击查看您的云房卡',
        'appid' => $appid,
        'pagepath' => $pagepath,
        'url' => $urlLink,
    ];
}

/**
 * 从宿家面向客户的分享文案中提取真实展示房间号。
 * 示例：关于您【某某门店的1001号房间】的预订 → 1001。
 */
function extractDisplayRoomNo(string $shareText): string
{
    if (!preg_match('/【([^】]+)】/u', $shareText, $matches)) {
        return '';
    }

    $label = trim($matches[1]);
    $label = preg_replace('/^.*的/u', '', $label) ?? $label;
    $label = preg_replace('/号房间$/u', '', $label) ?? $label;
    return trim($label);
}

/**
 * 保留旧入口，供 link 类型兜底和其他调用方兼容。
 */
function parseRoomCardForLink(array $card): ?array
{
    $parsed = parseRoomCardDelivery($card);
    if (!$parsed || $parsed['url'] === '') return null;

    return [
        'title' => $parsed['title'],
        'desc' => $parsed['desc'],
        'url' => $parsed['url'],
    ];
}

/**
 * v3.28:取云房卡接口地址。
 * 优先用 platform_config.roomcard.api_url(可后台/DB 覆盖),否则用内置常量。
 */
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
 * v3.16:调 generateByChannelOrder 拿当前订单的所有 cards。
 * 与 generateRoomCard 区别:返 cards 数组本身而非 [0],支持一单多房。
 */
function generateAllRoomCards(PDO $db, string $channelOrderId): ?array
{
    $user = trim(pcGet($db, 'roomcard.username', ''));
    $pwd  = trim(pcGet($db, 'roomcard.password', ''));
    if ($user === '' || $pwd === '') return null;

    $url = "https://apicenter.sujia365.com/index.php/openapi/room_card/generateByChannelOrder"
        . "?username=" . urlencode($user)
        . "&pwd=" . $pwd  // 密码已编码,不再二次 urlencode
        . "&channel_order_id=" . urlencode($channelOrderId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) {
        error_log('[roomcard] generateAll HTTP fail: ' . $httpCode . ' err=' . $err);
        return null;
    }
    $data = json_decode($resp, true);
    if (!isset($data['code']) || intval($data['code']) !== 1) return null;
    if (empty($data['data']['cards']) || !is_array($data['data']['cards'])) return null;
    return $data['data']['cards'];
}

/**
 * v3.16:一次返回当前订单的全部房卡 delivery 数组。
 * 宿家一单多房时返 N 张(N>=1),每张都是独立云房卡。
 * @return array[] 每个元素是 parseRoomCardDelivery + thumb_media_id 的结果
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
        // v3.28:新接口调用失败 → 回退旧宿家接口,保证业务不中断
        error_log('[roomcard] fallback to legacy sujia api: ' . $err);
        foreach ((array)generateAllRoomCards($db, $channelOrderId) as $card) {
            if (!is_array($card)) continue;
            $parsed = parseRoomCardDelivery($card);
            if (!$parsed) continue;
            $deliveries[] = $parsed;
        }
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

/**
 * 主入口:返回完整 link 卡片参数数组(给 sendKfLinkMessage 用)
 * 返回 null 表示无法生成(配置缺失/API 失败/无 thumb 等)
 */
function buildRoomCardLink(PDO $db, string $channelOrderId): ?array
{
    $card = getRoomCard($db, $channelOrderId);
    if (!$card) {
        $card = generateRoomCard($db, $channelOrderId);
        if (!$card) return null;
    }

    $parsed = parseRoomCardForLink($card);
    if (!$parsed || empty($parsed['url'])) return null;

    $thumb = getThumbMediaIdCached();
    if (!$thumb) return null;

    return [
        'title' => $parsed['title'],
        'desc'  => $parsed['desc'],
        'url'   => $parsed['url'],
        'thumb_media_id' => $thumb,
    ];
}

/**
 * v3.12 主入口：构建原生小程序卡片和 link 兜底所需的全部参数。
 */
function buildRoomCardDelivery(PDO $db, string $channelOrderId): ?array
{
    // v3.28:优先数据中台,失败回退旧宿家接口
    $err = null;
    $cards = fetchCenterRoomCards($db, $channelOrderId, $err);
    $parsed = null;

    if ($err === null) {
        foreach ($cards as $card) {
            if (!is_array($card)) continue;
            $parsed = parseCenterRoomCard($db, $card);
            if ($parsed) break;
        }
    } else {
        error_log('[roomcard] fallback to legacy sujia api: ' . $err);
        $card = getRoomCard($db, $channelOrderId);
        if (!$card) {
            $card = generateRoomCard($db, $channelOrderId);
        }
        if ($card) $parsed = parseRoomCardDelivery($card);
    }

    if (!$parsed) return null;

    // v3.21:同上,一律走过期检查,不再先读 DB
    $thumb = getThumbMediaIdCached() ?? '';
    if ($thumb === '') return null;

    $parsed['thumb_media_id'] = $thumb;
    // v3.21:DB 仅作运维副本
    @pcSet($db, 'ai.roomcard.thumb_media_id', $thumb);
    return $parsed;
}

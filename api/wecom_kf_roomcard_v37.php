<?php
/**
 * api/wecom_kf_roomcard_v37.php
 *
 * v3.12 — 云房卡跳转参数生成
 *
 * 流程:
 *   1. 调 byChannelOrder 查云房卡
 *   2. 有云房卡 → 解析 share_bundle → 返回宿家生成的 appid/path/urlLink
 *   3. 没有云房卡 → 调 generateByChannelOrder 生成 → 再解析
 *   4. 固定封面上传为 thumb_media_id,不生成订单图片
 *
 * 凭证来自 DB platform_config:
 *   - roomcard.username
 *   - roomcard.password
 */

declare(strict_types=1);

// 宿家云房卡固定封面图(不含订单动态文字)
define('ROOMCARD_THUMB_URL', 'https://gongan-1331464141.cos.ap-guangzhou.myqcloud.com/file/08010fd4a1aefe1eab90365e82e4ded9.jpg');

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
    $card = getRoomCard($db, $channelOrderId);
    if (!$card) {
        $card = generateRoomCard($db, $channelOrderId);
        if (!$card) return null;
    }

    $parsed = parseRoomCardDelivery($card);
    if (!$parsed) return null;

    // 优先沿用当前已经验证可显示的封面素材；缺失时才上传固定封面。
    $thumb = trim((string)pcGet($db, 'ai.roomcard.thumb_media_id', ''));
    if ($thumb === '') {
        $thumb = getThumbMediaIdCached() ?? '';
    }
    if ($thumb === '') return null;

    $parsed['thumb_media_id'] = $thumb;
    // v3.15.5:同步写回 DB,确保 buildRoomCardDelivery 后续调用读新 thumb
    @pcSet($db, 'ai.roomcard.thumb_media_id', $thumb);
    return $parsed;
}

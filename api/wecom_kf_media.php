<?php
declare(strict_types=1);
// api/wecom_kf_media.php
// v3.35 媒体上传层:URL/本地文件 → 企微临时素材 media_id,带按 key 的本地缓存。
//
// 对外接口(签名固定,另一路按此调用,蓝图 §7.4.2):
//   uploadMediaFromUrl(PDO $db, string $url, string $type='image'): array
//   uploadMediaFromFile(PDO $db, string $path, string $type='video'): array
//     返回 ['ok'=>bool,'media_id'=>string,'errcode'=>int,'errmsg'=>string]
//   mediaCacheGet(string $key): ?string
//   mediaCacheSet(string $key, string $mediaId): void
//   mediaCacheClear(string $key): void
//   mediaCacheKey(string $url, string $type='image'): string
//     ⚠️ 相比任务书固定签名多了一个「可选」第二参(默认 'image'):
//     蓝图 §7.4.2 要求键含 type(image/video 同 URL 不能撞键);
//     带默认值,另一路按单参 mediaCacheKey($url) 调用完全兼容。
//
// 口径(蓝图 rev2.2 §5.3 / §7.4.2 + CodeBuddy 方案审更正):
// - 下载超时 5s;图片上传 15s;视频上传 30s。
// - 缓存 TTL 60h(企微临时素材 3 天过期,留 12h 缓冲),读写用 PHP time()
//   (与 getThumbMediaIdCached() 一致,不与 MySQL NOW() 混用,P2 更正 #2)。
// - 写入时淘汰 >7 天条目,防缓存文件无限增长。
// - 不能复用 thumb 的单值缓存(ai.roomcard.thumb_media_id 是单值,
//   多图/多文件必须按 key 分开)。
// - qa C8:上传失败必须记录 errcode/errmsg 到日志(旧 uploadImageToWeCom
//   失败不记 errcode,是个坑)。日志只记 errcode/host/basename,
//   不记 access_token、不记中台凭据(敏感字段黑名单)。
// - 素材失效自愈由调用方负责:任何非 0 errcode + 命中缓存 →
//   mediaCacheClear($key) → 重新 upload → 重发一次(§7.6.1 宽口径)。

require_once __DIR__ . '/config.php';
// v3.35:access_token 从「中立 include」拿,不要 require api/wecom_kf.php
// (它顶层就是回调路由,require 会直接执行)。
// wecom_kf_token.php 含 getAccessToken()/httpGet()/wecom_kf_log()/
// wecom_kf_response_error()(P1 更正 #1:getAccessToken 依赖后三个)。
// 采用惰性 require(只在真要上传时加载):缓存层函数不依赖 token,
// 该文件暂未落地时缓存读写/删除端点仍可用,不连坐报错。

// ──────────────────────────────────────────
// 常量:超时与缓存参数(蓝图 §5.3 / §7.4.2)
// ──────────────────────────────────────────

define('KF_MEDIA_DOWNLOAD_TIMEOUT', 5);        // COS 图片下载
define('KF_MEDIA_IMAGE_UPLOAD_TIMEOUT', 15);   // media/upload?type=image
define('KF_MEDIA_VIDEO_UPLOAD_TIMEOUT', 30);   // media/upload?type=video
define('KF_MEDIA_CACHE_TTL', 60 * 3600);       // 60h(72h 有效期 - 12h 缓冲)
define('KF_MEDIA_CACHE_MAX_AGE', 7 * 86400);   // 写入时淘汰 >7 天条目

// ──────────────────────────────────────────
// 缓存(落 logs/kf_media_cache.json,风格对齐 getThumbMediaIdCached)
// 结构: { "<key>": {"media_id": "...", "created_at": <PHP time()>}, ... }
// ──────────────────────────────────────────

function kfMediaCacheFile(): string
{
    return __DIR__ . '/../logs/kf_media_cache.json';
}

/**
 * 缓存键 = host + path(剥 query) + type → hash。
 * 实测中台 COS URL 稳定且无签名 query(蓝图 §4.2),仍按 host+path 归一,
 * 防将来加了签名 query 导致缓存永不命中。
 * 本地文件路径(无 host)直接用路径本体(剥 query)参与 hash。
 */
function mediaCacheKey(string $url, string $type = 'image'): string
{
    $norm = $url;
    $parts = parse_url($url);
    if (is_array($parts) && !empty($parts['host'])) {
        $norm = strtolower($parts['host']) . '|' . ($parts['path'] ?? '');
    }
    $q = strpos($norm, '?');
    if ($q !== false) $norm = substr($norm, 0, $q);
    return substr(hash('sha256', $norm . '|' . $type), 0, 32);
}

function kfMediaCacheLoad(): array
{
    $file = kfMediaCacheFile();
    if (!is_file($file)) return [];
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function kfMediaCacheSave(array $data): void
{
    $file = kfMediaCacheFile();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // 并发写后写覆盖,丢一次更新只会导致多一次重传,可接受(与 thumb 缓存同款简单实现)
    if (@file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES)) === false) {
        error_log('[kf_media] cache file write failed: ' . $file);
    }
}

function mediaCacheGet(string $key): ?string
{
    $entry = kfMediaCacheLoad()[$key] ?? null;
    if (!is_array($entry)) return null;
    $mediaId   = (string)($entry['media_id'] ?? '');
    $createdAt = (int)($entry['created_at'] ?? 0);
    if ($mediaId === '' || $createdAt <= 0) return null;
    // 过期按未命中处理;清理交给 mediaCacheSet 的 7 天淘汰
    if (time() >= $createdAt + KF_MEDIA_CACHE_TTL) return null;
    return $mediaId;
}

function mediaCacheSet(string $key, string $mediaId): void
{
    $data = kfMediaCacheLoad();
    $now = time();
    $data[$key] = ['media_id' => $mediaId, 'created_at' => $now];
    // 淘汰 >7 天条目,防文件无限增长
    foreach ($data as $k => $e) {
        if (!is_array($e) || (int)($e['created_at'] ?? 0) < $now - KF_MEDIA_CACHE_MAX_AGE) {
            unset($data[$k]);
        }
    }
    kfMediaCacheSave($data);
}

function mediaCacheClear(string $key): void
{
    $data = kfMediaCacheLoad();
    if (!isset($data[$key])) return;
    unset($data[$key]);
    kfMediaCacheSave($data);
}

// ──────────────────────────────────────────
// 上传核心
// ──────────────────────────────────────────

/**
 * 本地文件 → media/upload?type=$type(CURLFile multipart,字段名 media)。
 * 写法照抄 api/wecom_kf_roomcard_v37.php 的 uploadImageToWeCom()(261-294 行),
 * 但失败必须记 errcode/errmsg(qa C8)。
 * 日志不含 access_token / 中台凭据。
 */
function kfMediaUploadFile(string $path, string $type): array
{
    $result = ['ok' => false, 'media_id' => '', 'errcode' => -1, 'errmsg' => ''];

    if (!is_file($path) || !is_readable($path)) {
        $result['errmsg'] = '文件不可读';
        error_log('[kf_media] upload skipped: file not readable, type=' . $type);
        return $result;
    }

    require_once __DIR__ . '/wecom_kf_token.php'; // 中立 token include(见文件头注释)
    $token = getAccessToken();
    if (!$token) {
        $result['errmsg'] = '企微 access_token 获取失败';
        error_log('[kf_media] upload failed: access_token unavailable (check wecom.corpid/corp_secret)');
        return $result;
    }

    $timeout = ($type === 'video') ? KF_MEDIA_VIDEO_UPLOAD_TIMEOUT : KF_MEDIA_IMAGE_UPLOAD_TIMEOUT;

    // CURLFile 的 MIME:用 finfo 探测,探测不出时按 type 兜底
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? (string)(finfo_file($finfo, $path) ?: '') : '';
    if ($finfo) finfo_close($finfo);
    if ($mime === '') $mime = ($type === 'video') ? 'video/mp4' : 'image/jpeg';

    $url = 'https://qyapi.weixin.qq.com/cgi-bin/media/upload'
         . '?access_token=' . urlencode($token) . '&type=' . rawurlencode($type);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['media' => new CURLFile($path, $mime, basename($path))],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $resp    = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if (!$resp) {
        $result['errmsg'] = '上传请求失败';
        error_log('[kf_media] media/upload curl failed type=' . $type . ' curl_err=' . $curlErr);
        return $result;
    }

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) {
        $result['errmsg'] = '上传响应非JSON';
        error_log('[kf_media] media/upload non-JSON response type=' . $type);
        return $result;
    }

    $errcode = (int)($data['errcode'] ?? -1);
    $result['errcode'] = $errcode;
    $result['errmsg']  = (string)($data['errmsg'] ?? '');

    if ($errcode === 0 && !empty($data['media_id'])) {
        $result['ok']       = true;
        $result['media_id'] = (string)$data['media_id'];
        return $result;
    }

    // qa C8:失败必须留痕(企微 errmsg 本身不含凭据)
    error_log('[kf_media] media/upload failed type=' . $type
        . ' errcode=' . $errcode . ' errmsg=' . $result['errmsg']);
    return $result;
}

/**
 * URL 下载 → 临时文件 → media/upload → media_id(缓存优先)。
 * $db 参数为调用口径保留(token 缓存在 platform_config,
 * getAccessToken() 自行读 global $db,本层不外呼 DB)。
 */
function uploadMediaFromUrl(PDO $db, string $url, string $type = 'image'): array
{
    $cacheKey = mediaCacheKey($url, $type);
    $cached = mediaCacheGet($cacheKey);
    if ($cached !== null) {
        return ['ok' => true, 'media_id' => $cached, 'errcode' => 0, 'errmsg' => ''];
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'kfmedia_');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => KF_MEDIA_DOWNLOAD_TIMEOUT,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '' || $httpCode !== 200) {
        if ($tmpFile !== false) @unlink($tmpFile);
        // 只记 host,不记整条 URL(防将来 URL 带签名/凭据)
        error_log('[kf_media] download failed http=' . $httpCode
            . ' curl_err=' . $curlErr
            . ' host=' . (string)(parse_url($url, PHP_URL_HOST) ?: '?'));
        return ['ok' => false, 'media_id' => '', 'errcode' => -1, 'errmsg' => '素材下载失败'];
    }
    file_put_contents($tmpFile, (string)$body);

    $res = kfMediaUploadFile((string)$tmpFile, $type);
    if ($tmpFile !== false) @unlink($tmpFile);

    if ($res['ok']) mediaCacheSet($cacheKey, $res['media_id']);
    return $res;
}

/**
 * 本地文件(如 uploads/guide/*.mp4)→ media/upload → media_id(缓存优先)。
 * 换传新文件时随机文件名必然使 path 变化 → 键自然失效,旧条目 7 天内被淘汰。
 */
function uploadMediaFromFile(PDO $db, string $path, string $type = 'video'): array
{
    $cacheKey = mediaCacheKey($path, $type);
    $cached = mediaCacheGet($cacheKey);
    if ($cached !== null) {
        return ['ok' => true, 'media_id' => $cached, 'errcode' => 0, 'errmsg' => ''];
    }

    $res = kfMediaUploadFile($path, $type);
    if ($res['ok']) mediaCacheSet($cacheKey, $res['media_id']);
    return $res;
}

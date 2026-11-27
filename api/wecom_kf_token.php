<?php
/**
 * api/wecom_kf_token.php
 *
 * v3.35 — 企微 access_token 中立 include（蓝图 §7.4.1 / CodeBuddy P1-1）
 *
 * 背景：api/wecom_kf.php 顶层 94-103 行就是路由（GET→URL 验证 / POST→事件回调），
 * 别的文件（api/verify.php、api/wecom_kf_media.php）想复用 getAccessToken() 时
 * require wecom_kf.php 会直接触发回调路由。故把 token 相关函数搬进本中立文件。
 *
 * P1-1（CodeBuddy 方案审发现）：getAccessToken() 内部调用 httpGet()、wecom_kf_log()、
 * wecom_kf_response_error()（原本都定义在 wecom_kf.php 里），只搬 getAccessToken() 会让
 * 只 require config.php 的 verify.php 报 Call to undefined function → 后台视频上传 500。
 * → 四个函数一并搬入本文件，**原函数名、逻辑、缓存键全部不变**，wecom_kf.php 改为 require 本文件。
 *
 * 依赖：仅 api/config.php（pcGet / pcSet）。$db 读全局 global $db（与 wecom_kf.php 同口径，
 * 调用方须先 $db = getDB()）。
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ──────────────────────────────────────────
// 日志 / 错误描述（自 wecom_kf.php 原样搬入）
// ──────────────────────────────────────────

if (!function_exists('wecom_kf_log')) {
    function wecom_kf_log(string $msg): void
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $line = '[' . date('Y-m-d H:i:s') . '] [wecom_kf] ' . $msg . "\n";
        @file_put_contents($logDir . '/wecom_kf.log', $line, FILE_APPEND);
    }
}

if (!function_exists('wecom_kf_response_error')) {
    function wecom_kf_response_error(array $response): string
    {
        return 'errcode=' . (string)($response['errcode'] ?? 'unknown');
    }
}

// ──────────────────────────────────────────
// HTTP helper（自 wecom_kf.php 原样搬入；httpPostJson 仍留在 wecom_kf.php）
// ──────────────────────────────────────────

if (!function_exists('httpGet')) {
    function httpGet(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $httpCode !== 200) {
            wecom_kf_log("HTTP GET failed: code=$httpCode err=$err");
            return null;
        }
        return json_decode($resp, true);
    }
}

// ──────────────────────────────────────────
// access_token 缓存（自 wecom_kf.php:828 原样搬入，缓存键不变）
// ──────────────────────────────────────────

if (!function_exists('getAccessToken')) {
    function getAccessToken(): ?string
    {
        global $db;

        // 检查缓存
        $cached = trim(pcGet($db, 'wecom.access_token_cache', ''));
        $expiresAt = (int)pcGet($db, 'wecom.access_token_expires_at', 0);
        if ($cached && $expiresAt > time() + 60) {  // 提前 60 秒过期避免临界
            return $cached;
        }

        // 调接口
        $corpSecret = trim(pcGet($db, 'wecom.corp_secret', ''));
        $corpId     = trim(pcGet($db, 'wecom.corpid', ''));

        $url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=" . urlencode($corpId) . "&corpsecret=" . urlencode($corpSecret);
        $resp = httpGet($url);
        if (!$resp || !isset($resp['access_token'])) {
            wecom_kf_log('gettoken failed: ' . wecom_kf_response_error(is_array($resp) ? $resp : []));
            return null;
        }

        $token = $resp['access_token'];
        $expiresIn = (int)($resp['expires_in'] ?? 7200);

        // 写缓存
        pcSet($db, 'wecom.access_token_cache', $token);
        pcSet($db, 'wecom.access_token_expires_at', (string)(time() + $expiresIn));

        return $token;
    }
}

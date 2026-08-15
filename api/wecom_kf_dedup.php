<?php
// 微信客服消息去重工具（被 wecom_kf.php require）
// 修复 95001 限流：sync_msg 一次性拉回游标内所有未消费消息
// 文件缓存 24 小时，防游标回退重试时重复发送同 msgid

declare(strict_types=1);

function isMsgProcessed(string $msgId): bool
{
    $cacheFile = __DIR__ . '/../logs/wecom_kf_msgid_cache.json';
    if (!file_exists($cacheFile)) return false;
    $cache = @json_decode(@file_get_contents($cacheFile), true) ?: [];
    $now = time();
    foreach ($cache as $k => $ts) {
        if ($ts < $now - 86400) unset($cache[$k]);
    }
    return isset($cache[$msgId]);
}

function markMsgProcessed(string $msgId): void
{
    $cacheFile = __DIR__ . '/../logs/wecom_kf_msgid_cache.json';
    $cache = [];
    if (file_exists($cacheFile)) {
        $cache = @json_decode(@file_get_contents($cacheFile), true) ?: [];
    }
    $now = time();
    foreach ($cache as $k => $ts) {
        if ($ts < $now - 86400) unset($cache[$k]);
    }
    $cache[$msgId] = $now;
    @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

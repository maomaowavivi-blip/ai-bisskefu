<?php
/**
 * scripts/regression_chat.php
 *
 * 回归测试：改动后跑一遍固定问题清单，确认老功能没被改坏。
 *
 * 用法：
 *   php scripts/regression_chat.php <baseUrl> [--core|--live] [--insecure] [--delay=秒]
 *
 * 例：
 *   php scripts/regression_chat.php https://43.138.217.6 --core   # 打生产
 *   php scripts/regression_chat.php http://localhost:8888 --core   # 打本地 MAMP
 *
 * v3.29.1 修复（原来这个脚本打生产会 16 个用例全 FAIL，误导成"系统坏了"）：
 *   1. baseUrl 是 IP 时自动跳过 TLS 证书校验 —— 生产证书是给域名签的，
 *      IP 直连必然校验失败，curl 拿不到任何响应（也可显式 --insecure）
 *   2. expect 支持 '关键词A|关键词B'，命中任一即通过
 *      （本地 MAMP 与生产的话术/KB 内容不完全一致，硬编码单一关键词会假失败）
 *   3. 连接层失败单独计为「请求失败」并打印原因，不再显示 "actual: 空"
 *   4. live 用例支持 group + prefill：同一 session 先绑一次房，后面连续追问
 *      （也正好覆盖 step=3 连续追问的真实用户路径）
 *   5. 生产有限流（每 IP 每分钟 20 次）：请求间隔默认 1.5s，撞到 429 自动等窗口重置再重试
 */

$baseUrl  = 'http://localhost:8888';
$mode     = '--core';
$insecure = false;
$delay    = 1.5;   // 每次请求后等待秒数（避开生产每分钟 20 次限流）

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--core' || $arg === '--live') {
        $mode = $arg;
    } elseif ($arg === '--insecure') {
        $insecure = true;
    } elseif (strpos($arg, '--delay=') === 0) {
        $delay = max(0.0, (float)substr($arg, 8));
    } elseif (strpos($arg, 'http://') === 0 || strpos($arg, 'https://') === 0) {
        $baseUrl = rtrim($arg, '/');
    }
}

// baseUrl 是 IP → 证书必然对不上，自动降级为跳过校验
$host = parse_url($baseUrl, PHP_URL_HOST) ?: '';
if (filter_var($host, FILTER_VALIDATE_IP)) {
    $insecure = true;
}

$cases = [
    ['id' => '1',  'msg' => '几点入住',          'expect' => '14:00',        'mode' => 'core'],
    ['id' => '2',  'msg' => '中午几点必须走',     'expect' => '12:00',        'mode' => 'core'],
    // 下面几条本地与生产话术不一致（知识库内容不同），用 | 列多个可接受答案
    ['id' => '3',  'msg' => 'WiFi密码多少',       'expect' => '云房卡|小程序', 'mode' => 'core'],
    ['id' => '4',  'msg' => '云房卡是什么',       'expect' => '云房卡',        'mode' => 'core'],
    ['id' => '5',  'msg' => '我想续住一晚',       'expect' => '转接人工|管家', 'mode' => 'core'],
    ['id' => '6',  'msg' => '我要退款',           'expect' => '平台',          'mode' => 'core'],
    ['id' => '7',  'msg' => '可以带宠物吗',       'expect' => '宠物',          'mode' => 'core'],
    ['id' => '13', 'msg' => '附近好吃吗',         'expect' => '没有查到|餐饮|餐厅', 'mode' => 'core'],
    ['id' => '14', 'msg' => '南宁今天适合出门吗', 'expect' => '没有查到',      'mode' => 'core'],

    // 以下需要网关/订单上下文，仅 --live 跑
    ['id' => 'F1', 'msg' => 'order_query:1128148162721995', 'expect' => '查询成功', 'mode' => 'live'],

    // 房间流：同一 group 共用一个 session（先绑一次房，后面连续追问）
    // prefill 顺序不能颠倒：先问房间问题（进 step=1 索要订单号）→ 再发订单号 → 再选房
    ['id' => '8',  'msg' => '房间地址在哪',   'expect' => '地址|广西|亭洪路|万达',
     'group' => 'room', 'prefill' => ['房间地址在哪', '123456789', '10002'], 'mode' => 'live'],
    ['id' => '9',  'msg' => '附近有地铁吗',   'expect' => '就在这里|地址|交通',
     'group' => 'room', 'mode' => 'live', 'note' => '房间类问题不应落兜底'],
    ['id' => '10', 'msg' => '有停车场吗',     'expect' => '停车',
     'group' => 'room', 'mode' => 'live'],
    ['id' => '11', 'msg' => '垃圾放哪',       'expect' => '垃圾|哈哈|温馨提示',
     'group' => 'room', 'mode' => 'live'],
    ['id' => '12', 'msg' => '房间有洗衣机吗', 'expect' => '洗衣机|没有|配有',
     'group' => 'room', 'mode' => 'live'],
    ['id' => '15', 'msg' => '谢谢',           'expect' => '好的|谢谢|不客气',
     'group' => 'room', 'mode' => 'live'],
];

$passed   = 0;
$failed   = 0;
$errored  = 0;
$skipped  = 0;
$sessions = [];

/** 发一条消息，返回 [reply, err, limited] */
function chat(string $baseUrl, string $sessionId, string $message, bool $insecure): array
{
    $body = json_encode(
        ['session_id' => $sessionId, 'message' => $message],
        JSON_UNESCAPED_UNICODE
    );

    $ch = curl_init($baseUrl . '/api/chat.php?action=chat');
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ];
    if ($insecure) {
        // 生产证书是给域名签的，IP 直连必须跳过校验
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['', '请求失败：' . ($curlErr !== '' ? $curlErr : '未知错误') . ' (http=' . $httpCode . ')', false];
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['', '响应不是 JSON（http=' . $httpCode . '）：' . substr((string)$resp, 0, 120), false];
    }
    if (intval($data['code'] ?? 0) === 429) {
        return ['', '触发限流(429)', true];
    }
    if (intval($data['code'] ?? 0) !== 0 && !isset($data['data']['reply'])) {
        return ['', '接口返回异常：' . substr((string)($data['msg'] ?? $resp), 0, 120), false];
    }
    return [(string)($data['data']['reply'] ?? ''), '', false];
}

/** 撞限流就等窗口重置再重试（生产限流：每 IP 每分钟 20 次） */
function chatRetry(string $baseUrl, string $sessionId, string $message, bool $insecure): array
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        [$reply, $err, $limited] = chat($baseUrl, $sessionId, $message, $insecure);
        if (!$limited) {
            return [$reply, $err];
        }
        echo "  (触发限流,等待 62s 让窗口重置后重试)\n";
        sleep(62);
    }
    return ['', '连续触发限流,放弃'];
}

/** expect 支持 'A|B'，命中任一即通过 */
function expectHit(string $reply, string $expect): bool
{
    if ($expect === '') return true;
    foreach (explode('|', $expect) as $kw) {
        $kw = trim($kw);
        if ($kw !== '' && mb_strpos($reply, $kw) !== false) return true;
    }
    return false;
}

/** 请求间隔 */
function pace(float $delay): void
{
    if ($delay > 0) usleep((int)($delay * 1000000));
}

echo "目标: {$baseUrl}    模式: {$mode}    TLS校验: " . ($insecure ? '跳过(证书对不上 IP)' : '开启')
    . "    请求间隔: {$delay}s\n";
if ($insecure) {
    echo "提示: 目标用的是 IP，服务器证书签给域名，已自动跳过证书校验。\n";
}
echo str_repeat('-', 60) . "\n";

foreach ($cases as $c) {
    if ($mode === '--core' && ($c['mode'] ?? '') === 'live') {
        echo "SKIP  #{$c['id']} {$c['msg']} (需要网关/订单上下文)\n";
        $skipped++;
        continue;
    }

    // 同 group 的用例共用一个 session（房间流：绑一次房，后面连续追问）
    $group = (string)($c['group'] ?? ('id_' . $c['id']));
    if (!isset($sessions[$group])) {
        $sessions[$group] = 'reg_' . $group . '_' . time();
        foreach (($c['prefill'] ?? []) as $pre) {
            chatRetry($baseUrl, $sessions[$group], $pre, $insecure);
            pace($delay);
        }
    }
    $sessionId = $sessions[$group];

    [$reply, $err] = chatRetry($baseUrl, $sessionId, $c['msg'], $insecure);
    pace($delay);

    if ($err !== '') {
        echo "ERROR #{$c['id']} {$c['msg']}\n  {$err}\n";
        $errored++;
        continue;
    }

    if (expectHit($reply, (string)$c['expect'])) {
        echo "PASS  #{$c['id']} {$c['msg']} → " . mb_substr($reply, 0, 90) . "\n";
        $passed++;
    } else {
        echo "FAIL  #{$c['id']} {$c['msg']}\n  expect: {$c['expect']}\n  actual: " . mb_substr($reply, 0, 200) . "\n";
        $failed++;
    }
}

echo "\n==========\n";
echo "通过: {$passed}  失败: {$failed}  请求失败: {$errored}  跳过: {$skipped}\n";
if ($errored > 0) {
    echo "提示: 「请求失败」= 没拿到回答（连接失败或接口异常），不等于功能坏了。\n";
}
exit(($failed > 0 || $errored > 0) ? 1 : 0);

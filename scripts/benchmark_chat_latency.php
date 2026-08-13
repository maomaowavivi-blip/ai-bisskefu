#!/usr/bin/env php
<?php
/**
 * 客服回复耗时基准测试
 *
 * 用法（MAMP）:
 *   php scripts/benchmark_chat_latency.php
 *   php scripts/benchmark_chat_latency.php --base http://localhost:8888/aibisskefu
 */

$opts = getopt('', ['base:']);
$base = rtrim($opts['base'] ?? 'http://localhost:8888/aibisskefu', '/');
$order = '1128148162721995';

function postChat(string $base, string $sid, string $ip, string $msg, array $history = []): array {
    $body = ['session_id' => $sid, 'message' => $msg];
    if ($history) {
        $body['history'] = $history;
    }
    $t0 = microtime(true);
    $ch = curl_init($base . '/api/chat.php?action=chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Forwarded-For: ' . $ip,
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $wallMs = (int) round((microtime(true) - $t0) * 1000);
    $json = json_decode($resp ?: '', true) ?: [];
    $data = $json['data'] ?? [];
    $serverMs = $data['elapsed_ms'] ?? null;
    return [
        'wall_ms' => $wallMs,
        'server_ms' => $serverMs,
        'http' => $http,
        'reply' => mb_substr((string)($data['reply'] ?? ''), 0, 60),
        'code' => $json['code'] ?? -1,
    ];
}

echo "Chat latency benchmark\nBase: {$base}\n\n";
printf("%-14s %8s %8s  %s\n", '场景', 'wall_ms', 'srv_ms', '回复摘要');
echo str_repeat('-', 78) . "\n";

$rows = [];
$benchIp = 10;

$run = function (string $label, string $msg, ?string $sid = null, array $history = []) use ($base, &$rows, &$benchIp) {
    $benchIp++;
    $sid = $sid ?: ('bench-' . $label . '-' . time());
    $r = postChat($base, $sid, '10.250.' . ($benchIp % 200 + 1) . '.1', $msg, $history);
    $rows[] = [$label, $r];
    printf("%-14s %8d %8s  %s\n", $label, $r['wall_ms'], $r['server_ms'] ?? '-', $r['reply']);
    return $sid;
};

$run('KB直答', '几点入住');
$run('云房卡引导', 'WiFi密码多少');
$run('转人工', '我想续住一晚');
$run('查单', 'order_query:' . $order);

$sid = 'bench-sidecar-' . time();
$run('Sidecar-要号', '房间地址在哪', $sid);
$run('Sidecar-绑单', $order, $sid);
$run('Sidecar-停车', '怎么停车', $sid);

$run('LLM兜底', '南宁今天适合出门吗');

$history = [
    ['role' => 'user', 'content' => '房间在哪'],
    ['role' => 'assistant', 'content' => '请提供订单号'],
    ['role' => 'user', 'content' => $order],
    ['role' => 'assistant', 'content' => '民族大道137号'],
];
$run('多轮-退房', '它几点退房', 'bench-multi-' . time(), $history);

echo "\n说明:\n";
echo "  wall_ms = 端到端 HTTP 耗时（含网络）\n";
echo "  srv_ms  = chat.php 返回的 elapsed_ms（服务端处理）\n";
echo "  Sidecar 绑单/查单慢主要来自 PMS 订单网关，非 AI\n";

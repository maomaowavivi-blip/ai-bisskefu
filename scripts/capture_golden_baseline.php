<?php
/**
 * scripts/capture_golden_baseline.php
 *
 * v3.3 PR0 — 抓取 chat.php 现有行为的 50 条 golden file 快照
 *
 * 用法：
 *   php scripts/capture_golden_baseline.php [--output tests/golden_chat_baseline.json]
 *
 * 设计：
 *   - 通过 HTTP 调用现有 chat.php（不直接 require，避免全局污染）
 *   - 50 条输入覆盖 §四点六 五类（核心/边界/常见/压力/回归）
 *   - 每条记录：input + expected_intent + match_contains + match_not_contains + ignore_fields + elapsed_ms_max
 *   - 抓取时实际跑 chat.php，记录：reply + elapsed_ms + 实际状态
 *   - 输出 JSON 可直接用于 diff_golden.php（PR1 实施）
 *
 * 修正 33：含 ignore_fields，避免 session_id / elapsed_ms 不固定导致 diff 假阳性
 * 修正 35：脚本顶部做前置环境检查（API Key / Pipeline 关闭）
 * 修正 8：遇错继续，最后报告失败 N 条
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

const CHAT_API_URL = 'http://localhost:8888/aibisskefu/api/chat.php?action=chat';
const PROJECT_ROOT = __DIR__ . '/..';
const ENV_FILE = PROJECT_ROOT . '/.env';

/**
 * 50 条 golden 测试用例
 * 结构：每条含 input + 期望的匹配规则
 * expected_intent 是蓝图打点后的目标；当前 chat.php 不返回 intent 字段，
 * 所以 PR0 阶段 expected_intent 仅作注释参考，diff 工具暂不校验。
 */
$CASES = [
    // ── 核心场景（10 条，对应蓝图 §十二）──
    ['id' => 1,  'category' => '核心', 'message' => 'WiFi密码多少', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => ['云房卡'], 'match_not_contains' => ['密码是', 'WIFI123']],
    ['id' => 2,  'category' => '核心', 'message' => '地址在哪', 'expected_intent' => 'ROOM_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 3,  'category' => '核心', 'message' => 'order_query:1094913365180824016', 'expected_intent' => 'ORDER_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 4,  'category' => '核心', 'message' => '续住一晚多少钱', 'expected_intent' => 'HUMAN',
     'match_contains' => ['转接', '人工'], 'match_not_contains' => []],
    ['id' => 5,  'category' => '核心', 'message' => '今天南宁天气怎么样', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 6,  'category' => '核心', 'message' => '你们有停车位吗', 'expected_intent' => 'ROOM_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 7,  'category' => '核心', 'message' => '刚才卡片是什么', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 8,  'category' => '核心', 'message' => '退订怎么操作', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => ['携程', '美团'], 'match_not_contains' => []],
    ['id' => 9,  'category' => '核心', 'message' => '附近有什么好吃的', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 10, 'category' => '核心', 'message' => 'asdfghjkl', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],

    // ── 边界场景（10 条）──
    ['id' => 11, 'category' => '边界', 'message' => str_repeat('非常长的消息内容测试是否正常工作', 30), 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 12, 'category' => '边界', 'message' => '   ', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 13, 'category' => '边界', 'message' => '😀😁😂🤣😃😄😅😆', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 14, 'category' => '边界', 'message' => '123456789012345', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 15, 'category' => '边界', 'message' => '!@#$%^&*()_+-={}[]|\\:;\'"<>,.?/~`', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 16, 'category' => '边界', 'message' => '我不要WiFi', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 17, 'category' => '边界', 'message' => 'WiFi和地址在哪', 'expected_intent' => 'ROOM_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 18, 'category' => '边界', 'message' => 'Hello, 你们酒店在哪里?', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 19, 'category' => '边界', 'message' => '冇系屋企可以接我嘅', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 20, 'category' => '边界', 'message' => 'wift密码是多少', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => ['WIFI', '密码']],

    // ── 常见问题（10 条）──
    ['id' => 21, 'category' => '常见', 'message' => 'WiFi', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => ['云房卡'], 'match_not_contains' => []],
    ['id' => 22, 'category' => '常见', 'message' => '怎么去', 'expected_intent' => 'ROOM_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 23, 'category' => '常见', 'message' => '可以带宠物吗', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => ['不接受', '禁'], 'match_not_contains' => []],
    ['id' => 24, 'category' => '常见', 'message' => '能接送机吗', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 25, 'category' => '常见', 'message' => '要发票', 'expected_intent' => 'HUMAN',
     'match_contains' => ['转接', '人工'], 'match_not_contains' => []],
    ['id' => 26, 'category' => '常见', 'message' => '要换房', 'expected_intent' => 'HUMAN',
     'match_contains' => ['转接', '人工'], 'match_not_contains' => []],
    ['id' => 27, 'category' => '常见', 'message' => '我要投诉', 'expected_intent' => 'HUMAN',
     'match_contains' => ['转接', '人工'], 'match_not_contains' => []],
    ['id' => 28, 'category' => '常见', 'message' => '空调坏了', 'expected_intent' => 'HUMAN',
     'match_contains' => ['转接', '人工'], 'match_not_contains' => []],
    ['id' => 29, 'category' => '常见', 'message' => '可以寄存行李吗', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 30, 'category' => '常见', 'message' => '提供早餐吗', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],

    // ── 压力场景（10 条）──
    ['id' => 31, 'category' => '压力', 'message' => 'WiFi', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 32, 'category' => '压力', 'message' => 'WiFi', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 33, 'category' => '压力', 'message' => 'WiFi', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 34, 'category' => '压力', 'message' => 'WiFi密码', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 35, 'category' => '压力', 'message' => '你们WiFi密码', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 36, 'category' => '压力', 'message' => 'order_query:1094913365180824016', 'expected_intent' => 'ORDER_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 37, 'category' => '压力', 'message' => 'order_query:9999999999999999999', 'expected_intent' => 'ORDER_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 38, 'category' => '压力', 'message' => 'order_query:', 'expected_intent' => 'ORDER_QUERY',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 39, 'category' => '压力', 'message' => '看 https://example.com 怎么去', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],
    ['id' => 40, 'category' => '压力', 'message' => '我手机号 13800000000 怎么联系', 'expected_intent' => 'UNKNOWN',
     'match_contains' => [], 'match_not_contains' => []],

    // ── 回归场景（10 条，PRD §7 验收项抽代表）──
    ['id' => 41, 'category' => '回归', 'message' => '几点入住', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => ['14:00', '14点'], 'match_not_contains' => []],
    ['id' => 42, 'category' => '回归', 'message' => '几点退房', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => ['12:00', '12点'], 'match_not_contains' => []],
    ['id' => 43, 'category' => '回归', 'message' => '可以吸烟吗', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => ['禁烟', '室内禁'], 'match_not_contains' => []],
    ['id' => 44, 'category' => '回归', 'message' => '怎么取消订单', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => ['携程', '美团'], 'match_not_contains' => []],
    ['id' => 45, 'category' => '回归', 'message' => '门锁密码多少', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => ['云房卡'], 'match_not_contains' => ['密码是']],
    ['id' => 46, 'category' => '回归', 'message' => '押金怎么交', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => ['云房卡'], 'match_not_contains' => []],
    ['id' => 47, 'category' => '回归', 'message' => '要刷脸吗', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => ['云房卡'], 'match_not_contains' => []],
    ['id' => 48, 'category' => '回归', 'message' => '我要订房', 'expected_intent' => 'KNOWLEDGE',
     'match_contains' => ['携程', '美团'], 'match_not_contains' => []],
    ['id' => 49, 'category' => '回归', 'message' => '能开发票吗', 'expected_intent' => 'HUMAN',
     'match_contains' => ['转接', '人工'], 'match_not_contains' => []],
    ['id' => 50, 'category' => '回归', 'message' => '房间里有WiFi吗', 'expected_intent' => 'ROOM_PASSWORD_QUERY',
     'match_contains' => ['云房卡'], 'match_not_contains' => []],
];

/**
 * 前置环境检查（修正 35）
 */
function preflight(): array {
    $errors = [];

    // 1. .env 至少一个 LLM key
    $envContent = file_exists(ENV_FILE) ? file_get_contents(ENV_FILE) : '';
    if (!preg_match('/(DEEPSEEK_API_KEY|MINIMAX_API_KEY)=.+/m', $envContent)) {
        $errors[] = '.env 缺 DEEPSEEK_API_KEY 或 MINIMAX_API_KEY';
    }

    // 2. chat.php 可达
    $ch = curl_init(CHAT_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['session_id' => 'preflight', 'message' => 'ping']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) {
        $errors[] = "chat.php 返回 HTTP $httpCode（期望 200）";
    } elseif ($resp === false) {
        $errors[] = "chat.php 无响应";
    }

    return $errors;
}

/**
 * 跑一条用例
 */
function runCase(array $case): array {
    $sessionId = 'pr0-' . str_pad((string)$case['id'], 3, '0', STR_PAD_LEFT);
    $payload = json_encode([
        'session_id' => $sessionId,
        'message'    => $case['message'],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(CHAT_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $start = microtime(true);
    $resp = curl_exec($ch);
    $elapsedSec = microtime(true) - $start;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $reply = '';
    $intentActual = '';
    $elapsedMs = 0;
    $ok = false;

    if ($err) {
        $ok = false;
    } else {
        $data = json_decode($resp, true);
        if (is_array($data) && isset($data['data'])) {
            $reply = (string)($data['data']['reply'] ?? '');
            $elapsedMs = (int)($data['data']['elapsed_ms'] ?? 0);
            $intentActual = (string)($data['data']['intent'] ?? '');
            $ok = ($httpCode === 200 && ($data['code'] ?? -1) === 0);
        }
    }

    return [
        'id'        => $case['id'],
        'category'  => $case['category'],
        'input'     => ['session_id' => $sessionId, 'message' => $case['message']],
        'expected'  => [
            'expected_intent'       => $case['expected_intent'],
            'match_contains'        => $case['match_contains'],
            'match_not_contains'    => $case['match_not_contains'],
            'ignore_fields'         => ['session_id', 'created_at', 'elapsed_ms', 'visitor_hash', 'source_ip', 'elapsed_ms_curl'],
            'expected_elapsed_ms_max' => 5000,
        ],
        'actual'    => [
            'http_code'   => $httpCode,
            'elapsed_ms'  => $elapsedMs,
            'elapsed_ms_curl' => (int)($elapsedSec * 1000),
            'reply'       => $reply,
            'intent'      => $intentActual,
            'ok'          => $ok,
            'curl_error'  => $err,
        ],
    ];
}

// ── 主流程 ──
echo "=== v3.3 PR0 golden file 抓取 ===\n\n";

echo "1. 前置环境检查...\n";
$errors = preflight();
if (!empty($errors)) {
    echo "❌ 前置检查失败：\n";
    foreach ($errors as $e) echo "  - $e\n";
    exit(1);
}
echo "✅ 前置环境 OK\n";

// 清空 rate_limits，避免之前测试残留触发 20 req/min 限流
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=8889;dbname=aibisskefu_com;charset=utf8mb4',
        'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $deleted = $pdo->exec("DELETE FROM rate_limits WHERE key_str LIKE 'rl:%'");
    echo "✅ 清空 rate_limits（删除 {$deleted} 条）\n";
} catch (Exception $e) {
    echo "⚠️  清空 rate_limits 失败（继续）：{$e->getMessage()}\n";
}

// 解析参数
$outputFile = $argv[1] ?? PROJECT_ROOT . '/tests/golden_chat_baseline.json';
if (strpos($outputFile, '--output=') === 0) {
    $outputFile = substr($outputFile, 9);
}
if (file_exists($outputFile)) {
    echo "⚠️  已存在 $outputFile，将覆盖。\n";
}

echo '2. 跑 ' . count($CASES) . " 条用例...\n";
$results = [];
$failed = 0;
foreach ($CASES as $i => $case) {
    $r = runCase($case);
    $results[] = $r;
    if (!$r['actual']['ok']) {
        $failed++;
        echo "  ❌ #{$case['id']} ({$case['category']}): HTTP {$r['actual']['http_code']} err={$r['actual']['curl_error']}\n";
    } else {
        echo "  ✅ #{$case['id']} ({$case['category']}): " . mb_substr($r['actual']['reply'], 0, 40) . "...\n";
    }
    // 间隔 3100ms 防限流（20 req/min = 1 req/3s，留 0.1s 余量）
    usleep(3100000);
}

echo "\n3. 写入 $outputFile ...\n";
$output = [
    'meta' => [
        'captured_at'  => date('c'),
        'php_version'  => PHP_VERSION,
        'total_cases'  => count($CASES),
        'failed_cases' => $failed,
        'source'       => 'chat.php (v3.3 PR0 baseline)',
        'schema_version' => '1.0',
    ],
    'cases' => $results,
];
$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (file_put_contents($outputFile, $json) === false) {
    echo "❌ 写入失败\n";
    exit(1);
}
echo "✅ 已写入 " . strlen($json) . " 字节\n\n";

if ($failed > 0) {
    echo "⚠️  $failed 条失败（修正 8：失败不中断，已记录到 JSON actual.ok=false）\n";
    exit(0); // 退出 0，人工决定是否重跑
}

echo "✅ 全部成功\n";
exit(0);
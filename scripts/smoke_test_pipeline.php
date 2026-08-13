<?php
/**
 * scripts/smoke_test_pipeline.php
 *
 * v3.3 PR2 — 通过 HTTP 模拟 Pipeline 调用（chat.php 走 Pipeline 后才真正路由到这里）
 *
 * 用法：php scripts/smoke_test_pipeline.php [smoke-cases.json]
 *
 * 设计：
- 不直接 require ChatPipeline（避开 config.php 的 header() 问题）
- 通过 HTTP POST /api/chat.php 模拟（chat.php 当前是旧逻辑，响应里的 intent/workflow 为空）
- 这个脚本的真正价值：PR3 chat.php 切换后跑同一组 smoke 验证 Pipeline 路径
 */

declare(strict_types=1);

const CASES = [
    ['id' => 1, 'message' => 'WiFi密码多少',           'expected_intent' => 'ROOM_PASSWORD_QUERY'],
    ['id' => 2, 'message' => '地址在哪',               'expected_intent' => 'ROOM_QUERY'],
    ['id' => 3, 'message' => 'order_query:1094913365180824016', 'expected_intent' => 'ORDER_QUERY'],
    ['id' => 4, 'message' => '续住一晚多少钱',         'expected_intent' => 'HUMAN'],
    ['id' => 5, 'message' => '今天南宁天气怎么样',     'expected_intent' => 'UNKNOWN'],
    ['id' => 6, 'message' => '退订怎么操作',           'expected_intent' => 'KNOWLEDGE'],
    ['id' => 7, 'message' => 'asdfghjkl',              'expected_intent' => 'UNKNOWN'],
];

foreach (CASES as $case) {
    $sessionId = 'smoke-' . str_pad((string)$case['id'], 3, '0', STR_PAD_LEFT);
    $payload = json_encode([
        'session_id' => $sessionId,
        'message'    => $case['message'],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('http://localhost:8888/aibisskefu/api/chat.php?action=chat');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    $intent = $data['data']['intent'] ?? '(空)';
    $workflow = $data['data']['workflow'] ?? '(空)';
    $reply = mb_substr($data['data']['reply'] ?? '', 0, 50);

    // 验证
    if ($intent === $case['expected_intent']) {
        echo "✅ #{$case['id']}: {$case['message']} → intent={$intent} workflow={$workflow}\n";
    } elseif ($intent === '(空)') {
        echo "⚠️  #{$case['id']}: {$case['message']} → chat.php 旧路径（intent 空，PR3 切换后才有值）\n";
    } else {
        echo "❌ #{$case['id']}: {$case['message']} → expected={$case['expected_intent']} got={$intent}\n";
    }

    usleep(200000);
}

echo "\n⚠️  当前 chat.php 未切到 Pipeline，所有 intent 都是空。\n";
echo "PR3 切换后（pipeline.enabled=true 且 chat.php 调用 ChatPipeline）会显示真实 intent。\n";
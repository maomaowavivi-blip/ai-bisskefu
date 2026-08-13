<?php
/**
 * scripts/smoke_test_pipeline_direct.php
 *
 * v3.3 PR2 — 直接调用 ChatPipeline（绕过 header() 限制）
 *
 * 设计：自己构造 PDO，不 require config.php
 * 验证 Pipeline 内部完整链路：限流 → 安全拦截 → Intent 分类 → Router → Workflow → Renderer
 */

declare(strict_types=1);

// 模拟 CLI 环境
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';

const CASES = [
    ['id' => 1, 'message' => 'WiFi密码多少',           'expected_intent' => 'ROOM_PASSWORD_QUERY'],
    ['id' => 2, 'message' => '地址在哪',               'expected_intent' => 'ROOM_QUERY'],
    ['id' => 3, 'message' => 'order_query:1094913365180824016', 'expected_intent' => 'ORDER_QUERY'],
    ['id' => 4, 'message' => '续住一晚多少钱',         'expected_intent' => 'HUMAN'],
    ['id' => 5, 'message' => '今天南宁天气怎么样',     'expected_intent' => 'UNKNOWN'],
    ['id' => 6, 'message' => '退订怎么操作',           'expected_intent' => 'KNOWLEDGE'],
    ['id' => 7, 'message' => 'asdfghjkl',              'expected_intent' => 'UNKNOWN'],
    ['id' => 8, 'message' => '我要发票',               'expected_intent' => 'HUMAN'],
    ['id' => 9, 'message' => '门锁密码多少',           'expected_intent' => 'ROOM_PASSWORD_QUERY'],
    ['id' => 10, 'message' => '几点入住',              'expected_intent' => 'KNOWLEDGE'],
];

// 1. 手动加载 config.php 的依赖
require_once __DIR__ . '/../api/AgentConfig.php';
require_once __DIR__ . '/../api/PromptEngine.php';
require_once __DIR__ . '/../api/HandoffTriggers.php';
require_once __DIR__ . '/../api/KnowledgeBaseSeed.php';
require_once __DIR__ . '/../api/sidecar/OrderRoomMapper.php';
require_once __DIR__ . '/../api/sidecar/SidecarSearch.php';
require_once __DIR__ . '/../api/sidecar/ChunkBuilder.php';
require_once __DIR__ . '/../api/sidecar/Vectorizer.php';
require_once __DIR__ . '/../api/sidecar/SidecarIntent.php';
require_once __DIR__ . '/../api/sidecar/RoomQueryService.php';
require_once __DIR__ . '/../api/RoomQueryFlow.php';
require_once __DIR__ . '/../api/Intent.php';
require_once __DIR__ . '/../api/IntentClassifier.php';
require_once __DIR__ . '/../api/SessionState.php';
require_once __DIR__ . '/../api/Workflow/AbstractWorkflow.php';
require_once __DIR__ . '/../api/Workflow/YunfangkaCredentialWorkflow.php';
require_once __DIR__ . '/../api/Workflow/RoomQueryWorkflow.php';
require_once __DIR__ . '/../api/Workflow/OrderQueryWorkflow.php';
require_once __DIR__ . '/../api/Workflow/KnowledgeWorkflow.php';
require_once __DIR__ . '/../api/Workflow/SmallTalkWorkflow.php';
require_once __DIR__ . '/../api/Workflow/UnknownWorkflow.php';
require_once __DIR__ . '/../api/Workflow/HandoffWorkflow.php';
require_once __DIR__ . '/../api/IntentRouter.php';
require_once __DIR__ . '/../api/ReplyRenderer.php';
require_once __DIR__ . '/../api/ChatPipeline.php';

// 2. 构造 PDO
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=8889;dbname=aibisskefu_com;charset=utf8mb4',
    'root', 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 3. 清空 rate_limits
$pdo->exec("DELETE FROM rate_limits WHERE key_str LIKE 'rl:%'");

// 3.5 清空 AgentConfig 静态缓存（AgentConfig::$cache 进程级缓存会导致 DB 改了但读不到）
AgentConfig::clearCache();

// 4. 跑 smoke
$passed = 0;
$failed = 0;
foreach (CASES as $case) {
    $sessionId = 'smoke-direct-' . str_pad((string)$case['id'], 3, '0', STR_PAD_LEFT);
    $result = ChatPipeline::process($sessionId, $case['message'], [], $pdo, 'web', 'test-hash', '127.0.0.1');

    if ($result['code'] !== 0) {
        echo "❌ #{$case['id']}: HTTP {$result['code']} msg={$result['msg']}\n";
        $failed++;
        continue;
    }

    $intent = $result['data']['intent'] ?? '';
    $workflow = $result['data']['workflow'] ?? '';
    $reply = mb_substr($result['data']['reply'] ?? '', 0, 50);

    if ($intent === $case['expected_intent']) {
        echo "✅ #{$case['id']}: {$case['message']} → intent={$intent} workflow={$workflow}\n";
        $passed++;
    } else {
        echo "❌ #{$case['id']}: {$case['message']} → expected={$case['expected_intent']} got={$intent}\n";
        $failed++;
    }
    usleep(200000);
}

echo "\n通过: $passed / " . count(CASES) . "\n";
exit($failed > 0 ? 1 : 0);
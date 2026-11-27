<?php
/**
 * scripts/test_v335_mixed_order_input.php
 *
 * v3.35.2 回归：中文/文字前后缀 + 订单号混合输入必须能查到订单。
 * 生产报障：`美团123456789` / `查单123456789` 意图判为 ORDER_QUERY 却查单失败。
 *
 * 跑法：/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/test_v335_mixed_order_input.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/helpers/order_candidate.php';
require_once __DIR__ . '/../api/sidecar/SidecarIntent.php';

$pass = 0; $fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name\n"; }
}

// ── 1. 提取函数本身（不依赖 DB / 中台）──
check('中文前缀「美团」+ 订单号 → 抠出数字',
    extractOrderCandidate('美团123456789') === '123456789');
check('中文前缀「查单」+ 订单号 → 抠出数字',
    extractOrderCandidate('查单123456789') === '123456789');
check('「查一下」+ 订单号 → 抠出数字',
    extractOrderCandidate('查一下123456789') === '123456789');
check('长订单号带中文前后缀 → 抠出数字',
    extractOrderCandidate('酒店订单号是：1128140821351313，谢谢') === '1128140821351313');
check('纯数字订单号不变',
    extractOrderCandidate('123456789') === '123456789');
check('order_query: 前缀由 workflow 单独处理，提取函数也能抠出',
    extractOrderCandidate('order_query:123456789') === '123456789');
check('短房号 10002 不当订单号',
    extractOrderCandidate('房间 10002') === '');
check('日期 2026-11-27 不当订单号（用 extractOrderCandidate 而非 extractOrderNo）',
    extractOrderCandidate('2026-11-27') === '');

// ── 2. 端到端：走 OrderQueryWorkflow，验证中文+订单号混合真能查到 ──
$db = getDB();
require_once __DIR__ . '/../api/Intent.php';
require_once __DIR__ . '/../api/AgentConfig.php';
require_once __DIR__ . '/../api/Workflow/AbstractWorkflow.php';
require_once __DIR__ . '/../api/Workflow/OrderQueryWorkflow.php';

// 安全垫前提：本地三段配置必须为空，否则 reply 会带引导文字
foreach (['precheckin','during','after'] as $k) {
    if (pcGet($db, 'order_reply.text.' . $k, '') !== '') {
        echo "SKIP 端到端用例：本地三段配置非空（安全垫前提不满足）\n";
        break;
    }
}

$runQuery = function (string $msg) use ($db): array {
    $ctx = new IntentContext();
    $ctx->intent = Intent::ORDER_QUERY;
    $ctx->slots['original_message'] = $msg;
    $ctx->slots['channel'] = 'web';
    $wf = new OrderQueryWorkflow($db, new AgentConfig($db), $ctx, [], 'mix_test_' . md5($msg), '', '127.0.0.1');
    $r = $wf->handle();
    return [$r->text, $r->extra];
};

foreach (['美团123456789', '查单123456789', '查一下123456789'] as $msg) {
    [$text, $extra] = $runQuery($msg);
    check("端到端「{$msg}」→ 查到订单（不是 ORDER_NOT_FOUND）",
        mb_strpos($text, '订单查询成功') !== false);
    check("端到端「{$msg}」→ 房型正确", mb_strpos($text, '测试房123456789') !== false);
}

// ── 2b. qa 方案审建议的边界守卫 ──
check('/u 反例：加 /u 后中文+数字抠不出（防止后人误加修饰符）',
    preg_match('/\b(\d{8,30})\b/u', '美团123456789') === 0);
check('多段数字取首个（与发卡链路同口径）',
    extractOrderCandidate('123456789 987654321') === '123456789');
check('字母+数字订单号保原文（extractOrderCandidate 比 SidecarIntent 版安全）',
    extractOrderCandidate('ABC12345678') === '');
// qa 方案审：裸前缀 `order_query:` 因正则 `(.+)` 要求至少一字符 → 走不到引导分支，
// 原文送中台 → NOT_FOUND。这是补丁前的既有行为，必须逐字节不变（防悄悄变化）。
check('order_query: 裸前缀 → NOT_FOUND 逐字节不变', (function () use ($db) {
    $ctx = new IntentContext();
    $ctx->intent = Intent::ORDER_QUERY;
    $ctx->slots['original_message'] = 'order_query:';
    $ctx->slots['channel'] = 'web';
    $wf = new OrderQueryWorkflow($db, new AgentConfig($db), $ctx, [], 'bare_prefix', '', '127.0.0.1');
    return $wf->handle()->text === ORDER_NOT_FOUND_REPLY;
})());

// ── 3. 不误伤：order_query: 前缀仍最高优先 ──
[$text] = $runQuery('order_query:123456789');
check('order_query: 前缀仍正常', mb_strpos($text, '订单查询成功') !== false);

// ── 4. 不误伤：纯文字非订单号仍返回 ORDER_NOT_FOUND ──
[$text] = $runQuery('今天天气不错');
check('非订单号文字 → 维持 ORDER_NOT_FOUND 兜底',
    $text === ORDER_NOT_FOUND_REPLY);

echo "\n== mixed order input: $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);

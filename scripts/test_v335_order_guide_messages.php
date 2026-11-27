<?php
/**
 * scripts/test_v335_order_guide_messages.php
 *
 * v3.35 单测：buildOrderGuideMessages 消息编排（蓝图 §5.2 / §5.2.1 / §5.2.3 / 验收 §9.1 条数分列）
 * 每个场景核对：实际发送条数（'cards' 占位按 cardCount 张计）≤5（5 卡及以上旧债场景除外，单独注明）。
 *
 * 跑法：/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/test_v335_order_guide_messages.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/wecom_kf_order_guide.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name\n";
    }
}

/** 实际客户收到的条数：'cards' 占位按其 count 展开为对应张数 */
function wireCount(array $msgs): int
{
    $n = 0;
    foreach ($msgs as $m) {
        $n += (($m['type'] ?? '') === 'cards') ? max(1, (int)($m['count'] ?? 1)) : 1;
    }
    return $n;
}
function types(array $msgs): array
{
    return array_map(static function ($m) { return $m['type']; }, $msgs);
}
function textWithMeta(array $msgs, string $meta): string
{
    foreach ($msgs as $m) {
        if (($m['type'] ?? '') === 'text' && ($m['meta'] ?? '') === $meta) return (string)$m['content'];
    }
    return '';
}

$lead  = '您好，欢迎入住柚光～';
$order = "订单查询成功：\n房型：测试房\n入住：2026-12-10\n离店：2026-12-12";
$reason = '入住当天才能自助生成云房卡哦，请当天再来生成入住云房卡。';
$gText = "第一步\n往前走\n第二步\n右拐就到了";
$imgs3 = ['https://cdn.example.com/a.png', 'https://cdn.example.com/b.png', 'https://cdn.example.com/c.png'];

// ── 未到入住 · 单房（§5.2 场景1）：M1 + 指引文字 + 图≤3 = 5 ──
$m = buildOrderGuideMessages([
    'stage' => 'precheckin', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $reason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => $gText, 'guideImages' => $imgs3, 'videoPath' => '',
]);
check('未到入住·单房·3图 → 5 条（=5 贴线）', wireCount($m) === 5);
check('未到入住·M1 含 lead+订单信息+房卡原因',
    mb_strpos(textWithMeta($m, 'm1'), $lead) === 0
    && mb_strpos(textWithMeta($m, 'm1'), '订单查询成功') !== false
    && mb_strpos(textWithMeta($m, 'm1'), '入住当天才能自助生成') !== false);
check('未到入住·序列 = M1,指引文字,图,图,图', types($m) === ['text', 'text', 'image', 'image', 'image']);

// 4 图 → 截 3 → 仍 5 条
$m = buildOrderGuideMessages([
    'stage' => 'precheckin', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $reason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => $gText, 'guideImages' => array_merge($imgs3, ['https://cdn.example.com/d.png']), 'videoPath' => '',
]);
check('4 图截 3 → 5 条', wireCount($m) === 5 && substr_count(implode(',', types($m)), 'image') === 3);

// 0 图（§5.2 场景2）：M1 + 指引文字 = 2
$m = buildOrderGuideMessages([
    'stage' => 'precheckin', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $reason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => $gText, 'guideImages' => [], 'videoPath' => '',
]);
check('0 图 → 2 条', wireCount($m) === 2);

// 指引为空（steps=[]）：只 M1，1 条（§5.3 不说暂无）
$m = buildOrderGuideMessages([
    'stage' => 'precheckin', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $reason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => '', 'guideImages' => [], 'videoPath' => '',
]);
check('中台指引为空 → 1 条（无「暂无」）', wireCount($m) === 1 && mb_strpos($m[0]['content'], '暂无') === false);

// ── 未到入住 · 多房（§5.2 场景3）：M1 含选房问句 = 1 ──
$pick = "您一共订了 2 间房，请回复序号选择（回复 1 或 2 即可）：\n① 和德新天地 2号楼C单元33楼3303\n② 和德公馆 1期1栋15楼1503";
$m = buildOrderGuideMessages([
    'stage' => 'precheckin', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $reason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => $pick,
    'guideText' => '', 'guideImages' => [], 'videoPath' => '',
]);
check('未到入住·多房 → 1 条', wireCount($m) === 1);
check('选房问句并入 M1 末尾', mb_substr(textWithMeta($m, 'm1'), -mb_strlen($pick)) === $pick);

// ── 入住期间 · 有卡 1 卡 + 视频（§5.2）：M1 + 卡 + 视频 = 3 ──
$m = buildOrderGuideMessages([
    'stage' => 'during', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => '', 'cardCount' => 1, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => '', 'guideImages' => [], 'videoPath' => '/srv/uploads/guide/g.mp4',
]);
check('入住期间·1卡+视频 → 3 条', wireCount($m) === 3);
check('视频排在房卡占位之后（发卡后才发视频）', types($m) === ['text', 'cards', 'video']);

// 2 卡（§5.2 贴线）：M1 + 间数引导 + 卡×2 + 视频 = 5
$m = buildOrderGuideMessages([
    'stage' => 'during', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => '', 'cardCount' => 2, 'roomCountText' => '您一共订了 2 间房,以下是各房间的云房卡～',
    'roomPickText' => '', 'guideText' => '', 'guideImages' => [], 'videoPath' => '/srv/uploads/guide/g.mp4',
]);
check('入住期间·2卡+间数引导+视频 → 5 条（=5 贴线）', wireCount($m) === 5);
check('2卡场景间数引导保持独立一条（§5.2.2 优先保留）',
    textWithMeta($m, 'room_count') === '您一共订了 2 间房,以下是各房间的云房卡～');

// 3 卡 + 视频：6 → 降级①间数引导并入 M1 → 5
$m = buildOrderGuideMessages([
    'stage' => 'during', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => '', 'cardCount' => 3, 'roomCountText' => '您一共订了 3 间房,以下是各房间的云房卡～',
    'roomPickText' => '', 'guideText' => '', 'guideImages' => [], 'videoPath' => '/srv/uploads/guide/g.mp4',
]);
check('入住期间·3卡 → 降级①并入后 5 条', wireCount($m) === 5);
check('降级①后间数引导文案并入 M1 且无独立一条',
    textWithMeta($m, 'room_count') === ''
    && mb_strpos(textWithMeta($m, 'm1'), '您一共订了 3 间房') !== false);

// 4 卡 + 视频 + 间数引导：1+1+4+1=7 → ①并入引导(6) → ②无图可删 → ③弃视频 → 5
// ⚠️ 入参必须与真实通道层一致（wecom_kf.php during 分支）：roomCountText 非空、during 无指引图
$m = buildOrderGuideMessages([
    'stage' => 'during', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => '', 'cardCount' => 4,
    'roomCountText' => '您一共订了 4 间房,以下是各房间的云房卡～',
    'roomPickText' => '', 'guideText' => '', 'guideImages' => [], 'videoPath' => '/srv/uploads/guide/g.mp4',
]);
check('入住期间·4卡+视频 → 两级降级 5 条', wireCount($m) === 5);
check('4卡场景弃视频/弃图后文字与卡保住',
    in_array('cards', types($m), true) && textWithMeta($m, 'm1') !== '');
check('4卡场景间数引导并入 M1（不再单独一条）',
    textWithMeta($m, 'room_count') === ''
    && mb_strpos(textWithMeta($m, 'm1'), '4 间房') !== false);

// 5 卡及以上（§5.2.4 管家模式，苏鸣 2026-11-27 定）：卡装不下 → 不发卡，改发管家提示
$butler = "请拨打电话联系管家：\n橙子：15676161693";
$m = buildOrderGuideMessages([
    'stage' => 'during', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => '', 'cardCount' => 5, 'roomCountText' => '',
    'roomPickText' => '', 'guideText' => '', 'guideImages' => [], 'videoPath' => '/srv/uploads/guide/g.mp4',
    'butlerContact' => $butler,
]);
check('5卡 → 管家模式：2 条（M1 + 管家提示）', wireCount($m) === 2);
check('5卡 → 管家模式：不产生 cards 占位（通道层据此跳过发卡）',
    !in_array('cards', types($m), true));
check('5卡 → 管家模式：不发视频（没有卡却发房卡指引视频不合理）',
    !in_array('video', types($m), true));
check('5卡 → 管家提示含间数与管家联系方式',
    mb_strpos(textWithMeta($m, 'butler'), '5 间房') !== false
    && mb_strpos(textWithMeta($m, 'butler'), '橙子') !== false);
check('5卡 → 管家模式仍保住 M1（引导文字+订单信息）',
    mb_strpos(textWithMeta($m, 'm1'), $lead) === 0
    && mb_strpos(textWithMeta($m, 'm1'), $order) !== false);

// 5 卡 + 无管家联系方式 → 仍有兜底文案，不空发
$m = buildOrderGuideMessages([
    'stage' => 'during', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => '', 'cardCount' => 7, 'roomCountText' => '',
    'roomPickText' => '', 'guideText' => '', 'guideImages' => [], 'videoPath' => '/srv/uploads/guide/g.mp4',
    'butlerContact' => '',
]);
check('7卡 → 管家模式仍 2 条（更多卡也装不下 → 同样走管家）', wireCount($m) === 2);
check('7卡 → 无联系方式时用兜底文案', textWithMeta($m, 'butler') !== '');

// 入住期间 · 无卡：M1(lead+订单+原因) + 视频 = 2（§5.2）
$m = buildOrderGuideMessages([
    'stage' => 'during', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $reason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => '', 'guideImages' => [], 'videoPath' => '/srv/uploads/guide/g.mp4',
]);
check('入住期间·无卡+视频 → 2 条', wireCount($m) === 2);

// 退房后：M1 = 1 条
$m = buildOrderGuideMessages([
    'stage' => 'after', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $reason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => '', 'guideImages' => [], 'videoPath' => '',
]);
check('退房后 → 1 条', wireCount($m) === 1);

// ── 指引文字 >600 拆 2 条 + 图片上限降 2（§5.2.3）──
$long = str_repeat('入', 800); // 800 字
$m = buildOrderGuideMessages([
    'stage' => 'precheckin', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $reason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => $long, 'guideImages' => $imgs3, 'videoPath' => '',
]);
check('指引 800 字拆 2 条且图上限 2 → 5 条', wireCount($m) === 5);
$guideTexts = [];
foreach ($m as $msg) {
    if (($msg['meta'] ?? '') === 'guide') $guideTexts[] = (string)$msg['content'];
}
check('拆条两段各 ≤600 且拼回=原文', count($guideTexts) === 2
    && mb_strlen($guideTexts[0]) === 600 && mb_strlen($guideTexts[1]) === 200
    && ($guideTexts[0] . $guideTexts[1]) === $long);

// 指引 >1200 截断到 1200 再拆
$huge = str_repeat('住', 1500);
$m = buildOrderGuideMessages([
    'stage' => 'precheckin', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => '', 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => $huge, 'guideImages' => [], 'videoPath' => '',
]);
$totalGuide = 0;
foreach ($m as $msg) {
    if (($msg['meta'] ?? '') === 'guide') $totalGuide += mb_strlen((string)$msg['content']);
}
check('指引 1500 字截到 1200（§5.2.3）', $totalGuide === 1200 && wireCount($m) <= 5);

// ── M1 溢出：房卡原因先截（§5.2.3），M1 ≤600 ──
$longReason = $reason . str_repeat('详', 500);
$m = buildOrderGuideMessages([
    'stage' => 'precheckin', 'lead' => $lead, 'orderText' => $order,
    'cardReason' => $longReason, 'cardCount' => 0, 'roomCountText' => '', 'roomPickText' => '',
    'guideText' => $gText, 'guideImages' => $imgs3, 'videoPath' => '',
]);
check('M1 溢出：长房卡原因被截、M1 ≤600 且总条数 5',
    mb_strlen(textWithMeta($m, 'm1')) <= 600
    && mb_strpos(textWithMeta($m, 'm1'), '入住当天才能自助生成') !== false
    && wireCount($m) === 5);

echo "\n== guide messages: $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);

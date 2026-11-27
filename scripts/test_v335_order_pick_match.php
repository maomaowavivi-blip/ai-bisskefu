<?php
/**
 * scripts/test_v335_order_pick_match.php
 *
 * v3.35 单测：多房选房匹配（蓝图 §5.4.1 / 验收 §9.3）
 * 覆盖：序号 / ①-⑩ 归一化 / 纯数字房号 / 双向包含 / 「和德」歧义 / 新订单号逃生门 / 太短不匹配。
 *
 * 跑法：/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/test_v335_order_pick_match.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/OrderGuidePick.php';

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

// 样本对（生产实测非纯数字房号，§4.2）
$cands = [
    ['baoyu_room_no' => '1217714', 'room_code' => '2号楼C单元33楼3303', 'display_name' => '和德新天地 2号楼C单元33楼3303'],
    ['baoyu_room_no' => '1678045', 'room_code' => '1期1栋15楼1503',     'display_name' => '和德公馆 1期1栋15楼1503'],
];
$hit1 = static function (array $m): bool { return $m['hit'] !== null && $m['hit']['baoyu_room_no'] === '1217714'; };
$hit2 = static function (array $m): bool { return $m['hit'] !== null && $m['hit']['baoyu_room_no'] === '1678045'; };

// 序号
check('序号 1 → 第一间', $hit1(OrderGuidePick::matchCandidate('1', $cands)));
check('序号 2 → 第二间', $hit2(OrderGuidePick::matchCandidate('2', $cands)));
check('① 归一化 → 第一间', $hit1(OrderGuidePick::matchCandidate('①', $cands)));
check('② 归一化 → 第二间', $hit2(OrderGuidePick::matchCandidate('②', $cands)));
check('带空白「 2 」→ 第二间', $hit2(OrderGuidePick::matchCandidate(' 2 ', $cands)));
check('序号越界 3（N=2）→ 不匹配', OrderGuidePick::matchCandidate('3', $cands)['hit'] === null);

// 房号完全相等 / 大小写（ASCII 归一）
check('房号全等 → 命中', $hit1(OrderGuidePick::matchCandidate('2号楼C单元33楼3303', $cands)));
check('房号大小写差异 → 命中', $hit1(OrderGuidePick::matchCandidate('2号楼c单元33楼3303', $cands)));

// 双向包含
$m = OrderGuidePick::matchCandidate('3303', $cands);
check('尾段房号 3303 → 命中第一间', $hit1($m));
$m = OrderGuidePick::matchCandidate('1503', $cands);
check('尾段房号 1503 → 命中第二间', $hit2($m));
$m = OrderGuidePick::matchCandidate('和德新天地', $cands);
check('完整小区名（t 被 display_name 包含）→ 命中第一间', $hit1($m));
check('客人输入包含完整房号 → 命中', $hit2(OrderGuidePick::matchCandidate('我要1期1栋15楼1503这间', $cands)));

// 「和德」歧义：不猜、判歧义（qa B9）
$m = OrderGuidePick::matchCandidate('和德', $cands);
check('「和德」歧义 → ambiguous=true', $m['ambiguous'] === true && $m['hit'] === null);
// 同一候选 code+name 双命中只算 1（去重），不应误判歧义
$m = OrderGuidePick::matchCandidate('2号楼C单元33楼3303', $cands);
check('code+name 同源命中去重 → 不算歧义', $m['ambiguous'] === false && $m['hit'] !== null);

// 新订单号逃生门（qa A2：8-30 位数字不匹配候选 → 清 stage 正常查单）
$m = OrderGuidePick::matchCandidate('123456789', $cands);
check('新订单号 123456789 → newOrderDigits', $m['newOrderDigits'] === '123456789' && $m['hit'] === null && $m['ambiguous'] === false);

// 数字命中候选房号 / baoyu 房号
$candsNum = [
    ['baoyu_room_no' => '88880001', 'room_code' => '10002', 'display_name' => '测试房A 10002'],
    ['baoyu_room_no' => '88880002', 'room_code' => '10003', 'display_name' => '测试房B 10003'],
];
check('纯数字房号 10002 → 命中', OrderGuidePick::matchCandidate('10002', $candsNum)['hit'] !== null);
check('baoyu房号 88880002 → 命中第二间', OrderGuidePick::matchCandidate('88880002', $candsNum)['hit']['room_code'] === '10003');

// 短输入防误伤（较短一方长度 <2 不计）
$m = OrderGuidePick::matchCandidate('2', $cands); // '2' 走序号规则 → 第二间（非包含误伤所有含 2 的房号）
check('单字符 2 → 序号命中第二间（非包含误伤）', $hit2($m));
$m = OrderGuidePick::matchCandidate('德', $cands); // 单字包含：较短=1 <2 不匹配 → 也非数字 → 无命中
check('单字「德」→ 不匹配（长度<2 不计包含）', $m['hit'] === null && $m['ambiguous'] === false);

// 无关文本 → 全 null（走正常流程，不追问）
$m = OrderGuidePick::matchCandidate('附近有地铁吗', $cands);
check('无关问题 → 无命中不歧义', $m['hit'] === null && $m['ambiguous'] === false && $m['newOrderDigits'] === null);

// 空消息 / 空候选
check('空消息 → 无命中', OrderGuidePick::matchCandidate('', $cands)['hit'] === null);
check('空候选 → 无命中', OrderGuidePick::matchCandidate('1', [])['hit'] === null);

// 选房问句格式（§5.2 样例逐字核对）
$pick = OrderGuidePick::buildPickText($cands);
$want = "您一共订了 2 间房，请回复序号选择（回复 1 或 2 即可）：\n"
      . "① 和德新天地 2号楼C单元33楼3303\n"
      . "② 和德公馆 1期1栋15楼1503";
check('选房问句与 §5.2 样例一致', $pick === $want);

// guideTextFromSteps：title+text 用 \n 拼接，原文不改（§5.2.3）
$steps = [
    ['text' => '往前走', 'title' => '第一步', 'images' => []],
    ['text' => '',       'title' => '第二步', 'images' => ['https://x/1.png']], // 有图无字（checkinAll 保图）
    ['text' => '**右拐** 原样', 'title' => '', 'images' => []],
];
$gt = OrderGuidePick::guideTextFromSteps($steps);
check('指引文字拼接=中台原文（Markdown 符号原样、空 text 只留 title）',
    $gt === "第一步\n往前走\n第二步\n**右拐** 原样");

// ── 宝寓房号命中（qa 验收 D7 缺口）：客人直接回宝寓房号也要能选中 ──
// 1678045 是 7 位（<8），旧规则只对 8-30 位数字做房号匹配 → 匹配不上
check('D8 宝寓房号全等命中(7位)', $hit2(OrderGuidePick::matchCandidate('1678045', $cands)));
// 9 位宝寓房号必须优先按候选命中，不能被当成新订单号（否则测试房 123456789 会误判）
$c9 = [['baoyu_room_no' => '123456789', 'room_code' => '10002', 'display_name' => '测试房 10002']];
$m9 = OrderGuidePick::matchCandidate('123456789', $c9);
check('D9 9位宝寓房号优先命中(不被当新订单号)',
    $m9['hit'] !== null
    && $m9['hit']['baoyu_room_no'] === '123456789'
    && $m9['newOrderDigits'] === null);
// 真·新订单号（不匹配任何候选）仍然要被识别为新订单号并清 stage
$mnew = OrderGuidePick::matchCandidate('887766554433', $c9);
check('D10 9位新订单号仍识别为新订单号',
    $mnew['hit'] === null && $mnew['newOrderDigits'] === '887766554433');

echo "\n== pick match: $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);

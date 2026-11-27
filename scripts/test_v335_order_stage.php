<?php
/**
 * scripts/test_v335_order_stage.php
 *
 * v3.35 单测：orderStage() 时段判定（蓝图 §7.1.1 / 验收 §9.2）
 * 覆盖：入住前一天 / 入住当天 / 离店当天 / 离店次日 / 空日期→unknown / 畸形日期→unknown /
 *       凌晨边界（$today 传 2026-11-27 与 2026-11-28 两种）。$today 纯参数传入，不依赖环境时区。
 *
 * 跑法：/Applications/MAMP/bin/php/php8.3.30/bin/php scripts/test_v335_order_stage.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../api/Workflow/OrderQueryWorkflow.php';

$pass = 0;
$fail = 0;
function check(string $name, $got, $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name  got=" . var_export($got, true) . " want=" . var_export($want, true) . "\n";
    }
}

// 边界日序（中台实测格式 Y-m-d H:i:s）
$ci = '2026-11-27 00:00:00';
$co = '2026-11-28 00:00:00';

check('入住前一天 → precheckin', orderStage('2026-11-26', $ci, $co), 'precheckin');
check('入住当天 → during',       orderStage('2026-11-27', $ci, $co), 'during');
check('离店当天 → during',       orderStage('2026-11-28', $ci, $co), 'during');
check('离店次日 → after',        orderStage('2026-11-29', $ci, $co), 'after');

// 凌晨边界（生产 PHP=UTC/MySQL=CST：同一自然日两侧 $today 判定不同，全部由调用方传参决定）
check('凌晨边界 A（$today=11-27，当天入住）→ during', orderStage('2026-11-27', $ci, $co), 'during');
check('凌晨边界 B（$today=11-28，离店日）→ during',   orderStage('2026-11-28', $ci, $co), 'during');
$sameDayCi = '2026-11-27 00:00:00';
$sameDayCo = '2026-11-27 00:00:00'; // 当晚退房订单
check('当日往返 $today=入住日 → during', orderStage('2026-11-27', $sameDayCi, $sameDayCo), 'during');
check('当日往返 $today=次日 → after',    orderStage('2026-11-28', $sameDayCi, $sameDayCo), 'after');

// 空 / 畸形 → unknown（中性，行为与今天一致）
check('check_in 空 → unknown',        orderStage('2026-11-27', '', $co), 'unknown');
check('check_out 空 → unknown',       orderStage('2026-11-27', $ci, ''), 'unknown');
check('check_in 乱码 → unknown',      orderStage('2026-11-27', 'abc', $co), 'unknown');
check('check_in 斜杠格式 → unknown',  orderStage('2026-11-27', '2026/11/27', $co), 'unknown');
check('check_out 乱码 → unknown',     orderStage('2026-11-27', $ci, 'null'), 'unknown');
check('两者都空 → unknown',           orderStage('2026-11-27', '', ''), 'unknown');
check('$today 空（CURDATE 失败）→ unknown', orderStage('', $ci, $co), 'unknown');
check('$today 非法 → unknown',        orderStage('not-a-date', $ci, $co), 'unknown');

// ISO8601 带时区前缀：正则取前 10 位有效
check('ISO 带 T 时区前缀仍有效 → during', orderStage('2026-11-27', '2026-11-27T14:00:00+08:00', '2026-11-28T12:00:00+08:00'), 'during');

echo "\n== orderStage: $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);

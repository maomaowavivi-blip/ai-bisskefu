#!/usr/bin/env php
<?php
/**
 * 同步系统默认转人工词库到数据库
 * 用法：php scripts/sync_handoff_triggers.php
 */
$root = dirname(__DIR__);
require $root . '/api/config.php';
require $root . '/api/HandoffTriggers.php';

$db = getDB();
$stats = HandoffTriggers::syncDefaultLibrary($db);

echo "Handoff triggers synced.\n";
echo "  added:   {$stats['added']}\n";
echo "  updated: {$stats['updated']}\n";
echo "  total:   {$stats['total']}\n";

$pCounts = [];
foreach ($db->query('SELECT priority, COUNT(*) c FROM handoff_triggers GROUP BY priority ORDER BY priority')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pCounts[(int)$row['priority']] = (int)$row['c'];
}
foreach ([0, 1, 2, 3, 4] as $p) {
    $n = $pCounts[$p] ?? 0;
    echo "  P{$p}: {$n}\n";
}

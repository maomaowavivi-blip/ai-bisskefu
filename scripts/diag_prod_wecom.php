<?php
// 临时诊断脚本:查询 wecom 配置(不打印完整 secret,只看长度和前缀)
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/www/wwwroot/aibisskefu/api/config.php';
$db = getDB();
$rows = $db->query("SELECT `key`, value, CHAR_LENGTH(value) AS len FROM platform_config WHERE `key` LIKE 'wecom.%' ORDER BY `key`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $prefix = substr($r['value'], 0, 8);
    echo $r['key'] . " | prefix=" . $prefix . " | len=" . $r['len'] . PHP_EOL;
}
// 也看下 chat_logs 有没有 intent 字段(确认 migration 是否跑了)
$cols = $db->query("SHOW COLUMNS FROM chat_logs LIKE 'intent'")->fetchAll(PDO::FETCH_ASSOC);
echo "chat_logs.intent 字段存在: " . (count($cols) > 0 ? 'YES' : 'NO') . PHP_EOL;
$cols2 = $db->query("SHOW COLUMNS FROM chat_logs LIKE 'workflow'")->fetchAll(PDO::FETCH_ASSOC);
echo "chat_logs.workflow 字段存在: " . (count($cols2) > 0 ? 'YES' : 'NO') . PHP_EOL;

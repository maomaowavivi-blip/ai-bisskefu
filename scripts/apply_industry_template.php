#!/usr/bin/env php
<?php
/**
 * CLI：应用行业模板（KB + handoff + Layer B defaults）
 *
 * 用法：
 *   php scripts/apply_industry_template.php --industry=homestay
 *   php scripts/apply_industry_template.php --industry=homestay --skip-kb --skip-handoff
 */

$opts = getopt('', array('industry:', 'skip-kb', 'skip-handoff'));
$industry = $opts['industry'] ?? 'homestay';
$importKb = !isset($opts['skip-kb']);
$importHandoff = !isset($opts['skip-handoff']);

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/IndustryTemplate.php';

$db = getDB();
$result = IndustryTemplate::apply($db, $industry, $importKb, $importHandoff);

echo "apply_industry_template.php\n";
echo "Industry: {$result['industry']}\n";
echo "Agent config keys: {$result['agent_keys']}\n";
echo "KB imported: {$result['kb_imported']}\n";
echo "Handoff imported: {$result['handoff_imported']}\n";
echo "Done.\n";

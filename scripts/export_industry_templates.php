#!/usr/bin/env php
<?php
/**
 * 从 KnowledgeBaseSeed / HandoffTriggers 导出行业模板 JSON
 *
 * 用法：php scripts/export_industry_templates.php [--industry=homestay]
 */

$opts = getopt('', array('industry:'));
$industry = $opts['industry'] ?? 'homestay';

$root = dirname(__DIR__);
require_once $root . '/api/KnowledgeBaseSeed.php';
require_once $root . '/api/HandoffTriggers.php';

$dir = $root . '/templates/' . $industry;
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

// KB seed
$kbRows = array();
foreach (KnowledgeBaseSeed::catalog() as $group) {
    $category = $group['desc'] ?? '默认';
    foreach ($group['entries'] as $entry) {
        $row = array(
            'category' => $category,
            'question' => $entry['question'] ?? '',
            'answer' => $entry['answer'] ?? '',
            'keywords' => $entry['keywords'] ?? '',
        );
        if (!empty($entry['similar']) && is_array($entry['similar'])) {
            $row['similar'] = $entry['similar'];
        }
        $kbRows[] = $row;
    }
}
$kbPath = $dir . '/kb_seed.json';
file_put_contents($kbPath, json_encode($kbRows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Handoff seed
$handoffRows = array();
foreach (HandoffTriggers::defaultSeed() as $pair) {
    $handoffRows[] = array(
        'keyword' => $pair[0],
        'priority' => (int)$pair[1],
    );
}
$handoffPath = $dir . '/handoff_seed.json';
file_put_contents($handoffPath, json_encode($handoffRows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "export_industry_templates.php\n";
echo "Industry: {$industry}\n";
echo "KB entries: " . count($kbRows) . " -> {$kbPath}\n";
echo "Handoff entries: " . count($handoffRows) . " -> {$handoffPath}\n";
echo "Done.\n";

#!/usr/bin/env php
<?php
/**
 * 重建南宁宿家民宿通用知识库（清空后写入系统默认条目）
 * 用法：php scripts/rebuild_knowledge_base.php
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $root . '/api/config.php';
require $root . '/api/KnowledgeBaseSeed.php';

$db = getDB();
$stats = KnowledgeBaseSeed::rebuild($db);

echo "Knowledge base rebuilt.\n";
echo "  deleted:    {$stats['deleted_entries']}\n";
echo "  categories: {$stats['categories']}\n";
echo "  entries:    {$stats['entries']}\n";

foreach (KnowledgeBaseSeed::catalog() as $key => $group) {
    echo "  - {$group['desc']}: " . count($group['entries']) . "\n";
}

echo "\nNext: run vectorize in admin or POST /api/embedding.php?action=batch_vectorize\n";

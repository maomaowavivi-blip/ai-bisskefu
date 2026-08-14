<?php
/**
 * scripts/import_kb_top200.php
 *
 * v3.7:把 kb_top200_v1.csv 导入到本地或生产库的 kb_entries + kb_categories
 * 用法:
 *   php scripts/import_kb_top200.php <csv_file> [--dry-run]
 *
 * 默认行为:
 *   - 读 config.php 的 DB_* 配置(本地默认 aibisskefu_com / 8889 / root/root)
 *   - 按 category_name 自动创建或复用 kb_categories
 *   - INSERT IGNORE(同 question 重复就跳过,避免重复导入)
 *   - 全部进 status=1(启用),苏鸣后台再调
 *   - 不向量化(向量化走后台批量按钮或单独跑 scripts/vectorize_kb.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/config.php';

$csvFile = $argv[1] ?? null;
$dryRun  = in_array('--dry-run', $argv ?? [], true);

if (!$csvFile || !file_exists($csvFile)) {
    fwrite(STDERR, "用法: php scripts/import_kb_top200.php <csv_file> [--dry-run]\n");
    fwrite(STDERR, "例子: php scripts/import_kb_top200.php kb_top200_v1.csv\n");
    exit(1);
}

$db = getDB();

echo "=== KB 导入开始 ===\n";
echo "CSV 文件: $csvFile\n";
echo "模式: " . ($dryRun ? 'DRY-RUN(不写)' : '正式导入') . "\n";
echo "数据库: {$db->query('SELECT DATABASE()')->fetchColumn()}\n\n";

$fh = fopen($csvFile, 'r');
if (!$fh) {
    fwrite(STDERR, "无法打开 CSV 文件\n");
    exit(1);
}

// 跳过 BOM
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($fh);

// 跳过表头
fgetcsv($fh);

$success = 0;
$skip = 0;
$empty = 0;
$errors = [];
$lineNo = 1;
$newCategories = [];

while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;
    $question     = trim($row[0] ?? '');
    $answer       = trim($row[1] ?? '');
    $keywords     = trim($row[2] ?? '');
    $categoryName = trim($row[3] ?? '');

    if (!$question || !$answer) {
        $empty++;
        continue;
    }

    try {
        // 处理分类
        $categoryId = 0;
        if ($categoryName) {
            $catStmt = $db->prepare("SELECT id FROM kb_categories WHERE name = ? LIMIT 1");
            $catStmt->execute([$categoryName]);
            $catRow = $catStmt->fetch();
            if ($catRow) {
                $categoryId = intval($catRow['id']);
            } else {
                if (!$dryRun) {
                    $db->prepare("INSERT INTO kb_categories (name) VALUES (?)")->execute([$categoryName]);
                    $categoryId = intval($db->lastInsertId());
                }
                $newCategories[] = $categoryName;
            }
        }

        // 重复检查(question 完全相同 → 跳过)
        $checkStmt = $db->prepare("SELECT id FROM kb_entries WHERE question = ? LIMIT 1");
        $checkStmt->execute([$question]);
        $existing = $checkStmt->fetch();
        if ($existing) {
            $skip++;
            continue;
        }

        // 插入
        if (!$dryRun) {
            $insStmt = $db->prepare("INSERT INTO kb_entries (category_id, question, answer, keywords, status) VALUES (?, ?, ?, ?, 1)");
            $insStmt->execute([$categoryId, $question, $answer, $keywords]);
        }
        $success++;
    } catch (Exception $e) {
        $errors[] = "第 {$lineNo} 行导入失败: " . $e->getMessage();
    }
}

fclose($fh);

echo "=== 导入结果 ===\n";
echo "成功新增: {$success}\n";
echo "已存在跳过: {$skip}\n";
echo "空行(无答案): {$empty}\n";
echo "新建分类: " . count($newCategories) . " 个\n";
if (!empty($newCategories)) {
    foreach (array_unique($newCategories) as $cn) echo "  + {$cn}\n";
}
if (!empty($errors)) {
    echo "\n=== 错误 ===\n";
    foreach ($errors as $e) echo "  ! {$e}\n";
}

if ($dryRun) {
    echo "\n=== DRY-RUN 模式,未实际写入 ===\n";
} else {
    echo "\n=== 已写入数据库 ===\n";
    echo "下一步: 1) 后台批量向量化  2) 测试客户问题命中\n";
}
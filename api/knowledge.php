<?php
// api/knowledge.php
// 知识库管理接口
//
// GET    /api/knowledge.php?action=categories         分类列表
// POST   /api/knowledge.php?action=save_category      保存分类
// POST   /api/knowledge.php?action=delete_category    删除分类
// GET    /api/knowledge.php?action=list               知识条目列表
// POST   /api/knowledge.php?action=save               保存条目
// POST   /api/knowledge.php?action=delete             删除条目
// GET    /api/knowledge.php?action=search             检索知识库

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PromptEngine.php';
require_once __DIR__ . '/KnowledgeBaseSeed.php';
require_once __DIR__ . '/embedding.php';

$action = $_GET['action'] ?? '';
$body   = getBody();
$db     = getDB();
$isAdmin = false;

try {
    adminGuard();
    $isAdmin = true;
} catch (Throwable $e) {
    // 不需要管理员权限的接口
}

// ══════════════════════════════════════════
// 分类管理
// ══════════════════════════════════════════

if ($action === 'categories') {
    $stmt = $db->query('SELECT * FROM kb_categories ORDER BY sort_order ASC, id ASC');
    ok(['list' => $stmt->fetchAll()]);
}

if ($action === 'save_category') {
    $id   = intval($body['id'] ?? 0);
    $name = trim($body['name'] ?? '');
    $parentId = intval($body['parent_id'] ?? 0);
    if (!$name) fail('请输入分类名称');

    if ($id) {
        $db->prepare('UPDATE kb_categories SET name=?, parent_id=? WHERE id=?')->execute([$name, $parentId, $id]);
    } else {
        $db->prepare('INSERT INTO kb_categories (name, parent_id) VALUES (?, ?)')->execute([$name, $parentId]);
        $id = $db->lastInsertId();
    }
    ok(['id' => intval($id)], '保存成功');
}

if ($action === 'delete_category') {
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');
    $db->prepare('DELETE FROM kb_categories WHERE id=?')->execute([$id]);
    $db->prepare('UPDATE kb_entries SET category_id=0 WHERE category_id=?')->execute([$id]);
    ok([], '删除成功');
}

// ══════════════════════════════════════════
// 知识条目管理
// ══════════════════════════════════════════

if ($action === 'list') {
    $categoryId = intval($_GET['category_id'] ?? 0);
    $page  = max(1, intval($_GET['page'] ?? 1));
    $size  = min(50, max(1, intval($_GET['size'] ?? 20)));
    $offset = ($page - 1) * $size;

    $where = '';
    $params = [];
    if ($categoryId) {
        $where = 'WHERE category_id = ?';
        $params[] = $categoryId;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM kb_entries $where");
    $countStmt->execute($params);
    $total = intval($countStmt->fetchColumn());

    $stmt = $db->prepare("SELECT id, category_id, question, answer, keywords, status, hit_count, created_at, updated_at FROM kb_entries $where ORDER BY updated_at DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params, [$size, $offset]));
    $list = $stmt->fetchAll();

    ok(['list' => $list, 'total' => $total, 'page' => $page]);
}

if ($action === 'save') {
    $id = intval($body['id'] ?? 0);
    $categoryId = intval($body['category_id'] ?? 0);
    $question   = trim($body['question'] ?? '');
    $answer     = trim($body['answer'] ?? '');
    $keywords   = trim($body['keywords'] ?? '');
    $similar    = $body['similar_questions'] ?? null;
    $status     = intval($body['status'] ?? 1);

    if (!$question || !$answer) fail('问题和答案不能为空');

    // XSS 防护：富文本只保留安全标签
    $answer = strip_tags($answer, '<p><br><b><strong><i><em><u><s><a><ul><ol><li><table><tr><td><th><thead><tbody><tfoot><caption><colgroup><col><blockquote><pre><code><h1><h2><h3><h4><h5><h6><span><div><hr><img><sup><sub><del><ins>');

    $similarJson = $similar ? json_encode($similar, JSON_UNESCAPED_UNICODE) : null;

    if ($id) {
        $db->prepare('UPDATE kb_entries SET category_id=?, question=?, answer=?, keywords=?, similar_questions=?, status=? WHERE id=?')
           ->execute([$categoryId, $question, $answer, $keywords, $similarJson, $status, $id]);
    } else {
        $db->prepare('INSERT INTO kb_entries (category_id, question, answer, keywords, similar_questions, status) VALUES (?,?,?,?,?,?)')
           ->execute([$categoryId, $question, $answer, $keywords, $similarJson, $status]);
        $id = $db->lastInsertId();
    }
    ok(['id' => intval($id)], '保存成功');
}

// ══════════════════════════════════════════
// 获取单条知识条目
// ══════════════════════════════════════════

if ($action === 'get') {
    $id = intval($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) fail('参数错误');

    $stmt = $db->prepare("SELECT id, category_id, question, answer, keywords, similar_questions, status, hit_count, created_at, updated_at FROM kb_entries WHERE id = ?");
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    if (!$entry) fail('条目不存在');

    if ($entry['similar_questions']) {
        $entry['similar_questions'] = json_decode($entry['similar_questions'], true);
    }
    ok($entry);
}

// ══════════════════════════════════════════
// 向量化操作（代理到 embedding.php）
// ══════════════════════════════════════════

if ($action === 'vectorize') {
    adminGuard();
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');
    try {
        ok(vectorizeKbEntry($db, $id), '向量化成功');
    } catch (Throwable $e) {
        fail('向量化失败', 500);
    }
}

if ($action === 'batch_vectorize') {
    adminGuard();
    try {
        $result = batchVectorizeKb($db);
        ok(['count' => $result['count']], $result['message']);
    } catch (Throwable $e) {
        fail('批量向量化失败', 500);
    }
}

if ($action === 'delete') {
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');
    $db->prepare('DELETE FROM kb_entries WHERE id=?')->execute([$id]);
    ok([], '删除成功');
}

// ══════════════════════════════════════════
// 知识库检索（公开接口，提供给 PromptEngine 使用）
// ══════════════════════════════════════════

if ($action === 'search') {
    $query = trim($_GET['q'] ?? '');
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));

    if (!$query) ok(['list' => []]);

    $like = '%' . $query . '%';

    // 管理后台搜索：返回完整字段（含 id），不过滤禁用状态
    try {
        $stmt = $db->prepare("
            SELECT id, category_id, question, answer, keywords, status, hit_count, created_at, updated_at
            FROM kb_entries
            WHERE question LIKE ? OR answer LIKE ? OR keywords LIKE ?
            ORDER BY updated_at DESC
            LIMIT ?
        ");
        $stmt->execute([$like, $like, $like, $limit]);
        $items = $stmt->fetchAll();
    } catch (Exception $e) {
        $items = [];
    }

    ok(['list' => $items]);
}

// ══════════════════════════════════════════
// POST /api/knowledge.php?action=import（CSV批量导入）
// ══════════════════════════════════════════

if ($action === 'import') {
    adminGuard();
    if (empty($_FILES['file'])) fail('请上传CSV文件');
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('文件上传失败');

    $csvPath = $_FILES['file']['tmp_name'];
    $fh = fopen($csvPath, 'r');
    if (!$fh) fail('无法读取文件');

    // 检测并跳过 UTF-8 BOM
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }

    // 跳过表头行
    fgetcsv($fh);

    $success = 0;
    $skip = 0;
    $errors = [];

    while (($row = fgetcsv($fh)) !== false) {
        $question   = trim($row[0] ?? '');
        $answer     = trim($row[1] ?? '');
        $keywords   = trim($row[2] ?? '');
        $categoryName = trim($row[3] ?? '');

        if (!$question || !$answer) {
            $skip++;
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
                    $db->prepare("INSERT INTO kb_categories (name) VALUES (?)")->execute([$categoryName]);
                    $categoryId = intval($db->lastInsertId());
                }
            }

            // 插入知识条目
            $insStmt = $db->prepare("INSERT INTO kb_entries (category_id, question, answer, keywords) VALUES (?, ?, ?, ?)");
            $insStmt->execute([$categoryId, $question, $answer, $keywords]);
            $success++;
        } catch (Exception $e) {
            $errors[] = '第' . ($success + $skip + count($errors) + 2) . '行导入失败';
        }
    }

    fclose($fh);
    ok(['success' => $success, 'skip' => $skip, 'errors' => $errors], '导入完成');
}

// ══════════════════════════════════════════
// 管理员 - 重建系统默认知识库（清空后写入）
// ══════════════════════════════════════════
if ($action === 'rebuild_defaults') {
    adminGuard();
    try {
        $stats = KnowledgeBaseSeed::rebuild($db);
        ok($stats, '知识库已按系统默认模板重建');
    } catch (Exception $e) {
        fail('重建失败', 500);
    }
}

fail('未知操作');

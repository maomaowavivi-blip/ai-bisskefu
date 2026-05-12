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
    $limit = min(10, max(1, intval($_GET['limit'] ?? 5)));

    if (!$query) ok(['list' => []]);

    // 先尝试全文检索
    $items = PromptEngine::searchKnowledge($db, $query, $limit);

    // 如果全文检索没结果，降级为 LIKE 模糊匹配
    if (empty($items)) {
        $like = '%' . $query . '%';
        $stmt = $db->prepare("
            SELECT question, answer, MATCH(question, answer, keywords) AGAINST(? IN BOOLEAN MODE) AS relevance
            FROM kb_entries
            WHERE status = 1
              AND (question LIKE ? OR answer LIKE ? OR keywords LIKE ?)
            ORDER BY hit_count DESC
            LIMIT ?
        ");
        $stmt->execute([$query, $like, $like, $like, $limit]);
        $items = $stmt->fetchAll();
    }

    // 增加命中计数
    if (!empty($items)) {
        foreach ($items as $item) {
            $db->prepare('UPDATE kb_entries SET hit_count = hit_count + 1 WHERE question = ?')->execute([$item['question']]);
        }
    }

    ok(['list' => $items]);
}

fail('未知操作');

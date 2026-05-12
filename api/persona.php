<?php
// api/persona.php
// 吉祥物人设管理接口
//
// GET  /api/persona.php?action=get   获取人设配置
// POST /api/persona.php?action=save  保存人设配置

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$body   = getBody();
$db     = getDB();

if ($action === 'get') {
    adminGuard();
    $stmt = $db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
    $persona = $stmt->fetch();
    if (!$persona) {
        ok([
            'name' => '智能客服',
            'greeting' => '您好~ 很高兴为您服务！',
            'avatar_url' => '',
            'brand_story' => '',
            'personality' => '',
            'speak_style' => '',
            'service_rules' => '',
            'principles' => '',
            'emotion_strategy' => '',
        ]);
    }
    ok($persona);
}

if ($action === 'save') {
    adminGuard();
    $fields = ['name', 'greeting', 'avatar_url', 'brand_story', 'personality', 'speak_style', 'service_rules', 'principles', 'emotion_strategy'];

    $stmt = $db->query('SELECT id FROM persona_config ORDER BY id DESC LIMIT 1');
    $existing = $stmt->fetch();

    if ($existing) {
        $sql = 'UPDATE persona_config SET ';
        $updates = [];
        $params = [];
        foreach ($fields as $f) {
            if (isset($body[$f])) {
                $updates[] = "`{$f}` = ?";
                $params[] = trim($body[$f]);
            }
        }
        if (empty($updates)) fail('没有要更新的字段');
        $sql .= implode(', ', $updates) . ' WHERE id = ?';
        $params[] = $existing['id'];
        $db->prepare($sql)->execute($params);
    } else {
        $cols = [];
        $placeholders = [];
        $params = [];
        foreach ($fields as $f) {
            if (isset($body[$f])) {
                $cols[] = "`{$f}`";
                $placeholders[] = '?';
                $params[] = trim($body[$f]);
            }
        }
        if (empty($params)) fail('没有要保存的字段');
        $db->prepare("INSERT INTO persona_config (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")")->execute($params);
    }

    ok([], '保存成功');
}

fail('未知操作');

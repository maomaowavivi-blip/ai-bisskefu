<?php
// api/persona.php
// 吉祥物人设管理接口
//
// GET  /api/persona.php?action=get          获取人设配置
// POST /api/persona.php?action=save         保存人设配置
// POST /api/persona.php?action=upload_avatar 上传头像

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
            'description' => '',
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
    $fields = ['name', 'greeting', 'description', 'avatar_url', 'brand_story', 'personality', 'speak_style', 'service_rules', 'principles', 'emotion_strategy'];

    $stmt = $db->query('SELECT id FROM persona_config ORDER BY id DESC LIMIT 1');
    $existing = $stmt->fetch();

    if ($existing) {
        $sql = 'UPDATE persona_config SET ';
        $updates = [];
        $params = [];
        foreach ($fields as $f) {
            if (isset($body[$f])) {
                $updates[] = "`{$f}` = ?";
                $params[] = strip_tags(trim((string)$body[$f]));
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
                $params[] = strip_tags(trim((string)$body[$f]));
            }
        }
        if (empty($params)) fail('没有要保存的字段');
        $db->prepare("INSERT INTO persona_config (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")")->execute($params);
    }

    ok([], '保存成功');
}

// ══════════════════════════════════════════
// 头像上传
// ══════════════════════════════════════════
if ($action === 'upload_avatar') {
    adminGuard();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        fail('上传失败，请重试');
    }

    $file = $_FILES['file'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualType = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    if (!in_array($actualType, $allowedTypes, true)) {
        fail('仅支持 JPG、PNG、GIF、WebP 格式');
    }

    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        fail('文件大小不能超过 5MB');
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? strtolower($ext) : 'jpg';
    $filename = 'avatar_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $destPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        fail('文件保存失败');
    }

    // 计算 URL 路径
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $basePath = dirname($basePath); // /aibisskefu/api/ → /aibisskefu/
    $url = rtrim($basePath, '/') . '/uploads/avatars/' . $filename;

    ok(['url' => $url], '上传成功');
}

fail('未知操作');

<?php
// api/verify.php
// 验证网关 - SMS验证码发送/校验 + 企业订单API调用
//
// POST /api/verify.php?action=send_code   发送验证码
// POST /api/verify.php?action=verify_code  校验验证码 + 查订单
// POST /api/verify.php?action=query_order  已验证后查订单

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$body   = getBody();
$db     = getDB();

// ══════════════════════════════════════════
// 发送验证码
// ══════════════════════════════════════════
if ($action === 'send_code') {
    fail('短信验证暂未开放', 503);
    $phone    = trim($body['phone'] ?? '');
    $orderNo  = trim($body['order_no'] ?? '');
    $sessionId = trim($body['session_id'] ?? '');

    if (!$phone || !preg_match('/^1[3-9]\d{9}$/', $phone)) {
        fail('请输入正确的手机号');
    }
    if (!$orderNo) fail('请输入订单号');
    if (!$sessionId) fail('session_id不能为空');

    $phoneHash = hash('sha256', $phone);
    $phoneMask = substr($phone, 0, 3) . '****' . substr($phone, -4);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // 频率限制
    $stmt = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE source_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    $stmt->execute([$ip]);
    if (intval($stmt->fetchColumn() ?: 0) >= 3) {
        fail('发送过于频繁，请1分钟后再试', 429);
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM sms_verify_logs WHERE phone_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $stmt->execute([$phoneHash]);
    if (intval($stmt->fetchColumn() ?: 0) >= 5) {
        fail('该手机号今天已达上限，请明天再试', 429);
    }

    // 生成验证码
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = hash('sha256', $otp);

    // 存入数据库
    $stmt = $db->prepare('INSERT INTO sms_verify_logs (phone_hash, phone_mask, otp_hash, session_id, source_ip, status, expires_at) VALUES (?, ?, ?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
    $stmt->execute([$phoneHash, $phoneMask, $otpHash, $sessionId, $ip]);

    ok([
        'phone_mask' => $phoneMask,
        'expires_in' => 300,
    ], '验证码已发送（测试环境，请查看服务器日志）');
}

// ══════════════════════════════════════════
// 校验验证码
// ══════════════════════════════════════════
if ($action === 'verify_code') {
    fail('短信验证暂未开放', 503);
    $phone    = trim($body['phone'] ?? '');
    $otp      = trim($body['otp'] ?? '');
    $orderNo  = trim($body['order_no'] ?? '');
    $sessionId = trim($body['session_id'] ?? '');

    if (!$phone || !$otp) fail('参数不完整');
    if (!$sessionId) fail('session_id不能为空');

    $phoneHash = hash('sha256', $phone);

    // 查找最近的未使用验证码
    $stmt = $db->prepare('
        SELECT id, otp_hash, status, expires_at 
        FROM sms_verify_logs 
        WHERE phone_hash = ? AND session_id = ? AND status = 0
        ORDER BY created_at DESC LIMIT 1
    ');
    $stmt->execute([$phoneHash, $sessionId]);
    $record = $stmt->fetch();

    if (!$record) fail('请先获取验证码');

    if (strtotime($record['expires_at']) < time()) {
        $db->prepare('UPDATE sms_verify_logs SET status = 2 WHERE id = ?')->execute([$record['id']]);
        fail('验证码已过期，请重新获取');
    }

    if (hash('sha256', $otp) !== $record['otp_hash']) {
        fail('验证码错误');
    }

    // 标记已验证
    $db->prepare('UPDATE sms_verify_logs SET status = 1, verified_at = NOW() WHERE id = ?')->execute([$record['id']]);

    // v3.26：旧「企业订单 API」已随数据中台迁移下线，不再在此调用。
    // 订单查询统一走 CenterApi::orderByChannelOrderId()（api/CenterApi.php）。

    ok([
        'verified'   => true,
        'order_data' => null,
    ], '验证通过');
}

// ══════════════════════════════════════════
// 数据中台配置（管理后台用，v3.26 取代旧「统一网关」）
//
// 存 platform_config:
//   center.api_url    中台基址，如 https://center.youguang8488.com/api/relay
//   center.username   账号
//   center.password   密码（只存 DB，不进源码/.env/commit）
//   center.tenant_id  租户 ID
// ══════════════════════════════════════════
if ($action === 'save_center_config') {
    adminGuard();

    $url      = trim($body['api_url'] ?? '');
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');
    $tenantId = trim($body['tenant_id'] ?? '');

    if (!$url || !$username) fail('参数不完整');
    if ($tenantId === '') $tenantId = '1';

    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['center.api_url', rtrim($url, '/')]);
    $stmt->execute(['center.username', $username]);
    $stmt->execute(['center.tenant_id', $tenantId]);
    // 密码留空 = 不改动已存密码（后台每次保存不覆盖真实密码）
    if ($password !== '') {
        $stmt->execute(['center.password', $password]);
    }

    ok([], '数据中台配置已保存');
}

if ($action === 'get_center_config') {
    adminGuard();
    ok([
        'api_url'   => pcGet($db, 'center.api_url', ''),
        'username'  => pcGet($db, 'center.username', ''),
        'password'  => pcGet($db, 'center.password', '') ? '已配置' : '',
        'tenant_id' => pcGet($db, 'center.tenant_id', '1'),
    ]);
}

if ($action === 'clear_center_config') {
    adminGuard();
    $stmt = $db->prepare('DELETE FROM platform_config WHERE `key` IN (?, ?, ?, ?)');
    $stmt->execute(['center.api_url', 'center.username', 'center.password', 'center.tenant_id']);
    ok([], '数据中台配置已清除');
}

// ══════════════════════════════════════════
// 房间查询触发关键词（管理后台用）
//
// 键名保持 gateway.room_keywords 不变 —— SidecarIntent::getEntryKeywords()
// 实时读它做房间意图扩展，改键名会导致后台配置静默失效。
// ══════════════════════════════════════════
if ($action === 'save_room_keywords') {
    adminGuard();

    // 请求体畸形时 getBody() 返回 []，若直接 ?? '' 会把关键词静默清空。
    // 这里用 array_key_exists 区分「参数缺失」与「显式清空」：缺失则拒绝，显式空串则允许
    //（空串 = 只用代码内置的基础词表）。
    if (!array_key_exists('room_keywords', $body)) {
        fail('参数不完整');
    }

    $keywords = trim((string)$body['room_keywords']);
    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['gateway.room_keywords', $keywords]);

    ok([], '房间查询关键词已保存');
}

if ($action === 'get_room_keywords') {
    adminGuard();
    ok(['room_keywords' => pcGet($db, 'gateway.room_keywords', '')]);
}

// ══════════════════════════════════════════
// 订单查询分时段回复 + 入住指引视频（管理后台用，v3.35）
//
// 存 platform_config（pcGet/pcSet，禁 AgentConfig::get()，AGENTS.md §10）:
//   order_reply.text.precheckin / .during / .after
//     三场景引导文字；空串 = 关闭该场景 = 完全保持旧行为（单一守门点，蓝图 §5.1）
//   roomcard.guide_video.filename / size / uploaded_at / path
//     指引视频；uploaded_at 用 SQL NOW() 落库（生产 PHP=UTC，
//     PHP date() 会存成 UTC 与 CST 差 8 小时，P2 更正 #2）
// 四个 action 全部 adminGuard()（蓝图 §7.7 / qa B7）
// ══════════════════════════════════════════
if ($action === 'get_order_reply_config') {
    adminGuard();

    $videoPath = pcGet($db, 'roomcard.guide_video.path', '');
    ok([
        'precheckin_text' => pcGet($db, 'order_reply.text.precheckin', ''),
        'during_text'     => pcGet($db, 'order_reply.text.during', ''),
        'after_text'      => pcGet($db, 'order_reply.text.after', ''),
        'video' => [
            'filename'    => pcGet($db, 'roomcard.guide_video.filename', ''),
            'size'        => pcGetInt($db, 'roomcard.guide_video.size', 0),
            'uploaded_at' => pcGet($db, 'roomcard.guide_video.uploaded_at', ''),
            'path'        => $videoPath,
            'exists'      => $videoPath !== '' && is_file($videoPath),
        ],
    ]);
}

if ($action === 'save_order_reply_config') {
    adminGuard();

    // array_key_exists 区分「参数缺失」与「显式清空」（照 save_room_keywords 先例）：
    // 显式空串也要保存 —— 清空 = 关闭该场景 = 恢复旧行为。
    $fields = [
        'precheckin_text' => ['order_reply.text.precheckin', '未到入住引导文字'],
        'during_text'     => ['order_reply.text.during', '入住期间引导文字'],
        'after_text'      => ['order_reply.text.after', '退房后引导文字'],
    ];
    foreach ($fields as $field => $spec) {
        if (!array_key_exists($field, $body)) fail('参数不完整: ' . $field);
        // textarea 提交的是 CRLF，归一化成 LF（同 v3.25 转人工话术口径）
        $text = str_replace(["\r\n", "\r"], "\n", trim((string)$body[$field]));
        $len = mb_strlen($text);
        if ($len > 200) fail($spec[1] . '不能超过 200 字（当前 ' . $len . ' 字）');
        if (!pcSet($db, $spec[0], $text)) fail($spec[1] . '保存失败');
    }

    ok([], '订单分时段回复已保存');
}

if ($action === 'upload_guide_video') {
    adminGuard();

    // 上传走 $_FILES + $_POST（getBody() 只解析 JSON，api/config.php:110），
    // 照抄 api/persona.php:77-110 头像上传先例。
    $err = isset($_FILES['file']) ? (int)$_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            fail('视频超过服务器上传上限，请压到 10MB 以内再传');
        }
        fail('上传失败，请重试');
    }

    $file = $_FILES['file'];

    // 扩展名白名单 .mp4（企微视频素材仅支持 MP4，蓝图 §3.2）
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'mp4') fail('仅支持 mp4 格式视频');

    // 大小 ≤10MB（企微硬上限）；企微还要求素材 >5 字节
    if ($file['size'] > 10 * 1024 * 1024) fail('视频不能超过 10MB');
    if ($file['size'] <= 5) fail('视频文件过小，请检查文件');

    // finfo 内容校验（防改名伪装，先例 persona.php:87）
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualType = $finfo ? (string)(finfo_file($finfo, $file['tmp_name']) ?: '') : '';
    if ($finfo) finfo_close($finfo);
    if ($actualType !== 'video/mp4') fail('文件内容不是 mp4 视频（检测为 ' . $actualType . '）');

    // 随机化存储文件名：防 x.mp4.php / 路径穿越（用户原名只作展示）
    $storedName = 'guide_video_' . time() . '_' . bin2hex(random_bytes(4)) . '.mp4';
    $uploadDir = __DIR__ . '/../uploads/guide/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $destPath = $uploadDir . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) fail('文件保存失败');

    // 保存前当场试传企微 media/upload，成功才算「已保存」（蓝图 §7.7：
    // 避免存了个传不上去的文件、客人当天查单才发现）。
    // 惰性 require：get/save/delete 端点不被媒体层连坐。
    require_once __DIR__ . '/wecom_kf_media.php';
    $up = uploadMediaFromFile($db, $destPath, 'video');
    if (!$up['ok']) {
        @unlink($destPath); // 回滚新文件；旧视频的配置保持不动
        // errmsg 来自企微（不含凭据），errcode 一并带回方便排查
        fail('企微上传校验失败（errcode=' . $up['errcode'] . ' ' . $up['errmsg'] . '），未保存，请重试或压缩视频', 502);
    }
    // 试传 media_id 用完即弃，不写配置（已进 kf_media_cache，发送时命中可复用）

    // 新视频成功后才删旧文件（替换语义）。路径只取配置项，不信任前端；
    // 且必须落在 uploads/guide/ 内才删（防配置被外部路径污染时误删）。
    $oldPath = pcGet($db, 'roomcard.guide_video.path', '');
    if ($oldPath !== '') {
        $oldReal = realpath($oldPath);
        $dirReal = realpath($uploadDir);
        if ($oldReal && $dirReal
            && strpos($oldReal, $dirReal . DIRECTORY_SEPARATOR) === 0
            && $oldReal !== realpath($destPath)) {
            if (!@unlink($oldReal)) {
                error_log('[order_guide] replace: delete old video failed: ' . basename($oldReal));
            }
            // ⚠️ 缓存键必须与写入侧同口径：发送/写入侧用 platform_config 里的**原始 path** 字符串，
            // 这里若用 realpath 归一，两者 hash 不同 → 清不掉（只残留，60h/7d 自愈）。
            mediaCacheClear(mediaCacheKey($oldPath, 'video'));
        }
    }

    // 展示名去标签限长；真实落盘路径进 path 键（发送时据此重传换 media_id）
    $displayName = mb_substr(strip_tags((string)$file['name']), 0, 100);
    if (!pcSet($db, 'roomcard.guide_video.filename', $displayName)) fail('视频配置保存失败');
    if (!pcSet($db, 'roomcard.guide_video.size', (string)$file['size'])) fail('视频配置保存失败');
    if (!pcSet($db, 'roomcard.guide_video.path', $destPath)) {
        @unlink($destPath);
        fail('视频配置保存失败');
    }
    // uploaded_at 走 SQL NOW()（与展示/审计口径同源，MySQL=CST 挂钟）
    $stmt = $db->prepare(
        "INSERT INTO platform_config (`key`, `value`)
         VALUES (?, DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    $stmt->execute(['roomcard.guide_video.uploaded_at']);

    ok(['filename' => $displayName, 'size' => intval($file['size'])], '指引视频已上传并通过企微校验');
}

if ($action === 'delete_guide_video') {
    adminGuard();

    require_once __DIR__ . '/wecom_kf_media.php';

    // 只删配置里记着的路径（不接受前端传路径），并限定在 uploads/guide/ 内
    $oldPath = pcGet($db, 'roomcard.guide_video.path', '');
    if ($oldPath !== '') {
        $oldReal = realpath($oldPath);
        $dirReal = realpath(__DIR__ . '/../uploads/guide');
        if ($oldReal && $dirReal && strpos($oldReal, $dirReal . DIRECTORY_SEPARATOR) === 0) {
            if (!@unlink($oldReal)) {
                error_log('[order_guide] delete: video file unlink failed: ' . basename($oldReal));
            }
        }
        // ⚠️ 同上：与写入侧同口径（原始 path），不要用 realpath 归一后的字符串
        mediaCacheClear(mediaCacheKey($oldPath, 'video'));
    }

    // 清 4 个配置键（pcGet 读空回默认 = 不发视频，行为回到「未上传」）
    pcSet($db, 'roomcard.guide_video.filename', '');
    pcSet($db, 'roomcard.guide_video.size', '');
    pcSet($db, 'roomcard.guide_video.uploaded_at', '');
    pcSet($db, 'roomcard.guide_video.path', '');

    ok([], '指引视频已删除');
}

// ══════════════════════════════════════════
// AI API Key 配置（管理后台用）
// ══════════════════════════════════════════
if ($action === 'save_ai_api') {
    adminGuard();

    $apiKey = trim($body['api_key'] ?? '');

    if (!$apiKey) fail('API Key 不能为空');

    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['ai.api_key', $apiKey]);

    ok([], 'AI API Key 保存成功');
}

if ($action === 'get_ai_api') {
    adminGuard();
    $saved = pcGet($db, 'ai.api_key', '');
    $temp  = pcGet($db, 'ai.temperature', '0.5');
    ok([
        'api_key' => $saved ? '已配置' : '',
        'has_key' => $saved ? true : false,
        'temperature' => floatval($temp),
    ]);
}

// ══════════════════════════════════════════
// AI 温度调节
// ══════════════════════════════════════════
if ($action === 'save_temperature') {
    adminGuard();
    $temperature = floatval($body['temperature'] ?? 0.5);
    if ($temperature < 0.01) $temperature = 0.01;
    if ($temperature > 1.0)  $temperature = 1.0;
    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['ai.temperature', (string)$temperature]);
    ok([], 'AI 温度已保存');
}
if ($action === 'get_temperature') {
    adminGuard();
    $temp = pcGet($db, 'ai.temperature', '0.5');
    ok(['temperature' => floatval($temp)]);
}

// ══════════════════════════════════════════
// 企业微信对接配置（管理后台用）
// ══════════════════════════════════════════
if ($action === 'save_wecom_config') {
    adminGuard();

    $corpId = trim($body['corpid'] ?? '');
    $token  = trim($body['token'] ?? '');
    $aesKey = trim($body['aes_key'] ?? '');

    if (!$corpId || !$token || !$aesKey) fail('参数不完整');

    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['wecom.corpid', $corpId]);
    $stmt->execute(['wecom.token', $token]);
    $stmt->execute(['wecom.aes_key', $aesKey]);

    ok([], '企业微信配置保存成功');
}

if ($action === 'get_wecom_config') {
    adminGuard();
    ok([
        'corpid'  => pcGet($db, 'wecom.corpid', ''),
        'token'   => pcGet($db, 'wecom.token', ''),
        'aes_key' => pcGet($db, 'wecom.aes_key', ''),
    ]);
}

// ══════════════════════════════════════════
// 微信客服 API 配置（v3.4，用户扫客服二维码）
// ══════════════════════════════════════════
if ($action === 'save_wecom_kf_config') {
    adminGuard();

    $corpSecret = trim($body['corp_secret'] ?? '');
    $openKfId   = trim($body['open_kfid'] ?? '');

    if (!$corpSecret || !$openKfId) fail('参数不完整');

    $stmt = $db->prepare('INSERT INTO platform_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute(['wecom.corp_secret', $corpSecret]);
    $stmt->execute(['wecom.kf_open_kfid', $openKfId]);

    ok([], '微信客服配置保存成功');
}

if ($action === 'get_wecom_kf_config') {
    adminGuard();
    ok([
        'corp_secret' => pcGet($db, 'wecom.corp_secret', ''),
        'open_kfid'   => pcGet($db, 'wecom.kf_open_kfid', ''),
        'configured'  => !empty(pcGet($db, 'wecom.corp_secret', '')) && !empty(pcGet($db, 'wecom.kf_open_kfid', '')),
    ]);
}

// ══════════════════════════════════════════
// API 密钥管理（管理后台用）
// ══════════════════════════════════════════
if ($action === 'create_api_key') {
    adminGuard();

    $name = trim($body['name'] ?? '未命名');
    if (mb_strlen($name) > 100) fail('名称过长');

    $key = 'neb_' . bin2hex(random_bytes(24));

    $stmt = $db->prepare('INSERT INTO api_keys (name, api_key, enabled) VALUES (?, ?, 1)');
    $stmt->execute([$name, $key]);

    ok(['id' => intval($db->lastInsertId()), 'api_key' => $key]);
}

if ($action === 'list_api_keys') {
    adminGuard();

    $stmt = $db->query('SELECT id, name, api_key, enabled, last_used_at, created_at FROM api_keys ORDER BY created_at DESC');
    $list = $stmt->fetchAll();

    ok(['list' => $list]);
}

if ($action === 'toggle_api_key') {
    adminGuard();

    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');

    $stmt = $db->prepare('UPDATE api_keys SET enabled = 1 - enabled WHERE id = ?');
    $stmt->execute([$id]);

    ok([], '操作成功');
}

if ($action === 'delete_api_key') {
    adminGuard();

    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误');

    $stmt = $db->prepare('DELETE FROM api_keys WHERE id = ?');
    $stmt->execute([$id]);

    ok([], '已删除');
}

fail('未知操作');

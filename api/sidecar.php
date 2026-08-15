<?php
// api/sidecar.php — Sidecar 知识块 / 向量化 / 房间查询运维

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sidecar/ChunkBuilder.php';
require_once __DIR__ . '/sidecar/Vectorizer.php';
require_once __DIR__ . '/sidecar/RoomQueryService.php';

// Sidecar 运维接口不再接受客户端自报 is_verified；客服主流程直接调用服务类。
adminGuard();

$action = $_GET['action'] ?? '';
$body = getBody();

function sidecarDbOrFail(): PDO {
    try {
        return getSidecarDB();
    } catch (Exception $e) {
        error_log('[sidecar] database connection failed: ' . get_class($e));
        fail('Sidecar 数据库连接失败', 500);
    }
}

if ($action === 'stats') {
    $db = sidecarDbOrFail();
    ok(ChunkBuilder::stats($db));
}

if ($action === 'rebuild_chunks') {
    $db = sidecarDbOrFail();
    $roomId = intval($body['room_id'] ?? $_GET['room_id'] ?? 0) ?: null;
    ok(ChunkBuilder::rebuildAll($db, $roomId), '知识块重建完成');
}

if ($action === 'vectorize_pending') {
    $db = sidecarDbOrFail();
    $batch = min(100, max(1, intval($body['batch'] ?? 50)));
    try {
        ok(Vectorizer::vectorizePending($db, $batch), '向量化完成');
    } catch (Exception $e) {
        fail('向量化失败', 500);
    }
}

if ($action === 'query_room') {
    $roomId = trim($body['room_id'] ?? $_GET['room_id'] ?? '');
    $question = trim($body['question'] ?? $_GET['question'] ?? '');
    $sessionId = trim($body['session_id'] ?? '');
    $isVerified = false;
    if (!$roomId || !$question) fail('缺少 room_id 或 question');
    $result = RoomQueryService::query($roomId, $question, $isVerified, $sessionId);
    if (!$result) fail('Sidecar 查询失败', 500);
    ok($result);
}

fail('未知操作');

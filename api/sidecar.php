<?php
// api/sidecar.php — Sidecar 知识块 / 向量化 / 房间查询运维

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sidecar/ChunkBuilder.php';
require_once __DIR__ . '/sidecar/Vectorizer.php';
require_once __DIR__ . '/sidecar/RoomQueryService.php';

$action = $_GET['action'] ?? '';
$body = getBody();

function sidecarDbOrFail(): PDO {
    try {
        return getSidecarDB();
    } catch (Exception $e) {
        fail('Sidecar 数据库连接失败：' . $e->getMessage(), 500);
    }
}

if ($action === 'stats') {
    $db = sidecarDbOrFail();
    ok(ChunkBuilder::stats($db));
}

if ($action === 'rebuild_chunks') {
    adminGuard();
    $db = sidecarDbOrFail();
    $roomId = intval($body['room_id'] ?? $_GET['room_id'] ?? 0) ?: null;
    ok(ChunkBuilder::rebuildAll($db, $roomId), '知识块重建完成');
}

if ($action === 'vectorize_pending') {
    adminGuard();
    $db = sidecarDbOrFail();
    $batch = min(100, max(1, intval($body['batch'] ?? 50)));
    try {
        ok(Vectorizer::vectorizePending($db, $batch), '向量化完成');
    } catch (Exception $e) {
        fail($e->getMessage(), 500);
    }
}

if ($action === 'query_room') {
    $roomId = trim($body['room_id'] ?? $_GET['room_id'] ?? '');
    $question = trim($body['question'] ?? $_GET['question'] ?? '');
    $sessionId = trim($body['session_id'] ?? '');
    $isVerified = !empty($body['is_verified']) || !empty($_GET['is_verified']);
    if (!$roomId || !$question) fail('缺少 room_id 或 question');
    $result = RoomQueryService::query($roomId, $question, $isVerified, $sessionId);
    if (!$result) fail('Sidecar 查询失败', 500);
    ok($result);
}

fail('未知操作');

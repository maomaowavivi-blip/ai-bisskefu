#!/usr/bin/env php
<?php
/**
 * 将永凯春晖 1013 的 ai_room_parking 复制到同 toponym 的其他房间，并重建知识块。
 *
 * 用法（MAMP PHP）:
 *   /Applications/MAMP/bin/php/php8.3.30/bin/php scripts/copy_yongkai_parking_from_1013.php
 */

require dirname(__DIR__) . '/api/config.php';
require dirname(__DIR__) . '/api/sidecar/ChunkBuilder.php';

$srcRoomCode = '1013';
$toponymLike = '%永凯%';

$sdb = getSidecarDB();

$srcProfile = $sdb->prepare('SELECT p.id, p.room_code FROM ai_room_profile p INNER JOIN ai_room_location l ON l.ai_room_id = p.id WHERE p.room_code = ? AND l.toponym LIKE ? LIMIT 1');
$srcProfile->execute([$srcRoomCode, $toponymLike]);
$srcRow = $srcProfile->fetch(PDO::FETCH_ASSOC);
if (!$srcRow) {
    fwrite(STDERR, "未找到源房间 {$srcRoomCode}\n");
    exit(1);
}
$srcRoomId = (int)$srcRow['id'];

$src = $sdb->prepare('SELECT * FROM ai_room_parking WHERE ai_room_id = ? LIMIT 1');
$src->execute([$srcRoomId]);
$srcPark = $src->fetch(PDO::FETCH_ASSOC);
if (!$srcPark) {
    fwrite(STDERR, "源房间 {$srcRoomCode} 无 ai_room_parking 数据\n");
    exit(1);
}

$targets = $sdb->prepare("
    SELECT p.id, p.room_code
    FROM ai_room_profile p
    INNER JOIN ai_room_location l ON l.ai_room_id = p.id AND l.toponym LIKE ?
    WHERE p.id != ?
    ORDER BY p.room_code
");
$targets->execute([$toponymLike, $srcRoomId]);
$rooms = $targets->fetchAll(PDO::FETCH_ASSOC);

$fields = [
    'parking_lot_name', 'address_park', 'building_name_park', 'lng_park', 'lat_park',
    'parking_lot_navigation_html', 'parking_lot_navigation_text', 'parking_fee_note', 'parking_rule_note',
];

$updSql = 'UPDATE ai_room_parking SET '
    . implode(', ', array_map(fn($f) => "$f = ?", $fields))
    . ', updated_at = NOW() WHERE ai_room_id = ?';
$updStmt = $sdb->prepare($updSql);

$updated = 0;
$chunks = 0;
foreach ($rooms as $t) {
    $roomId = (int)$t['id'];
    $exists = $sdb->prepare('SELECT id FROM ai_room_parking WHERE ai_room_id = ? LIMIT 1');
    $exists->execute([$roomId]);
    if (!$exists->fetchColumn()) {
        echo "跳过 {$t['room_code']}：无 ai_room_parking 行\n";
        continue;
    }
    $vals = array_map(fn($f) => $srcPark[$f] ?? null, $fields);
    $updStmt->execute([...$vals, $roomId]);
    $updated++;
    $chunks += ChunkBuilder::rebuildRoom($sdb, $roomId);
    echo "✓ {$t['room_code']} (id={$roomId})\n";
}

echo "\n源: {$srcRoomCode} (ai_room_id={$srcRoomId})\n";
echo "复制字段: " . implode(', ', $fields) . "\n";
echo "更新房间: {$updated}\n";
echo "重建知识块: {$chunks}\n";
echo "fee_note: {$srcPark['parking_fee_note']}\n";
echo "rule_note: {$srcPark['parking_rule_note']}\n";
echo "nav_text: {$srcPark['parking_lot_navigation_text']}\n";

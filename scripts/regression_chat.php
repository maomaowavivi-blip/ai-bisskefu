<?php
// scripts/regression_chat.php
// Phase 2 回归测试：验证 AgentConfig 切换后 AI 行为不变

$baseUrl = $argv[1] ?? 'http://localhost:8888';  // MAMP 默认端口
$mode    = $argv[2] ?? '--core';  // --core 只跑不依赖网关的用例，--live 全量

$cases = [
    ['id' => '1',  'msg' => '几点入住',              'expect' => '14:00',        'mode' => 'core'],
    ['id' => '2',  'msg' => '中午几点必须走',         'expect' => '12:00',        'mode' => 'core'],
    ['id' => '3',  'msg' => 'WiFi密码多少',           'expect' => '云房卡',        'mode' => 'core'],
    ['id' => '4',  'msg' => '云房卡是什么',           'expect' => '云房卡',        'mode' => 'core'],
    ['id' => '5',  'msg' => '我想续住一晚',           'expect' => '转接人工',      'mode' => 'core'],
    ['id' => '6',  'msg' => '我要退款',               'expect' => '平台',          'mode' => 'core'],
    ['id' => '7',  'msg' => '可以带宠物吗',           'expect' => '宠物',          'mode' => 'core'],
    ['id' => '13', 'msg' => '附近好吃吗',             'expect' => '没有查到',      'mode' => 'core'],
    ['id' => '14', 'msg' => '南宁今天适合出门吗',     'expect' => '没有查到',      'mode' => 'core'],
    // 以下依赖 Sidecar + 网关，仅 --live 模式跑
    ['id' => 'F1', 'msg' => 'order_query:1128148162721995', 'expect' => '查询成功', 'mode' => 'live'],
    ['id' => '8',  'msg' => '房间地址在哪',           'expect' => '订单号',        'mode' => 'live'],
    ['id' => '9',  'msg' => '',                       'expect' => '',              'mode' => 'live', 'note' => '需要上一条 session 发订单号'],
    ['id' => '10', 'msg' => '有停车场吗',             'expect' => '停车',          'mode' => 'live', 'note' => '需绑单后'],
    ['id' => '11', 'msg' => '垃圾放哪',               'expect' => '垃圾',          'mode' => 'live', 'note' => '需绑单后'],
    ['id' => '12', 'msg' => '房间有洗衣机吗',         'expect' => '',              'mode' => 'live', 'note' => '需绑单后，不含温馨提示全文'],
    ['id' => '15', 'msg' => '谢谢',                   'expect' => '好的',          'mode' => 'live', 'note' => '需绑单后'],
];

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($cases as $c) {
    if ($mode === '--core' && ($c['mode'] ?? '') === 'live') {
        echo "SKIP  #{$c['id']} {$c['msg']} (需要网关/Sidecar)\n";
        $skipped++;
        continue;
    }

    $sessionId = 'reg_' . $c['id'] . '_' . time();
    $body = json_encode(['session_id' => $sessionId, 'message' => $c['msg']], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($baseUrl . '/api/chat.php?action=chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    $reply = $data['data']['reply'] ?? '';

    if ($c['expect'] === '') {
        echo "PASS  #{$c['id']} {$c['msg']} → {$reply}\n";
        $passed++;
    } elseif (mb_strpos($reply, $c['expect']) !== false) {
        echo "PASS  #{$c['id']} {$c['msg']} → {$reply}\n";
        $passed++;
    } else {
        echo "FAIL  #{$c['id']} {$c['msg']}\n  expect: {$c['expect']}\n  actual: {$reply}\n";
        $failed++;
    }
}

echo "\n==========\n";
echo "通过: {$passed}  失败: {$failed}  跳过: {$skipped}\n";
exit($failed > 0 ? 1 : 0);

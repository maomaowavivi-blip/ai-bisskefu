#!/usr/bin/env php
<?php
/**
 * 一次性：将 Layer B 默认配置灌入 platform_config
 *
 * 用法：php scripts/migrate_agent_config.php [--industry=homestay]
 */

$opts = getopt('', array('industry:'));
$industry = $opts['industry'] ?? 'homestay';

$root = dirname(__DIR__);
require_once $root . '/api/config.php';

/** @return array<string, string> */
function migrateAgentConfigValues() {
    return array(
        'agent.industry' => 'homestay',
        'agent.fallback.reply' => '这边暂时没有查到准确信息，建议您联系前台确认。',
        'agent.fallback.preserved_markers' => '["转接人工","不太方便讨论","没法聊","无法回应"]',
        'agent.fallback.contact_label' => '前台',
        'agent.reply.min_chars' => '20',
        'agent.reply.max_chars' => '80',
        'agent.llm.max_tokens' => '150',
        'plugin.sidecar.enabled' => '1',
        'plugin.order_query.enabled' => '1',
        'agent.order.guide_reply' => '如需查询订单，请点击下方「订单查询」按钮，或直接发送 order_query:订单号～',
        'agent.prohibition.examples' => '["地址","路线","导航","停车","车位","收费","WiFi","门禁","房价","押金","退改政策","设施","设备","订单状态","入住时间","周边配套"]',
        'agent.rules.speech_bans' => '["禁止推荐房源、换房、升级、预订、下单等任何引导消费的话术","禁止追问房源、小区、房型、订单平台等信息","禁止以「您」开头的问句或建议","禁止引导在本客服内预订；问如何订房只指引至配置的平台搜索品牌","禁止万能结尾（如「有任何问题随时找我」「有我在」等）","禁止回答后追加第二句、第三句"]',
        'agent.rules.booking_platforms' => '携程、美团、去哪儿',
        'agent.rules.booking_brand_hint' => '宿家民宿',
        'agent.rules.handoff_system_hint' => "【必须直接转人工】以下问题只回复\"正在为您转接人工客服，请稍候。\"不做任何其他回答\n涉及：发票、续住、换房、退款、投诉、赔偿、押金纠纷等；具体以系统「转人工规则」词库为准",
        'agent.filter.sales_patterns' => '["推荐.*房型","建议.*升级","建议.*换","看看.*套房","适合.*人数.*房型","可以.*看看","可以.*选择"]',
        'agent.filter.bad_endings' => '["有任何问题随时找我","有我在","随时联系我","有什么可以帮您","随时为您服务","请告诉我","需要的话","可以告诉我","我帮您"]',
        'agent.routing.credential_guide' => 'WiFi密码、门锁密码、在线交押金及公安刷脸核验，请在云房卡中查看。请点击聊天窗口「订单查询」，或发送 order_query:您的订单号，查询成功后点击云房卡即可。',
        'agent.routing.credential_keywords' => '["wifi","WiFi","无线","无线网","网络","网密码","上网","门禁","门锁","密码锁","门锁密码","进门密码","单元门","大门密码","钥匙密码","刷脸","公安","核验","实名认证","实名登记","人脸核验","人脸验证","身份核验"]',
        'agent.routing.credential_kb_marker' => '请在云房卡中查看',
        'agent.routing.sidecar_route_phrases' => '["请提供订单号","提供订单号","查询订单后","请先查询订单"]',
        'agent.routing.sidecar_entry_extra' => '',
        'agent.kb.policy_patterns' => '[{"pattern":"/几点.*入住/u","blob_needle":"入住"},{"pattern":"/入住.*几点/u","blob_needle":"入住"},{"pattern":"/几点.*退房/u","blob_needle":"退房"},{"pattern":"/退房.*几点/u","blob_needle":"退房"},{"pattern":"/(中午|必须).{0,6}(几点|走|退)/u","blob_needle":"退房"},{"pattern":"/(可以|能|能否).{0,4}带.{0,2}宠物/u","blob_needle":"宠物"},{"pattern":"/宠物/u","blob_needle":"宠物"},{"pattern":"/(退款|退钱|退费|申请退款)/u","blob_needle":"退款"},{"pattern":"/云房卡/u","blob_needle":"云房卡"},{"pattern":"/(WiFi|wifi|无线).{0,6}密码/u","blob_needle":"WiFi"},{"pattern":"/(刷脸|公安.{0,4}核验|实名)/u","blob_needle":"刷脸"},{"pattern":"/(交|付|缴).{0,4}押金/u","blob_needle":"押金"},{"pattern":"/(门禁|门锁).{0,4}密码/u","blob_needle":"门禁"},{"pattern":"/(接送机|接机|送机|寄存行李|生日布置)/u","blob_needle":"增值"},{"pattern":"/(预订|订房|下单|代订|帮我订|帮你订)/u","blob_needle":"预订"}]',
    );
}

$db = getDB();
$values = migrateAgentConfigValues();
$values['agent.industry'] = $industry;

$remark = 'migrate: ' . date('Y-m-d H:i:s');
$stmt = $db->prepare(
    'INSERT INTO platform_config (`key`, `value`, `remark`) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `remark` = VALUES(`remark`)'
);

$written = 0;
foreach ($values as $key => $value) {
    $stmt->execute(array($key, $value, $remark));
    $written++;
}

$migratedKeywords = 0;
$roomKw = trim((string)pcGet($db, 'gateway.room_keywords', ''));
$extra = trim((string)pcGet($db, 'agent.routing.sidecar_entry_extra', ''));
if ($roomKw !== '' && $extra === '') {
    $stmt->execute(array('agent.routing.sidecar_entry_extra', $roomKw, $remark . ' (from gateway.room_keywords)'));
    $written++;
    $migratedKeywords = 1;
}

echo "migrate_agent_config.php\n";
echo "Industry: {$industry}\n";
echo "platform_config rows written/updated: {$written}\n";
echo "sidecar_entry_extra migrated from gateway.room_keywords: {$migratedKeywords}\n";
echo "Done.\n";

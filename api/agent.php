<?php
// api/agent.php
// Layer B 智能体配置 CRUD（Phase 1：读写配置，不改变 PromptEngine 行为）

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AgentConfig.php';
require_once __DIR__ . '/IndustryTemplate.php';

$action = $_GET['action'] ?? '';
$body = getBody();
$db = getDB();

adminGuard();

$jsonKeys = array(
    'agent.fallback.preserved_markers',
    'agent.prohibition.examples',
    'agent.rules.speech_bans',
    'agent.filter.sales_patterns',
    'agent.filter.bad_endings',
    'agent.routing.credential_keywords',
    'agent.routing.sidecar_route_phrases',
    'agent.kb.policy_patterns',
    'agent.safety.political',
);

$secretKeyPrefixes = array('ai.api_key', 'gateway.api_key', 'order.api_key', 'JWT_SECRET');

function agentIsJsonKey($key, array $jsonKeys) {
    return in_array($key, $jsonKeys, true);
}

function agentValidateJsonValue($key, $value) {
    if ($value === '' || $value === null) {
        return true;
    }
    $decoded = json_decode((string)$value, true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
}

function agentParseConfigForUi(AgentConfig $cfg) {
    $raw = $cfg->all();
    $parsed = array();
    foreach ($raw as $k => $v) {
        $parsed[$k] = $v;
        if (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) {
            $decoded = json_decode($v, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsed[$k . '__parsed'] = $decoded;
            }
        }
    }
    return $parsed;
}

if ($action === 'get_config') {
    $cfg = new AgentConfig($db);
    ok(array(
        'config' => agentParseConfigForUi($cfg),
        'phase' => 1,
        'note' => 'Phase 1 — 配置仅存储/展示，PromptEngine 仍读 PHP 硬编码',
    ));
}

if ($action === 'save_config') {
    $items = $body['config'] ?? $body;
    if (!is_array($items)) {
        fail('无效的配置数据');
    }

    $stmt = $db->prepare(
        'INSERT INTO platform_config (`key`, `value`, `remark`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `remark` = VALUES(`remark`)'
    );
    $saved = 0;
    foreach ($items as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (strpos($key, 'agent.') !== 0 && strpos($key, 'plugin.') !== 0) {
            continue;
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $value = (string)$value;
        if (agentIsJsonKey($key, $jsonKeys) && !agentValidateJsonValue($key, $value)) {
            fail('JSON 格式错误：' . $key);
        }
        $stmt->execute(array($key, $value, 'admin:save_config'));
        $saved++;
    }

    AgentConfig::clearCache();
    ok(array('saved' => $saved), '保存成功');
}

if ($action === 'apply_industry_template') {
    $industry = trim((string)($body['industry'] ?? 'homestay'));
    $importKb = !isset($body['import_kb']) || !empty($body['import_kb']);
    $importHandoff = !isset($body['import_handoff']) || !empty($body['import_handoff']);

    $result = IndustryTemplate::apply($db, $industry, $importKb, $importHandoff);
    ok($result, '行业模板已应用');
}

if ($action === 'export_config') {
    $cfg = new AgentConfig($db);
    $raw = $cfg->all();
    $export = array();
    foreach ($raw as $key => $value) {
        if (strpos($key, 'agent.') !== 0 && strpos($key, 'plugin.') !== 0) {
            continue;
        }
        $skip = false;
        foreach ($secretKeyPrefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $export[$key] = $value;
        }
    }
    ok(array(
        'exported_at' => date('c'),
        'industry' => $export['agent.industry'] ?? pcGet($db, 'agent.industry', 'homestay'),
        'config' => $export,
    ));
}

if ($action === 'import_config') {
    $incoming = $body['config'] ?? array();
    if (!is_array($incoming)) {
        fail('无效的配置数据');
    }

    $stmt = $db->prepare(
        'INSERT INTO platform_config (`key`, `value`, `remark`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `remark` = VALUES(`remark`)'
    );
    $imported = 0;
    foreach ($incoming as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (strpos($key, 'agent.') !== 0 && strpos($key, 'plugin.') !== 0) {
            continue;
        }
        foreach ($secretKeyPrefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                continue 2;
            }
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $value = (string)$value;
        if (agentIsJsonKey($key, $jsonKeys) && !agentValidateJsonValue($key, $value)) {
            fail('JSON 格式错误：' . $key);
        }
        $stmt->execute(array($key, $value, 'admin:import_config'));
        $imported++;
    }

    AgentConfig::clearCache();
    ok(array('imported' => $imported), '导入成功');
}

fail('未知 action', 404);

<?php
// api/IndustryTemplate.php

require_once __DIR__ . '/AgentConfig.php';

class IndustryTemplate {

    /**
     * @return array{industry:string, agent_keys:int, kb_imported:int, handoff_imported:int}
     */
    public static function apply(PDO $db, $industry, $importKb = true, $importHandoff = true) {
        $industry = trim((string)$industry);
        if ($industry === '') {
            $industry = 'homestay';
        }

        $result = array(
            'industry' => $industry,
            'agent_keys' => self::importAgentDefaults($db, $industry),
            'kb_imported' => 0,
            'handoff_imported' => 0,
        );

        if ($importKb) {
            $result['kb_imported'] = self::importKbSeed($db, $industry);
        }
        if ($importHandoff) {
            $result['handoff_imported'] = self::importHandoffSeed($db, $industry);
        }

        return $result;
    }

    public static function importAgentDefaults(PDO $db, $industry) {
        $path = dirname(__DIR__) . '/templates/' . $industry . '/agent_defaults.json';
        if (!is_file($path)) {
            return 0;
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return 0;
        }

        $stmt = $db->prepare(
            'INSERT INTO platform_config (`key`, `value`, `remark`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `remark` = VALUES(`remark`)'
        );
        $remark = 'industry_template:' . $industry;
        $count = 0;
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (strpos($key, 'agent.') !== 0 && strpos($key, 'plugin.') !== 0) {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $stmt->execute(array($key, (string)$value, $remark));
            $count++;
        }

        AgentConfig::clearCache();
        return $count;
    }

    private static function importKbSeed(PDO $db, $industry) {
        $path = dirname(__DIR__) . '/templates/' . $industry . '/kb_seed.json';
        if (!is_file($path)) {
            return 0;
        }
        $entries = json_decode(file_get_contents($path), true);
        if (!is_array($entries)) {
            return 0;
        }

        $count = 0;
        $ins = $db->prepare(
            'INSERT INTO kb_entries (category_id, question, answer, keywords, similar_questions, status, hit_count)
             VALUES (?, ?, ?, ?, ?, 1, 0)'
        );
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $catName = $entry['category'] ?? '默认';
            $catId = self::ensureCategory($db, $catName);
            $similar = null;
            if (!empty($entry['similar']) && is_array($entry['similar'])) {
                $similar = json_encode($entry['similar'], JSON_UNESCAPED_UNICODE);
            }
            $ins->execute(array(
                $catId,
                $entry['question'] ?? '',
                $entry['answer'] ?? '',
                $entry['keywords'] ?? '',
                $similar,
            ));
            $count++;
        }
        return $count;
    }

    private static function importHandoffSeed(PDO $db, $industry) {
        $path = dirname(__DIR__) . '/templates/' . $industry . '/handoff_seed.json';
        if (!is_file($path)) {
            return 0;
        }
        $entries = json_decode(file_get_contents($path), true);
        if (!is_array($entries)) {
            return 0;
        }

        $count = 0;
        $ins = $db->prepare('INSERT IGNORE INTO handoff_triggers (keyword, priority) VALUES (?, ?)');
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ins->execute(array($entry['keyword'] ?? '', (int)($entry['priority'] ?? 2)));
            $count += $ins->rowCount();
        }
        return $count;
    }

    private static function ensureCategory(PDO $db, $name) {
        $name = trim((string)$name);
        if ($name === '') {
            $name = '默认';
        }
        $st = $db->prepare('SELECT id FROM kb_categories WHERE name = ? LIMIT 1');
        $st->execute(array($name));
        $row = $st->fetch();
        if ($row) {
            return (int)$row['id'];
        }
        $db->prepare('INSERT INTO kb_categories (name, parent_id, sort_order) VALUES (?, 0, 0)')->execute(array($name));
        return (int)$db->lastInsertId();
    }
}

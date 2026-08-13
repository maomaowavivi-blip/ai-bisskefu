<?php
// api/AgentConfig.php
// Layer B 配置读取：platform_config + templates/{industry}/agent_defaults.json

require_once __DIR__ . '/config.php';

class AgentConfig {
    /** @var array|null */
    private static $cache = null;

    /** @var PDO */
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * 读取配置值，优先级：platform_config(DB) > agent_defaults.json > $default
     */
    public function get($key, $default = null) {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        return $default;
    }

    public function getInt($key, $default = 0) {
        $v = $this->get($key);
        return ($v !== null && $v !== '' && is_numeric($v)) ? (int)$v : $default;
    }

    public function getJson($key, array $default = array()) {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        $decoded = json_decode($v, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * @return array<string, string>
     */
    public function all() {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $industry = pcGet($this->db, 'agent.industry', 'homestay');
        if ($industry === null || $industry === '') {
            $industry = 'homestay';
        }
        $defaults = $this->loadDefaults($industry);
        $dbOverrides = $this->loadDbOverrides();

        self::$cache = array_merge($defaults, $dbOverrides);
        return self::$cache;
    }

    private function loadDefaults($industry) {
        $path = dirname(__DIR__) . '/templates/' . $industry . '/agent_defaults.json';
        if (!is_file($path)) {
            return array();
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return array();
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : array();
    }

    private function loadDbOverrides() {
        try {
            $stmt = $this->db->query(
                "SELECT `key`, `value` FROM platform_config WHERE `key` LIKE 'agent.%' OR `key` LIKE 'plugin.%'"
            );
            $overrides = array();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $k = $row['key'] ?? '';
                if ($k !== '') {
                    $overrides[$k] = $row['value'] ?? '';
                }
            }
            return $overrides;
        } catch (Exception $e) {
            return array();
        }
    }

    public static function clearCache() {
        self::$cache = null;
    }

    /** Phase 2：替代 SidecarIntent::isYunfangkaCredentialQuery */
    public function isCredentialQuery($message) {
        $msg = trim((string)$message);
        if ($msg === '') {
            return false;
        }

        $faultWords = array(
            '密码错误', '打不开', '开不了', '进不去', '进不了', '连不上', '上不了网', '断网', '没网络',
            '失效', '不对', '没反应', '押金不退', '扣押金', '乱扣费', '押金纠纷',
        );
        foreach ($faultWords as $fw) {
            if (mb_strpos($msg, $fw) !== false) {
                return false;
            }
        }

        if (preg_match('/(交|付|缴).{0,4}押金/u', $msg) || preg_match('/押金.{0,6}(怎么交|在哪|如何|多少)/u', $msg)) {
            return true;
        }

        foreach (array('刷脸', '公安', '核验', '实名认证', '实名登记', '人脸核验', '人脸验证', '身份核验') as $kw) {
            if (mb_strpos($msg, $kw) !== false) {
                return true;
            }
        }

        $keywords = $this->getJson('agent.routing.credential_keywords', array());
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_stripos($msg, $kw) !== false) {
                return true;
            }
        }

        if (preg_match('/(WiFi|wifi|无线).{0,4}密码/u', $message) || preg_match('/密码.{0,4}(WiFi|wifi|无线|门锁|门禁)/u', $message)) {
            return true;
        }

        return false;
    }

    /**
     * v3.3 PR4：Intent 架构相关配置默认值
     * 决策 2：AgentConfig Phase 2（暂不实施到 PromptEngine，仅提供配置位）
     */
    public static function defaultIntentConfig(): array
        {
            return [
                // Intent 分类
                'agent.intent.llm_enabled' => 'false',  // 蓝图 §六 修正 3：LLM 不参与 Intent 分类
                'agent.intent.confidence_min' => '0.5',
                'agent.intent.unknown_threshold' => '0.3',

                // Workflow 行为
                'agent.workflow.unknown.try_semantic' => 'true',  // UnknownWorkflow 先试 KB 弱匹配
                'agent.workflow.unknown.llm_timeout_ms' => '1200',
                'agent.workflow.unknown.llm_max_tokens' => '80',
                'agent.workflow.unknown.llm_temperature' => '0.2',

                // Reply 渲染
                'agent.reply.min_chars' => '20',
                'agent.reply.max_chars' => '80',
                'agent.reply.temperature' => '0.2',  // 决策点 5：低温度防飘逸
            ];
        }
    }

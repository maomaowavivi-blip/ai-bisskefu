# Phase 1 实施任务：可配置智能体 — 配置层搭建

## 总体目标
搭建可配置智能体的基础设施（配置读写），但**不改变现网 AI 客服的任何行为**。所有原有逻辑照常运行，PromptEngine 仍然读取 PHP 硬编码常量。Phase 2 才会把 PromptEngine 切换到读数据库配置。

## 硬约束（必须遵守）
1. **PHP 7 兼容**：不能用 PHP 8 专属语法（如 named arguments、match 表达式、str_starts_with 等，项目已有兼容层）
2. **不改变现有行为**：不改 PromptEngine.php、不改 chat.php 的路由逻辑
3. **所有新文件用 `<?php` 开头**，遵循项目现有代码风格
4. **配置存储**：`platform_config` 表（已存在），key 列 varchar(50)，value 列 text，均为字符串
5. **JSON 配置**：UTF-8 单行 JSON 存入 value 字段，布尔用 "0"/"1"
6. **布尔值**：用字符串 "0" / "1" 表示
7. **读取工具**：复用现有 `pcGet($db, $key, $default)` 函数（api/config.php L155-167）

---

## 文件 1：sql/init.sql — 补全 api_keys 表

在文件末尾（`-- 初始数据` 之前）添加 api_keys 建表语句，内容照抄 `sql/migration_v2.0.sql` 第 6-15 行的 CREATE TABLE 语句。

---

## 文件 2：.env.example — 更新 AI 模型说明

把 MiniMax 相关注释改为 DeepSeek 默认。当前 config.php 的默认模型是 `deepseek-v4-flash`，.env.example 应该说明这点。

---

## 文件 3：api/AgentConfig.php — 核心配置读取类（新增）

职责：从 `platform_config` 表 + `templates/{industry}/agent_defaults.json` 合并读取配置，提供请求级缓存。

```php
<?php
// api/AgentConfig.php

require_once __DIR__ . '/config.php';

class AgentConfig {
    private static ?array $cache = null;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * 读取配置值，优先级：platform_config(DB) > agent_defaults.json > $default 参数
     */
    public function get(string $key, ?string $default = null): ?string {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    /**
     * 读取整数配置
     */
    public function getInt(string $key, int $default = 0): int {
        $v = $this->get($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    /**
     * 读取 JSON 数组配置
     */
    public function getJson(string $key, array $default = []): array {
        $v = $this->get($key);
        if ($v === null || $v === '') return $default;
        $decoded = json_decode($v, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * 返回所有配置合并结果（DB 优先，JSON 兜底），请求级缓存
     */
    public function all(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // 1. 从 agent_defaults.json 加载行业默认值
        $industry = pcGet($this->db, 'agent.industry', 'homestay');
        $defaults = $this->loadDefaults($industry);

        // 2. DB 中有则覆盖
        $dbOverrides = $this->loadDbOverrides();

        self::$cache = array_merge($defaults, $dbOverrides);
        return self::$cache;
    }

    private function loadDefaults(string $industry): array {
        $path = dirname(__DIR__) . "/templates/{$industry}/agent_defaults.json";
        if (!is_file($path)) return [];
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function loadDbOverrides(): array {
        try {
            $stmt = $this->db->query("SELECT `key`, `value` FROM platform_config WHERE `key` LIKE 'agent.%' OR `key` LIKE 'plugin.%'");
            $overrides = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $k = $row['key'] ?? '';
                $v = $row['value'] ?? '';
                if ($k !== '') {
                    $overrides[$k] = $v;
                }
            }
            return $overrides;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 清除请求级缓存（用于测试或长驻进程）
     */
    public static function clearCache(): void {
        self::$cache = null;
    }

    // ── 便捷方法（Phase 2 用）──

    /** 判断消息是否为凭证类查询（替代 SidecarIntent::isYunfangkaCredentialQuery） */
    public function isCredentialQuery(string $message): bool {
        $keywords = $this->getJson('agent.routing.credential_keywords', []);
        if (empty($keywords)) return false;
        // 故障/纠纷类不触发凭证引导
        $faultWords = ['密码错误', '打不开', '开不了', '进不去', '进不了', '连不上', '上不了网', '断网', '没网络', '失效', '不对', '没反应', '押金不退', '扣押金', '乱扣费', '押金纠纷'];
        foreach ($faultWords as $fw) {
            if (mb_strpos($message, $fw) !== false) return false;
        }
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_strpos($message, $kw) !== false) return true;
        }
        return false;
    }
}
```

要点：
- `all()` 方法合并 JSON defaults + DB overrides，DB 优先
- 请求级缓存（static $cache），同一次请求多次调用不重复查库
- `clearCache()` 供测试用

---

## 文件 4：api/agent.php — 配置 CRUD API（新增）

接口设计：

| action | method | 说明 |
|--------|--------|------|
| `get_config` | GET | 返回当前全部 agent.* + plugin.* 配置（合并 defaults + DB） |
| `save_config` | POST | 保存配置（仅写 platform_config，校验 JSON 字段格式） |
| `apply_industry_template` | POST | 切换行业模板，参数：industry + flags（import_kb, import_handoff） |
| `export_config` | GET | 导出 Layer B 配置（排除密钥），返回 JSON |
| `import_config` | POST | 导入 Layer B 配置（校验后写入） |

要求：
- 所有 action 需要 admin 鉴权（调用 `adminGuard()`）
- `save_config` 只接受 `agent.*` 和 `plugin.*` 开头的 key
- 对于 JSON 类型的字段（如 policy_patterns、speech_bans 等），校验 `json_decode` 成功才写入
- `export_config` 排除 `ai.api_key`、`gateway.api_key`、`order.api_key`、`JWT_SECRET`、`persona_config` 全文
- `apply_industry_template` 调用 IndustryTemplate 类的方法
- 返回格式遵循项目规范：`ok($data)` / `fail($msg, $code)`

---

## 文件 5：api/IndustryTemplate.php — 行业模板管理（新增）

职责：导入 KB 条目 + handoff 触发词（不导入 persona），从 JSON 模板文件读取。

```php
<?php
// api/IndustryTemplate.php

class IndustryTemplate {

    /**
     * 应用行业模板
     * @return array{industry:string, kb_imported:int, handoff_imported:int}
     */
    public static function apply(PDO $db, string $industry, bool $importKb = true, bool $importHandoff = true): array {
        $result = ['industry' => $industry, 'kb_imported' => 0, 'handoff_imported' => 0];

        if ($importKb) {
            $result['kb_imported'] = self::importKbSeed($db, $industry);
        }

        if ($importHandoff) {
            $result['handoff_imported'] = self::importHandoffSeed($db, $industry);
        }

        return $result;
    }

    private static function importKbSeed(PDO $db, string $industry): int {
        $path = dirname(__DIR__) . "/templates/{$industry}/kb_seed.json";
        if (!is_file($path)) return 0;
        $entries = json_decode(file_get_contents($path), true);
        if (!is_array($entries)) return 0;

        $count = 0;
        $ins = $db->prepare(
            'INSERT INTO kb_entries (category_id, question, answer, keywords, similar_questions, status, hit_count)
             VALUES (?, ?, ?, ?, ?, 1, 0)'
        );
        foreach ($entries as $entry) {
            $catName = $entry['category'] ?? '默认';
            $catId = self::ensureCategory($db, $catName);
            $ins->execute([
                $catId,
                $entry['question'] ?? '',
                $entry['answer'] ?? '',
                $entry['keywords'] ?? '',
                !empty($entry['similar']) ? json_encode($entry['similar'], JSON_UNESCAPED_UNICODE) : null,
            ]);
            $count++;
        }
        return $count;
    }

    private static function importHandoffSeed(PDO $db, string $industry): int {
        $path = dirname(__DIR__) . "/templates/{$industry}/handoff_seed.json";
        if (!is_file($path)) return 0;
        $entries = json_decode(file_get_contents($path), true);
        if (!is_array($entries)) return 0;

        $count = 0;
        $ins = $db->prepare('INSERT IGNORE INTO handoff_triggers (keyword, priority) VALUES (?, ?)');
        foreach ($entries as $entry) {
            $ins->execute([$entry['keyword'] ?? '', (int)($entry['priority'] ?? 2)]);
            $count += $ins->rowCount();
        }
        return $count;
    }

    private static function ensureCategory(PDO $db, string $name): int {
        $st = $db->prepare('SELECT id FROM kb_categories WHERE name = ? LIMIT 1');
        $st->execute([$name]);
        $row = $st->fetch();
        if ($row) return (int)$row['id'];

        $db->prepare('INSERT INTO kb_categories (name, sort_order) VALUES (?, 0)')->execute([$name]);
        return (int)$db->lastInsertId();
    }
}
```

---

## 文件 6：templates/homestay/agent_defaults.json（新增）

创建目录 `templates/homestay/`，写入 JSON 文件，内容为蓝图 §4.3 中定义的默认值，**另外补充 3 个字段**（preserved_markers、handoff_system_hint、policy_patterns）：

```json
{
  "agent.industry": "homestay",
  "agent.fallback.reply": "这边暂时没有查到准确信息，建议您联系前台确认。",
  "agent.fallback.preserved_markers": "[\"转接人工\",\"不太方便讨论\",\"没法聊\",\"无法回应\"]",
  "agent.fallback.contact_label": "前台",
  "agent.reply.min_chars": "20",
  "agent.reply.max_chars": "80",
  "agent.llm.max_tokens": "150",
  "plugin.sidecar.enabled": "1",
  "plugin.order_query.enabled": "1",
  "agent.order.guide_reply": "如需查询订单，请点击下方「订单查询」按钮，或直接发送 order_query:订单号～",
  "agent.prohibition.examples": "[\"地址\",\"路线\",\"导航\",\"停车\",\"车位\",\"收费\",\"WiFi\",\"门禁\",\"房价\",\"押金\",\"退改政策\",\"设施\",\"设备\",\"订单状态\",\"入住时间\",\"周边配套\"]",
  "agent.rules.speech_bans": "[\"禁止推荐房源、换房、升级、预订、下单等任何引导消费的话术\",\"禁止追问房源、小区、房型、订单平台等信息\",\"禁止以「您」开头的问句或建议\",\"禁止引导在本客服内预订；问如何订房只指引至配置的平台搜索品牌\",\"禁止万能结尾（如「有任何问题随时找我」「有我在」等）\",\"禁止回答后追加第二句、第三句\"]",
  "agent.rules.booking_platforms": "携程、美团、去哪儿",
  "agent.rules.booking_brand_hint": "宿家民宿",
  "agent.rules.handoff_system_hint": "【必须直接转人工】以下问题只回复\"正在为您转接人工客服，请稍候。\"不做任何其他回答\n涉及：发票、续住、换房、退款、投诉、赔偿、押金纠纷等；具体以系统「转人工规则」词库为准",
  "agent.filter.sales_patterns": "[\"推荐.*房型\",\"建议.*升级\",\"建议.*换\",\"看看.*套房\",\"适合.*人数.*房型\",\"可以.*看看\",\"可以.*选择\"]",
  "agent.filter.bad_endings": "[\"有任何问题随时找我\",\"有我在\",\"随时联系我\",\"有什么可以帮您\",\"随时为您服务\",\"请告诉我\",\"需要的话\",\"可以告诉我\",\"我帮您\"]",
  "agent.routing.credential_guide": "WiFi密码、门锁密码、在线交押金及公安刷脸核验，请在云房卡中查看。请点击聊天窗口「订单查询」，或发送 order_query:您的订单号，查询成功后点击云房卡即可。",
  "agent.routing.credential_keywords": "[\"wifi\",\"WiFi\",\"无线\",\"无线网\",\"网络\",\"网密码\",\"上网\",\"门禁\",\"门锁\",\"密码锁\",\"门锁密码\",\"进门密码\",\"单元门\",\"大门密码\",\"钥匙密码\",\"刷脸\",\"公安\",\"核验\",\"实名认证\",\"实名登记\",\"人脸核验\",\"人脸验证\",\"身份核验\"]",
  "agent.routing.credential_kb_marker": "请在云房卡中查看",
  "agent.routing.sidecar_route_phrases": "[\"请提供订单号\",\"提供订单号\",\"查询订单后\",\"请先查询订单\"]",
  "agent.routing.sidecar_entry_extra": "",
  "agent.kb.policy_patterns": "[{\"pattern\":\"/几点.*入住/u\",\"blob_needle\":\"入住\"},{\"pattern\":\"/入住.*几点/u\",\"blob_needle\":\"入住\"},{\"pattern\":\"/几点.*退房/u\",\"blob_needle\":\"退房\"},{\"pattern\":\"/退房.*几点/u\",\"blob_needle\":\"退房\"},{\"pattern\":\"/(中午|必须).{0,6}(几点|走|退)/u\",\"blob_needle\":\"退房\"},{\"pattern\":\"/(可以|能|能否).{0,4}带.{0,2}宠物/u\",\"blob_needle\":\"宠物\"},{\"pattern\":\"/宠物/u\",\"blob_needle\":\"宠物\"},{\"pattern\":\"/(退款|退钱|退费|申请退款)/u\",\"blob_needle\":\"退款\"},{\"pattern\":\"/云房卡/u\",\"blob_needle\":\"云房卡\"},{\"pattern\":\"/(WiFi|wifi|无线).{0,6}密码/u\",\"blob_needle\":\"WiFi\"},{\"pattern\":\"/(刷脸|公安.{0,4}核验|实名)/u\",\"blob_needle\":\"刷脸\"},{\"pattern\":\"/(交|付|缴).{0,4}押金/u\",\"blob_needle\":\"押金\"},{\"pattern\":\"/(门禁|门锁).{0,4}密码/u\",\"blob_needle\":\"门禁\"},{\"pattern\":\"/(接送机|接机|送机|寄存行李|生日布置)/u\",\"blob_needle\":\"增值\"},{\"pattern\":\"/(预订|订房|下单|代订|帮我订|帮你订)/u\",\"blob_needle\":\"预订\"}]"
}
```

**重要说明**：
- 上面的值已经是最终版，直接复制写入 JSON 文件即可
- policy_patterns 共 15 条（含 1 条「预订」），顺序与 PromptEngine.php L518-534 完全一致，**不能调整顺序**

---

## 文件 7：templates/homestay/kb_seed.json（新增）

从 `api/KnowledgeBaseSeed.php` 的 `catalog()` 方法中导出所有 KB 条目。每条格式：

```json
{
  "category": "品牌介绍",
  "question": "宿家民宿是什么？",
  "answer": "宿家民宿是面向客人的南宁城市民宿品牌...",
  "keywords": "宿家,宿家民宿,品牌",
  "similar": ["你们是什么民宿", "橙途民宿是什么"]
}
```

任务：读取 `api/KnowledgeBaseSeed.php` → `catalog()` → 遍历所有 category 和 entries → 导出为上述 JSON 数组。共 8 个分类约 30+ 条目。

---

## 文件 8：templates/homestay/handoff_seed.json（新增）

从 `api/HandoffTriggers.php` 的 `defaultSeed()` 方法导出。每条格式：

```json
{
  "keyword": "密码错误",
  "priority": 0
}
```

任务：读取 `api/HandoffTriggers.php` → `defaultSeed()` → 遍历所有优先级分组 → 导出为 JSON 数组。共约 100+ 条。

---

## 文件 9：scripts/migrate_agent_config.php（新增，一次性脚本）

**这是最关键的文件。** 作用：把现网 PHP 代码中写死的所有配置值，一次性灌入 `platform_config` 表。灌完退役。

要求：
1. 使用 `INSERT INTO platform_config (\`key\`, \`value\`, \`remark\`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE \`value\` = VALUES(\`value\`), \`remark\` = VALUES(\`remark\`)`
2. 每个配置键的 value **和上面 agent_defaults.json 中的完全一致**（脚本灌 DB 的值 = JSON 默认值）
3. 额外处理进流词迁移：从 `platform_config` 读 `gateway.room_keywords`，如果为空则跳过；如果有值且 `agent.routing.sidecar_entry_extra` 为空，则拷贝过去
4. 运行完输出报告：共写入多少条、迁移了进流词
5. 所有值硬编码在脚本的数组里（不读 JSON 文件），因为这是一次性脚本，写完就退役
6. remark 字段统一填 `'migrate: ' . date('Y-m-d H:i:s')`

---

## 文件 10：scripts/export_industry_templates.php（新增，一次性脚本）

作用：从 PHP Seed 类（KnowledgeBaseSeed、HandoffTriggers）导出 kb_seed.json 和 handoff_seed.json。

逻辑：
1. `require_once` KnowledgeBaseSeed.php 和 HandoffTriggers.php
2. 遍历 `KnowledgeBaseSeed::catalog()` → 写入 `templates/homestay/kb_seed.json`
3. 遍历 `HandoffTriggers::defaultSeed()` → 写入 `templates/homestay/handoff_seed.json`
4. 输出报告：导出了多少 KB 条目、多少 handoff 条目

---

## 文件 11：scripts/apply_industry_template.php（新增，一次性/SOP 脚本）

作用：命令行调用 IndustryTemplate::apply()。参数：`--industry=homestay`，可选 `--skip-kb`、`--skip-handoff`。

实现逻辑同 `api/agent.php` 的 `apply_industry_template` action，但走 CLI 模式（不需要 admin 鉴权，直接读 DB 配置）。

---

## 文件 12：admin/settings.html — 新增「AI 行为规则」Tab

在现有 4 个 Tab（AI 模型、企业集成、网站嵌入、转人工规则）之后，新增第 5 个 Tab：「AI 行为规则」。

Tab 内容为**只读展示**（Phase 1 不改行为，所以展示即可）：

| 区块 | 展示字段 |
|------|---------|
| 基础设置 | 行业(agent.industry)、拒答句(fallback.reply)、contact_label、回复字数(min/max)、max_tokens |
| 事实禁止 | prohibition.examples（一行一个） |
| 话术禁止 | speech_bans（一行一条规则） |
| 预订引导 | booking_platforms、booking_brand_hint |
| KB 直答策略 | policy_patterns（表格：pattern + blob_needle） |
| 后处理过滤 | sales_patterns（一行一个正则）、bad_endings（一行一个） |
| 凭证路由 | credential_guide、credential_keywords、credential_kb_marker |
| Sidecar | sidecar_route_phrases、sidecar_entry_extra |
| 查单引导 | order.guide_reply、plugin.order_query.enabled |
| 插件开关 | plugin.sidecar.enabled |
| 安全 | agent.safety.political（如有） |

技术细节：
- 调用 `GET /api/agent.php?action=get_config` 获取数据
- JavaScript 解析 JSON 数组字段展示为列表
- 页面顶部加提示条：「Phase 1 — 配置仅展示，当前 AI 行为仍由代码控制。Phase 2 起此处修改将实时生效。」
- 复用 settings.html 现有的 Tab 切换逻辑（class `.tab-btn` / `.tab-content`）
- 样式复用 `admin/css/design-system.css`

---

## 验收标准（Phase 1 Checklist）

完成后验证：
1. `GET /api/agent.php?action=get_config` 返回的 JSON 中，所有 agent.* / plugin.* 键值与 agent_defaults.json 一致
2. `php scripts/migrate_agent_config.php` 可重复执行不报错，数据不乱
3. `php scripts/export_industry_templates.php` 执行后 templates/homestay/ 下 3 个 JSON 文件生成
4. 新库执行 `sql/init.sql` 后 `openapi.php` 不因缺 api_keys 表报错
5. 后台「AI 行为规则」Tab 正常展示配置数据
6. **chat.php 对话行为与改造前完全一致**（PromptEngine 仍读 PHP 常量）

---

## 参考文档
- 完整蓝图：`.trae/documents/configurable-agent-blueprint.md`（v1.2.1）
- 现有配置读取函数：`api/config.php` L155-167（pcGet）
- 平台配置表：`sql/init.sql` L119-127（platform_config DDL）
- 迁移 V2 SQL：`sql/migration_v2.0.sql` L6-15（api_keys DDL）

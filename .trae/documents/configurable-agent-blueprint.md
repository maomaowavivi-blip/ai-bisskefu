# 独立部署 · 可配置智能体 — 修改蓝图 v1.2.1

> **日期**：2026-05-26  
> **状态**：待实施（**v1.2.1 配置键默认值澄清**）  
> **目标读者**：实施者 / 运维 / 产品  
> **前置**：宿家 MVP 已落地（`PRD_AI_CUSTOMER_SERVICE_v1.0.md` v1.1）  
> **关联**：`room-query-flow-blueprint.md`（Sidecar 外挂）、`PRD` 附录 A  
> **铁律**：`.cursor/rules/dev-workflow-soul.mdc`（工作区 → MAMP → 测试 → PRD 同步）

### v1.2 修订摘要（相对 v1.1 · 代码审查合并）

| # | 审查项 | 修订 |
|---|--------|------|
| 1 | `init.sql` 缺 `api_keys` 表 | Phase 1 补全（`openapi.php` 依赖；现仅在 `migration_v2.0.sql`） |
| 2 | `.env.example` 过时（MiniMax 主模型） | Phase 1 SOP 同步为 DeepSeek 默认 |
| 3 | `HomestaySidecarPlugin` 命名 | 改为 **`RoomQuerySidecarPlugin`**（Core 行业无关） |
| 4 | `PluginRegistry` 过度设计 | **删除**；Phase 3 用 `chat.php` 内 `if (plugin.sidecar.enabled)` |
| 5 | `persona_seed.json` | **删除**；人设属 Layer C，**不**随行业模板覆盖 |
| 6 | `finalizeReply` 保留标记硬编码 | 新增 `agent.fallback.preserved_markers`（Phase 2） |
| 7 | `checkInputSafety` 注入词 | **注入词库固定 Core**；仅 `agent.safety.political` 可选覆盖 |
| 8 | `policyPatterns` 导出 | §5.1 明确 `pattern` + `blob_needle` 映射规则 |
| 9 | `filterReply` 在 chat.php 膨胀 | Phase 2 **迁入 `PromptEngine::filterReply()`** |
| 10 | 回归依赖网关/Sidecar | 附录 A + **附录 B** mock 策略 |
| 11 | `chat.php` 行号微调 | §3.3 矩阵与现网 807 行对齐（±1 行标注） |
| 12 | `brand_story` / Session TTL | §4.5 / §1.2 边界说明 |
| 13 | `preserved_markers` / handoff hint 默认值未写清 | §4.1 补全；`injection` 移出配置表 |

### v1.2.1 修订（文档澄清，2026-05-26）

| # | 项 | 修订 |
|---|-----|------|
| 1 | `agent.fallback.preserved_markers` | §4.1 列出默认 JSON 数组 |
| 2 | `agent.safety.injection` | 从 §4.1 移除；§3.3 注明注入词库固定 Core |
| 3 | `agent.rules.handoff_system_hint` | §4.1 注明来源 `PromptEngine.php` L65–66 及默认全文 |

### v1.1 修订摘要（相对 v1.0）

| # | 问题 | 修订 |
|---|------|------|
| 1 | Phase 1 验收与「不改行为」矛盾 | Phase 1 只验配置读写；拒答生效挪 Phase 2 |
| 2 | 冻结行号与旧蓝图不一致 | 统一为 `chat.php` L425–481 |
| 3 | `OrderQueryPlugin` 边界模糊 | 改为查单引导配置；`order_query:` 不可关 |
| 4 | 漏 `chat.php` Sidecar 耦合 | §3.3 改造矩阵 |
| 5 | 漏 `policyPatterns` | Gap + Phase 2 + 配置键 |
| 6 | `gateway.room_keywords` 迁移 | 双读 + migrate |
| 7 | SOP 脚本不一致 | 补齐 `apply_industry_template.php` |
| 8 | 回归不可执行 | 附录 A 用例表 |
| 9 | export 密钥风险 | §4.4 |
| 10 | Sidecar 内分段逻辑 | §1.2 边界 |

---

## 一、设计目标（产品定稿）

### 1.1 要做什么

| 原则 | 说明 |
|------|------|
| **独立部署** | 每个商家一套实例（一库一 `.env`），不做 SaaS 多租户 |
| **直接配置** | 商家/运维在后台改 **Core 层规则**，**不修改 PHP 源码** |
| **框架复用** | 提示词拼装、禁止层、RAG、KB 直答、转人工、后处理 — **跨行业共用 Core** |
| **外挂可换** | Sidecar 房间流、PMS 查单展示 — **可选/外挂**；换行业换外挂，不重写 Core |
| **行业启动包** | 安装时选「民宿 / 餐饮 / 通用 FAQ」，一键导入 **Layer B + KB + handoff**（**不含 persona**） |

### 1.2 不做什么

- 不做 SaaS 套餐、enterprise_id 隔离、计费
- 不做可视化规则引擎 DSL（第一期：表单 + JSON 配置）
- 不要求 Sidecar 录入 UI 第一期完整（可继续 ai-sujia 录入 + 本系统同步）
- **不要求 Sidecar 内部分段逻辑可配置**（停车/设备/tips 仍在 `RoomQueryService.php`）
- **`RoomQueryFlow::SESSION_TTL_MINUTES`（30 分钟）不纳入配置化**（Sidecar 外挂常量；关插件则无影响）

### 1.3 三层模型

```
┌─────────────────────────────────────────────────────────┐
│ Layer A · Core（所有商家相同，发版升级）                  │
│  chat 主路由 · PromptEngine · KB/handoff · admin 壳      │
│  finalizeReply / filterReply（读 AgentConfig，非行业词） │
└───────────────────────────┬─────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────┐
│ Layer B · 实例配置（platform_config `agent.*` + 插件开关）│
│  industry 模板 · 拒答/禁止话术 · 凭证引导 · KB 直答策略   │
└───────────────────────────┬─────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────┐
│ Layer C · 商家日常配置（admin，无代码）                   │
│  persona · kb_entries · handoff_triggers · 网关/AI Key   │
│  gateway.room_keywords（进流词扩展，见 §4.1 迁移说明）    │
└─────────────────────────────────────────────────────────┘
```

**「零二次开发」边界**：Layer B + Layer C 可配置；Sidecar 外挂与 PMS 网关属 **部署/数据运维**，不是 Core 配置项。

---

## 二、现状差距（Gap）

| 能力 | 现状 | 目标 |
|------|------|------|
| FAQ / 转人工 / 人设 | ✅ admin 可改 | 保持 |
| 框架话术禁止（第二层） | ❌ `PromptEngine::buildSystem` 写死房源/宿家/携程 | `agent.rules.speech_bans` + `booking_platforms` |
| 事实禁止举例（第一层 §3） | ❌ 写死民宿字段列表 | `agent.prohibition.examples` |
| 拒答兜底句 | ❌ `NO_KB_FALLBACK_REPLY` 常量 | `agent.fallback.reply` |
| filterReply 推销/结尾 | ❌ `chat.php` L181–249 正则写死 | Phase 2 迁入 `PromptEngine::filterReply()` + `agent.filter.*` |
| finalizeReply 保留标记 | ❌ `isPreservedReplyWithoutKb()` 硬编码中文 | `agent.fallback.preserved_markers`（Phase 2） |
| **KB 直答 policy 正则** | ❌ `_messageMatchesKbEntry()` 内 `$policyPatterns`（入住/宠物/云房卡/预订…） | `agent.kb.policy_patterns` 或 Phase 2 弱化为纯 keywords |
| 云房卡凭证路由 | ❌ `SidecarIntent` + KB 答案标记 + `chat.php` early 路径 | **Core** 读 `agent.routing.credential_*`（须在 Sidecar 插件之前执行） |
| Sidecar 路由话术跳过 | ❌ `_isRoomRoutedAnswer()` 硬编码 | `agent.routing.sidecar_route_phrases` |
| 行业默认 KB / handoff | ❌ 仅 PHP Seed 类 | `templates/{industry}/*.json` |
| Sidecar 房间流 | ✅ 可用，无法关闭 | `plugin.sidecar.enabled` |
| 查单展示 | ✅ `order_query:` 冻结块 | **不可关**；仅「自然语言查单引导」可配置 |
| 进流词扩展 | ✅ `gateway.room_keywords` | 双读 → 逐步迁 `agent.routing.sidecar_entry_extra` |
| PromptEngine → SidecarIntent | ❌ `require`  sidecar 类 | Phase 2 起 Core 只读 `AgentConfig` |

---

## 三、目标架构

### 3.1 chat 路由（插件化 + 冻结块）

```
用户消息
  → 速率限制 / 安全拦截（Core；可选 agent.safety.* 覆盖）
  → 凭证类判断（Core ← AgentConfig，Sidecar 插件之前）
  → KB early 直答（Core ← PromptEngine + AgentConfig）
  → 改写 / KB 检索 / 按需语义向量（Core）
  → 【冻结】order_query: 块（chat.php L425–481）
  → policy KB 直答（Core）
  → if plugin.sidecar.enabled → RoomQueryFlow::handle（**无 Registry**）
  → Handoff（Core + handoff_triggers 表）
  → LLM（Core + AgentConfig 拼 prompt）
  → PromptEngine::filterReply / finalizeReply（Core + AgentConfig）
```

**🔒 冻结区（与 `room-query-flow-blueprint.md` 对齐，行号以现网为准）**

| 冻结项 | 位置 | 说明 |
|--------|------|------|
| `order_query:{订单号}` 整段 | `api/chat.php` **L425–L481** | 含 `callGateway(query_order)`、文案、云房卡 `rich_content`、`order_context_cache` 写入 |
| `callGateway()` 函数 | `api/chat.php` | 可被 Sidecar 内部调用，**不改函数签名与鉴权方式** |

> **注意**：`room-query-flow-blueprint.md` 中 L385–441 为历史行号，代码演进后已变为 **L425–481**；实施时以本蓝图为准，并回写 room-query 文档行号。

**查单与插件开关**

| 能力 | `plugin.order_query.enabled` | 说明 |
|------|------------------------------|------|
| `order_query:` 弹窗查单 | **不受影响（始终 ON）** | 冻结块内，无开关 |
| 自然语言「我要查订单」引导文案 | 可配置 / 可关 | 如 `agent.order.guide_reply`；关则仅保留按钮入口 |
| Sidecar 内 `callGateway(query_order)` | 随 `plugin.sidecar.enabled` | 房间绑单用，非冻结展示块 |

**删除 v1.0 的 `OrderQueryPlugin` 类**；查单展示不是插件，避免与冻结块概念冲突。

### 3.2 新增核心类（v1.2 简化）

| 类 | 路径 | 职责 |
|----|------|------|
| `AgentConfig` | `api/AgentConfig.php` | 合并 `platform_config` + `templates/{industry}/agent_defaults.json` |
| `IndustryTemplate` | `api/IndustryTemplate.php` | 导入 Layer B / KB / handoff（**不导入 persona**） |
| `RoomQuerySidecarPlugin` | `api/plugins/RoomQuerySidecarPlugin.php` | **可选**薄封装 `RoomQueryFlow::handle`；Phase 3 亦可在 `chat.php` 直接调用 |

**不新增 `PluginRegistry`**：当前仅 Sidecar 一个开关，Phase 3 实现为：

```php
if (pcGet($db, 'plugin.sidecar.enabled', '1') === '1') {
    $flowResult = RoomQueryFlow::handle(...);
}
```

未来多插件时再抽象 Registry（YAGNI）。

`PromptEngine` Phase 2 起 **不再** `require SidecarIntent.php`。

### 3.3 `chat.php` 改造矩阵（现网 807 行 · 审查对齐）

| 现网位置 | 现逻辑 | Phase | 改法 |
|----------|--------|-------|------|
| L363–367 | `SidecarIntent::isYunfangkaCredentialQuery` | **2** | `AgentConfig::isCredentialQuery($msg)` |
| L370–374 | early `directReplyFromKb` | 2 | 路径不变；PromptEngine 读 AgentConfig |
| L379–384 | 改写 skip | 2 | 凭证判断改 AgentConfig |
| L389–391 | `isRoomIntentPreview` | **3** | 仅 `plugin.sidecar.enabled=1` 时计算 |
| L425–481 | `order_query:` | **冻结** | 不改 |
| L523–525 | policy `directReplyFromKb` | 2 | + policyPatterns 配置化 |
| L538–557 | `RoomQueryFlow::handle` | **3** | `if (plugin.sidecar.enabled)` 包裹 |
| L181–249 | `filterReply()` | **2** | **迁至 `PromptEngine::filterReply()`** + `agent.filter.*` |
| L95–121 | `checkInputSafety()` | 2 | 仅 `agent.safety.political` 可覆盖；**注入词库固定 Core，不开放配置**（见下） |
| **L465** | `$isOrderIntent` 正则 | **P4** | 新增 `我要入住` / `我想入住` 意图识别，进入订单验证流程 → 引导云房卡。不改冻结块。 |

**安全拦截（固定 Core，不进配置表）**：`checkInputSafety()`（L95–121）中政治/色情/**注入**三类词库（如 `忽略上面`、`system prompt`）为安全防护底线，**不允许**通过 `platform_config` 关闭或改写。Phase 2 仅可选覆盖 `agent.safety.political`；色情词库同理保持 Core 内置（与 injection 一并文档化，不入 §4.1 表）。

凭证类 **必须在 Core、Sidecar 之前**：early KB 与 rewrite skip 依赖它，不能放进 Sidecar 插件。

---

## 四、配置 schema（platform_config）

**存储约定**：`platform_config.value` 均为 **字符串**；布尔用 `0`/`1`，整数用数字字符串；JSON 配置 UTF-8 单行存储。读取用现有 `pcGet()` / 新增 `AgentConfig::getInt()` / `getJson()`。

键名：Layer B 统一 `agent.*`；插件开关 `plugin.*`（与 `gateway.*` / `ai.*` 并列，不强制并入 `agent.`）。

### 4.1 实例级（Layer B）

| Key | 默认（homestay） | 说明 |
|-----|------------------|------|
| `agent.industry` | `homestay` | `homestay` \| `restaurant` \| `generic` |
| `agent.fallback.reply` | 这边暂时没有查到准确信息，建议您联系前台确认。 | 无 KB 唯一拒答 |
| `agent.fallback.preserved_markers` | JSON array | `finalizeReply` 无 KB 时仍保留的回复子串标记。**默认**：`["转接人工", "不太方便讨论", "没法聊", "无法回应"]`（来源 `PromptEngine::isPreservedReplyWithoutKb()` L174–182；`转接人工` 为子串匹配，其余为 foreach 项） |
| `agent.fallback.contact_label` | 前台 | 后台 UI 标签 |
| `agent.reply.min_chars` | `20` | system 回复格式 |
| `agent.reply.max_chars` | `80` | system 回复格式 |
| `agent.llm.max_tokens` | `150` | 与现网一致 |
| `plugin.sidecar.enabled` | `1` | `0` 跳过 RoomQueryFlow |
| `plugin.order_query.enabled` | `1` | **仅**控制自然语言查单引导；不影响 `order_query:` |
| `agent.order.guide_reply` | 如需查询订单，请点击…order_query:… | 自然语言查单意图时的引导 |
| `agent.prohibition.examples` | JSON array | 第一层禁止断言举例 |
| `agent.rules.speech_bans` | JSON array | 第二层话术禁止 |
| `agent.rules.booking_platforms` | 携程、美团、去哪儿 | 预订指引 |
| `agent.rules.booking_brand_hint` | 宿家民宿 | buildSystem 第 4 条中的品牌搜索提示 |
| `agent.rules.handoff_system_hint` | text（多行） | 写入 system 的转人工摘要；**运行时匹配仍以 `handoff_triggers` 表为准**。**默认**（导出源 `PromptEngine::buildSystem()` **L65–66**，两行合并存一条配置）：<br>① `【必须直接转人工】以下问题只回复"正在为您转接人工客服，请稍候。"不做任何其他回答`<br>② `涉及：发票、续住、换房、退款、投诉、赔偿、押金纠纷等；具体以系统「转人工规则」词库为准` |
| `agent.filter.sales_patterns` | JSON array 正则 | filterReply |
| `agent.filter.bad_endings` | JSON array | filterReply 万能结尾 |
| `agent.routing.credential_guide` | 同 KnowledgeBaseSeed 云房卡句 | 凭证类统一引导 |
| `agent.routing.credential_keywords` | JSON | 触发 credential 逻辑（wifi/门锁/押金/刷脸…） |
| `agent.routing.credential_kb_marker` | 请在云房卡中查看 | `_isYunfangkaCredentialEntry` 等价标记 |
| `agent.routing.sidecar_route_phrases` | JSON | 「请提供订单号」等；匹配则 KB 不直答 |
| `agent.routing.sidecar_entry_extra` | text | 进流词扩展（**新键**） |
| `gateway.room_keywords` | text | **现网键，保留**；AgentConfig 合并读取（见下） |
| `agent.kb.policy_patterns` | JSON | 见 §5.1；`[{ "pattern": "/几点.*入住/u", "blob_needle": "入住" }]` |
| `agent.safety.political` | JSON 可选 | 非空覆盖 Core 默认政治词库（`checkInputSafety` L96 一带） |

**进流词双读（Phase 1 起）**

```text
effective_entry_extra = merge(
  SidecarIntent::baseEntryKeywords(),   // 代码内基础表，Phase 4 可迁 JSON
  split_lines(pcGet('gateway.room_keywords')),
  split_lines(pcGet('agent.routing.sidecar_entry_extra'))
)
```

migrate 脚本：若仅有 `gateway.room_keywords` 且无 `agent.routing.sidecar_entry_extra`，**拷贝一份**到新键（可选，不破坏现网）。

### 4.2 商家级（Layer C — 已有）

| 存储 | 后台 |
|------|------|
| `persona_config` | `admin/persona.html` |
| `kb_entries` | `admin/knowledge.html` |
| `handoff_triggers` | `admin/settings.html` → 转人工 |
| `gateway.*` / `order.*` / `ai.*` | `admin/settings.html` |

### 4.3 民宿默认 JSON（`templates/homestay/agent_defaults.json`）

> 目录 **待创建**；由 `scripts/export_industry_templates.php` 从现网 PHP Seed + PromptEngine 文案导出。

```json
{
  "agent.industry": "homestay",
  "agent.fallback.reply": "这边暂时没有查到准确信息，建议您联系前台确认。",
  "agent.prohibition.examples": [
    "地址", "路线", "停车", "WiFi", "门禁", "房价", "押金", "退改政策",
    "设施", "设备", "订单状态", "入住时间", "周边配套"
  ],
  "agent.rules.speech_bans": [
    "禁止推荐房源、换房、升级、预订、下单等引导消费",
    "禁止追问房源、小区、房型、订单平台等信息",
    "禁止以「您」开头的问句或建议",
    "禁止引导在本客服内预订；问如何订房只指引至配置的平台搜索品牌",
    "禁止万能结尾（有任何问题随时找我等）",
    "禁止回答后追加第二句、第三句"
  ],
  "agent.rules.booking_platforms": "携程、美团、去哪儿",
  "agent.rules.booking_brand_hint": "宿家民宿",
  "agent.filter.sales_patterns": [
    "推荐.*房型", "建议.*升级", "建议.*换", "看看.*套房"
  ],
  "agent.fallback.preserved_markers": [
    "转接人工", "不太方便讨论", "没法聊", "无法回应"
  ],
  "agent.rules.handoff_system_hint": "【必须直接转人工】以下问题只回复\"正在为您转接人工客服，请稍候。\"不做任何其他回答\n涉及：发票、续住、换房、退款、投诉、赔偿、押金纠纷等；具体以系统「转人工规则」词库为准",
  "agent.kb.policy_patterns": []
}
```

`agent.kb.policy_patterns` Phase 1 导出脚本从 `PromptEngine.php` 内 `$policyPatterns` **自动生成**；Phase 2 改为运行时读配置。

餐饮包：改 prohibition 举例、speech_bans、platforms、brand_hint；`plugin.sidecar.enabled` 默认 `0`。

### 4.4 导出 / 导入范围（安全）

| 操作 | 包含 | 排除（默认） |
|------|------|--------------|
| `export_config` | `agent.*`、`plugin.*`、handoff 列表摘要 | 密钥、`persona_config` 全文（人设属 Layer C，单独备份） |
| `import_config` | 同上 | 密钥需部署后在 settings 单独填 |
| 全实例克隆 | 运维可选 `--include-secrets`（仅内网） | 禁止经 IM 传输 |

### 4.5 Layer C 特殊字段

| 字段 | 规则 |
|------|------|
| `persona_config.brand_story` | **永不进入 prompt**（现网已遵守）；行业模板 **不覆盖**；admin 仅展示用 |
| `persona_config` 其余字段 | 商家自行配置；`apply_industry_template` **默认 skip persona** |

---

## 五、行业模板目录（待创建）

```
templates/
├── homestay/
│   ├── agent_defaults.json
│   ├── kb_seed.json
│   └── handoff_seed.json
├── restaurant/
│   ├── agent_defaults.json
│   ├── kb_seed.json
│   └── handoff_seed.json
└── generic/
    ├── agent_defaults.json
    └── kb_seed.json
```

> **无 `persona_seed.json`**：吉祥物名称/性格由商家在 `admin/persona.html` 配置，避免模板覆盖。

### 5.1 `policy_patterns` 导出规则（审查必遵）

从 `PromptEngine::_messageMatchesKbEntry()` 内 `$policyPatterns` 导出时，**必须保留二元组**：

```php
// 现网结构（L518–534）
'/几点.*入住/u' => '入住'   // pattern => blob_needle
```

导出 JSON 每条：

```json
{ "pattern": "/几点.*入住/u", "blob_needle": "入住" }
```

运行时逻辑不变：`preg_match(pattern, $msg) && mb_strpos($question.' '.$keywords, $blob_needle) !== false`。

`export_industry_templates.php` 应 **反射或复制** 该数组，禁止手工编写遗漏 needle。

**API（`api/agent.php`）**

| action | 说明 |
|--------|------|
| `get_config` | 合并 defaults + DB |
| `save_config` | 写 Layer B |
| `apply_industry_template` | `industry` + flags：`import_kb` / `import_handoff`（**无 import_persona**） |
| `export_config` | §4.4 |
| `import_config` | §4.4 |

`KnowledgeBaseSeed.php` / `HandoffTriggers::defaultSeed()`：**开发源**；发版前 export 到 `templates/`；运行时 rebuild 优先读 JSON，PHP 类作 fallback。

**`KnowledgeBaseSeed::YUNFANGKA_CREDENTIAL`** 与 `agent.routing.credential_guide`：export 脚本须 **保持字面一致**；后台改 credential_guide 时提示「需同步 KB 中含云房卡条目」。

---

## 六、PromptEngine 改造要点

### 6.1 拼装顺序（不变）

```
buildSystem(AgentConfig, persona):
  1. buildProhibitionLayer(config)
  2. buildSpeechBanLayer(config)      // 含 booking_platforms + brand_hint
  3. buildHandoffSystemHint(config)   // 一句；详表 handoff_triggers
  4. buildReplyFormatLayer(config)
  5. buildMascotLayer(persona)
  6. service_rules / principles（persona 表）

buildUserTurn(): 不变

PromptEngine::filterReply() / finalizeReply(): 读 agent.filter.*、agent.fallback.*
```

### 6.2 directReplyFromKb + finalizeReply（完整）

| 现逻辑 | 改法 | Phase |
|--------|------|-------|
| `$policyPatterns` | `agent.kb.policy_patterns` | **2** |
| `_isYunfangkaCredentialEntry` | `credential_kb_marker` | 2 |
| `_isRoomRoutedAnswer` | `sidecar_route_phrases` | 2 |
| `isPreservedReplyWithoutKb()` | `agent.fallback.preserved_markers` | **2** |
| `_messageMatchesKbEntry` keywords/similar | kb_entries | — |
| `SidecarIntent::isYunfangkaCredentialQuery` | `AgentConfig::isCredentialQuery` | **2** |

Sidecar 插件 **不负责** 凭证 early 路径。

### 6.3 向后兼容

`scripts/migrate_agent_config.php` / `export_industry_templates.php` 还须从现网提取：

| 配置键 | 导出源 |
|--------|--------|
| `agent.rules.handoff_system_hint` | `PromptEngine::buildSystem()` **L65–66**（两行 `\n` 合并） |
| `agent.fallback.preserved_markers` | `isPreservedReplyWithoutKb()` **L174–182** → 默认四元组见 §4.1 |
| `agent.kb.policy_patterns` | `_messageMatchesKbEntry()` **L518–534**（§5.1） |

`scripts/migrate_agent_config.php`：

1. 从现网 PromptEngine / chat / SidecarIntent / KnowledgeBaseSeed **灌入** `platform_config`
2. 生成 `templates/homestay/agent_defaults.json`
3. 拷贝 `gateway.room_keywords` → `agent.routing.sidecar_entry_extra`（若新键空）
4. 跑完 **附录 A 回归 + order_query 冻结用例**

**Phase 1 行为不变**：migrate 后 PromptEngine **仍读 PHP 常量**；Phase 2 切换为 AgentConfig 后行为应与 migrate 前 bit-level 等价（允许拒答句字面仅当商家已改配置）。

---

## 七、admin 后台改造

### 7.1 新 Tab：`AI 行为规则`（`admin/settings.html` 增 Tab 或 `admin/agent-rules.html`）

| 区块 | 字段 |
|------|------|
| 基础 | 拒答、contact_label、回复字数、max_tokens |
| 事实禁止 | prohibition.examples |
| 话术禁止 | speech_bans、booking_platforms、booking_brand_hint |
| KB 直答 | policy_patterns（高级：JSON 编辑器） |
| 后处理 | sales_patterns、bad_endings |
| 凭证 / Sidecar 路由 | credential_guide、keywords、sidecar_route_phrases、sidecar_entry_extra |
| 查单引导 | order.guide_reply、plugin.order_query.enabled |
| 插件 | plugin.sidecar.enabled |
| 行业 | industry、应用模板、导出/导入（§4.4） |

### 7.2 知识库页

- 「重建默认知识库」→ **「从行业模板恢复 KB」**（选 homestay / restaurant / generic）
- 保留 CRUD、向量化

### 7.3 安装向导（Phase 4 可选）

`admin/setup.html`：选 industry → apply template → AI Key → 跑一条 KB 直答自测

---

## 八、文件改动清单

### Phase 1 — 配置层 + 部署修复（**行为不变**）

| 文件 | 操作 |
|------|------|
| `sql/init.sql` | **改** 并入 `api_keys` 表（自 `migration_v2.0.sql`） |
| `.env.example` | **改** 主模型说明为 DeepSeek v4-flash + MiniMax Embedding |
| `api/AgentConfig.php` | **新增** |
| `api/agent.php` | **新增** |
| `api/IndustryTemplate.php` | **新增** |
| `templates/homestay/*` | **新增**（export 生成；无 persona_seed） |
| `scripts/migrate_agent_config.php` | **新增** |
| `scripts/export_industry_templates.php` | **新增**（含 §5.1 policy 导出） |
| `scripts/apply_industry_template.php` | **新增** |
| `admin/settings.html` | **改** AI 行为规则 Tab（可先只读） |
| `PRD` | **改** 附录 B/C/D（实施后） |

**Phase 1 明确不做**：PromptEngine 读配置；Sidecar 开关；Registry。

### Phase 2 — Core 读配置

| 文件 | 操作 |
|------|------|
| `api/PromptEngine.php` | AgentConfig；**迁入 `filterReply()`**；去 SidecarIntent |
| `api/chat.php` | 调用 `PromptEngine::filterReply`；凭证/safety；**L425–481 冻结** |
| `api/KnowledgeBaseSeed.php` | rebuild 可读 JSON |
| `api/HandoffTriggers.php` | sync 可读 JSON |
| `api/openapi.php` | 与 chat 一致 |
| `scripts/regression_chat.php` | **新增**（附录 A + B） |

### Phase 3 — Sidecar 开关（无 Registry）

| 文件 | 操作 |
|------|------|
| `api/chat.php` | `plugin.sidecar.enabled` 包裹 L538–557；room intent preview 同步 |
| `api/plugins/RoomQuerySidecarPlugin.php` | **可选** 薄封装；非必须 |

### Phase 4 — 餐饮 + 克隆

| 文件 | 操作 |
|------|------|
| `templates/restaurant/*` | **新增** |
| `admin/setup.html` | 可选 |
| `room-query-flow-blueprint.md` | 更新冻结行号 L425–481 |

---

## 九、实施阶段与验收

### Phase 1（约 3–5 天）— 只搭配置层

- [ ] `sql/init.sql` 含 `api_keys`，新库 `openapi.php` 不报错
- [ ] `.env.example` 与 `config.php` 默认模型一致
- [ ] `AgentConfig` 可读合并 defaults + `platform_config`
- [ ] 后台「AI 行为规则」可读（写可选；写了也不影响 PromptEngine）
- [ ] `migrate_agent_config.php` + `export_industry_templates.php` 可重复执行
- [ ] **行为与现网一致**（PromptEngine 仍用 PHP 常量）
- [ ] 附录 A **冻结用例** + 全量 15 条回归 **全过**

**Phase 1 不做验收**：「改拒答话术立即生效」（属 Phase 2）。

### Phase 2（约 4–6 天）— Core 切换 AgentConfig

- [ ] `buildSystem` / `finalizeReply` / **`PromptEngine::filterReply`** / `directReplyFromKb` / 凭证判断 读 AgentConfig
- [ ] `preserved_markers` 配置后，安全拦截/转人工回复不被 finalize 覆盖
- [ ] 改 `agent.fallback.reply` **无需改 PHP** 即可生效
- [ ] `policy_patterns` 与 migrate 导出一致；删一条 pattern 后回归对应用例变化可测
- [ ] `export_config` / `import_config` 在第二实例还原 Layer B（不含密钥）
- [ ] 附录 A 全过 + `benchmark_chat_latency.php`

### Phase 3（约 2–3 天）

- [ ] `plugin.sidecar.enabled=0`：无 RoomQueryFlow、room intent 不 skip 语义 KB（除非仍命中 KB 直答）
- [ ] `=1` 时与现网 Sidecar 行为一致

### Phase 4（约 3–5 天）

- [ ] `restaurant` 模板 apply 后，Sidecar 默认关，FAQ 可答
- [ ] generic 模板可用

**总体验收（产品设计）**

1. 新商家：部署 → migrate → apply homestay → 对话 OK  
2. 商家改 KB / handoff / **AI 行为规则（Phase 2 起）** → 零 PHP  
3. 换餐饮：apply restaurant + 关 Sidecar → 零 PHP  
4. `order_query:` 冻结用例始终通过  
5. Sidecar 分段逻辑仍属外挂代码（符合 §1.2）

---

## 十、风险与约束

| 风险 | 缓解 |
|------|------|
| 配置过多 | 行业模板 + 「恢复默认」+ export 备份 |
| handoff system hint vs 表 | system 仅一句；**匹配只查 handoff_triggers** |
| policyPatterns 导出漏 needle | §5.1 + export 脚本单测对比 PHP 数组长度 |
| 回归依赖外网网关 | 附录 B：`--live` / `--mock` 双模式 |
| 新库缺 api_keys | Phase 1 并入 init.sql |
| PromptEngine 漂移 | 每 Phase 附录 A + benchmark |
| 密钥随 export 泄露 | §4.4 默认排除 |
| PHP 7 / MAMP | 无 PHP 8 独占语法 |
| `credential_guide` vs KB | 后台改 guide 时 UI 警告同步 KB |

---

## 十一、部署 SOP（单商家）

```bash
# 1. 部署代码 + .env（DB、JWT、可选 Sidecar DB）
# 2. 初始化 DB（须含 api_keys）
mysql ... < sql/init.sql
# 若旧库已存在：mysql ... < sql/migration_v2.0.sql  # 仅补 api_keys

# 2b. 核对 .env.example → 复制为 .env（DeepSeek 主聊天 + MiniMax Embedding）

# 3. 导出/生成行业模板
php scripts/export_industry_templates.php

# 4. 灌 Layer B
php scripts/migrate_agent_config.php --industry=homestay

# 5. 导入 KB + handoff（不含 persona）
php scripts/apply_industry_template.php --industry=homestay

# 6. 回归
bash scripts/sync-to-mamp.sh
php scripts/regression_chat.php              # 默认 --mock 或跳过 F1/#8-12
php scripts/regression_chat.php --live       # 全量含网关/Sidecar
php scripts/benchmark_chat_latency.php
```

商家：admin 填 AI Key / 网关 → 改 persona、KB、AI 规则。

---

## 十二、与 PRD 关系

| PRD 章节 | 内容 |
|----------|------|
| §3.4 | PromptEngine 读 AgentConfig |
| §3.5 | AI 行为规则后台 |
| 附录 B | `platform_config` agent.* / plugin.* 键表（§4.1） |
| 附录 C | 行业模板 + 本 SOP |
| 附录 A（PRD 已有） | 代码差异；与本蓝图附录 A（回归）不同名，实施时 PRD 可增「附录 D 回归用例」避免混淆 |

---

## 附录 A：回归用例（15 + 冻结）

> 建议实现为 `scripts/regression_chat.php`（HTTP 调 `chat.php`，独立 session/IP）。  
> 订单号示例：`1128148162721995`（永凯春晖）。断言：**回复包含期望子串**（非全文相等）。

| # | 场景 | 输入 | 期望（子串） |
|---|------|------|-------------|
| F1 | **冻结**查单 | `order_query:1128148162721995` | `查询成功`、`云房卡` 或 rich_content image_link |
| 1 | KB 入住 | `几点入住` | `14:00` |
| 2 | KB 退房 | `中午几点必须走` | `12:00` |
| 3 | 云房卡引导 | `WiFi密码多少` | `云房卡` |
| 4 | 云房卡 FAQ | `云房卡是什么` | 非仅 Sidecar 地址；含云房卡说明 |
| 5 | 转人工 | `我想续住一晚` | `转接人工` |
| 6 | 退款 KB 不转人工 | `我要退款` | 含`平台`；不含误转人工（或 KB 直答平台规则） |
| 7 | 宠物 KB | `可以带宠物吗` | `宠物` + 禁止/不允许 |
| 8 | Sidecar 要号 | `房间地址在哪` | `订单号` |
| 9 | Sidecar 绑单 | 上条 session 发订单号 | 地址类信息（非 LLM 编造拒答） |
| 10 | 停车 | 绑单后发 `有停车场吗` | `停车`；**不含**整段温馨提示全文 |
| 11 | 垃圾 tips | `垃圾放哪` | `垃圾` + `门口` |
| 12 | 设备 | `房间有洗衣机吗` | **不含**温馨提示全文；可为「未标注」短答 |
| 13 | KB 回落 | 绑单后 `附近好吃吗` | `没有查到` 或联系前台/门店 |
| 14 | 无 KB 拒答 | `南宁今天适合出门吗` | `没有查到` / `前台` / `门店` |
| 15 | 寒暄 step3 | Sidecar 绑单后 `谢谢` | `好的` 类短答 |

**Phase 1**：全表仍用现网逻辑跑通。  
**Phase 2 起**：改 `agent.fallback.reply` 后 #14 期望改为新 contact 文案。  
**Phase 3**：`plugin.sidecar.enabled=0` 时 #8–12 走 KB/拒答；`regression_chat.php --no-sidecar`。

---

## 附录 B：回归 mock 策略

| 用例 | 默认 CI（`--mock`） | 发版前（`--live`） |
|------|---------------------|-------------------|
| F1 冻结查单 | 跳过或 stub `callGateway` 返回 fixture | 真实 PMS + 订单号 |
| #1–#7 KB/转人工 | **HTTP 实调** chat.php | 同左 |
| #8–#12 Sidecar | 跳过；或 mock Sidecar DB fixture room | 真实 Sidecar + 网关绑单 |
| #13–#15 | #13–#14 实调；#15 需 live Sidecar session | 同左 |

实现建议：

- `regression_chat.php` 读附录 A 表驱动
- `--mock`：环境变量 `REGRESSION_MOCK_GATEWAY=1` 时 chat 不改动，脚本 stub 层在 **脚本内** 预置 session（Phase 1 可先只做 `--live` 手工跑）
- 文档化：CI 日常跑 #1–#7 + `--no-sidecar` 子集

---

*文档版本：v1.2.1*  
*审查结论：Gap 12/12 准确，架构可实施；v1.2.1 补全 preserved_markers / handoff_system_hint 默认值*  
*下一步：Phase 1 — `init.sql` 补 `api_keys` + `AgentConfig` + export/migrate 脚本*

# 商用AI客服智能体 - 产品需求文档 v1.2

> **v1.2 更新摘要（2026-08）**
> - 新增 §九 v3.1 演进模块：企微回调、开放 API、AI Agent 配置、行业模板、用户管理、CSV 批量导入
> - 更新 §三 知识库：CSV 批量导入已实现（PRD v1.1 标的"未做"）
> - 更新 §五 技术方案：新增 5 个 PHP 模块 + 用户管理 UI
> - 更新 §四 Phase 3：iframe 嵌入已生成（chat.html 嵌入式嵌入待补）
> - 更新 §七 验收清单：补 11 个 v3.1 新验收项
> - 更新附录 A 差异表：v1.2 同步实际代码状态

> **基于 ZORAVA/Soulmix OC 智能体平台改造**
> 保留现有 OC 创作者平台作为第二产品线，新增商用 AI 客服模块

---

## 一、产品定位

### 1.1 核心价值
将 OC 智能体的"人格化角色扮演"能力，迁移到**商用 AI 客服**场景：
- **差异化竞争力**：传统 AI 客服冷冰冰、模板化；我们的客服有**企业吉祥物/品牌IP人格**，有温度、不 OOC
- **小微企业友好**：定价低于主流竞品（晓多/七鱼），功能聚焦核心场景
- **轻量级 MVP**：基于现有代码快速改造，1-2 周内可上线

### 1.2 目标用户
- **小微企业/个体户**：需要低成本 AI 客服，但又不想用"冷冰冰的机器人"
- **有品牌IP的企业**：已有吉祥物/虚拟角色，需要在线客服中保持人设一致
- **非电商企业**：官网客服、预约咨询、FAQ 自动回复

### 1.3 MVP 核心公式
```
企业吉祥物人设（原OC系统） + 知识库（FAQ/产品知识） + API对接 = MVP
```

---

## 二、系统架构

### 2.1 总体架构

```
用户(微信)
  ↓
企业微信消息网关(wecom_kf.php)
  ↓
消息去重(msgid + 5min 缓存)+ 会话状态(service_state/trans,带 cursor 推进)
  ↓
LLM 意图/参数识别(IntentClassifier · 规则优先,LLM 兜底)
  ↓
确定性 Router(IntentRouter)
  ├─ 查询(订单/房间/停车场) → 业务 API/只读适配器 → 固定查询卡片
  ├─ 凭证类(WiFi/门锁/押金) → YunfangkaCredentialWorkflow → 云房卡 rich_content
  ├─ 知识问答 → 检索资料 → LLM 回复(KnowledgeWorkflow)
  ├─ 闲聊 → 固定模板(SmallTalkWorkflow,不走 LLM)
  └─ 不确定/敏感 → 转 400-155-9959(HandoffWorkflow)
  ↓
消息 Outbox(待加 · 暂用同步 send_msg + dedup)
  ↓
企业微信(kf/send_msg)
```

**架构说明(v2.0 · 2026-08-13)**:

- 消息网关:`api/wecom_kf.php`,接收 `kf_msg_or_event` POST
- 消息去重 + cursor 推进:`logs/wecom_kf_msgid_cache.json`(msgid 5min 缓存)+ `logs/wecom_kf_cursor_<kfhash>.txt`(sync_msg 增量)
- LLM 意图识别:`IntentClassifier` 规则优先 + KB 弱匹配,LLM 仅在 UNKNOWN 兜底时介入(避免 30ms → 1.5s 拖慢)
- 确定性 Router:`IntentRouter` 按 Intent 类型分发到对应 Workflow,**无歧义才走 LLM**
- 业务兜底:任何 API 失败 → 统一回 `400-155-9959`
- 转人工:任何转人工触发 → 统一回 `400-155-9959`

**查询分流（已实现，按优先级）**：

| 优先级 | 意图 | 数据源 / 模块 | 是否走 LLM |
|--------|------|---------------|-----------|
| 1 | 输入安全拦截 | `checkInputSafety()` | 否 |
| 2 | 政策 / 预订 / 云房卡说明 / 平台退改 | `kb_entries` + **`directReplyFromKb` 极速直答** | 否 |
| 3 | WiFi / 门锁 / 交押金 / 公安刷脸 | **云房卡引导**（KB + `SidecarIntent::isYunfangkaCredentialQuery`） | 否 |
| 4 | 订单查询 + 云房卡展示 | PMS 网关 `callGateway('query_order')`（**`order_query:` 冻结**） | 否 |
| 4.5 | 查单后云房卡卡片追问 | `isYunfangkaCardFollowUp` + `directReplyFromKb('云房卡是什么')`（依赖 `order_context_cache` 24h） | 否 |
| 5 | 地址 / 停车 / 垃圾 / 设备 | `RoomQueryFlow` → Sidecar `RoomQueryService` | 否 |
| 6 | 续住 / 换房 / 发票 / 投诉 / 故障 | `HandoffTriggers` → `human_handoffs` | 否 |
| 7 | 冷门 FAQ（KB 未命中） | DeepSeek + RAG（`max_tokens: 150`） | 是 |

**chat.php 单轮处理顺序（与代码一致）**：

```
用户消息
 → 速率限制 / 安全拦截
 → KB 关键词直答（early fast path，~25–40ms）
 → 问题改写 rewriteQuery（仅有 history 且非订单/转人工/云房卡凭证类时）
 → KB 检索：关键词 FULLTEXT → 可选 kbSemanticSearch（仅 LLM 兜底且未命中时）
 → order_query: 冻结块（成功时写 `order_context_cache`，TTL 24h，并返回 `rich_content` 云房卡卡片）
 → 云房卡卡片追问 `isYunfangkaCardFollowUp` → KB 直答「云房卡是什么」
 → policy directReplyFromKb
 → RoomQueryFlow（Sidecar）
 → HandoffTriggers 直转人工
 → directReplyFromKb / callAI → filterReply → finalizeReply
```

**响应耗时参考（MAMP 实测）**：KB 直答 ~30ms；Sidecar 绑单 ~2.5s（PMS 网关）；LLM 兜底 ~1s。API 响应含 `elapsed_ms` 字段；脚本 `scripts/benchmark_chat_latency.php`。

**安全与限流（已实现）**：

| 机制 | 位置 | 说明 |
|------|------|------|
| IP 速率限制 | `chat.php` + `rate_limits` 表 | 同一 IP **60 秒内最多 20 次**；超限返回 429 |
| 输入安全拦截 | `checkInputSafety()` | 政治/色情/注入类关键词直接固定回复，不消耗 token |
| 订单验证网关 | `api/verify.php` | SMS 验证码 + `query_order`（弹窗查单可走 `order_query:` 直连 PMS） |
| 对话审计字段 | `chat_logs` | `visitor_hash`、`source_ip` 随每条消息写入 |

### 2.2 改造策略（宿家 MVP 落地状态）

| 层次 | 改造方式 | 状态 |
|------|---------|------|
| **前端** | `chat.html` + `admin/*.html` | ✅ 已落地 |
| **API** | `chat.php` / `knowledge.php` / `handoff.php` / `sidecar.php` / `embedding.php` | ✅ 已落地 |
| **数据库** | `kb_*`、`handoff_*`、`room_query_sessions`、`rate_limits` 等 | ✅ 运行时迁移 |
| **AI 模型** | **DeepSeek v4-flash** 主聊天 + MiniMax `embo-01` Embedding | ✅ 已切换 |
| **部署** | MAMP 本地 + `scripts/sync-to-mamp.sh` | ✅ 开发环境可用 |

---

### 2.3 v2.0 业务规则（2026-08-13 确认）

#### 2.3.1 业务范围(只查询)

| 业务类型 | 走哪条路 | 数据源 | 兜底 |
|---|---|---|---|
| 订单查询 | OrderQueryWorkflow | 未来 API(明日接入) | `400-155-9959` |
| 房间信息查询 | RoomQueryWorkflow | 未来 API(暂未接) | `400-155-9959` |
| 停车场查询 | UnknownWorkflow(临时)→ 未来 ParkingQueryWorkflow | 未来 API(暂未接) | `400-155-9959` |
| 房间设备查询 | RoomQueryWorkflow | 未来 API(暂未接) | `400-155-9959` |
| WiFi/门锁/押金 | YunfangkaCredentialWorkflow | KB + Sidecar | 云房卡卡片 |
| 入住时间/退订流程 | KnowledgeWorkflow | kb_entries(44 条) | KB 直答 |
| 闲聊(晚上好/谢谢) | SmallTalkWorkflow | 固定模板 | 固定回复 |
| 续住/换房/发票/投诉 | HandoffWorkflow | human_handoffs 表 | **`400-155-9959`** |

**铁律**:AI **不处理任何新增业务**(续住、换房、改期、取消订单、申请发票等),**统一回 400-155-9959**。

#### 2.3.2 兜底话术统一规则

任何未实现的功能、API 失败、知识盲区,**统一回复**:

> "[功能名]查询功能暂未上线,请拨打 400-155-9959 联系我们。"

- 订单查询未接 API → "订单查询功能暂未上线,请拨打 400-155-9959 联系我们。"
- 房间查询未接 API → "房间信息查询功能暂未上线,请拨打 400-155-9959 联系我们。"
- 停车场查询未接 API → "停车场查询功能暂未上线,请拨打 400-155-9959 联系我们。"

#### 2.3.3 转人工统一规则

所有 HandoffWorkflow 触发(包括关键词"续住""换房""投诉""发票"等)、用户主动要求"转人工"、"找真人":

> "已为您转接人工客服,请拨打 400-155-9959 联系我们。"

**实现位置**:`api/Workflow/HandoffWorkflow.php:38`

#### 2.3.4 Intent 分类优先级(2026-08-13 修正)

```
优先级从高到低:
1. 输入安全拦截(checkInputSafety)
2. 转人工关键词(HandoffTriggers) → HUMAN
3. 凭证类(WiFi/门锁/押金) → ROOM_PASSWORD_QUERY
4. 订单查询(order_query: 前缀) → ORDER_QUERY
5. 房间查询(房型/地址/设备关键词) → ROOM_QUERY
6. KB 早期命中(品牌/退订/入住时间 FAQ) → KNOWLEDGE
7. 闲聊(晚上好/谢谢/在吗) → SMALL_TALK
8. UNKNOWN → UnknownWorkflow(LLM 兜底)
```

**修正要点(v2.0)**:
- ✅ 业务意图(订单/房间)在 KB 早期匹配**之前**,避免"order_query:xxx"被 KB 误判
- ✅ 闲聊在 KB 之前,避免"晚上好"被 KB 抢答
- ✅ 凭证类在所有之前,确保"WiFi 密码"永远走 YunfangkaCredentialWorkflow
- ❌ LLM 不参与 Intent 分类(避免 30ms fast path 拖慢到 1.5s)

#### 2.3.5 消息可靠性(v2.0 已实现,v3.0 规划)

| 机制 | 实现位置 | 状态 |
|---|---|---|
| msgid 去重(5min) | `logs/wecom_kf_msgid_cache.json` | ✅ v2.0 |
| sync_msg cursor 推进 | `logs/wecom_kf_cursor_<hash>.txt` | ✅ v2.0 |
| service_state/trans(95018 修复) | `api/wecom_kf.php` | ✅ v2.0 |
| 消息 Outbox(重试+死信) | — | ❌ v3.0 规划 |

---

## 三、核心功能模块

### 3.1 企业吉祥物人设系统（基于 OC 高级设定改造）

#### 原有 OC 系统 → 客服人设映射

| OC 系统 (Soulmix) | 客服系统 | 说明 |
|-------------------|---------|------|
| OC 名称/头像/简介 | 吉祥物名称/形象/品牌介绍 | 企业品牌 IP 基本资料 |
| 世界观钩子 (w-world-hook) | 品牌背景故事 | 品牌起源/核心理念 |
| 性格 (f3) | 客服性格基调 | 热情/专业/可爱/稳重等 |
| 说话方式 (f4) | 客服话术风格 | 语气、称呼方式、禁用语 |
| 处事原则 (f5-principles) | 客服行为准则 | 处理客诉的原则、价值观 |
| 世界规则 (f2) | 服务边界规则 | 不能做什么、不懂时怎么办 |
| 情景题 (sc_*) | 场景化应对策略 | 客户生气/催单/投诉时的应对 |
| bg_story | 品牌故事（展示用） | 非注入 prompt，仅展示 |

#### 新增人设管理页面（企业后台）
- **品牌资料**：头像、名称、简介
- **人格设定**：性格、语气、行为准则（向导式配置，复用 oc-advanced.html 七步逻辑）
- **服务规范**：禁用语、转人工规则、情绪应对策略

### 3.2 知识库系统（核心新增）

#### 数据模型

```sql
-- 知识分类
CREATE TABLE kb_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enterprise_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 知识条目
CREATE TABLE kb_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enterprise_id INT NOT NULL,
    category_id INT DEFAULT 0,
    question VARCHAR(500) NOT NULL,        -- 标准问题
    answer TEXT NOT NULL,                   -- 标准答案
    keywords VARCHAR(500) DEFAULT '',       -- 关键词（逗号分隔）
    similar_questions TEXT DEFAULT NULL,    -- 相似问法（JSON数组）
    status TINYINT DEFAULT 1,              -- 1=启用 0=禁用
    hit_count INT DEFAULT 0,               -- 命中次数
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enterprise (enterprise_id),
    INDEX idx_category (category_id)
);

-- 文档知识
CREATE TABLE kb_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enterprise_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content LONGTEXT NOT NULL,              -- 文档全文
    source_type VARCHAR(20) DEFAULT 'manual', -- manual/upload/import
    status TINYINT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enterprise (enterprise_id)
);

-- 订单API配置
CREATE TABLE enterprise_api_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enterprise_id INT NOT NULL UNIQUE,
    order_api_url VARCHAR(500) DEFAULT '',
    api_key VARCHAR(255) DEFAULT '',
    api_secret VARCHAR(255) DEFAULT '',
    webhook_url VARCHAR(500) DEFAULT '',
    is_active TINYINT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### 功能特性（已实现 · 宿家 MVP）

| 功能 | 实现 | 说明 |
|------|------|------|
| 手动录入 CRUD | `api/knowledge.php` + `admin/knowledge.html` | 分类 + 问答对 |
| **默认种子重建** | `KnowledgeBaseSeed.php` + `rebuild_defaults` | 一键恢复 44 条宿家标准 FAQ |
| 关键词匹配 | `PromptEngine::searchKnowledge` + `_searchByKeywords` | FULLTEXT 降级 LIKE |
| 语义向量检索 | `api/embedding.php` → `kbSemanticSearch()` | MiniMax `embo-01`；chat **进程内调用**（非 HTTP 自调） |
| **KB 直答（跳过 LLM）** | `PromptEngine::directReplyFromKb` | 政策/预订/云房卡类固定答复 |
| 批量向量化 | `embedding.php?action=batch_vectorize` | 后台「批量向量化」按钮 |
| **CSV 批量导入** | `api/knowledge.php?action=import` | v3.1 新增；≤1000 行 UTF-8 |
| Excel 批量导入 | — | **未实现**（CSV 已覆盖 80% 场景） |
| `kb_documents` 文档库 | — | **表结构在 PRD，未接入 RAG** |

#### 宿家通用知识库结构（`KnowledgeBaseSeed`，8 类 44 条）

| 分类 | 条目数 | 内容边界 |
|------|--------|----------|
| 品牌介绍 | 4 | 对外品牌 **宿家民宿**；小橙能力范围 |
| 预订与订单 | 7 | 仅携程/美团/去哪儿；**AI 不代订**；云房卡说明 |
| 入住与退房 | 7 | **14:00 入住 / 12:00 退房**；公安刷脸 KB 条目 |
| 房规与禁忌 | 5 | **禁宠物、室内禁烟** |
| 费用与退改 | 6 | **取消/退款走平台**；交押金引导云房卡 |
| 服务边界与路由 | 7 | 地址/停车→Sidecar；凭证类→云房卡 |
| 南宁本地攻略 | 4 | **短答 + 地图核实**（长篇吃喝玩乐 **暂缓**） |
| 需人工处理 | 4 | 续住/换房/发票/投诉说明（实际触发走词库） |

#### 知识库注入与直答策略（实际落地）

1. **有明确 KB 条目** → `directReplyFromKb` **原文直出**，不经过 LLM（防幻觉优先）。
2. **有【参考资料】且走 LLM** → RAG 注入 `buildUserTurn`；`finalizeReply` 无 KB 时强制拒答。
3. **无 KB** → 固定：`这边暂时没有查到准确信息，建议您联系前台确认。`

示例（宿家政策，非旧版虚构产品价）：

```
【参考资料】
· 宿家民宿统一标准入住时间为当日14:00，退房时间为当日12:00。
· 取消与改期请在携程、美团、去哪儿订单内按平台规则操作。
· WiFi密码、门锁密码、在线交押金及公安刷脸核验，请在云房卡中查看。
```

### 3.3 订单 API 对接（轻量级）

#### 设计思路
通过插件式配置，企业只需提供 API 接口地址和密钥，就可实现对订单的查询和操作。

#### API 接口规范（需要企业方实现）

```
订单查询接口：
  POST {enterprise_api_url}/order/query
  Headers: Authorization: Bearer {api_key}
  Body: { "order_no": "xxx", "phone": "xxx" }
  Response: { "code": 0, "data": { "status": "shipped", ... } }
```

#### Prompt 中的订单查询能力

在 `PromptEngine` 中新增订单查询工具描述：

```
【工具能力】你可以调用以下工具帮助客户：
· 查询订单状态（需要客户提供订单号或手机号）
· 查询物流信息

当客户询问与订单/交易相关的问题时，引导客户提供订单号或手机号，
然后调用订单API查询并告知结果。
```

> **状态：已完成。** 订单查询返回云房卡，此模块冻结不再改动。

#### 查单后云房卡卡片追问（2026-05 · 已实现）

查单成功会在气泡下方展示 **云房卡 `rich_content` 卡片**（`image_link` +「查看云房卡」）。客人常见追问「这是什么 / 不会用 / 你发的是什么」若落到 LLM 兜底，会答非所问。

**后端（`api/chat.php`）**

| 机制 | 说明 |
|------|------|
| `order_context_cache` | `order_query:` 查单成功写入；`expires_at = NOW() + 24h`；存 `order_no` / `room_id` / `room_list` |
| `isYunfangkaCardFollowUp()` | 在 `order_query:` 块之后、RoomQueryFlow 之前执行 |
| 触发条件 | 消息含「云房卡/电子房卡」；**或** 会话存在未过期 cache **且** 匹配追问词表（如「这是什么」「不会用」「你发的是什么」「怎么点」等） |
| 回复 | `PromptEngine::directReplyFromKb('云房卡是什么')`；前缀「刚才下方卡片是云房卡…」；KB 未命中时用固定 fallback |
| 不走 LLM | 与政策类 KB 直答相同，`respondDirectKb` ~30ms |

**前端（`chat.html`）**

- 助手回复写入 `history` 时，将 `rich_content` 卡片摘要追加为 `[云房卡卡片：标题 描述]`，供后续 `rewriteQuery` / 多轮理解卡片上下文。

**KB 文案（`templates/homestay/kb_seed.json`）**

- 「云房卡是什么？」标准答：电子入住凭证 + 刷脸/押金/WiFi/门锁；**不含**「299 元/年」「与业主端服务无关」等旧描述。

**`order_context_cache` 与房间流的分工**

| 用途 | 是否使用 cache |
|------|----------------|
| 云房卡卡片追问识别 | ✅ 24h 内有效 |
| `queryRoomLocal` 已验证 shortcut | ✅ 有 cache 视为已验证 |
| RoomQueryFlow step=1 免重复要订单号 | ❌ **仍每次索要**（见 §7.1.5 暂缓项） |

### 3.3.1 Sidecar 房间知识库（宿家场景 · 已实现）

#### 设计思路

房间级**可核实事实**（怎么去、停车、垃圾、设备）**不经过 LLM 编造**，从本地 Sidecar 库 `sujia_ai_sidecar_dev` 检索。

**凭证类信息不进 Sidecar 直答**（已确认业务规则）：

| 内容 | 处理方式 |
|------|----------|
| WiFi 密码、门锁密码 | 引导查看 **云房卡** |
| 在线交押金、公安刷脸核验 | 引导查看 **云房卡** |
| 地址、停车、垃圾、设备 | Sidecar（需订单号绑定） |

**房间查询与订单查询是两条独立流程：**

1. `order_query:` / 订单弹窗 → PMS 查单 + 云房卡（**冻结，不改**）
2. 房间意图（怎么去/停车/垃圾/设备等）→ `RoomQueryFlow` 状态机：
   - **每次**进入房间意图必须先发送订单号
   - 内部 `query_order` 映射 Sidecar 房源（`OrderRoomMapper`）
   - 一单多房 → 聊天气泡内 `room_pick` 方块点选（方案 A）
   - 绑定后 step=3：**默认 Sidecar**；停车/垃圾/设备等追问走 `RoomQueryService`
   - step=3 **回落通用 KB**：`SidecarIntent::isGeneralKbQuestion`（如附近好吃、统一退房时间）→ 退出 Sidecar，走 KB 直答
   - step=3 例外：换房间 → step=1；发票/投诉/查订单 → 退出房间流；寒暄（谢谢/好的）保留绑单
   - Sidecar 未命中 → 固定话术「暂未找到该房间的相关资料…」，**禁止** LLM 编造
   - 云房卡 FAQ 问句（「云房卡是什么」）**不进** Sidecar 进流（`SidecarIntent::matchesEntry` 排除）
   - 进流关键词：`SidecarIntent` 统一词表（含 **车停哪里 / 停在哪** 等停车词）+ 后台 `gateway.room_keywords` 扩展

#### 数据流

```
sujia_source_snapshot_dev → ai-sujia 同步 → sujia_ai_sidecar_dev
                                              ↓ ChunkBuilder
                                         ai_knowledge_chunk
                                              ↓ Vectorizer（MiniMax Embedding）
                                         语义检索 + 结构化查询
                                              ↓ RoomQueryFlow + OrderRoomMapper
                                         chat.php 房间回复（Sidecar only）
```

#### 房间流状态（`room_query_sessions`）

| step | 含义 |
|------|------|
| 0 | 空闲 |
| 1 | 待订单号 |
| 2 | 待选房（`room_pick` 卡片） |
| 3 | 已绑定 order + sidecar_room_id |

#### 运维接口（`api/sidecar.php`）

| action | 说明 |
|--------|------|
| `stats` | 房源数 / 知识块数 / 向量化进度 |
| `rebuild_chunks` | 从 sidecar 表重建知识块（管理员） |
| `vectorize_pending` | 批量向量化待处理块（管理员） |
| `query_room` | 调试：本地房间查询 |

#### 环境配置（`.env`）

```ini
SIDECAR_DB_HOST=127.0.0.1
SIDECAR_DB_PORT=8889
SIDECAR_DB_NAME=sujia_ai_sidecar_dev
SIDECAR_DB_USER=root
SIDECAR_DB_PASS=root
MINIMAX_API_KEY=          # 可选；Embedding 优先读 platform_config.ai.api_key.minimax_backup
PROMPT_ENGINE_REWRITE_MODEL=deepseek-v4-flash
```

### 3.3.2 宿家民宿业务政策（已确认 · 写入 KB）

| 主题 | 客人侧政策 |
|------|-----------|
| 对外品牌 | **宿家民宿**（橙途为系统/运营侧，不对客强调） |
| 入住/退房 | **14:00 入住，12:00 退房** |
| 云房卡 | 住客电子入住凭证；含刷脸、交押金、WiFi/门锁；**无 299 元/年业主端描述** |
| 取消/退款 | **全部在携程/美团/去哪儿平台处理** |
| 宠物/吸烟 | **南宁统一禁宠物、室内禁烟** |
| 增值服务 | **无**接送机/生日布置/寄存等特殊服务 |
| 预订 | 仅上述三平台；**AI/聊天不代订** |
| 掌柜联系方式 | 暂未写入 KB（待补充） |
| 吃喝玩乐攻略 | **暂缓**；现有条目仅短答 + 建议地图核实 |

### 3.3.3 转人工触发词库（已实现）

| 项 | 说明 |
|----|------|
| 数据表 | `handoff_triggers`（keyword + priority） |
| 代码 | `api/HandoffTriggers.php`（P0–P4 默认种子 + `pruneRetiredKeywords`） |
| 后台 | `admin/settings.html` → 转人工规则；`admin/handoff.html` 人工接管 |
| API | `api/handoff.php`（pending/take_over/send/end） |
| 运行时 | `chat.php` / `filterReply` / `RoomQueryFlow` 统一读取 |
| 与 KB 分工 | **退款/带宠物/取消改期** 等已有 KB 直答的词 **不转人工**；续住/换房/发票/投诉/故障 **转人工** |
| 同步 | 后台「补全系统默认词库」→ `HandoffTriggers::syncDefaultLibrary()` |

### 3.4 PromptEngine 3.0 - 实际提示词结构（2026-05 落地）

> LLM 仅处理**通用 FAQ**；房间事实不走 LLM。品牌故事录入**知识库**，不进入 system prompt。

```
┌────────────────────────────────────────────┐
│ 1. 【第一层·事实禁止】buildProhibitionLayer │  ← 最高优先级
│    → 事实唯一来源：本轮【参考资料】          │
│    → 无资料 → 固定拒答（finalizeReply 硬兜底）│
│    → 禁止猜测/编造/模糊措辞                   │
├────────────────────────────────────────────┤
│ 2. 【第二层·话术禁止】                       │
│    → 禁止推销、追问用户信息、多句、万能结尾    │
│    → 发票/投诉/退款 → 转人工固定话术          │
├────────────────────────────────────────────┤
│ 3. 【吉祥物语气层】buildMascotLayer          │
│    → 名字 + description（角色说明）           │
│    → 性格 / 说话风格（仅语气，非事实来源）     │
│    ✗ brand_story 不进入 prompt               │
├────────────────────────────────────────────┤
│ 4. 【服务边界 / 处事原则】                   │
│    → service_rules、principles              │
├────────────────────────────────────────────┤
│ 5. 【用户消息·RAG】buildUserTurn             │
│    → 【本轮约束】+【参考资料】+ 用户问题      │
│    → 无 KB 时写入「不得输出业务事实」指令      │
└────────────────────────────────────────────┘
```

**后处理：** `filterReply()` 去推销套话 → `finalizeReply()` 无 KB 时强制固定拒答。

**已实现增强（2026-05）**：

| 机制 | 文件 | 说明 |
|------|------|------|
| KB 极速直答 | `chat.php` + `directReplyFromKb` | 在 rewrite/向量 **之前** 返回，~30ms |
| 凭证类云房卡路由 | `SidecarIntent::isYunfangkaCredentialQuery` | WiFi/门锁/押金/刷脸不 Sidecar 直报 |
| 语义检索按需 | `kbSemanticSearch()` | 仅 LLM 兜底且关键词未命中时调用 |
| Embedding Key | `embedding.php` | 优先 `ai.api_key.minimax_backup` |
| 改写跳过 | `chat.php` | 订单/转人工/云房卡凭证类不调用 rewrite |
| 云房卡卡片追问 | `isYunfangkaCardFollowUp` | 查单 24h 内追问用途/不会用 → KB 直答，不进 LLM |
| 多轮 history | `chat.html` | `rich_content` 卡片摘要写入 `history`，供改写与追问 |
| LLM 参数 | `chat.php` | `deepseek-v4-flash`，`max_tokens: 150`，`thinking: disabled` |
| 响应耗时 | `chatResponse` | 返回 `elapsed_ms` |

**回复长度策略**：system 要求 **单句 20–80 字**；`max_tokens: 150` 与防幻觉优先一致；**不做长篇吃喝玩乐生成**（攻略类暂缓）。

### 3.5 企业管理后台（已实现文件）

基于 `admin/` 目录（非 PRD 原规划的 `enterprise-admin.html`）：

| 模块 | 页面 | 功能 | 状态 |
|------|------|------|------|
| **仪表盘** | `admin/dashboard.html` | 概览 + 待接管数量 | ✅ |
| **人设管理** | `admin/persona.html` | 吉祥物名称/性格/服务规范；对外 **宿家民宿** | ✅ |
| **知识库管理** | `admin/knowledge.html` | CRUD、批量向量化、**重建默认知识库** | ✅ |
| **系统设置** | `admin/settings.html` | AI Key、订单/PMS 网关、Sidecar 运维、**转人工规则**、房间进流词扩展 | ✅ |
| **对话记录** | `admin/chat-logs.html` | 历史会话查看 | ✅ |
| **转人工** | `admin/handoff.html` | 待处理/接管中/已结束；人工发消息 | ✅ |
| **客户画像** | `memory.php` | OC 遗留，客服场景 **未作为主路径** | 保留 |
| **七步 OC 向导** | `oc-advanced.html` | OC 产品线 | 与宿家客服并行 |

**访客聊天窗**：`chat.html`（订单查询弹窗、`order_query:`、云房卡 `rich_content` 卡片、卡片摘要进 `history`、`room_pick` 卡片、handoff 轮询）。

---

## 四、实施路径

> **宿家 MVP（Phase 1 + Sidecar + 转人工 + 防幻觉）已于 2026-05 落地**；下表保留原规划对照，✅ 表示已实现或等价实现。

### Phase 1：MVP（预计 1-2 周）

| 步骤 | 内容 | 状态 |
|------|------|------|
| 1 | 数据库表创建（knowledge_base 系列表） | ✅ |
| 2 | `PromptEngine.php` 改造 → 企业人设 + 知识库分层 | ✅ |
| 3 | `api/knowledge.php` → 知识库 CRUD + 重建默认种子 | ✅ |
| 4 | `chat.php` → 宿家客服路由（非独立 `enterprise_chat` action） | ✅ |
| 5 | 管理后台 → **`admin/*.html`**（非 `enterprise-admin.html`） | ✅ |
| 6 | 访客聊天窗 → **`chat.html`**（非 `customer-chat.html`） | ✅ |
| 7 | MAMP 测试环境 + `sync-to-mamp.sh` | ✅ |

### Phase 2：增强（2-3 周）

| 步骤 | 内容 | 状态 |
|------|------|------|
| 8 | PMS 网关 + `order_query:` 查单云房卡 | ✅（**冻结**） |
| 9 | Sidecar 房间流 + 向量检索 | ✅ |
| 10 | 转人工词库 + `admin/handoff.html` | ✅ |
| 11 | 对话记录查看（`admin/chat-logs.html`） | ✅ |
| 12 | 知识库批量导入（Excel） | ❌ 未做 |
| 13 | 基础数据统计（对话量、满意度） | ❌ 未做 |

### Phase 3：商用化（持续）

| 步骤 | 内容 | 状态 |
|------|------|------|
| 14 | SaaS 套餐管理体系 | ❌ |
| 15 | 多渠道接入（微信公众号/企微/网页嵌入） | 🟡 企微 + 嵌入代码已生成（v3.1 §9.1, §9.7）；iframe 嵌入测试待补 |
| 16 | 转人工机制 | ✅ |
| 17 | AI 自动学习知识库（从对话中提取 FAQ） | ❌ |

### Phase 4：v3.1 演进（2026-08 · 已落地）

| 模块 | 状态 | 详见 |
|------|------|------|
| 18 | 企微回调 | ✅ §9.1 |
| 19 | 开放 API（外部集成） | ✅ §9.2 |
| 20 | AI Agent 可配置（Phase 1：仅展示） | ✅ §9.3 |
| 21 | 行业模板（民宿/餐厅/通用） | ✅ §9.4 |
| 22 | 用户管理（新增/重置密码） | ✅ §9.5 |
| 23 | CSV 批量导入 KB | ✅ §9.6 |

---

## 五、技术方案

### 5.1 后端改造文件清单（宿家 MVP · 与代码同步）

| 文件 | 操作 | 说明 |
|------|------|------|
| `api/chat.php` | **修改** | 路由层：early KB、RoomQueryFlow、Handoff、LLM；`order_query:` **冻结**；`elapsed_ms` |
| `api/PromptEngine.php` | **修改** | 禁止层、RAG、`directReplyFromKb`、`rewriteQuery`、`finalizeReply` |
| `api/embedding.php` | **修改** | 向量化 + `kbSemanticSearch()` 进程内检索 |
| `api/KnowledgeBaseSeed.php` | **新增** | 宿家 44 条默认 FAQ 种子 |
| `api/knowledge.php` | **修改** | CRUD + `rebuild_defaults` + 向量化代理 |
| `api/HandoffTriggers.php` | **新增** | 转人工词库（DB + 默认种子 + 退役词清理） |
| `api/handoff.php` | **已有** | 人工接管 API |
| `api/RoomQueryFlow.php` | **新增** | 房间查询状态机 step 0–3 |
| `api/sidecar/OrderRoomMapper.php` | **新增** | PMS → Sidecar 映射 |
| `api/sidecar/SidecarIntent.php` | **新增** | 进流词表、云房卡凭证、泛攻略回落 |
| `api/sidecar/RoomQueryService.php` | **修改** | 结构化查询；凭证类→云房卡 |
| `api/sidecar/SidecarSearch.php` | **新增** | Sidecar 向量 + LIKE 降级 |
| `api/sidecar/ChunkBuilder.php` | **新增** | Sidecar 表 → ai_knowledge_chunk |
| `api/sidecar/Vectorizer.php` | **新增** | 知识块向量化 |
| `api/sidecar.php` | **新增** | stats / rebuild_chunks / vectorize_pending |
| `api/config.php` | **修改** | Sidecar DB、`callAI`（DeepSeek 默认） |
| `chat.html` | **修改** | room_pick、订单查询、云房卡卡片 + history 摘要、handoff 轮询 |
| `admin/knowledge.html` | **修改** | 重建默认 KB、批量向量化 |
| `admin/settings.html` | **修改** | Sidecar 卡片、转人工规则维护 |
| `admin/handoff.html` | **已有** | 人工接管 UI |
| `sql/migration_room_query_v3.sql` | **新增** | room_query_sessions 扩展 |
| `scripts/rebuild_knowledge_base.php` | **新增** | CLI 重建 KB |
| `scripts/sync-to-mamp.sh` | **新增** | 工作区 → MAMP 同步 |
| `scripts/benchmark_chat_latency.php` | **新增** | 回复耗时基准测试 |
| `scripts/copy_yongkai_parking_from_1013.php` | **新增** | 运维：永凯停车批量复制 |
| `scripts/sync_handoff_triggers.php` | **新增** | CLI 同步转人工词库 |
| `api/wecom.php` | **新增（v3.1）** | 企业微信回调（GET 验证 + POST text 消息） |
| `api/openapi.php` | **新增（v3.1）** | 外部 API 集成（X-API-Key 鉴权） |
| `api/agent.php` | **新增（v3.1）** | AI Agent 可配置读写（Phase 1） |
| `api/AgentConfig.php` | **新增（v3.1）** | AgentConfig 类（platform_config 读写） |
| `api/IndustryTemplate.php` | **新增（v3.1）** | 行业模板应用（KB/转人工/Agent 三件套） |
| `admin/users.html` | **新增（v3.1）** | 用户管理（新增/重置密码） |
| `templates/{homestay,restaurant,generic}/*` | **新增（v3.1）** | 行业模板种子（KB + handoff + agent_defaults） |
| `scripts/apply_industry_template.php` | **新增（v3.1）** | CLI 应用行业模板 |

### 5.2 前端文件（实际）

| 文件 | 状态 | 说明 |
|------|------|------|
| `chat.html` | ✅ 已实现 | 宿家访客聊天窗（非 customer-chat.html） |
| `admin/*.html` | ✅ 已实现 | 管理后台 |
| `customer-admin.html` | ❌ 未建 | 功能已分散在 admin/ |
| `customer-chat.html` | ❌ 未建 | 由 chat.html 承担 |
| `sdk/chat-widget.js` | 可选 | 嵌入组件（若需 iframe 封装再补） |

### 5.3 PromptEngine 改造关键代码

> 以下为 **v1.0 原规划伪代码**；实际 v3.0 见 §3.4，方法名为 `buildMascotLayer` / `buildProhibitionLayer` / `buildUserTurn` / `directReplyFromKb`，知识注入在 **user 轮 RAG**，非 system 层 `_buildKnowledgeLayer`。

```php
// PromptEngine.php v3.0 新增

// 企业人设层（改造原 _buildIdentityLayer）
private static function _buildCorporateIdentityLayer(array $corpConfig): string {
    // 吉祥物名称、品牌背景、服务定位
}

// 服务规范层（从原世界规则改造）
private static function _buildServiceRuleLayer(array $corpConfig): string {
    // 服务边界、处理原则、转人工条件
}

// 知识库层（重写原 _buildKnowledgeLayer）
private static function _buildKnowledgeLayer(array $config): string {
    // 从 kb_entries 检索最相关的 3-5 条
    // 从 kb_documents 检索相关段落
    // 注入到 prompt
}

// 订单工具能力描述
private static function _buildOrderAbilityLayer(array $apiConfig): string {
    // 如有配置订单 API，注入工具描述
}
```

---

## 六、与原系统的共存关系

| 模块 | OC 平台 (Soulmix) | 客服系统 | 共享 |
|------|------------------|---------|------|
| 用户表 `users` | role=1/2/3 不变 | 新增 enterprise_admin 角色 | 共用 |
| OC角色表 `oc_characters` | 正常使用 | ❌ 不使用 | - |
| 新表 `kb_*` | ❌ 不使用 | ✅ 核心表 | - |
| PromptEngine | 原逻辑 | 新增企业模式参数 | 共用类 |
| 聊天接口 `chat.php` | 原 chat/preview_chat | 新增 enterprise_chat | 共用文件 |
| 记忆系统 `memory.php` | ✅ 正常使用 | ✅ 客户画像 | 共用 |
| 订阅系统 | OC 订阅 | 企业 SaaS 套餐 | 可共用逻辑 |
| 管理后台 | admin.html / OC 后台 | **admin/**（persona/knowledge/settings/handoff） | 宿家用 admin/ |

---

## 七、验收标准

### 7.1 MVP 验收（宿家客服）

- [x] 吉祥物人设可配置（`admin/persona.html`）
- [x] 知识库录入与管理（`admin/knowledge.html`）
- [x] 默认知识库一键重建（`KnowledgeBaseSeed` / 44 条）
- [x] 客服聊天窗回复（`chat.html` + `chat.php`）
- [x] KB 命中时直答，不编造
- [x] iframe 嵌入代码生成（`admin/settings.html` Tab 3）— **chat.html 作 iframe 内页面嵌入测试待补**

### 7.1.1 Sidecar 房间知识验收

- [x] 订单查询 + 云房卡（PMS 网关，**冻结**）
- [x] 查单后云房卡卡片追问（「这是什么/不会用」→ KB 直答，不进 LLM fallback）
- [x] `chat.html` 云房卡 `rich_content` 写入 `history` 支持多轮
- [x] `order_query:` 冻结回归
- [x] 房间意图二次确认订单号（`RoomQueryFlow` step=1）
- [x] step=3 绑单后 Sidecar（地址/停车/垃圾/设备）
- [x] **WiFi/门锁/押金/刷脸 → 云房卡引导**（不 Sidecar 直报密码）
- [x] 云房卡 FAQ 不进 Sidecar 误拦截
- [x] step=3 泛攻略/统一政策回落 KB（`isGeneralKbQuestion`）
- [x] Sidecar 未命中固定话术，禁止 LLM 编造
- [x] 一单多房 `room_pick` 方块点选
- [x] ChunkBuilder + 后台 Sidecar 运维
- [x] Sidecar + KB 向量语义检索（MiniMax Embedding）

### 7.1.2 防幻觉与政策验收

- [x] 【第一层·事实禁止】无 KB 时固定拒答
- [x] `brand_story` 不进入 system prompt
- [x] `finalizeReply` / `directReplyFromKb` 硬兜底
- [x] 对外品牌 **宿家民宿**；14:00/12:00；禁宠物禁烟
- [x] 取消退款平台处理；AI 不代订
- [x] 无免费停车等 Sidecar 真值不被 LLM 覆盖（如永凯春晖）
- [x] 15 条精简回归测试通过（2026-05）

### 7.1.3 转人工验收

- [x] `HandoffTriggers` DB 词库 + 后台维护
- [x] 续住/换房/发票/投诉/设施故障 → 转人工
- [x] 退款/宠物/平台退改 → KB 直答，不误转人工
- [x] `admin/handoff.html` 人工接管闭环

### 7.1.4 性能验收（参考）

- [x] KB/云房卡引导类 **&lt;100ms**（服务端 `elapsed_ms`）
- [x] 查单/Sidecar 绑单 **~2s**（PMS 网关，非 LLM）
- [x] `scripts/benchmark_chat_latency.php` 可复测

### 7.1.5 暂缓 / 未做

- [ ] 南宁吃喝玩乐长篇攻略 KB
- [ ] 掌柜统一联系方式写入 KB
- [ ] PMS 绑单结果会话缓存（RoomQueryFlow step=1 **仍每次要订单号**；`order_context_cache` 已用于查单展示、云房卡追问、`queryRoomLocal` 验证，**不**用于房间流 step=1 跳过）
- [x] ~~Excel 批量导入 FAQ~~ → **已用 CSV 替代**（`api/knowledge.php?action=import`，§9.6）
- [ ] `kb_documents` 文档 RAG
- [ ] `chat.html` 作 iframe 内页面嵌入测试（嵌入代码已生成，待补测试）

### 7.2 人格化验收
- [ ] 同一问题，不同人设的客服回复风格明显不同
- [ ] 吉祥物不会出现 OOC（脱离角色）的回复
- [ ] 客服语气一致，不会冷冰冰像传统机器人

### 7.3 知识库验收

- [x] KB 内容准确直答（政策/品牌/云房卡/平台退改）
- [x] KB + Sidecar 分工清晰，未覆盖问题拒答或转人工
- [x] 默认种子重建 + 批量向量化可用
- [x] CSV 批量导入（`api/knowledge.php?action=import`，§9.6）
- [ ] 批量导入 1000+ 条（未实现）

### 7.4 v3.1 模块验收（2026-08 新增）

完整清单见 §9.7。摘要：

| 编号 | 模块 | 状态 |
|------|------|------|
| §9.1 | 企业微信回调（GET 验证 + POST text 消息） | ✅ |
| §9.2 | 开放 API（X-API-Key 鉴权 + AI 回复） | ✅ |
| §9.3 | AI Agent 可配置（Phase 1：存储+展示） | 🟡 Phase 1 已落地；Phase 2（生效到 PromptEngine）待做 |
| §9.4 | 行业模板（民宿/餐厅/通用） | ✅ |
| §9.5 | 用户管理（新增/重置密码） | 🟡 权限矩阵暂缓 |
| §9.6 | CSV 批量导入 KB | ✅ |

---

## 附录 A：代码与 PRD 差异说明

### A.1 v1.0 → v1.1 差异（已收敛）

| PRD 原规划 | 实际实现 |
|-----------|----------|
| MiniMax M2-her 主聊天 | **DeepSeek v4-flash** 主聊天 + MiniMax Embedding |
| `_buildKnowledgeLayer` 注入 system | **RAG 注入 user 轮** + KB 直答跳过 LLM |
| WiFi/门禁 Sidecar 直答 | **云房卡引导** |
| `enterprise-admin.html` | **`admin/*.html`** |
| 299 元/年云房卡示例 | **已删除**；云房卡=住客电子凭证 |
| 橙途对外品牌 | 对外 **宿家民宿** |
| HTTP 自调 embedding.php | **`kbSemanticSearch` 进程内** + 按需调用 |
| IP 速率限制 20/min | **`rate_limits` 表，60 秒窗口 20 次** |
| `order_context_cache` 加速房间绑单 | **查单成功写入（24h）**；用于云房卡卡片追问 + `queryRoomLocal` 验证；**房间流 step=1 仍每次要订单号** |
| Excel 批量导入 FAQ | **CSV 批量导入已实现**（`api/knowledge.php?action=import`） |

### A.2 v1.1 → v1.2 演进（v3.1 新增模块）

**PRD v1.1 未规划但代码已落地的 6 个模块：**

| 模块 | 文件 | PRD v1.1 状态 | PRD v1.2 状态 |
|------|------|---------------|---------------|
| 企业微信回调 | `api/wecom.php` | 未规划 | ✅ §九 正式纳入 |
| 开放 API（外部集成） | `api/openapi.php` + `api_keys` 表 | 未规划 | ✅ §九 正式纳入 |
| AI Agent 可配置 | `api/agent.php` + `api/AgentConfig.php` | 未规划 | ✅ §九 正式纳入（Phase 1：仅存储/展示，PromptEngine 仍读 PHP 硬编码） |
| 行业模板 | `api/IndustryTemplate.php` + `templates/{homestay,restaurant,generic}/` | 未规划 | ✅ §九 正式纳入 |
| 用户管理 | `admin/users.html` | 未规划 | ✅ §九 正式纳入 |
| 网站嵌入代码生成 | `admin/settings.html` 「🔗 网站嵌入」Tab | "可选，若需再做" | ✅ §九 正式纳入（嵌入代码已生成，chat.html 作 iframe 内页面待补） |

### A.3 仍欠账项（v1.2 仍暂缓）

| 项 | 状态 | 影响 |
|----|------|------|
| 掌柜统一联系方式 KB 条目 | ❌ 未做 | 客人问"怎么联系掌柜"会被引导到 KB 内的"联系掌柜"叙述但无具体微信/电话 |
| `kb_documents` 文档 RAG | ❌ 未做 | 长篇攻略 KB 无法落地（§7.1.5 暂缓） |
| AI 自动学习 KB（对话提取 FAQ） | ❌ 未做 | Phase 3 |
| SaaS 套餐管理体系 | ❌ 未做 | Phase 3 |
| `chat.html` 作 iframe 内嵌入式嵌入 | ⚠️ 部分（代码生成已支持，待测试嵌入） | Phase 3 |

---

## 八、竞品定位

| 对比项 | 晓多/七鱼/智齿 | Soulmix 客服 |
|--------|--------------|-------------|
| **人设能力** | 仅头像+话术模板 | **真实人格引擎**（OC级深度）|
| **价格** | 2000-15000元/年 | 更具竞争力 |
| **电商集成** | 深度（晓多） | 通用API对接 |
| **目标客户** | 中大型企业 | **小微企业/有IP企业** |
| **技术架构** | 重、复杂 | 轻量、灵活 |

---

## 九、v3.1 演进模块（2026-08 · PRD v1.2 新增）

> v3.1 是 v3.0（宿家 MVP 落地）之后的演进迭代，重点从"一个企业能用"扩展到"多企业、多渠道、多行业"。本节纳入 PRD v1.1 未规划但代码已实现的 6 个模块。

### 9.1 企业微信回调（`api/wecom.php`）

#### 设计目标
让已部署企微的企业，客服能力无缝接入员工/客户微信对话，不强制客户切换到 H5 聊天窗。

#### 接入流程
1. 企业微信后台 → 应用 → 接收消息 → 设置 API 接收 → 填入 `https://your-host/aibisskefu/api/wecom.php`
2. 后台「系统设置 → AI 模型」配置 `wecom.corpid` / `wecom.token` / `wecom.aes_key`
3. 企业微信后台保存回调地址 → 触发 GET 验证 → `sha1Sort` 签名校验 → 解密 echostr → 回写明文

#### 接口协议
| 方法 | 用途 | 校验 |
|------|------|------|
| GET | URL 验证（保存回调地址时触发）| msg_signature + sha1 + AES 解密 echostr |
| POST | 接收加密消息（text 类型） | msg_signature + AES 解密 → 提取 Content → 调 PromptEngine 回复 → AES 加密回包 |

#### 当前实现范围
- ✅ text 消息类型处理
- ✅ 安全拦截（`checkInputSafety`）
- ✅ 与 `chat.php` 共用 PromptEngine + KB + Sidecar + 转人工
- ⚠️ 图片/语音/事件类型未处理（仅 text）
- ⚠️ `wecom.log` 写文件未做轮转

#### 错误响应
- 500 配置缺失 → `WeCom not configured`
- 400 参数缺失
- 403 签名校验失败 / CorpID 不匹配
- 500 AES 解密失败

---

### 9.2 开放 API（`api/openapi.php` + `api_keys` 表）

#### 设计目标
让第三方系统（CRM、工单、客服中台）通过 API Key 直接调用 AI 客服能力，无需嵌入 H5 聊天窗。

#### 请求协议
```
POST /api/openapi.php
Headers:
  X-API-Key: ak_xxx                # 或 Authorization: Bearer ak_xxx
  Content-Type: application/json

Body:
{
  "session_id": "可选，不传则自动生成",
  "message": "用户消息内容",
  "history": []                     # 可选，历史消息数组
}
```

#### 响应
```json
{
  "code": 0,
  "data": {
    "session_id": "sess_xxx",
    "reply": "AI 回复内容"
  }
}
```

#### 鉴权
- 数据库表 `api_keys`（`api_key` + `enabled` + `last_used_at`）
- 每次请求更新 `last_used_at`，便于审计/吊销
- 无 Key → 401；Key 无效/禁用 → 403

#### 业务约束
- session_id 在 SMS 验证（30 分钟）通过前 → 跟 H5 聊天窗走相同的安全拦截
- 复用 `chat.php` 的 KB / Sidecar / 转人工 / LLM 兜底链路

---

### 9.3 AI Agent 可配置（`api/agent.php` + `api/AgentConfig.php`）

#### 设计目标
让"哪些问题走哪个分支"、"凭证类关键词"、"插件参数"这些 PromptEngine 硬编码逻辑可被后台配置，降低改代码频率。

#### Phase 1 状态（v3.1 当前）
- ✅ 配置存储：`platform_config` 表 `agent.*` / `plugin.*` keys
- ✅ UI 展示：`api/agent.php?action=get_config` 返回 JSON 配置树
- ✅ UI 保存：`api/agent.php?action=save_config` 批量 upsert
- ⚠️ **PromptEngine 仍读 PHP 硬编码** —— 配置只展示不生效（避免误改线上行为）

#### 配置 key 清单
| Key | 类型 | 用途 |
|-----|------|------|
| `agent.routing.credential_keywords` | JSON 数组 | 凭证类关键词（WiFi/门锁/押金/刷脸 → 云房卡引导）|
| `agent.routing.sidecar_route_phrases` | JSON 数组 | 进 Sidecar 房间流的触发短语 |
| `agent.kb.policy_patterns` | JSON 数组 | 政策类 KB 直答关键词 |
| `agent.safety.political` | JSON 数组 | 政治安全拦截词 |
| `plugin.*` | JSON | 插件参数（预留）|

#### 后续 Phase 2 规划
- PromptEngine 读取 `agent.*` 配置替代硬编码
- 配置改动自动 reload（无需重启 PHP）
- 配置变更审计日志

---

### 9.4 行业模板（`api/IndustryTemplate.php` + `templates/`）

#### 设计目标
一套代码服务多行业客户：新签约客户选择"民宿/餐厅/通用"，自动套用对应的 KB 种子、转人工词库、AI Agent 默认值。

#### 模板清单
| Industry | 目录 | 适用 |
|----------|------|------|
| `homestay` | `templates/homestay/` | **宿家民宿（默认）** |
| `restaurant` | `templates/restaurant/` | 餐饮门店 |
| `generic` | `templates/generic/` | 通用 FAQ |

#### 每个模板包含
- `kb_seed.json` —— 行业 FAQ 种子（问题/答案/关键词/相似问）
- `handoff_seed.json` —— 转人工触发词库
- `agent_defaults.json` —— Agent 默认配置（凭证关键词、Sidecar 路由短语等）

#### 应用方式
```bash
php scripts/apply_industry_template.php [industry]
# 或 API: api/industry_template.php?action=apply&industry=homestay
```

返回：`{ industry, agent_keys, kb_imported, handoff_imported }`

#### 设计边界
- 行业模板是 **起步配置**，签约后客户仍可在后台自由增删 KB 条目
- 切换行业模板 → 不会清空已有 KB（合并而非替换）

---

### 9.5 用户管理（`admin/users.html`）

#### 设计目标
多管理员协作：管理员可新增/重置其他管理员账号密码，无需直接操作数据库。

#### 当前实现
- ✅ 「新增管理员」表单（用户名 + 角色 + 初始密码）
- ✅ 「重置密码」操作
- ⚠️ 删除/停用/角色权限矩阵未做（仍以 `users.role` 单一字段区分）
- ⚠️ 操作审计日志未做

#### 数据表
沿用原 `users` 表（role: 1=普通 / 2=企业 / 3=超管）。

---

### 9.6 CSV 批量导入（`api/knowledge.php?action=import`）

#### 设计目标
PRD v1.1 标的"Excel 批量导入未实现"——v3.1 先以 CSV 落地，覆盖 80% 批量导入需求（Excel 可另存为 CSV）。

#### 请求
```
POST /api/knowledge.php?action=import
Content-Type: multipart/form-data

file: kb.csv        # CSV 文件，UTF-8 编码
columns: question,answer,keywords,category
```

#### CSV 格式
```csv
question,answer,keywords,category
WiFi密码多少,WiFi密码请查看云房卡,WiFi;密码;网络,入住
押金多少,在线交押金请查看云房卡,押金;支付,费用
```

#### 返回
```json
{
  "code": 0,
  "data": {
    "imported": 23,
    "skipped": 2,
    "errors": ["第 5 行：question 不能为空"]
  }
}
```

#### 限制
- 单次 ≤ 1000 行
- UTF-8 only
- 重复 question 跳过（不覆盖已有）

---

### 9.7 v3.1 模块验收（新增 11 项）

| 编号 | 验收项 | 状态 |
|------|--------|------|
| 9.1.1 | 企微 GET URL 验证签名 + AES 解密 | ✅ |
| 9.1.2 | 企微 POST 加密消息解密 + AI 回复 + 回包加密 | ✅（text 类型） |
| 9.2.1 | 开放 API X-API-Key 鉴权 | ✅ |
| 9.2.2 | 开放 API 安全拦截 + SMS 验证态读取 | ✅ |
| 9.3.1 | Agent 配置存储与 UI 展示 | ✅（Phase 1） |
| 9.3.2 | Agent 配置生效到 PromptEngine | ⚠️ Phase 2 |
| 9.4.1 | 三个行业模板（民宿/餐厅/通用）KB/转人工/Agent 配置 | ✅ |
| 9.4.2 | 应用行业模板脚本 + API | ✅ |
| 9.5.1 | 用户管理新增/重置密码 | ✅ |
| 9.5.2 | 用户管理删除/角色权限矩阵 | ⚠️ 暂缓 |
| 9.6.1 | CSV 批量导入 KB | ✅ |

---

*文档版本：v1.2*
*更新日期：2026 年 8 月 13 日*
*状态：宿家 MVP 客服已落地 + v3.1 演进模块已上线 · PRD 与代码同步*
*关联蓝图：`.trae/documents/room-query-flow-blueprint.md`*
*关联代码：见 §九 v3.1 演进模块*

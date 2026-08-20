# 商用 AI 客服智能体 — 产品需求文档 v1.3

> ## ⚠️ 重要提示(2026-08-20 更新)
>
> **本文档反映截至 v3.16 的实现状态**(代码改动需同步更新)。
>
> - PRD 与代码的**实时差异**:见 `附录 B 代码与 PRD 对账表`
> - **变更历史**:见 `§十 v3.4–v3.16 演进模块`(按版本号索引到对应蓝图)
> - **修改蓝图目录**:`.trae/documents/`(每个版本 v3.X.Y 一份)
>
> 接手人请**先读 §十和附录 B**,再回头看正文设计意图。

> **基于 ZORAVA/Soulmix OC 智能体平台改造**
> 保留现有 OC 创作者平台作为第二产品线,新增商用 AI 客服模块。

---

## 一、产品定位

### 1.1 核心价值
将 OC 智能体的"人格化角色扮演"能力,迁移到**商用 AI 客服**场景:
- **差异化竞争力**:传统 AI 客服冷冰冰、模板化;我们的客服有**企业吉祥物/品牌 IP 人格**,有温度、不 OOC
- **小微企业友好**:定价低于主流竞品(晓多/七鱼),功能聚焦核心场景
- **轻量级 MVP**:基于现有代码快速改造,1-2 周内可上线

### 1.2 目标用户
- **小微企业/个体户**:需要低成本 AI 客服,但又不想用"冷冰冰的机器人"
- **有品牌 IP 的企业**:已有吉祥物/虚拟角色,需要在线客服中保持人设一致
- **非电商企业**:官网客服、预约咨询、FAQ 自动回复

### 1.3 MVP 核心公式
```
企业吉祥物人设(原 OC 系统) + 知识库(FAQ/产品知识) + 宿家云房卡对接 + 企业微信客服 = MVP
```

> **v3.16 修订**:对接的不是"企业自配 API",而是**宿家(苏鸣民宿运营平台的供应商)云房卡 API**。这是和 PRD v1.0 设计意图的关键差异——见 §3.3。

---

## 二、系统架构

### 2.1 总体架构(双通道,2026-08 现状)

```mermaid
用户(微信 / H5)
  ├─ 微信客服通道 → wecom_kf.php → ChatPipeline → Workflows → send_msg
  │   └─ 客户从客服链接/扫码进入,无需加好友
  └─ H5 聊天窗 → chat.php → ChatPipeline → Workflows → chat.html 渲染

  ├─ 自建应用通道 → wecom.php (PRD §9.1,加好友对话,text 类型)
  └─ 开放 API → openapi.php (PRD §9.2,第三方系统对接)
```

**`ChatPipeline` 内部**(`api/ChatPipeline.php`):
```
IntentClassifier → IntentRouter → Workflows
  ├─ OrderQueryWorkflow         订单查询 + 云房卡发送(v3.14+)
  ├─ RoomQueryWorkflow          房间查询(地址/停车/垃圾)
  ├─ YunfangkaCredentialWorkflow 凭证类 → 云房卡引导
  ├─ KnowledgeWorkflow          KB 直答或 RAG → LLM
  ├─ PreSalesWorkflow           售前引导(OTA 引导)
  ├─ SmallTalkWorkflow          闲聊(轻量 prompt,不进 KB)
  └─ HandoffWorkflow            转人工(400-155-9959)
```

**Workflow 路由表(v3.16)**:

| 优先级 | 意图 | 数据源 / 模块 | 走 LLM? |
|--------|------|---------------|---------|
| 1 | 输入安全拦截 | `checkInputSafety()` | 否 |
| 2 | 转人工关键词 | `HandoffTriggers` | 否 |
| 3 | 凭证类(WiFi/门锁/押金/刷脸)| `YunfangkaCredentialWorkflow` | 否 |
| 4 | 订单查询(含数字订单号)| `OrderQueryWorkflow` + **宿家直连** | 否 |
| 4.5 | 云房卡卡片追问 | `isYunfangkaCardFollowUp` | 否 |
| 5 | 房间意图 | `RoomQueryWorkflow` → Sidecar | 否 |
| 6 | 售前引导 | `PreSalesWorkflow` | 否 |
| 7 | 政策/预订/KB 命中 | `directReplyFromKb` | 否 |
| 8 | 闲聊 | `SmallTalkWorkflow` | 否 |
| 9 | UNKNOWN | LLM 兜底(`deepseek-v4-flash`) | 是 |

**`chat.php` 单轮处理顺序**(与代码一致):
```
用户消息
 → 速率限制 / 安全拦截
 → KB 关键词直答(early fast path,~25–40ms)
 → 改写 rewriteQuery(订单/转人工/云房卡凭证类跳过)
 → KB 检索: 关键词 FULLTEXT → 可选 kbSemanticSearch
 → order_query: 宿家直连(成功时发云房卡)
 → 云房卡卡片追问 → KB 直答
 → RoomQueryWorkflow(Sidecar)
 → PreSalesWorkflow(售前)
 → HandoffTriggers(转人工)
 → directReplyFromKb / callAI → filterReply → finalizeReply
```

**`wecom_kf.php` 处理顺序**(微信客服通道,v3.14+):
```
kf_msg_or_event
 → msgid 去重(5min 缓存)
 → 闸门(数字订单号 / 图片 OCR → v3.15.4)
 → OrderQueryWorkflow / YunfangkaCredentialWorkflow
 → 一单多房:N 张云房卡全部发送(v3.16)
 → sendKfMiniprogramMessage(miniprogram 卡片)
```

**响应耗时**(VPS 实测):
- KB 直答 ~30ms
- Sidecar 绑单 ~1s(宿家直连)
- LLM 兜底 ~1s(DeepSeek)
- 云房卡发送 ~2s(企业微信 API + 宿家 + thumb 上传)

### 2.2 改造策略(2026-08-20 现状)

| 层次 | 改造方式 | 状态 |
|------|---------|------|
| **前端** | `chat.html` + `admin/*.html` | ✅ 已落地 |
| **API** | `chat.php` / `knowledge.php` / `handoff.php` / `sidecar.php` / `embedding.php` / `wecom.php` / `wecom_kf.php` / `openapi.php` / `agent.php` | ✅ 已落地 |
| **微信客服通道** | `wecom_kf.php` + `wecom_kf_roomcard_v37.php` + `wecom_kf_dedup.php` | ✅ v3.4+ |
| **云房卡** | 宿家直连(`room_card/byChannelOrder` + `generateByChannelOrder`),绕过自建网关 | ✅ v3.15.2 |
| **数据库** | `kb_*`、`handoff_*`、`room_query_sessions`、`rate_limits` 等 | ✅ 运行时迁移 |
| **AI 模型** | **DeepSeek v4-flash** 主聊天 + **Qwen dashscope text-embedding-v3** Embedding | ✅ v3.7 |
| **部署** | VPS(`root@43.138.217.6`)+ `git push` 触发同步 | ✅ |

### 2.3 v2.0 业务规则(2026-08-13 确认)

#### 2.3.1 业务范围(只查询)
| 业务类型 | 走哪条路 | 数据源 | 兜底 |
|---|---|---|---|
| 订单查询 | OrderQueryWorkflow | **宿家直连** | `400-155-9959` |
| 云房卡发送 | wecom_kf.php | 宿家 + miniprogram 卡片 | `暂时未能打开您的云房卡,请稍后重试或联系前台。` |
| 房间信息查询 | RoomQueryWorkflow | Sidecar | `400-155-9959` |
| WiFi/门锁/押金 | YunfangkaCredentialWorkflow | KB + Sidecar | **云房卡引导**(不 Sidecar 直报) |
| 入住时间/退订流程 | KnowledgeWorkflow | kb_entries(149 条 v3.7 KB) | KB 直答 |
| 闲聊 | SmallTalkWorkflow | 固定模板 | 固定回复 |
| 续住/换房/发票/投诉 | HandoffWorkflow | human_handoffs 表 | `400-155-9959` |

**铁律**:AI **不处理任何新增业务**(续住、换房、改期、取消订单、申请发票等),**统一回 400-155-9959**。

#### 2.3.2 兜底话术统一规则
任何未实现的功能、API 失败、知识盲区,**统一回复**:
> "[功能名]查询功能暂未上线,请拨打 400-155-9959 联系我们。"

#### 2.3.3 转人工统一规则
所有 HandoffWorkflow 触发(包括"续住""换房""投诉""发票"等关键词)、用户主动要求"转人工"、"找真人":
> "已为您转接人工客服,请拨打 400-155-9959 联系我们。"

**实现位置**:`api/Workflow/HandoffWorkflow.php:38`

#### 2.3.4 Intent 分类优先级(2026-08-13 修正)
```
1. 输入安全拦截(checkInputSafety)
2. 转人工关键词(HandoffTriggers) → HUMAN
3. 凭证类(WiFi/门锁/押金) → ROOM_PASSWORD_QUERY
4. 订单查询(数字订单号 / order_query:) → ORDER_QUERY
5. 房间查询(房型/地址/设备关键词) → ROOM_QUERY
6. 售前引导 → PRE_SALES
7. KB 早期命中(品牌/退订/入住时间 FAQ) → KNOWLEDGE
8. 闲聊 → SMALL_TALK
9. UNKNOWN → UnknownWorkflow(LLM 兜底)
```

**修正要点(v3.8)**:
- ✅ 售前判定在 KB 早期匹配**之前**(防止"有空房吗"被 KB 抢答)
- ✅ 业务意图(订单/房间)在 KB 早期匹配**之前**
- ✅ 凭证类在所有之前
- ✅ 闲聊在 KB 之前
- ✅ LLM 不参与 Intent 分类

#### 2.3.5 消息可靠性

| 机制 | 实现位置 | 状态 |
|---|---|---|
| msgid 去重(5min) | `logs/wecom_kf_msgid_cache.json` | ✅ v2.0 |
| sync_msg cursor 推进 | `logs/wecom_kf_cursor_<hash>.txt` | ✅ v2.0 |
| service_state/trans(95018 修复) | `api/wecom_kf.php` | ✅ v2.0 |
| 闸门放宽(数字订单号) | `api/wecom_kf.php` | ✅ v3.15.3 |
| 图片 OCR(订单截图) | `api/helpers/order_candidate.php` + 127.0.0.1:9003 | ✅ v3.15.4 |

---

## 三、核心功能模块

### 3.1 企业吉祥物人设系统(基于 OC 高级设定改造)

#### OC 系统 → 客服人设映射

| OC 系统 (Soulmix) | 客服系统 | 说明 |
|-------------------|---------|------|
| OC 名称/头像/简介 | 吉祥物名称/形象/品牌介绍 | 企业品牌 IP 基本资料 |
| 世界观钩子 (w-world-hook) | 品牌背景故事 | 品牌起源/核心理念 |
| 性格 (f3) | 客服性格基调 | 热情/专业/可爱/稳重等 |
| 说话方式 (f4) | 客服话术风格 | 语气、称呼方式、禁用语 |
| 处事原则 (f5-principles) | 客服行为准则 | 处理客诉的原则、价值观 |
| 世界规则 (f2) | 服务边界规则 | 不能做什么、不懂时怎么办 |
| 情景题 (sc_*) | 场景化应对策略 | 客户生气/催单/投诉时的应对 |
| bg_story | 品牌故事(展示用) | 非注入 prompt,仅展示 |

**v3.4 修复**(`v3.4-persona-prompt-fix-blueprint.md`):
- `emotion_strategy` 进入 prompt
- `service_rules` 掌柜句过滤修正
- `principles` 不再截断
- "您"字冲突解决

#### 新增人设管理页面(企业后台)
- **品牌资料**:头像、名称、简介
- **人格设定**:性格、语气、行为准则(向导式配置,复用 `oc-advanced.html` 七步逻辑)
- **服务规范**:禁用语、转人工规则、情绪应对策略

### 3.2 知识库系统(核心新增)

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
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    keywords VARCHAR(500) DEFAULT '',
    similar_questions TEXT DEFAULT NULL,
    status TINYINT DEFAULT 1,
    hit_count INT DEFAULT 0,
    embedding_status TINYINT DEFAULT 0, -- v3.7 KB: 0=未向量 1=已向量
    embedding_dim INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enterprise (enterprise_id),
    INDEX idx_category (category_id)
);

-- 文档知识(表结构保留,未启用)
CREATE TABLE kb_documents ( ... );

-- 订单 API 配置(预留,实际用宿家直连)
CREATE TABLE enterprise_api_config ( ... );
```

#### 功能特性(已实现 · 柚光 MVP)

| 功能 | 实现 | 说明 |
|------|------|------|
| 手动录入 CRUD | `api/knowledge.php` + `admin/knowledge.html` | 分类 + 问答对 |
| **默认种子重建** | `KnowledgeBaseSeed.php` + `rebuild_defaults` | 柚光标准 FAQ(v3.7 KB 后 **149 条**) |
| 关键词匹配 | `PromptEngine::searchKnowledge` + `_searchByKeywords` | FULLTEXT 降级 LIKE |
| 语义向量检索 | `api/embedding.php` → `kbSemanticSearch()` | **Qwen dashscope `text-embedding-v3`**(1024 维) |
| **KB 直答(跳过 LLM)** | `PromptEngine::directReplyFromKb` | 政策/预订/云房卡类固定答复 |
| 批量向量化 | `embedding.php?action=batch_vectorize` | 后台「批量向量化」按钮 |
| **CSV 批量导入** | `api/knowledge.php?action=import` | v3.1 新增;≤1000 行 UTF-8 |
| Excel 批量导入 | — | **未实现**(CSV 已覆盖 80% 场景) |
| `kb_documents` 文档库 | — | **表结构在 PRD,未接入 RAG** |

#### 柚光通用知识库结构(`KnowledgeBaseSeed`,8 类)

| 分类 | 条目数(v3.7 KB 后) | 内容边界 |
|------|--------|----------|
| 品牌介绍 | ~12 | 对外品牌 **柚光民宿**;小柚能力范围 |
| 预订与订单 | ~20 | 仅携程/美团/去哪儿;**AI 不代订**;云房卡说明 |
| 入住与退房 | ~20 | **14:00 入住 / 12:00 退房**;公安刷脸 KB 条目 |
| 房规与禁忌 | ~10 | **禁宠物、室内禁烟** |
| 费用与退改 | ~15 | **取消/退款走平台**;交押金引导云房卡 |
| 服务边界与路由 | ~25 | 地址/停车→Sidecar;凭证类→云房卡 |
| 南宁本地攻略 | ~10 | **短答 + 地图核实**(长篇吃喝玩乐 **暂缓**) |
| 需人工处理 | ~12 | 续住/换房/发票/投诉说明(实际触发走词库) |

#### 知识库注入与直答策略(实际落地)
1. **有明确 KB 条目** → `directReplyFromKb` **原文直出**,不经过 LLM(防幻觉优先)
2. **有【参考资料】且走 LLM** → RAG 注入 `buildUserTurn`;`finalizeReply` 无 KB 时强制拒答
3. **无 KB** → 固定:`这边暂时没有查到准确信息,建议您联系前台确认。`

### 3.3 订单与云房卡(2026-08-20 实现)

#### 重要变更(v3.15.2 起)
**原 PRD 设计的自建网关 `callGateway('query_order')` 已停用**(返 401)。
当前实现:**绕过网关,直接调用宿家真实 API**。

#### 宿家 API 对接

| 端点 | 方法 | 用途 |
|------|------|------|
| `/openapi/room_card/byChannelOrder` | GET | 查云房卡(根据订单号) |
| `/openapi/room_card/generateByChannelOrder` | POST | 生成云房卡(若无则生成) |
| `/sujia/room/card/service` | — | ❌ **不再使用**(网关 401) |

**凭证**:DB `platform_config`(`roomcard.username` / `roomcard.password`)

**实现文件**:`api/wecom_kf_roomcard_v37.php`
- `getRoomCard($db, $orderNo)` → 查
- `generateRoomCard($db, $orderNo)` → 生成
- `generateAllRoomCards($db, $orderNo)` → v3.16 一单多房
- `buildRoomCardDelivery($db, $orderNo)` → 单卡 delivery
- `buildRoomCardDeliveries($db, $orderNo)` → v3.16 N 卡 delivery 数组
- `parseRoomCardDelivery($card)` → 解析宿家返回的 share_bundle
- `getThumbMediaIdCached()` → 封面素材缓存(**v3.15.5 自动 60h 刷新**)

#### 微信客服通道发卡流程(v3.16)
```
客户发订单号(文本/图片 OCR / 任意含 8+ 位数字)
  ↓
wecom_kf.php 闸门
  ↓
OrderQueryWorkflow
  ↓
buildRoomCardDeliveries()
  ├─ 1 张卡 → 直接发 miniprogram 卡片
  └─ N 张卡 → 先发"您一共订了 N 间房" → 循环发 N 张
  ↓
企微 send_msg(miniprogram 消息)
```

#### 闸门放宽(v3.15.3)
原正则 `/^\d{10,30}$/` 改成 `/\b(\d{8,30})\b/`,允许消息含中文夹杂订单号。

#### 图片 OCR(v3.15.4)
客户发订单截图 → `127.0.0.1:9003` 本地 RapidOCR → 识别订单号 → 走闸门。**VPS 不接 PII**。

#### 一单多房(v3.16)
宿家返 `cards` 数组有几张就发几张(支持 N≥1),2 张及以上先发文字引导。

#### 云房卡小程序参数(原样使用宿家返回)
```json
{
  "appid": "wxc5bd79445fd465bb",
  "pagepath": "pages/index/index?qrCodeId=xxx",  // 微信拼 query 自动注入 onLoad
  "thumb_media_id": "...",
  "title": "您的房间为 1002"
}
```

#### `isYunfangkaCardFollowUp`(chat.php 通道)
查单成功后客户问"这是什么/不会用/怎么点"→ KB 直答「云房卡是什么」。

#### 凭证红线(**永不泄漏**)
AI 客服**只显示**:客房号、有效期、地址等非敏感信息。
**永不显示**:WiFi 密码、门锁密码、押金链接、公安验证流程(在 `roomPassword` / `wifi_password` / `deposit_link` / `auth_url` 字段过滤掉)。

### 3.3.1 Sidecar 房间知识库(柚光场景 · 已实现)

#### 设计思路
房间级**可核实事实**(怎么去、停车、垃圾、设备)**不经过 LLM 编造**,从本地 Sidecar 库 `sujia_ai_sidecar_dev` 检索。

**凭证类信息不进 Sidecar 直答**(已确认业务规则):

| 内容 | 处理方式 |
|------|----------|
| WiFi 密码、门锁密码 | 引导查看 **云房卡** |
| 在线交押金、公安刷脸核验 | 引导查看 **云房卡** |
| 地址、停车、垃圾、设备 | Sidecar(需订单号绑定) |

#### 房间流状态(`room_query_sessions`)

| step | 含义 |
|------|------|
| 0 | 空闲 |
| 1 | 待订单号 |
| 2 | 待选房(`room_pick` 卡片) |
| 3 | 已绑定 order + sidecar_room_id |

#### 数据流
```
sujia_source_snapshot_dev → ai-sujia 同步 → sujia_ai_sidecar_dev
                                              ↓ ChunkBuilder
                                         ai_knowledge_chunk
                                              ↓ Vectorizer(Qwen Embedding)
                                         语义检索 + 结构化查询
                                              ↓ RoomQueryWorkflow + OrderRoomMapper
                                         chat.php 房间回复(Sidecar only)
```

#### 运维接口(`api/sidecar.php`)

| action | 说明 |
|--------|------|
| `stats` | 房源数 / 知识块数 / 向量化进度 |
| `rebuild_chunks` | 从 sidecar 表重建知识块(管理员) |
| `vectorize_pending` | 批量向量化待处理块(管理员) |
| `query_room` | 调试:本地房间查询 |

### 3.3.2 柚光民宿业务政策(已确认 · 写入 KB)

| 主题 | 客人侧政策 |
|------|-----------|
| 对外品牌 | **柚光民宿**(橙途为系统/运营侧,不对客强调) |
| 入住/退房 | **14:00 入住,12:00 退房** |
| 云房卡 | 住客电子入住凭证;含刷脸、交押金、WiFi/门锁;**无 299 元/年业主端描述** |
| 取消/退款 | **全部在携程/美团/去哪儿平台处理** |
| 宠物/吸烟 | **南宁统一禁宠物、室内禁烟** |
| 增值服务 | **无**接送机/生日布置/寄存等特殊服务 |
| 预订 | 仅上述三平台;**AI/聊天不代订** |
| 掌柜联系方式 | ❌ 暂未写入 KB(待补充) |
| 吃喝玩乐攻略 | **暂缓**;现有条目仅短答 + 建议地图核实 |

### 3.3.3 转人工触发词库(已实现)

| 项 | 说明 |
|----|------|
| 数据表 | `handoff_triggers`(keyword + priority) |
| 代码 | `api/HandoffTriggers.php`(P0–P4 默认种子 + `pruneRetiredKeywords`) |
| 后台 | `admin/settings.html` → 转人工规则;`admin/handoff.html` 人工接管 |
| API | `api/handoff.php`(pending/take_over/send/end) |
| 运行时 | `chat.php` / `filterReply` / `RoomQueryWorkflow` 统一读取 |
| 与 KB 分工 | **退款/带宠物/取消改期** 等已有 KB 直答的词 **不转人工**;续住/换房/发票/投诉/故障 **转人工** |
| 同步 | 后台「补全系统默认词库」→ `HandoffTriggers::syncDefaultLibrary()` |

### 3.4 PromptEngine 3.0(2026-05 落地 + 持续迭代)

> LLM 仅处理**通用 FAQ**;房间事实不走 LLM。品牌故事录入**知识库**,不进入 system prompt。

```
┌────────────────────────────────────────────┐
│ 1. 【第一层·事实禁止】buildProhibitionLayer │  ← 最高优先级
│    → 事实唯一来源:本轮【参考资料】
│    → 无资料 → 固定拒答(finalizeReply 硬兜底)
│    → 禁止猜测/编造/模糊措辞
├────────────────────────────────────────────┤
│ 2. 【第二层·话术禁止】
│    → 禁止推销、追问用户信息、多句、万能结尾
│    → 发票/投诉/退款 → 转人工固定话术
├────────────────────────────────────────────┤
│ 3. 【吉祥物语气层】buildMascotLayer
│    → 名字 + description(角色说明)
│    → 性格 / 说话风格(仅语气,非事实来源)
│    ✗ brand_story 不进入 prompt
├────────────────────────────────────────────┤
│ 4. 【服务边界 / 处事原则】
│    → service_rules、principles
├────────────────────────────────────────────┤
│ 5. 【用户消息·RAG】buildUserTurn
│    → 【本轮约束】+【参考资料】+ 用户问题
│    → 无 KB 时写入「不得输出业务事实」指令
└────────────────────────────────────────────┘
```

**后处理:** `filterReply()` 去推销套话 → `finalizeReply()` 无 KB 时强制固定拒答。

**已实现增强(2026-05+)**:

| 机制 | 文件 | 说明 |
|------|------|------|
| KB 极速直答 | `chat.php` + `directReplyFromKb` | 在 rewrite/向量 **之前** 返回,~30ms |
| 凭证类云房卡路由 | `SidecarIntent::isYunfangkaCredentialQuery` | WiFi/门锁/押金/刷脸不 Sidecar 直报 |
| 语义检索按需 | `kbSemanticSearch()` | 仅 LLM 兜底且关键词未命中时调用 |
| Embedding Key | `embedding.php` | 优先 `ai.api_key.qwen_embedding`(MEMORY) |
| 改写跳过 | `chat.php` | 订单/转人工/云房卡凭证类不调用 rewrite |
| 云房卡卡片追问 | `isYunfangkaCardFollowUp` | 查单 24h 内追问用途/不会用 → KB 直答,不进 LLM |
| 多轮 history | `chat.html` | `rich_content` 卡片摘要写入 `history`,供改写与追问 |
| LLM 参数 | `chat.php` | `deepseek-v4-flash`,`max_tokens: 150`,`thinking: disabled` |
| 响应耗时 | `chatResponse` | 返回 `elapsed_ms` |

**回复长度策略**:system 要求 **单句 20–80 字**;`max_tokens: 150` 与防幻觉优先一致;**不做长篇吃喝玩乐生成**(攻略类暂缓)。

### 3.5 企业管理后台(已实现文件)

基于 `admin/` 目录(非 PRD 原规划的 `enterprise-admin.html`):

| 模块 | 页面 | 功能 | 状态 |
|------|------|------|------|
| **仪表盘** | `admin/dashboard.html` | 概览 + 待接管数量 | ✅ |
| **人设管理** | `admin/persona.html` | 吉祥物名称/性格/服务规范;对外 **柚光民宿** | ✅ |
| **知识库管理** | `admin/knowledge.html` | CRUD、批量向量化、**重建默认知识库** | ✅ |
| **系统设置** | `admin/settings.html` | AI Key、订单/PMS 网关、Sidecar 运维、**转人工规则**、房间进流词扩展、**网站嵌入代码** | ✅ |
| **对话记录** | `admin/chat-logs.html` | 历史会话查看 | ✅ |
| **转人工** | `admin/handoff.html` | 待处理/接管中/已结束;人工发消息 | ✅ |
| **用户管理** | `admin/users.html` | 新增/重置密码 | ✅ v3.1 |
| **客户画像** | `memory.php` | OC 遗留,客服场景 **未作为主路径** | 保留 |
| **七步 OC 向导** | `oc-advanced.html` | OC 产品线 | 与柚光客服并行 |

**访客聊天窗**:`chat.html`(订单查询弹窗、`order_query:`、云房卡 `rich_content` 卡片、卡片摘要进 `history`、`room_pick` 卡片、handoff 轮询)。

**微信客服通道**:**不需要访客聊天窗**,直接在企业微信内对话。

---

## 四、实施路径

> **柚光 MVP(Sidecar + 转人工 + 防幻觉)已于 2026-05 落地**;v3.1 演进模块于 2026-08 落地;v3.4–v3.16 于 2026-08-14~20 落地。

### Phase 1:MVP ✅
| 步骤 | 内容 | 状态 |
|------|------|------|
| 1 | 数据库表创建(knowledge_base 系列表) | ✅ |
| 2 | `PromptEngine.php` 改造 → 企业人设 + 知识库分层 | ✅ |
| 3 | `api/knowledge.php` → 知识库 CRUD + 重建默认种子 | ✅ |
| 4 | `chat.php` → 柚光客服路由(非独立 `enterprise_chat` action) | ✅ |
| 5 | 管理后台 → **`admin/*.html`**(非 `enterprise-admin.html`) | ✅ |
| 6 | 访客聊天窗 → **`chat.html`**(非 `customer-chat.html`) | ✅ |
| 7 | MAMP 测试环境 + `sync-to-mamp.sh` | ✅(后改 VPS) |

### Phase 2:增强 ✅
| 步骤 | 内容 | 状态 |
|------|------|------|
| 8 | 宿家 API + `order_query:` 查单云房卡 | ✅(v3.15.2 起绕过自建网关) |
| 9 | Sidecar 房间流 + 向量检索 | ✅ |
| 10 | 转人工词库 + `admin/handoff.html` | ✅ |
| 11 | 对话记录查看(`admin/chat-logs.html`) | ✅ |
| 12 | 知识库批量导入(CSV 替代 Excel) | ✅ |
| 13 | 基础数据统计(对话量、满意度) | ❌ 未做 |

### Phase 3:商用化(持续)
| 步骤 | 内容 | 状态 |
|------|------|------|
| 14 | SaaS 套餐管理体系 | ❌ |
| 15 | 多渠道接入(微信公众号/企微/网页嵌入) | 🟡 微信客服 + 自建应用 + 嵌入代码已生成(iframe 嵌入测试待补) |
| 16 | 转人工机制 | ✅ |
| 17 | AI 自动学习知识库(从对话中提取 FAQ) | ❌ |

### Phase 4:v3.1–v3.16 演进(2026-08 已全部落地)
详见 §十 v3.4–v3.16 演进模块。

---

## 五、技术方案

### 5.1 后端文件清单(柚光 MVP · 与代码同步)

| 文件 | 操作 | 说明 |
|------|------|------|
| `api/chat.php` | 修改 | H5 通道路由;early KB、RoomQueryWorkflow、Handoff、LLM;`order_query:` → 宿家直连 |
| `api/ChatPipeline.php` | 新增(v3.8) | 统一聊天编排入口 |
| `api/PromptEngine.php` | 修改 | 禁止层、RAG、`directReplyFromKb`、`rewriteQuery`、`finalizeReply` |
| `api/embedding.php` | 修改 | Qwen dashscope 向量化 + `kbSemanticSearch()` 进程内检索 |
| `api/KnowledgeBaseSeed.php` | 新增 | 柚光默认 FAQ 种子(v3.7 KB:149 条) |
| `api/knowledge.php` | 修改 | CRUD + `rebuild_defaults` + 向量化代理 + CSV 导入 |
| `api/HandoffTriggers.php` | 新增 | 转人工词库(DB + 默认种子 + 退役词清理) |
| `api/handoff.php` | 已有 | 人工接管 API |
| `api/RoomQueryFlow.php` | 新增 | 房间查询状态机 step 0–3 |
| `api/sidecar/OrderRoomMapper.php` | 新增 | PMS → Sidecar 映射 |
| `api/sidecar/SidecarIntent.php` | 新增 | 进流词表、云房卡凭证、泛攻略回落 |
| `api/sidecar/RoomQueryService.php` | 修改 | 结构化查询;凭证类→云房卡 |
| `api/sidecar/SidecarSearch.php` | 新增 | Sidecar 向量 + LIKE 降级 |
| `api/sidecar/ChunkBuilder.php` | 新增 | Sidecar 表 → ai_knowledge_chunk |
| `api/sidecar/Vectorizer.php` | 新增 | 知识块向量化 |
| `api/sidecar.php` | 新增 | stats / rebuild_chunks / vectorize_pending |
| `api/Intent.php` `IntentClassifier.php` `IntentRouter.php` | 新增(v3.8) | Intent 体系重构 |
| `api/SessionState.php` `Workflow/AbstractWorkflow.php` | 新增 | Workflow 抽象 |
| `api/Workflow/OrderQueryWorkflow.php` | 新增 | 订单查询(宿家直连) |
| `api/Workflow/RoomQueryWorkflow.php` | 新增 | 房间查询 |
| `api/Workflow/YunfangkaCredentialWorkflow.php` | 新增 | 凭证类云房卡引导 |
| `api/Workflow/KnowledgeWorkflow.php` | 新增 | KB / RAG / LLM |
| `api/Workflow/PreSalesWorkflow.php` | 新增(v3.8) | 售前引导 |
| `api/Workflow/SmallTalkWorkflow.php` | 新增 | 闲聊 |
| `api/Workflow/HandoffWorkflow.php` | 新增 | 转人工 |
| `api/Workflow/UnknownWorkflow.php` | 新增 | LLM 兜底(v3.8 轻量 prompt) |
| `api/config.php` | 修改 | Sidecar DB、`callAI`(DeepSeek 默认) |
| `api/wecom.php` | 新增(v3.1) | 企微回调(自建应用通道) |
| `api/wecom_kf.php` | 新增(v3.4) | **微信客服通道(主路径)** |
| `api/wecom_kf_roomcard_v37.php` | 新增(v3.4) | **云房卡核心逻辑(宿家直连)** |
| `api/wecom_kf_dedup.php` | 新增(v3.4) | 消息去重(95001 修复) |
| `api/wecom_crypto.php` | 新增(v3.4) | 企微 AES 加解密共用 |
| `api/helpers/order_candidate.php` | 新增(v3.15.4) | **统一订单号提取 + OCR** |
| `api/openapi.php` | 新增(v3.1) | 外部 API 集成(X-API-Key 鉴权) |
| `api/agent.php` | 新增(v3.1) | AI Agent 可配置读写(Phase 1) |
| `api/AgentConfig.php` | 新增(v3.1) | AgentConfig 类(platform_config 读写) |
| `api/IndustryTemplate.php` | 新增(v3.1) | 行业模板应用(KB/转人工/Agent 三件套) |
| `api/auth.php` `api/verify.php` | 新增 | 鉴权 + SMS 验证 |
| `api/chat_helpers.php` | 已有 | 公共辅助函数 |
| `chat.html` | 修改 | room_pick、订单查询、云房卡卡片 + history 摘要、handoff 轮询 |
| `admin/knowledge.html` | 修改 | 重建默认 KB、批量向量化 |
| `admin/settings.html` | 修改 | Sidecar 卡片、转人工规则维护、网站嵌入代码 |
| `admin/handoff.html` | 已有 | 人工接管 UI |
| `admin/persona.html` `admin/users.html` | 新增(v3.1) | 人设 + 用户管理 |
| `sql/migration_room_query_v3.sql` | 新增 | room_query_sessions 扩展 |
| `scripts/rebuild_knowledge_base.php` | 新增 | CLI 重建 KB |
| `scripts/sync-to-mamp.sh` | 新增 | 工作区 → MAMP 同步 |
| `scripts/benchmark_chat_latency.php` | 新增 | 回复耗时基准测试 |
| `scripts/sync_handoff_triggers.php` | 新增 | CLI 同步转人工词库 |
| `scripts/apply_industry_template.php` | 新增 | CLI 应用行业模板 |
| `templates/{homestay,restaurant,generic}/*` | 新增 | 行业模板种子 |

### 5.2 前端文件

| 文件 | 状态 | 说明 |
|------|------|------|
| `chat.html` | ✅ 已实现 | 柚光访客聊天窗 |
| `admin/*.html` | ✅ 已实现 | 管理后台 |
| `sdk/chat-widget.js` | 可选 | 嵌入组件 |

### 5.3 PromptEngine 改造关键方法(实际方法名)
> **v3.0 方法名**:`buildMascotLayer` / `buildProhibitionLayer` / `buildUserTurn` / `directReplyFromKb`,知识注入在 **user 轮 RAG**,非 system 层 `_buildKnowledgeLayer`。

---

## 六、与原系统的共存关系

| 模块 | OC 平台 (Soulmix) | 客服系统 | 共享 |
|------|------------------|---------|-----|
| 用户表 `users` | role=1/2/3 不变 | 新增 enterprise_admin 角色 | 共用 |
| OC 角色表 `oc_characters` | 正常使用 | ❌ 不使用 | - |
| 新表 `kb_*` | ❌ 不使用 | ✅ 核心表 | - |
| PromptEngine | 原逻辑 | 新增企业模式参数 | 共用类 |
| 聊天接口 `chat.php` | 原 chat/preview_chat | 新增 enterprise_chat | 共用文件 |
| 微信通道 `wecom.php` | ❌ 不使用 | ✅ 自建应用通道 | - |
| 微信通道 `wecom_kf.php` | ❌ 不使用 | ✅ 微信客服通道(**主**) | - |
| 记忆系统 `memory.php` | ✅ 正常使用 | ✅ 客户画像 | 共用 |
| 管理后台 | admin.html / OC 后台 | **admin/**(persona/knowledge/settings/handoff/users)| 柚光用 admin/ |

---

## 七、验收标准

### 7.1 MVP 验收(柚光客服)

- [x] 吉祥物人设可配置(`admin/persona.html`)
- [x] 知识库录入与管理(`admin/knowledge.html`)
- [x] 默认知识库一键重建(`KnowledgeBaseSeed` / 149 条 v3.7 KB)
- [x] 客服聊天窗回复(`chat.html` + `chat.php`)
- [x] 微信客服通道(`wecom_kf.php`)**主路径**
- [x] KB 命中时直答,不编造
- [x] iframe 嵌入代码生成(`admin/settings.html`)— **chat.html 作 iframe 内页面嵌入测试待补**

### 7.1.1 Sidecar 房间知识验收

- [x] 订单查询 + 云房卡(**宿家直连**,v3.15.2 起绕过网关)
- [x] 查单后云房卡卡片追问(「这是什么/不会用」→ KB 直答,不进 LLM fallback)
- [x] `chat.html` 云房卡 `rich_content` 写入 `history` 支持多轮
- [x] 房间意图二次确认订单号(`RoomQueryWorkflow` step=1)
- [x] step=3 绑单后 Sidecar(地址/停车/垃圾/设备)
- [x] **WiFi/门锁/押金/刷脸 → 云房卡引导**(不 Sidecar 直报密码)
- [x] 云房卡 FAQ 不进 Sidecar 误拦截
- [x] step=3 泛攻略/统一政策回落 KB(`isGeneralKbQuestion`)
- [x] Sidecar 未命中固定话术,禁止 LLM 编造
- [x] 一单多房 `room_pick` 方块点选
- [x] ChunkBuilder + 后台 Sidecar 运维
- [x] Sidecar + KB 向量语义检索(**Qwen dashscope Embedding**)

### 7.1.2 防幻觉与政策验收

- [x] 【第一层·事实禁止】无 KB 时固定拒答
- [x] `brand_story` 不进入 system prompt
- [x] `finalizeReply` / `directReplyFromKb` 硬兜底
- [x] 对外品牌 **柚光民宿**;14:00/12:00;禁宠物禁烟
- [x] 取消退款平台处理;AI 不代订
- [x] 无免费停车等 Sidecar 真值不被 LLM 覆盖
- [x] 15 条精简回归测试通过(2026-05)+ 33 用例安全审计(2026-08-14)

### 7.1.3 转人工验收

- [x] `HandoffTriggers` DB 词库 + 后台维护
- [x] 续住/换房/发票/投诉/设施故障 → 转人工
- [x] 退款/宠物/平台退改 → KB 直答,不误转人工
- [x] `admin/handoff.html` 人工接管闭环

### 7.1.4 性能验收(参考)

- [x] KB/云房卡引导类 **<100ms**(服务端 `elapsed_ms`)
- [x] 查单/Sidecar 绑单 **~1-2s**(宿家直连)
- [x] `scripts/benchmark_chat_latency.php` 可复测

### 7.1.5 暂缓 / 未做

- [ ] 南宁吃喝玩乐长篇攻略 KB
- [ ] 掌柜统一联系方式写入 KB
- [ ] PMS 绑单结果会话缓存(`RoomQueryWorkflow` step=1 **仍每次要订单号**)
- [x] ~~Excel 批量导入 FAQ~~ → **已用 CSV 替代**
- [ ] `kb_documents` 文档 RAG(表结构在,未启用)
- [ ] `chat.html` 作 iframe 内页面嵌入测试(嵌入代码已生成,待补测试)

### 7.2 人格化验收
- [ ] 同一问题,不同人设的客服回复风格明显不同(未做完整验收)
- [ ] 吉祥物不会出现 OOC(脱离角色)的回复
- [ ] 客服语气一致,不会冷冰冰像传统机器人

### 7.3 知识库验收

- [x] KB 内容准确直答(政策/品牌/云房卡/平台退改)
- [x] KB + Sidecar 分工清晰,未覆盖问题拒答或转人工
- [x] 默认种子重建 + 批量向量化可用
- [x] CSV 批量导入
- [ ] 批量导入 1000+ 条(未实现,当前 ≤1000)

### 7.4 v3.1–v3.16 模块验收

完整清单见 §十。摘要:

| 编号 | 模块 | 状态 |
|------|------|------|
| §9.1 | 企业微信回调(自建应用,GET 验证 + POST text)| ✅ |
| §9.2 | 开放 API(X-API-Key 鉴权 + AI 回复)| ✅ |
| §9.3 | AI Agent 可配置(Phase 1:存储+展示)| 🟡 Phase 1 已落地;Phase 2 待做 |
| §9.4 | 行业模板(民宿/餐厅/通用)| ✅ |
| §9.5 | 用户管理(新增/重置密码)| 🟡 权限矩阵暂缓 |
| §9.6 | CSV 批量导入 KB | ✅ |
| §十 v3.4+ | 微信客服通道 + 云房卡全链路 | ✅ |

---

## 附录 A:代码与 PRD 差异说明

### A.1 v1.0 → v1.1 差异(已收敛)
| PRD 原规划 | 实际实现 |
|-----------|----------|
| MiniMax M2-her 主聊天 | **DeepSeek v4-flash** 主聊天 |
| `_buildKnowledgeLayer` 注入 system | **RAG 注入 user 轮** + KB 直答跳过 LLM |
| WiFi/门禁 Sidecar 直答 | **云房卡引导** |
| `enterprise-admin.html` | **`admin/*.html`** |
| 299 元/年云房卡示例 | **已删除**;云房卡=住客电子凭证 |
| 橙途对外品牌 | 对外 **柚光民宿** |
| HTTP 自调 embedding.php | **`kbSemanticSearch` 进程内** + 按需调用 |
| IP 速率限制 20/min | **`rate_limits` 表,60 秒窗口 20 次** |
| `order_context_cache` 加速房间绑单 | **查单成功写入(24h)**;用于云房卡卡片追问 + `queryRoomLocal` 验证;**房间流 step=1 仍每次要订单号** |
| Excel 批量导入 FAQ | **CSV 批量导入已实现** |
| MiniMax Embedding | **Qwen dashscope text-embedding-v3**(1024 维)|
| 自建 PMS 网关 `callGateway('query_order')` | **绕过网关,直接调宿家 API**(v3.15.2)|
| 单卡云房卡 | **一单多房 N 张卡**(v3.16)|

### A.2 v1.1 → v1.2 演进(v3.1 新增模块)
PRD v1.1 未规划但代码已落地的模块(企微回调、开放 API、AI Agent 配置、行业模板、用户管理、网站嵌入代码)— 见 §9.x。

### A.3 v1.2 → v1.3 演进(v3.4–v3.16,2026-08-14~20)
见 §十。

---

## 八、竞品定位

| 对比项 | 晓多/七鱼/智齿 | Soulmix 客服 |
|--------|--------------|-------------|
| **人设能力** | 仅头像+话术模板 | **真实人格引擎**(OC 级深度) |
| **价格** | 2000-15000 元/年 | 更具竞争力 |
| **电商集成** | 深度(晓多) | 通用 API 对接 |
| **目标客户** | 中大型企业 | **小微企业/有 IP 企业** |
| **技术架构** | 重、复杂 | 轻量、灵活 |

---

## 九、v3.1 演进模块(2026-08 · PRD v1.2 新增)

> v3.1 是 v3.0(柚光 MVP 落地)之后的演进迭代,重点从"一个企业能用"扩展到"多企业、多渠道、多行业"。

### 9.1 企业微信回调(`api/wecom.php`)

#### 设计目标
让已部署企微的企业,客服能力无缝接入员工/客户微信对话,不强制客户切换到 H5 聊天窗。**(自建应用通道,需加好友)**

#### 接入流程
1. 企业微信后台 → 应用 → 接收消息 → 设置 API 接收 → 填入 `https://your-host/aibisskefu/api/wecom.php`
2. 后台「系统设置 → AI 模型」配置 `wecom.corpid` / `wecom.token` / `wecom.aes_key`
3. 企业微信后台保存回调地址 → 触发 GET 验证 → `sha1Sort` 签名校验 → 解密 echostr → 回写明文

#### 当前实现范围
- ✅ text 消息类型处理
- ✅ 安全拦截(`checkInputSafety`)
- ✅ 与 `chat.php` 共用 PromptEngine + KB + Sidecar + 转人工
- ⚠️ 图片/语音/事件类型未处理(仅 text)
- ⚠️ `wecom.log` 写文件未做轮转

### 9.2 开放 API(`api/openapi.php` + `api_keys` 表)

#### 请求协议
```
POST /api/openapi.php
Headers:
  X-API-Key: ***
  Content-Type: application/json

Body: { "session_id": "...", "message": "...", "history": [] }
```

#### 鉴权
- 数据库表 `api_keys`(`api_key` + `enabled` + `last_used_at`)
- 无 Key → 401;Key 无效/禁用 → 403

### 9.3 AI Agent 可配置(`api/agent.php` + `api/AgentConfig.php`)

#### Phase 1 状态(v3.1 当前)
- ✅ 配置存储:`platform_config` 表 `agent.*` / `plugin.*` keys
- ✅ UI 展示:`api/agent.php?action=get_config`
- ✅ UI 保存:`api/agent.php?action=save_config`
- ⚠️ **PromptEngine 仍读 PHP 硬编码** —— 配置只展示不生效

#### 后续 Phase 2 规划
- PromptEngine 读取 `agent.*` 配置替代硬编码
- 配置改动自动 reload(无需重启 PHP)
- 配置变更审计日志

### 9.4 行业模板(`api/IndustryTemplate.php` + `templates/`)

| Industry | 目录 | 适用 |
|----------|------|------|
| `homestay` | `templates/homestay/` | **柚光民宿(默认)** |
| `restaurant` | `templates/restaurant/` | 餐饮门店 |
| `generic` | `templates/generic/` | 通用 FAQ |

每个模板包含:`kb_seed.json` + `handoff_seed.json` + `agent_defaults.json`。

### 9.5 用户管理(`admin/users.html`)
- ✅ 新增管理员 / 重置密码
- ⚠️ 删除/停用/角色权限矩阵未做

### 9.6 CSV 批量导入(`api/knowledge.php?action=import`)
- ≤1000 行 UTF-8
- 重复 question 跳过(不覆盖)

### 9.7 v3.1 模块验收
| 编号 | 验收项 | 状态 |
|------|--------|------|
| 9.1.1 | 企微 GET URL 验证签名 + AES 解密 | ✅ |
| 9.1.2 | 企微 POST 加密消息解密 + AI 回复 + 回包加密 | ✅(text 类型) |
| 9.2.1 | 开放 API X-API-Key 鉴权 | ✅ |
| 9.2.2 | 开放 API 安全拦截 + SMS 验证态读取 | ✅ |
| 9.3.1 | Agent 配置存储与 UI 展示 | ✅(Phase 1) |
| 9.3.2 | Agent 配置生效到 PromptEngine | ⚠️ Phase 2 |
| 9.4.1 | 三个行业模板 KB/转人工/Agent 配置 | ✅ |
| 9.4.2 | 应用行业模板脚本 + API | ✅ |
| 9.5.1 | 用户管理新增/重置密码 | ✅ |
| 9.5.2 | 用户管理删除/角色权限矩阵 | ⚠️ 暂缓 |
| 9.6.1 | CSV 批量导入 KB | ✅ |

---

## 十、v3.4–v3.16 演进模块(2026-08-14~20 上线)

> **本节是 PRD v1.3 新增**。原 PRD v1.2 没规划但代码已落地的所有微信客服通道模块,以及 v3.7/v3.8 KB 重大升级。

### 10.1 v3.4 — persona prompt 修复(2026-08-14)
**蓝图**:`v3.4-persona-prompt-fix-blueprint.md`
- `emotion_strategy` 进 prompt
- `service_rules` 掌柜句过滤修正
- `principles` 不再截断
- 「您」字冲突解决

### 10.2 v3.5 — SmallTalk 轻量化(2026-08-14)
**蓝图**:`v3.5-smalltalk-lightweight-blueprint.md`
- 闲聊不再被「无 KB 强制 fallback」锁死(改轻量 prompt)

### 10.3 v3.6 — isSmallTalk 中文后缀正则(2026-08-14)
**蓝图**:`v3.6-issmalltalk-regex-blueprint.md`
- 「你好呀/晚上好呀」正确识别,避免误伤「你好烦」

### 10.4 v3.7 — 云房卡 link 卡片 + KB 大扩充(2026-08-14)
**蓝图**:`v3.7-roomcard-link-blueprint.md` + `v3.7-kb-expand-blueprint.md`
- 长串数字订单号 → 直调 channelOrder/byChannelOrder → 企微 link 卡片
- 订单号正则阈值 10→8 位(覆盖 9 位订单号)+ `is_dir`→`is_file` bug
- KB top200 批量导入(44→148 条,含 62 分类)
- **embedding 切换**:MiniMax → **Qwen dashscope text-embedding-v3**(1024 维)

### 10.5 v3.7.3 — 消息去重(2026-08-14)
**蓝图**:`v3.7.3-dedup-blueprint.md`
- 修复 95001 错误:msgid 缓存 5min

### 10.6 v3.8 — Intent 体系重构 + 品牌统一(2026-08-15)
**蓝图**:`v3.8-intent-refactor-blueprint.md` + `v3.8-brand-unify-blueprint.md`
- IntentRouter 补 `PreSalesWorkflow` case(售前引导失效修复)
- 售前判定提到 KB 之前
- 品牌统一:宿家/橙途/小橙 → **柚光/小柚**(全项目 17 文件 + DB 11 条)
- `UnknownWorkflow` 轻量 prompt(LLM 兜底不再被锁死)

### 10.7 v3.9 — web 端订单查询(2026-08-15)
**蓝图**:`v3.9-web-order-query-blueprint.md`
- web 端订单查询接 `channelOrder`(替代坏网关)
- 拒绝话术:私下交易拒绝 / AI 身份识别 / 隐私查询拒绝(查手机号/他人订单)

### 10.8 v3.10 — 搜索误匹配修复(2026-08-15)
**蓝图**:`v3.10-ngram-fix-blueprint.md`
- 售前类查询不进 KB
- n-gram 2 字 gram 降权(「空房→空调」修复)

### 10.9 v3.11 — handoff 文件清理(2026-08-15)
**蓝图**:`v3.11-handoff-remove-blueprint.md`
- 清理"假删除"的 `admin/handoff.html` / `api/handoff.php` / `Workflow/HandoffWorkflow.php`
- 注意:**线上仍在用,清理需谨慎**(详见 AGENTS.md §4)

### 10.10 v3.14 — 原生小程序云房卡切换(2026-08-18)
**蓝图**:`v3.14-roomcard-native-miniprogram-blueprint.md`
- A 样式原生 miniprogram 卡片(appid + pagepath)
- 替代 link 卡片(link 需"打开小程序"中间页)

### 10.11 v3.15.1 — 恢复可打开的云房卡(2026-08-18)
**蓝图**:`v3.15.1-roomcard-open-regression-blueprint.md`
- 修复 A 卡片打开问题

### 10.12 v3.15.2 — 完成 A 样式生产验收(2026-08-18)
**蓝图**:`v3.15.2-roomcard-a-regression-blueprint.md`
- 真实用户验收 A 卡片
- **`order_query:` 冻结改为宿家直连**(绕开自建网关)

### 10.13 v3.15.3 — 闸门放宽(2026-08-18)
**蓝图**:`v3.15.3-roomcard-gate-relax-blueprint.md`
- 正则 `/^\d{10,30}$/` 改成 `/\b(\d{8,30})\b/`(允许消息含中文夹杂订单号)

### 10.14 v3.15.4 — 图片 OCR 接入(2026-08-18)
**蓝图**:`v3.15.4-roomcard-image-ocr-blueprint.md`
- 客户发订单截图 → 本地 RapidOCR(127.0.0.1:9003)→ 识别订单号 → 走闸门
- **VPS 不接 PII**;PII 仅在本地临时存储

### 10.15 v3.15.5 — thumb 自动刷新(2026-08-18)
**蓝图**:`v3.15.5-thumb-auto-refresh-blueprint.md`
- 缓存文件改 JSON `{media_id, created_at}`
- 60 小时阈值强制重传(企微临时素材有效期 ~72h)

### 10.16 v3.16 — 一单多房 N 张云房卡(2026-08-20)
**蓝图**:`v3.16-multi-roomcard-deliver-blueprint.md`
- 宿家 `cards` 数组有几张就发几张(支持 N≥1)
- 2 张及以上先发文字引导
- `buildRoomCardDeliveries()` + `generateAllRoomCards()` 新增

---

## 附录 B:代码与 PRD 对账表(2026-08-20)

> 接手人对照本表即可判断"哪些 PRD 描述和代码实现一致"、"哪些不一致"。

### B.1 PRD 描述与代码不一致项(必须更新 PRD)

| # | PRD 原描述 | 实际实现 | 状态 |
|---|-----------|----------|------|
| 1 | 订单查询走 PMS 网关 `callGateway('query_order')` | 宿家直连(网关已废) | v3.15.2 |
| 2 | 云房卡 `image_link` link 卡片 | A 样式原生 miniprogram | v3.14 |
| 3 | embedding = MiniMax `embo-01` | **Qwen dashscope text-embedding-v3** | v3.7 |
| 4 | 客服通道只有 `wecom.php`(自建应用)| 双通道:`wecom.php` + `wecom_kf.php`(**主**) | v3.4 |
| 5 | 单卡云房卡 | **一单多房 N 张**(v3.16)| v3.16 |
| 6 | KB 44 条 | **149 条**(v3.7 KB 大扩充)| v3.7 |
| 7 | MiniMax M2-her 主聊天 | **DeepSeek v4-flash** | 已收敛 |

### B.2 PRD 完全没写但代码已实现的

| # | 模块 | 蓝图 |
|---|------|------|
| 1 | 微信客服通道 `wecom_kf.php` | v3.4+ |
| 2 | 云房卡核心逻辑 `wecom_kf_roomcard_v37.php` | v3.4+ |
| 3 | 消息去重 `wecom_kf_dedup.php` | v3.7.3 |
| 4 | 企微 AES 加解密 `wecom_crypto.php` | v3.4+ |
| 5 | 统一订单号提取 + OCR `helpers/order_candidate.php` | v3.15.4 |
| 6 | ChatPipeline / Intent 体系重构 | v3.8 |
| 7 | 本地 OCR 服务(127.0.0.1:9003) | v3.15.4 |
| 8 | thumb 自动刷新(60h) | v3.15.5 |
| 9 | 一单多房 N 张发卡 | v3.16 |

### B.3 PRD 写了但**完全欠账**的

| # | 项 | 影响 |
|---|----|------|
| 1 | 南宁吃喝玩乐长篇攻略 KB | 客户问吃喝玩乐只能短答 + 建议地图核实 |
| 2 | 掌柜统一联系方式 KB | 客户问"怎么联系掌柜"无具体微信/电话 |
| 3 | `kb_documents` 文档 RAG | 表结构在,未启用 |
| 4 | `chat.html` iframe 嵌入测试 | 嵌入代码已生成,测试待补 |
| 5 | 批量导入 1000+ 条 | 当前 ≤1000 |
| 6 | §7.2 人格化验收 3 项 | 整节空白 |
| 7 | SaaS 套餐管理体系 | Phase 3 |
| 8 | AI 自动学习 KB | Phase 3 |
| 9 | Phase 3 基础数据统计(对话量、满意度) | 未做 |

### B.4 当前线上 MD5(2026-08-20)

| 文件 | MD5 |
|------|-----|
| `api/wecom_kf.php` | `4799b02de082ab93e188fc168e6e5ab8` |
| `api/wecom_kf_roomcard_v37.php` | `5c55e522f380ce540c7bdeaec4bb57a5` |

(其他文件本地 == VPS 已自动同步,详见 GitHub commit `5c1a708`)

---

## 附录 C:运维速查

| 操作 | 命令 |
|------|------|
| 重载 PHP-FPM | `systemctl reload php-fpm` |
| 看最近 100 行日志 | `tail -100 /www/wwwroot/aibisskefu/logs/wecom_kf.log` |
| 看 chat_logs | `mysql -uroot -p aibisskefu_com -e "SELECT * FROM chat_logs ORDER BY id DESC LIMIT 20"` |
| 重建 KB | `php scripts/rebuild_knowledge_base.php` |
| 应用行业模板 | `php scripts/apply_industry_template.php homestay` |
| OCR 服务状态 | `systemctl status rapidocr` |
| 紧急回滚某个版本 | `git revert <commit> && scp + reload` |

---

*文档版本:v1.3*
*更新日期:2026 年 8 月 20 日*
*状态:已同步至 v3.16 实现 + 附录 B 对账表*
*关联蓝图目录:`.trae/documents/`(v3.4–v3.16)*
*关联代码:见 §五 与附录 B.4*
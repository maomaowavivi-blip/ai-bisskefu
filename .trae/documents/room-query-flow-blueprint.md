# 房间查询流程改造 — 修改蓝图 v1.1

> **日期**：2026-05-26  
> **状态**：待实施（多房 UI：**方案 A 已确认**）  
> **前置**：Sidecar 接入已完成（`sidecar-integration-blueprint.md`）  
> **铁律**：`.cursor/rules/dev-workflow-soul.mdc`（工作区 → MAMP → 测试 → PRD）

---

## 一、目标与冻结范围

### 1.1 要解决的问题

| 现象 | 根因 |
|------|------|
| 查完订单问「怎么去」仍提示验证 | 房间分支与会话表逻辑不一致 |
| 再发订单号出现「五象大道」 | 掉进 DeepSeek，未走 Sidecar |
| PMS 房名与 Sidecar 对不上 | 用营销标题查库，缺 ID 映射 |

### 1.2 产品设计（已确认）

1. **订单查询 + 云房卡**：保持现有体验，用户查单即展示订单信息与云房卡。  
2. **房间信息查询**（地址/怎么去/WiFi/停车/设备）：**每次进入房间查询，必须再次提供订单号**。  
   - 原因：同一天多单；一单多房。  
3. 房间答案 **只来自 Sidecar**，Sidecar 无命中时不允许 LLM 编造地址。

### 1.3 🔒 冻结区（禁止修改）

以下代码路径 **一字不改**（含逻辑、文案、rich_content、网关调用方式）：

| 冻结项 | 位置 | 说明 |
|--------|------|------|
| `order_query:{订单号}` 整段 | `api/chat.php` ≈ L385–L441 | 前端弹窗查单入口 |
| 其中 `callGateway($db, 'query_order', …)` 的**展示用途** | 同上 | 成功文案 + 云房卡 `rich_content` |
| 云房卡卡片结构 | `yunfangka_image` / `yunfangka_url` | 字段名与组装方式不变 |
| PMS 订单网关配置 | `platform_config` `order.api_*` / 设置页订单网关 | 不动 |
| `callGateway()` 函数本身 | `api/chat.php` | 可继续被其他模块**调用**，但不改函数实现 |

**验收红线**：用现有方式 `order_query:1128148162721995` 测，返回格式、云房卡、HTTP 响应结构与现在 **bit-level 一致**（允许无关 whitespace 差异）。

### 1.4 ✅ 允许修改区

| 模块 | 操作 |
|------|------|
| 房间意图检测后的分支 | **重写**（独立状态机） |
| `room_query_sessions` 表 | **扩展** step 语义 |
| `order_context_cache` 在房间流程中的使用 | **停用或仅只读**，不再自动绑 Sidecar |
| `order_verify_sessions` 与房间的耦合 | **解除**（订单验证弹窗流若存在，与房间流分离） |
| `RoomQueryService` / `resolveRoom` | **增强** PMS→Sidecar 映射 |
| 房间类问题的 LLM fallback | **禁止** |

---

## 二、目标架构（双通道）

```
用户消息
    │
    ├─ order_query:xxx ──────────→ 【冻结】PMS 查单 + 云房卡展示
    │
    ├─ 房间类意图（怎么去/WiFi/设备…）
    │       │
    │       └─→ 【新】RoomQueryFlow 状态机
    │              ① 索要订单号（必做，即刚查过单也要再问）
    │              ② callGateway(query_order) 仅解析 room 列表（不展示云房卡）
    │              ③ 多房 → 让用户选房；单房 → 自动选定
    │              ④ PMS room → Sidecar ai_room_id
    │              ⑤ RoomQueryService 检索（结构化 + 向量）
    │              ⑥ 返回 Sidecar 文本/图片（禁止 LLM）
    │
    └─ 通用 FAQ ─────────────────→ kb_entries + DeepSeek（不变）
```

**关键原则**：房间流 **可以内部调用** `callGateway('query_order')` 取数据，但 **不得**走 `order_query:` 那段展示逻辑，不得再次推送云房卡。

---

## 三、RoomQueryFlow 状态机

复用并扩展表 `room_query_sessions`（已有 `session_id`, `room_id`, `question`, `step`, `order_no`）。

### 3.1 状态定义

| step | 名称 | 等待用户输入 | 系统行为 |
|------|------|--------------|----------|
| `0` | 空闲 | — | 无活跃房间查询 |
| `1` | 待订单号 | 订单号 | 提示：「查询房间信息需要订单号，请发送要咨询的那笔订单号」 |
| `2` | 待选房 | 点击方块 / 兜底打字 | 多房：**聊天气泡内卡片点选**（方案 A）；单房自动 step→3 |
| `3` | 已绑定 | 同房间追问 | 用已绑 `order_no + sidecar_room_id` 继续 Sidecar 查 |

### 3.2 触发规则

**进入房间流（step→1）**当：

- 消息命中 `gateway.room_keywords`（怎么去、WiFi、设备等）
- 且 **不是** `order_query:` 前缀
- 且 **不是** 纯 FAQ 意图

**每次**从 step=0 进入房间意图 → **必须** step=1 索要订单号（即使 `order_context_cache` 里 5 秒前刚查过同一单）。

**同一次绑定内（step=3）**：用户可连续问「停车」「WiFi」不再要订单号；若用户再次触发「查别的房间」或 step 超时 → 回到 step=1。

### 3.3 话术（示例，实施时可微调）

| 场景 | 回复 |
|------|------|
| 首次房间意图 | 「查询房间地址/设施需要订单号，请发送要咨询的那笔订单号～」 |
| 订单无效 | 「未查到该订单，请核对订单号」 |
| 一单多房 | 「该订单包含 N 间房，请选择：」+ **`room_pick` 方块卡片**（见 §3.4） |
| Sidecar 命中 | 直接返回 Sidecar 内容 + 可选图片 |
| Sidecar 未命中 | 「暂未找到该房间的相关资料，请联系前台确认」**（禁止 LLM）** |

### 3.4 多房选择 UI — 方案 A（已确认）

**形态**：在机器人消息下方展示 **可点击方块卡片网格**（与云房卡同属 `rich_content`，不用 Modal 弹窗）。

```
客服：该订单有 2 间房，请选择要咨询的房间：

┌──────────────┐  ┌──────────────┐
│ 1021         │  │ 1509         │
│ 万象城店1021 │  │ 星宿世界1509 │
│ 永凯春晖     │  │ 西乡塘…      │
└──────────────┘  └──────────────┘
```

**交互**：

1. 用户点击方块 → 前端自动发送 `room_pick:{sidecar_room_id}`（不手打序号）
2. 后端收到 `room_pick:` → 校验 step=2 + 订单号 → 绑定房间 → step=3 → Sidecar 回答原问题
3. **兜底**：用户仍可打字 `1` / 房间号 / `room_pick:` 同格式（无障碍 & 旧客户端）

**与云房卡区别**：

| 类型 | `type` | 用途 | 冻结 |
|------|--------|------|------|
| 云房卡 | `image_link` | 订单查询成功 | ✅ 冻结 |
| 选房方块 | `room_pick` | 房间流 step=2 | 🆕 新增 |

**API 响应示例（step=2，多房）**：

```json
{
  "code": 0,
  "data": {
    "reply": "该订单包含 2 间房，请选择要咨询的房间：",
    "room_query_step": 2,
    "rich_content": [
      {
        "type": "room_pick",
        "sidecar_room_id": 657,
        "room_index": 1,
        "room_code": "1021",
        "title": "万象城店1021",
        "description": "永凯春晖 · 民族大道137号"
      },
      {
        "type": "room_pick",
        "sidecar_room_id": 1,
        "room_index": 2,
        "title": "星宿世界1509",
        "description": "西乡塘区…"
      }
    ]
  }
}
```

**前端（`chat.html`）改动要点**：

- 扩展 `appendRichContent()`：识别 `type === 'room_pick'`
- 新增样式 `.room-pick-grid` / `.room-pick-card`（可基于 `.rich-card` 扩展）
- 点击卡片：`sendMessage('room_pick:' + sidecar_room_id)`，可选禁用输入框直至选房
- **不修改** `order_query:` / 订单弹窗 / 云房卡 `image_link` 渲染逻辑

**后端（`RoomQueryFlow`）要点**：

- step=2 持久化候选列表（JSON 存 `room_query_sessions` 新字段 `room_candidates`）
- 校验 `room_pick:{id}` 必须在候选列表内，防止伪造 room_id
- 单房订单：跳过 step=2，不渲染卡片，直接 step→3

---

## 四、PMS 订单 → Sidecar 房源映射

新增 **`api/sidecar/OrderRoomMapper.php`**（或在 `RoomQueryService` 内聚）。

### 4.1 输入

`callGateway('query_order')` 返回的每条 `order_info`，典型字段：

```json
{
  "order_no": "1128148162721995",
  "room": "安静大床房「直面万象城景观」",
  "room_id": "",
  "check_in": "...",
  "check_out": "..."
}
```

### 4.2 映射优先级（依次尝试）

1. `room_id`（PMS 若返回 Sidecar/宝寓可识别 ID）  
2. `baoyu_room_id`（若 PMS 扩展字段有）  
3. `room_code` / 数字房号（从 `room` 字符串提取 `\d{3,4}` 等）  
4. `ai_room_identifier_map.id_value` 精确匹配  
5. **模糊匹配**（可选 Phase 2）：`short_name` / `toponym` 与 `room` 文本相似度，阈值 ≥0.6，低置信度时 **要求用户选房确认**

### 4.3 输出

```php
[
  'sidecar_room_id' => 657,
  'room_code' => '1021',
  'display_name' => '万象城店1021',
  'confidence' => 'high|medium|low',
]
```

映射失败 → 不调用 LLM，返回「无法匹配该订单对应的房源资料」。

---

## 五、文件改动清单

### 5.1 新增

| 文件 | 职责 |
|------|------|
| `api/RoomQueryFlow.php` | 状态机：意图→要订单号→查 PMS→选房→Sidecar |
| `api/sidecar/OrderRoomMapper.php` | PMS order_info → sidecar ai_room_id |

### 5.2 修改

| 文件 | 改动 | 冻结检查 |
|------|------|----------|
| `api/chat.php` | 在 `order_query:` 块**之后**插入 `RoomQueryFlow::handle()`；**删除/绕过**旧房间分支（L495–L735 中房间相关） | `order_query:` 块不动 |
| `api/sidecar/RoomQueryService.php` | 接受 `sidecar_room_id` 直接查询，减少二次 resolve | — |
| `sql/migration_room_query_v3.sql` | 扩展 `room_query_sessions`：`sidecar_room_id`, `room_candidates`, `bound_at`, `expires_at` | — |
| `chat.html` | 新增 `room_pick` 方块渲染与点击（方案 A）；**不动**订单弹窗与云房卡 | 冻结回归 |
| `PRD_AI_CUSTOMER_SERVICE_v1.0.md` | 测试通过后更新 3.3.1 + 验收项 | — |

### 5.3 不修改

- `order_query:` 块（L385–L441）  
- `callGateway()` 实现  
- `api/verify.php` 订单网关保存  
- `chat.html` 订单弹窗 → `sendMessage('order_query:…')` 及云房卡 `image_link` 渲染

---

## 六、与现有表的关系

| 表 | 房间流改造后 |
|----|--------------|
| `order_context_cache` | **订单展示用**；房间流 **不读取** 作免验证依据 |
| `order_verify_sessions` | 仅服务旧「输入订单号查单」弹窗流（若有）；与房间流解耦 |
| `room_query_sessions` | **房间流唯一会话状态** |
| `sujia_ai_sidecar_dev.*` | 只读查询 + 已有向量 |

---

## 七、实施阶段

### Phase R1 — 状态机骨架（1–2 天）

- [ ] 新增 `RoomQueryFlow.php`  
- [ ] 房间意图 → step=1 固定索要订单号  
- [ ] step=1 收到订单号 → 内部 `callGateway(query_order)` 解析（**不展示云房卡**）  
- [ ] 删除旧房间分支对 `order_verify_sessions` / `order_context_cache` 的依赖  
- [ ] **回归**：`order_query:` 云房卡 100% 不变

### Phase R2 — 多房 + 映射 + 方案 A UI（1–2 天）

- [ ] 一单多房 → 返回 `rich_content` `room_pick` 方块列表  
- [ ] `chat.html` 卡片网格 + 点击发 `room_pick:{id}`  
- [ ] `room_candidates` 校验防伪造  
- [ ] `OrderRoomMapper` 实现优先级 1–4  
- [ ] Sidecar 查询接通  

### Phase R3 — 禁止 LLM + 追问（0.5 天）

- [ ] 房间类消息在 step=3 内只允许 Sidecar 出口  
- [ ] Sidecar miss 固定话术，不进入 `callAI()`  
- [ ] 超时 / 换房间 → 重新 step=1  

### Phase R4 — 测试 + PRD（0.5 天）

- [ ] 测试矩阵（见下）  
- [ ] sync MAMP + 更新 PRD  

---

## 八、测试矩阵

### 8.1 🔒 冻结回归（必须先过）

```bash
# 与改造前截图/JSON 对比
curl -X POST 'http://localhost:8888/aibisskefu/api/chat.php?action=chat' \
  -H 'Content-Type: application/json' \
  -d '{"session_id":"freeze-test","message":"order_query:1128148162721995"}'
```

- [ ] `code=0`  
- [ ] 含「查询成功」+ 房间/入住/离店/订单号  
- [ ] `rich_content` 含云房卡图片（若 PMS 有返回）  
- [ ] **响应 JSON 结构与字段名不变**

### 8.2 房间流

| # | 步骤 | 期望 |
|---|------|------|
| 1 | 先发 `order_query:…` 成功 | 云房卡正常 |
| 2 | 再问「房间怎么去？」 | **仍要求订单号**（新话术） |
| 3 | 发同一订单号 | Sidecar 返回真实地址（非五象大道） |
| 4 | 连续问「怎么停车」 | step=3 内不再要订单号 |
| 5 | 再问「Wifi密码」 | Sidecar access 字段 |
| 6 | 换房间意图 / 超时后再问 | 再次要订单号 |
| 7 | 纯发订单号（无房间关键词） | **不走** Sidecar 胡编；引导或走订单流，**不** LLM 编地址 |

### 8.3 多单 / 多房（有测试数据时）

- [ ] 两笔订单分别查房间，互不串单  
- [ ] 一单两房：展示 **2 个方块卡片**，点击后 Sidecar 返回对应 `ai_room_id` 资料  
- [ ] 方块点击等价于 `room_pick:{id}`，无需手打序号  
- [ ] 移动端卡片网格换行正常、可点区域 ≥44px  

---

## 九、风险与对策

| 风险 | 对策 |
|------|------|
| 误改 `order_query:` 块 | Code review + 冻结回归用例 CI 化（可选） |
| PMS 无 `room_id` | OrderRoomMapper 多策略 + 用户选房 |
| 营销房名无法映射 | 低置信度强制 step=2 选房，不猜 |
| 用户嫌每次都要订单号 | 产品已定；step=3 内同房间追问免重复 |

---

## 十、PRD 更新范围（实施后）

| 章节 | 内容 |
|------|------|
| 3.3.1 Sidecar | 增加「房间查询必须二次确认订单号」+ 多房方块点选（方案 A） |
| 3.3 订单 API | 强调订单查询与房间查询 **两条独立流程** |
| 七、验收 | 新增冻结回归 + 房间状态机用例 |

---

*本蓝图遵守：订单/云房卡冻结；Sidecar 已接入；实施走灵魂铁律闭环。*

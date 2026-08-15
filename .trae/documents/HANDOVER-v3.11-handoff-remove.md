# 接手说明:v3.11 删除人工接管整套机制

**接手时间**:2026-08-14 22:50
**接手原因**:改动量大(v3.11 整套删人工接管),中途可能被截断
**接手人**:请按以下顺序完成剩余工作

---

## ✅ 已完成(波尼做完的)

1. ✅ 蓝图写完:`v3.11-handoff-remove-blueprint.md`(审计 3 次修正)
2. ✅ 关键扫描完成:
   - 8 处 grep 触点(共 15+ 文件)
   - KB 5 条转人工话术已确认
   - sidebar 真正位置在 `dashboard.html`
   - `order_verify_sessions` 表无引用,不删
3. ✅ DB 状态确认:
   - `human_handoffs` / `handoff_messages` 存在,有数据
   - `order_verify_sessions` 留着

## 🎯 接手要做的事(按这个顺序)

### Step 1: 改 KB(本地 + 生产 SQL)

```sql
-- 5 条转人工话术改为 400 兜底
UPDATE kb_entries SET answer = '请拨打 400-155-9959 联系管家～' WHERE id IN (400, 401, 402, 403);
-- 386 押金纠纷话术改
UPDATE kb_entries SET answer = '押金退还以携程、美团、去哪儿订单及平台规则为准；如有扣款争议请拨打 400-155-9959 联系管家。' WHERE id = 386;
-- 363 小柚能力描述里的"人工处理"改 400
UPDATE kb_entries SET answer = REPLACE(answer, '续住换房发票投诉等由人工处理', '续住换房发票投诉等请拨打 400-155-9959 联系管家') WHERE id = 363;
```

### Step 2: 改 `api/HandoffTriggers.php`(改写为 400 助手)

- 删掉 `matchKeyword` 返回的 priority 字段
- 删掉 `prune/seed/sync/load/match` 类方法中涉及 `human_handoffs` 的部分
- 保留 `matchesMessage` 函数(供 PromptEngine 调用)— 行为不变
- 或更简单:**保留整个文件不动**,只在 IntentClassifier 里把 HUMAN 路由改成"直接回 400"不走 Workflow

### Step 3: 改 `api/IntentClassifier.php`(转 400)

```php
// 1. 转人工（含 priority，v3.11：直接回 400 兜底）
$match = HandoffTriggers::matchKeyword($ctx['db'], $message);
if ($match !== null) {
    return IntentContext::of(
        Intent::KNOWLEDGE,
        1.0,
        ['four_hundred_redirect' => true],
        'rule:four_hundred_redirect'
    );
}
```

### Step 4: 改 `api/Workflow/KnowledgeWorkflow.php`(识别 fast path)

加一段(类似 v3.9 privacy_refuse):

```php
// v3.11:400 兜底(v3.9 改 handoff 关键词 → 直接 400,不走 LLM)
if (!empty($this->intentCtx->slots['four_hundred_redirect'])) {
    return WorkflowResult::text(
        '请拨打 400-155-9959 联系管家～',
        'KnowledgeWorkflow'
    );
}
```

### Step 5: 改 `api/IntentRouter.php`(移除 HUMAN → HandoffWorkflow 路由)

```php
// v3.11:HUMAN 已改走 KNOWLEDGE 400 fast path,不再路由 HandoffWorkflow
// 删 case 'HandoffWorkflow': ...
```

(注:Intent 枚举里的 HUMAN 常量暂时保留,无害)

### Step 6: 改 `api/PromptEngine.php::filterReply`

第 302 行附近:
```php
// v3.11:移除 handoff 自动标记(现在所有 handoff 关键词都走 400 直答,不再触发 shouldTriggerHandoff)
if (HandoffTriggers::matchesMessage($db, $message)) {
    return '请拨打 400-155-9959 联系管家';
}
```

→ **删除这段**,不再从 filterReply 强制改写

### Step 7: 改 `api/chat_helpers.php`

第 86 行 `shouldTriggerHandoff` + 第 90-130 行 `respondDirectHandoff` → **删除或改为 noop**

### Step 8: 删 `api/Workflow/HandoffWorkflow.php`

直接删除文件。require 引用也要清:
- `api/wecom_kf.php:52` `require_once 'Workflow/HandoffWorkflow.php'`
- `api/IntentRouter.php` 路由 case

### Step 9: 删 `api/handoff.php`

直接删除文件。后台 admin/handoff.html 用它。

### Step 10: 删 `admin/handoff.html`

直接删除。

### Step 11: 改 `admin/dashboard.html`

第 43 行 `<a class="nav-item" href="handoff.html" ...>` → 删掉
第 45 行 `<span class="handoff-badge" ...>` → 删掉
第 181-198 行 `loadHandoffBadge()` 函数 + 定时器 → 删掉

### Step 12: 改 `admin/settings.html`

第 366 行 `<button class="tab-btn" data-tab="handoff">🔄 转人工规则</button>` → 删
第 689-725 行 `tabHandoff` tab 内容 + 按钮 → 删
第 1112/1362/1386/1390/1398/1473/1490/1545/1561-1582/1631/1728 行 → 删
(注意:1728 行 `'转人工 system hint'` 也删)

### Step 13: 改 `api/chat.php`

第 64-84 行 human_handoffs / handoff_messages 的 CREATE TABLE → **删**
(注:order_verify_sessions 保留 — 自动建表但无代码引用,无害)

### Step 14: 改 `api/ReplyRenderer.php`、`api/openapi.php`、`api/agent.php`、`api/IndustryTemplate.php`、`api/RoomQueryFlow.php`

扫所有 `HUMAN`/`HandoffTriggers`/`handoff` 引用,删除已死代码。

### Step 15: DROP DB 表(生产同步)

```sql
DROP TABLE IF EXISTS human_handoffs;
DROP TABLE IF EXISTS handoff_messages;
```

### Step 16: 验证

1. 本地 php -l 全部
2. 本地 curl "我要投诉" → "请拨打 400-155-9959"
3. 部署 + md5 + 同步 DB
4. 线上复测

### Step 17: 推 GitHub

```bash
git add -A && git commit -m "v3.11: 删除人工接管整套机制(转 400 电话兜底)" && git push origin main
```

---

## ⚠️ 关键提醒

1. **改动量大**,建议用 `patch` 工具的 `old_string` 精准替换,别用 sed 批量改
2. **每改一个文件**先 `php -l` 语法检查
3. **DONE-3 个文件**后才部署
4. **DROP TABLE 必须在代码改完后做**,别颠倒
5. **本地 + 生产 DB 必须同步**(苏鸣 2026-08-14 立的铁律)

---

## 📋 文件变更清单(总览)

**新增**:0
**删除**:
- `api/Workflow/HandoffWorkflow.php`
- `api/handoff.php`
- `admin/handoff.html`

**改写**(按 step):
- `api/HandoffTriggers.php`(保留 matchesMessage,其他清空)
- `api/IntentClassifier.php`(HUMAN → four_hundred_redirect slot)
- `api/IntentRouter.php`(删 HandoffWorkflow case)
- `api/PromptEngine.php`(删 filterReply handoff 强制改写)
- `api/chat_helpers.php`(删 respondDirectHandoff)
- `api/Workflow/KnowledgeWorkflow.php`(加 four_hundred_redirect fast path)
- `api/wecom_kf.php`(删 HandoffWorkflow require)
- `api/chat.php`(删 human_handoffs / handoff_messages CREATE TABLE)
- `admin/dashboard.html`(删 handoff 菜单项 + badge JS)
- `admin/settings.html`(删"转人工规则"tab)
- `api/ReplyRenderer.php`/`openapi.php`/`agent.php`/`IndustryTemplate.php`/`RoomQueryFlow.php`(清死引用)

**DB**:
- DROP `human_handoffs`
- DROP `handoff_messages`
- UPDATE 5 条 KB 转人工话术

---

**完成。**
# ai-bisskefu 开发约束

> 写给所有 AI 编程助手和未来接手的人。**这是硬约束,不是建议。**

## 1. 六步开发流程(苏鸣 2026-08-13 定)

所有修改必须走:

1. **修改蓝图** → `.trae/documents/` 落 markdown
2. **审计蓝图** → 实测方案(不能猜),发现 bug 立即修正
3. **修改代码** → 等蓝图审计通过才动手
4. **审计效果** → 本地 `php -l` + 真实调用验证
5. **上传 VPS** → scp + md5 校验,php-fpm reload
6. **推送 GitHub** → commit 不含敏感数据,先 `git status` 检查

**不允许跳步**。蓝图落 `.trae/documents/vX.Y-*.md`,命名带版本号。

## 2. 同步铁律(苏鸣 2026-08-14 强调)

**本地代码 + 本地 DB + 生产 VPS 代码 + 生产 DB 必须同步**。

每次写代码/改 DB 后:
1. 本地 commit
2. SCP 上 VPS
3. 远程 SQL 同步生产 DB
4. md5 校验本地 == 生产

## 3. 凭据处理红线

**严禁把任何真实凭据写入**:
- 源码(api/*.php)
- .env 文件
- commit message
- git 历史
- 截图 / 日志 / 文档

**正确做法**:
- 写到生产 DB `platform_config` 表的 `value` 字段
- 用环境变量传给一次性脚本
- 脚本执行后立刻删除
- 测试响应只显示前缀+后缀(例: `sk-d83...a4c3 len=35`)

## 4. handoff 残留文件现状(2026-10-13 校正)

> **本节已于 2026-10-13 重写**。原「Git 操作禁区」清单基于一个不成立的断言:
> 「`chat.php:25` 仍 require `HandoffWorkflow.php`，删了必死」。
> 2026-10-13 三方只读审计(本地 / GitHub / 生产)实测确认该断言**已失效**，
> 原「Git 操作禁区」清单**全部解除**。

### 三个文件的真实状态

| 文件 | 真实状态 | 唯一残留引用 |
|---|---|---|
| `admin/handoff.html` | **已删除**(v3.19 / `a99c726`, 2026-08-24，苏鸣授权 a 方案)。生产仅存备份 `admin/bak_v318/`、`admin/bak_v319/` | 无 |
| `api/handoff.php` | **410 死壳**。第 9 行 `fail('人工接管已停用', 410)` 无条件拦截，第 11 行起 340 行不可达 | `admin/settings.html:1319/1346/1526`(触发词配置) |
| `api/Workflow/HandoffWorkflow.php` | **主链路零引用死代码**。`api/wecom_kf.php:53`、`api/IntentRouter.php:20` 均注「v3.11 已删除，不再 require」 | `scripts/smoke_test_pipeline_direct.php:52` |

### 清理前置条件(违反会造成功能损失)

- ⚠️ 删除 `api/handoff.php` 前，**必须先确认 `admin/settings.html` 的转人工触发词配置去向**。该页面的 `get_triggers` / `sync_defaults` / `save_triggers` 三个 action 目前挂在该文件上(定义于 `api/handoff.php:260/282/296`)，全仓库无第二个端点。直接删文件 = 后台转人工规则配置**永久报废**。
- ⚠️ 删除 `api/Workflow/HandoffWorkflow.php` 前，**必须先改 `scripts/smoke_test_pipeline_direct.php:52` 的 require**，否则该 smoke 脚本 Fatal。

### 已知问题:后台「转人工规则」配置界面返回 410

`api/handoff.php:9` 的无条件 `fail(410)` 把 `get_triggers` / `sync_defaults` / `save_triggers`
一起拦掉了，**后台可视化编辑触发词当前不可用**。

影响面**仅限后台编辑界面**。运行时匹配走
`HandoffTriggers::matchKeyword()`(`api/HandoffTriggers.php:192`)，直读 `handoff_triggers` 表，
**转人工 → 400 电话兜底不受影响**。修复方案另开蓝图。

### 当前转人工怎么走(链路未变)

客户消息 → IntentClassifier → `HandoffTriggers::matchKeyword()`(p0~p3 关键词)
  → Intent::KNOWLEDGE + `four_hundred_redirect=true`
  → KnowledgeWorkflow 检查标记 → 直接回复 "请拨打 400-155-9959"

**完全不依赖上述任何文件**。`HandoffTriggers.php` 是实际入口，不是 handoff 三件套之一。

### Git 操作说明

原「禁止 `git rm` 三个文件 / 禁止 `git add -A`」清单**已解除**。
但仍需遵守:

- 改文件时**显式 `git add <路径>`**，不要 `git add -A`。工作区常有未跟踪的设计文档
  (如 `v3.8-miniprogram-*.md`、`v3.15.5-miniprogram-roomcard-direct-spec.md`)，`git add -A` 会误带进提交。
- 删除任何生产在用的文件，必须先按本节「清理前置条件」核对引用链，并走独立蓝图。

### 本节不做的事

- 不删除任何 handoff 文件(清理另开蓝图)
- 不修 410 问题(修复另开蓝图)
- 不动生产备份 `admin/bak_v319/` 等

---
## 5. KB 向量化相关(2026-08-15)

- **embedding provider**:`dashscope`(阿里云 Qwen `text-embedding-v3`,1024 维)
- **batch 上限**:**10 条/次**(实测确认)
- **配置位置**:DB `platform_config`(`ai.api_key.qwen_embedding` / `ai.embedding_api_url` 等 4 项)
- **禁止**:用 MiniMax `embo-01`(token plan 不含 embedding 余额)
- **禁止**:写 .env / 源码 / commit(密钥泄露)
- **维度防御**:`cosineSimilarity` 已加维度检查,维度不一致返 0
- **全文检索对 PDO 连接方式敏感(2026-10-21 踩坑)**:`PromptEngine::searchKnowledge()` 的 SQL 带 `LIMIT ?`,
  只有**原生预处理**(`ATTR_EMULATE_PREPARES => false`,同 `api/config.php:75`)能执行。
  用 PDO 默认的 emulated 模式会把参数插值成 `LIMIT '3'`,MySQL 8.3 报 `1064 语法错误`(日志里叫
  `FULLTEXT search error`,**与 FULLTEXT 索引无关,别误判成"生产索引坏了"**)。
  写临时脚本调这个函数时,连接必须显式带上 `ATTR_EMULATE_PREPARES => false`。
  生产 `getDB()` 一直是原生预处理,线上全文检索正常(生产日志该错误 0 次)。
- **未跑向量化** = 部分 KB 搜不到答案

## 6. 蓝图命名规范

格式: `v<主版本>.<次版本>-<功能>-blueprint.md`
示例: `v3.7-kb-vectorize-dashscope-blueprint.md`

存于: `.trae/documents/`

## 7. commit message 规范

格式:
```
v<版本>: <一句话概括>

- 改动点 1
- 改动点 2
- ...
```

**禁止**在 commit message 中粘贴:
- API key 原值
- 真实订单号 / 手机号 / 身份证号
- 任何 PII 数据

## 8. 测试响应脱敏

curl / 测试时:
- ✅ 显示: `sk-d83...a4c3 len=35`
- ❌ 不要显示: `sk-d8324dc69f444311b329530722caa4c3`

## 9. 不要做

- 不要凭空写代码 — 必须先出蓝图
- 不要"我先试试" — 试之前问苏鸣
- 不要 5 步以上的全自动部署
- 不要在聊天回复里展开"我接下来做什么" — 直接做,简短报告
- 不要替苏鸣拍板 a/b/c/d(除非他明确说"你来决定")

## 10. 转人工兜底话术（2026-10-21 v3.24 起可后台配置）

- **配置键**:`platform_config.agent.handoff.reply`,默认 `请拨打 400-155-9959 联系管家～`
- **改法**:管理后台 → 设置 → 「AI 行为规则」→ 「转人工兜底话术(可编辑)」→ 保存 → **下一条消息立即生效,无需 reload**
- **唯一取值入口**:`PromptEngine::handoffReply()`(`api/PromptEngine.php`)。全仓任何地方都不要再硬编码这句
- **读值必须用 `pcGet()` 直查 DB**,不能用 `AgentConfig::get()`——后者是进程级静态缓存、无 TTL、无跨进程失效,
  php-fpm 多 worker 下后台保存后其它 worker 仍读旧值(表现为"保存了没生效")。`AgentConfig::getFresh()` 是为此加的旁路
- **生效范围**:H5 / 微信客服 / 企业微信 / openapi 全渠道一致(主链路 `KnowledgeWorkflow`、openapi `filterReply`、
  system prompt 两处注入、LLM 分支终检保留,四处同源)
- **prompt 里的 `{reply}` 占位符**:`agent.rules.handoff_system_hint` 用 `{reply}` 代表当前话术,
  代码在拼 prompt 时替换。**不要再往这个配置值里写死号码**,否则换话术后 prompt 会说旧号码
- **支持多行话术**(v3.25):后台 textarea 提交的是 CRLF,`handoffReply()` 统一归一化成 LF 再返回,
  避免微信客服纯文本出现不可见的 `\r`。**客户实际收到多行版,system prompt 注入用单行折叠版**
  (`handoffReplyOneLine()`),否则引号内换行会破坏「只回复"…"」的指令结构
- **终检保留判定已放宽为三级**(v3.25):整句包含 / 任意一行包含 / 关键数字片段(≥5 位数字)包含。
  多行话术被模型压成一行、或只复述了其中一个号码时,都不会再被拒答句吃掉
- **不归本配置管的**:`kb_entries` 里的历史话术、`RoomQueryWorkflow.php:34` 的房间查询话术(要去后台知识库单独改)
- 背景与取舍见 `.trae/documents/v3.24-handoff-reply-config-blueprint.md`

## 11. 出蓝图前必查的事

1. **接口是否真存在?** `curl` 实测
2. **认证方式是什么?** Bearer? query string?
3. **返回格式是什么?** JSON 字段
4. **线上 vs 本地差异?** `cat /etc/hosts`、`git status`、`mysql` 对照
5. **依赖的字段/方法是否真存在?** 不要猜

---

**最后更新**:2026-10-23 by 波尼(v3.28 云房卡主链路切到中台房卡接口)
## 12. 数据中台(新) — v3.26 起取代旧网关(2026-10-22)

### 接口与鉴权

基址 `https://center.youguang8488.com/api/relay`,**GET + query string** 鉴权:

| 用途 | 端点 | 过滤参数 | 返回 |
|---|---|---|---|
| 订单查询 | `s4e1a8` | `channel_order_id` | `data.total` / `data.list[]` / `data.sum_price_paid` |
| 房源 | `s4e3a9` | `baoyu_room_no` 或 `id` | `data.list[]` |
| 间夜列表 | `s4e2a10` | `room_id` | `data.list[]` |

- **公共参数**:`username` + `password` + `tenant_id`(固定 `1`)
- ⚠️ 参数名是 `password` **不是** `pwd`;不是 header、不是 POST body
- 分页:`page` + `limit`
- 客户端:`api/CenterApi.php`(统一封装 + 错误日志)

### 配置键(存生产 `platform_config`,不进源码/.env/commit)

`center.api_url` / `center.username` / `center.password` / `center.tenant_id`

后台:管理后台 → 企业集成 → 「数据中台配置」

### ⚠️ `sum_price_paid` 的坑

它**不是订单级字段**,是「当前命中集合的客户实付合计」。
按 `channel_order_id` 过滤后 `total=1`,此时该值才是该单实付额(已用 3 个真实订单与旧接口 `price_paid` 逐笔核对一致)。
客户端在 `total>1` 时会打告警日志。

### ID 映射链(已闭环验证)

```
订单.room_ids(逗号分隔,可多房) = 中台 baoyu_room_no = sidecar ai_room_profile.baoyu_room_id
中台房源.id = sidecar ai_room_profile.sujia_room_id
```

- 优先查 sidecar `baoyu_room_id`(覆盖 650/921,纯 DB 命中,不外呼)
- 未命中再调中台房源接口拿 `id`,再按 `sujia_room_id` 精确查
- ⚠️ **不能用宽匹配**:sidecar 有 577 行 `id` 与他人 `sujia_room_id` 撞号(如 `id=1` 的 `sujia_room_id=127`,而 `id=127` 是另一间房)。必须走 `resolveBySujiaRoomId()` 精确查

### 已下线的旧链路(v3.26 全部删除)

| 旧链路 | 状态 |
|---|---|
| `http://119.91.30.52:8081/sujia/room/card/service`(自建 RuoYi 网关) | 401,已删 `callGateway()` + 配置 |
| `http://192.168.3.14:8081/sujia/room/card/order_no/get` | 内网不可达,配置已删 |
| `apicenter.sujia365.com/index.php/openapi/order/channelOrder` | 订单查询已切走,**云房卡仍在用**(见下) |

### 云房卡(2026-10-23 v3.28 已切中台)

`api/wecom_kf_roomcard_v37.php` 主链路已改走中台房卡接口:

```
GET https://business.youguang8488.com/api/relay/room_card/byOrder
    ?username=&password=&channel_order_id=
```

- 凭据复用 `center.username` / `center.password`(与订单/房源同一账号)。**新接口只认中台密码**,
  旧宿家密码返回 `账号或密码错误`;`tenant_id` 可选
- 端点存在 `platform_config.roomcard.api_url`,换接口改配置即可,不用改代码
- 响应 `data.cards[]` = 一单多房全部房卡(实测订单 `123456789` 返 2 张:roomId 1374 / 1437),
  **不返回** appid / path / urlLink → appid 取 `ai.miniprogram.appid`,
  pagepath = `ai.miniprogram.pagepath` + `.html?qrCodeId=`(与旧宿家 share_bundle 同构)
- 旧宿家接口(`apicenter.sujia365.com` + `roomcard.username` / `roomcard.password`)**保留作回退**:
  新接口 HTTP 失败或 `code=0` 时自动回退,日志打 `[roomcard] fallback to legacy sujia api`
- 新接口 `code=1` 但 0 张卡时**不回退**(中台说没有就是没有,避免旧接口生成旧卡)
- 蓝图:`.trae/documents/v3.28-roomcard-center-relay-blueprint.md`

### 房间流续接(v3.26 修复的结构性缺陷)

旧代码 `IntentClassifier` 把任何裸订单号判为 `ORDER_QUERY`,而房间流 step=1 等的正是订单号 → 房间流永远进不了 step=2,
生产上「地址/停车/垃圾/设备」100% 查不到(迁移前后都一样,非迁移引入)。
现规则:会话 `room_query_sessions.step=1` + 消息含订单号 + 不含 `order_query:`/订单/查单 → 判 `ROOM_QUERY`。
依据 `.trae/documents/room-query-flow-blueprint.md` §3.1 与验收清单。

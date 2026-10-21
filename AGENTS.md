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
- **不归本配置管的**:`kb_entries` 里的历史话术、`RoomQueryWorkflow.php:34` 的房间查询话术(要去后台知识库单独改)
- 背景与取舍见 `.trae/documents/v3.24-handoff-reply-config-blueprint.md`

## 11. 出蓝图前必查的事

1. **接口是否真存在?** `curl` 实测
2. **认证方式是什么?** Bearer? query string?
3. **返回格式是什么?** JSON 字段
4. **线上 vs 本地差异?** `cat /etc/hosts`、`git status`、`mysql` 对照
5. **依赖的字段/方法是否真存在?** 不要猜

---

**最后更新**:2026-10-21 by 波尼(v3.24 新增第 10 节 转人工兜底话术配置说明)
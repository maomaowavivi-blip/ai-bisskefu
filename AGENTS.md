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

## 4. ⚠️ 不要删除的"假删除"文件(2026-08-15 苏鸣特别提醒)

以下 3 个文件 **线上仍在运行**,但本地 git 可能曾显示为 `D` 状态:

| 文件 | 作用 | 状态 |
|---|---|---|
| `admin/handoff.html` | 后台人工接管页面 | 线上存在,**生产在用** |
| `api/handoff.php` | 后台 API 入口 | 线上存在,**生产在用** |
| `api/Workflow/HandoffWorkflow.php` | 工作流逻辑 | 线上存在,**chat.php:25 在 require** |

### 为什么不能删?

- v3.7 之前本应清理,但**线上还在跑** — 删了会让 `chat.php` require 报 Fatal,所有客户对话立刻挂掉
- 当前线上 `chat.php:25` 仍然 `require_once HandoffWorkflow.php`,删了必死
- 清理必须单独走 v3.8+ 蓝图(改 chat.php:25 + IntentRouter.php:73-74 引用后),不能 git rm

### Git 操作禁区

**禁止**以下操作(直到 v3.8+ 蓝图清理完成):
- `git rm admin/handoff.html`
- `git rm api/handoff.php`
- `git rm api/Workflow/HandoffWorkflow.php`
- `git add -A`(可能误带 D 类 — 但本次已恢复,理论上不再有 D 类,但仍要小心)
- 把这些路径加入任何 commit 的删除变更

### 当前转人工怎么走?(供参考)

客户消息 → IntentClassifier → `HandoffTriggers::matchKeyword()`(p0~p3 关键词)
  → Intent::KNOWLEDGE + `four_hundred_redirect=true`
  → KnowledgeWorkflow 检查标记 → 直接回复 "请拨打 400-155-9959"

**完全不依赖已"假删除"的 3 个文件**。但既然线上还在,就不能真删。

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

## 10. 出蓝图前必查的事

1. **接口是否真存在?** `curl` 实测
2. **认证方式是什么?** Bearer? query string?
3. **返回格式是什么?** JSON 字段
4. **线上 vs 本地差异?** `cat /etc/hosts`、`git status`、`mysql` 对照
5. **依赖的字段/方法是否真存在?** 不要猜

---

**最后更新**:2026-08-15 by 波尼(基于 v3.7 实战教训)
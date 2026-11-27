# QA 验收报告

VERDICT: PASS WITH RISKS

## 元信息

| 项 | 值 |
|---|---|
| 项目路径 | /Users/smiler/Documents/GitHub/ai-bisskefu |
| Commit | 26cd3ea（v3.35.1，HEAD） |
| 分支 | HEAD（detached 状态未检查，工作区含未提交补丁） |
| 本轮改动范围 | api/Workflow/OrderQueryWorkflow.php:22-23（require）+ :61-72（提取分支） |
| 验收时间 | 2026-11-27 |
| QA 环境 | macOS / PHP 8.5.12 CLI（本地，未触碰生产） |

## 版本核对

- `git log --oneline -1`：`26cd3ea v3.35.1: 修复纯数字房号触发的 TypeError（未到入住查单 500）` ✅ 与派单一致
- `git status --short`：**脏**。`M api/Workflow/OrderQueryWorkflow.php` —— 补丁已按派单描述**原样应用到工作区但未提交**（git diff 与方案逐行一致：require + `extractOrderCandidate` 分支，前缀分支未动）；另有未跟踪 `scripts/test_v335_mixed_order_input.php`。
- ⚠️ 声明：本次验收对象 = 工作区中已应用补丁的代码，与派单描述的补丁完全等价。

## 验收标准来源

- 派单描述（7 个问题）+ v3.35 安全垫承诺（AGENTS/蓝图 §5.1 单一守门点）

## 复测记录

| # | 验收标准 | 测试方法 | 执行命令/操作 | 原始输出（关键部分） | 结论 |
|---|----------|----------|---------------|---------------------|------|
| 1 | 正则对中文边界有效 | php -r 直跑 require 真实 helper | `preg_match('/\b(\d{8,30})\b/','美团123456789')` | `no-u match: 1 got=123456789` | ✅ |
| 2 | 反例：加 /u 会坏 | 同上 | 同正则加 `/u` 修饰符 | `with-u match: 0`（UCP 下汉字=\w，\b 失效） | ✅（并暴露脆弱点） |
| 3 | 19 个边界输入提取行为 | php -r 批量对照 cand/side/looks | 「房间 10002」→cand=''；「2026-11-27」→cand=''；「查一下123456789」→cand=123456789 looks=0；「ABC12345678」→cand=''(保原文)；全角数字→cand='' | 见正文边界矩阵 | ✅ |
| 4 | 端到端（workflow 级）+ 提取回归 | 跑 scripts/test_v335_mixed_order_input.php（本地 DB+中台真 HTTP） | `php scripts/test_v335_mixed_order_input.php` | `16 passed, 0 failed`（含「美团/查单/查一下123456789」查到订单、房型正确、order_query: 前缀正常、纯文字仍 ORDER_NOT_FOUND、三段配置空=安全垫前提检查） | ✅ |
| 5 | 前缀分支不变 | git diff + 代码读 | diff 中 `preg_match('/^order_query:(.+)$/u')` 分支零改动 | diff 确认 | ✅ |
| 6 | 路由单一入口（chat.php 无需改） | 读 chat.php + grep | chat.php:74 唯一走 ChatPipeline；旧逻辑 PR4 已删（chat.php 197 行，grep `order_query` 0 命中；pipeline 关→503）；IntentRouter.php:31 ORDER_QUERY→OrderQueryWorkflow 唯一实例化点(:65) | ✅ |
| 7 | original_message 是原文 | ChatPipeline.php:134 | `$intentCtx->slots['original_message'] = $message;`（$message 为入口原文） | ✅ |
| 8 | 选房轮/房间流不受影响 | 读 OrderGuidePick::tryHandle + IntentClassifier:150-172 | 选房轮在 ChatPipeline:121 **先于意图**拦截、有查单逃生门；房间流续接是分类器 step 3.5 **先于** step 4 ORDER_QUERY | ✅ |
| 9 | 发卡/文本口径一致 | 读 wecom_kf.php:321,386,391 | 卡用 `$orderCandidate`(:391)；文本经 Pipeline→workflow 现也用同一函数、同一 `$extractedText` 原文 | ✅ |
| 10 | require 无冲突 | 读 order_candidate.php:17,38 + require_once realpath | `function_exists` 双守卫；wecom_kf 与 workflow require_once 同一路径天然去重 | ✅ |
| 11 | 空订单号引导 | 代码推演 + 实测 looks | `order_query:` 无单号：(.+) 不匹配→走 else→extract=''→**保原文**「order_query:」→中台查不到→ORDER_NOT_FOUND（与补丁前逐字节一致）；引导话术(:74-79)仅 `order_query:␣` 触发，前缀分支未动 | ✅ |
| 12 | 配置全空安全垫 | 代码读 | 补丁只改 :61-72（守门点之前），lead==='' 守门点(:182-184)零接触；测试脚本显式校验三段配置为空 | ✅ |

## 边界矩阵（问题5）

| 输入 | 补丁前（workflow 收到时） | 补丁后 | 判定 |
|---|---|---|---|
| 房间 10002（5位） | extract=''→原文 | 同左；且 looks=0 根本进不了 ORDER_QUERY | 无误伤 |
| 2026-11-27 | 原文查→NOT_FOUND | extract=''（连字符断串）→原文，同左 | 无误伤（looks=1 判 ORDER_QUERY 是旧债，非本轮） |
| 查一下123456789 | — | **意图层**：9位数字+3汉字 looks=0 且不含「查单」→不进 ORDER_QUERY（旧债，补丁不修）；≥10位真实订单号 looks=1 正常进→提取成功 | 生产号(10-19位)修复生效；8-9位+≥3汉字残留缺口 |
| 123456789 987654321 | looks=0 不进 workflow | 若经关键词进入→取**首个**数字查询 | P3：可能展示非用户所指的第一个订单（发卡链路同口径，卡文一致） |
| 美团订单13800138000（手机号） | 原文查→NOT_FOUND | 抠手机号→中台查不到→NOT_FOUND | 等价，无变化 |
| 我订了8月1日到8月3日的房间12345678 | looks=1→原文查→NOT_FOUND | 抠 12345678 查单→若巧合命中则展示无关订单 | P3 低概率误伤（真实号 10-19 位，8位连号撞上概率极低） |
| ABC12345678（字母数字单） | 原文整条查 | extract=''→保原文，行为不变 | ✅ 优于 SidecarIntent 版（后者会抠 substring 12345678 反而错） |
| １２３４５６７８９（全角） | 原文查→NOT_FOUND | 同左（\d 不认全角） | 无变化，残留缺口 |

## 发现

### [P3] `\b` 提取只在「无 /u 修饰符」下成立
- 证据：`with-u match: 0`（PHP 8.5 PCRE2 UCP 下汉字算 \w，「美团」与数字之间无词边界）。helper 现无 /u 故正确，但注释「\b 对中文有效」缺前提说明，后人加 /u 会**静默**破坏中文前缀提取（order_candidate.php:29-31）。
- 建议：helper 注释补一句「勿加 /u，否则 UCP 下 \b 对中文失效」；现有回归用例「中文前缀美团→抠出数字」已能兜住，建议保留为常驻回归。
- 分类：旧债（注释层面），补丁本身不受影响。

### [P3] 多段数字消息取首个数字
- 见边界矩阵；与发卡链路口径一致（都取首个），卡文不再矛盾。建议单测固化。旧债外的补丁固有属性，P3。

### [P3] 意图层缺口未修（不在本补丁范围）
- 「查一下 + 8~9位数字」（looks=0）与全角数字消息仍进不了/查不动 ORDER_QUERY；生产订单号 10-19 位故实际影响小。旧债，交小优决定是否立后续单。

### 无 P0/P1/P2。

## 未覆盖项

- 完整 H5 管线级（chat.php HTTP 全链路含意图路由）e2e：测试脚本的「端到端」是 **workflow 级直调**（test:60-61 手造 IntentContext，绕过 IntentClassifier/IntentRouter），「查一下12345789」全管线结论来自我对分类器代码的静态推演（looks=0 已实测），非全链路实跑——标注为推演，置信度高（分类器规则链 step 3.5→4→7→8 为纯规则、无 LLM 兜底，IntentClassifier.php:172-177）。
- 微信客服真机（企微回调）未模拟；两链路共用同一提取函数+同一原文已代码级确认。
- 测试脚本跑本地 DB/中台在 order_context_cache/session_order_links 留下 `mix_test_*` 会话行（24h TTL 自然过期），未手工清理。

## 最终状态

**PASS WITH RISKS**

理由：补丁方案与已应用代码等价，12 项复测全过（含真实中台 e2e 16/16）；前缀分支、选房轮、房间流续接、空单号引导、ORDER_NOT_FOUND、配置全空安全垫六条红线均确认零影响；发卡/文本两条链路口径统一。已知风险全部 P3（首数字歧义、\b 无 /u 脆弱点、8-9位+≥3汉字及全角的意图层残留），不阻断上线。

## 自验证清单

- [x] 每条结论都有实际执行证据（php 实测/git diff/代码行号）
- [x] 未实际执行的全管线项已标注为推演，未写入"已通过"
- [x] FAIL 项：无；夹具问题已排除（安全垫配置为空有脚本显式校验）
- [x] 新旧问题已区分（looks 阈值、日期判单等为旧债）
- [x] 报告中无编造内容
- [x] 订单查询（核心交付链路）按高密度复测

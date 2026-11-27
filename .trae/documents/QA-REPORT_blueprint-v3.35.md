# QA 蓝图审计报告：v3.35 订单查询分时段回复 + 入住指引送达

VERDICT: FAIL（蓝图需修订：4 个阻断级设计缺陷 + 1 个部署缺口，修订后可复审，预计一轮即过）

## 元信息

| 项 | 值 |
|---|---|
| 项目路径 | /Users/smiler/Documents/GitHub/ai-bisskefu |
| 基线 Commit | 2199631（v3.34.1，与蓝图声明一致，已实测核对） |
| 本地 md5 | OrderQueryWorkflow.php=7cb0519b3f6a29646f719e5708da7d58，wecom_kf.php=561d34d6a357bb07677b8bcc558d3554（与蓝图 §前言一致） |
| 验收时间 | 2026-11-27 |
| 审计性质 | 蓝图审计（代码未写，全部证据 = 静态读码 + 蓝图内已载生产实测；无运行时执行） |
| git status | 仅未跟踪设计文档（含本蓝图），无脏代码 ✓ |

## 一、问题清单（阻断 → 重要 → 建议）

### 阻断（写代码前必须在蓝图中解决）

**A1. 5 条预算漏算了「多房发卡引导文字」**
- 问题：现行订单消息在发卡数 ≥2 时额外发一条「您一共订了 N 间房…」文字。入住期间 2 卡 + 视频 = 订单信息1+引导文字1+卡2+视频1 = **5 条贴线**；3 卡 = **6 条超限**（官方 5 条硬限）。蓝图 §5.2 表中「有卡=3 条」只对单卡成立。
- 证据：api/wecom_kf.php:419-426（`if ($cardCount >= 2) sendKfMessage(...您一共订了 %d 间房...)`）；官方 5 条/轮（蓝图 §3.1 自引）。
- 连带：未到入住**多房**那一轮，房卡原因消息（中台「入住当天…」）归属未定义（§5.2 多房表只列 M1）。
- 建议改法：预算公式改为 `1(订单信息) + [cardCount≥2]引导1 + N卡 + [guide场景]指引/视频`；>5 时的降级策略写死（优先丢视频或并指引入 M1）；补「多房未到入住轮次的房卡原因去哪」一行。

**A2. 客人回房号那一轮与 `$isOrderMessage` 分支冲突**
- 问题：extractOrderCandidate 把**任意 8–30 位连续数字**当订单候选。房号若 ≥8 位，pick 轮在 wecom_kf 会进房卡分支：客户会收到「未找到该渠道订单号，请核对后重试」（中台按订单查不到房号）这条原因消息，且 §7.6.4 的图片发送逻辑挂不进房卡分支。
- 证据：api/helpers/order_candidate.php:31（`\b(\d{8,30})\b`）；api/wecom_kf.php:323-324、373；房号位数（样本仅 5 位 10002）本地无法向中台求证（IP 限制）。
- 建议改法：蓝图明确「OrderGuidePick 命中并返回 order_guide 时，本轮跳过房卡链路」；或 pick 轮不跑 extractOrderCandidate。同时把 pick 轮的图片发送插入点写到具体代码位置（wecom_kf.php:489/496 文本路径处）。

**A3. 选房等待期（24h）吞掉一切消息：无转人工/退出/换订单逃生门**
- 问题：stage=1 期间任何不匹配消息先被吞去重问选房问句。转人工关键词、寒暄、甚至「发另一笔订单号查单」都会先挨一次选房追问。RoomQueryFlow 在 step=2 有 handoff 逃生门（handleStep2Pick 先 `handoffIfTriggered`）且 TTL 仅 30 分钟；蓝图声称「照抄 RoomQueryFlow 已验证逻辑」，但两条都没抄：无 handoff/exit 检查、TTL 反而放大到 24h。
- 证据：api/RoomQueryFlow.php:156-159、348-354（handoff 优先）；RoomQueryFlow.php:10（TTL 30min）；蓝图 §5.4/§7.5 均未提。
- 建议改法：tryHandle 在匹配前先放行：转人工/退出词、含 `order_query:|订单|查单` 或合法订单号的消息（放行时清 stage）；TTL 降为 2–4h 或给出 24h 的理由。

**A4. 「最多提示 1 次」没有计数器可存**
- 问题：§5.4 规定匹配失败重问「最多 1 次后放弃转正常流程」，但 `order_guide_sessions` DDL 没有 attempts 字段，规则无法按文实现。
- 证据：蓝图 §5.4 表结构（session_id/order_no/stage/room_candidates/expires_at…）。
- 建议改法：加 `attempts tinyint NOT NULL DEFAULT 0`，或改成「第二次不匹配即放弃」的明确 SQL 判据。

### 重要

**B1. 安全垫守门写法含糊：payload 门与 wecom 门必须同源**
- §7.1.4 载荷恒带 `stage`，若三段全空仍 set `extra['order_guide']`（非空数组），ReplyRenderer:307 的 `!empty` 会把字段透出，wecom 层必须二次判 lead——两处判、两种写法，正是安全垫最容易破的缝。另 §7.6.3 入住期间「发卡成功后追加视频」漏写「during 文字非空才发」的门（只在 §5.1 总则里）。
- 建议：workflow **仅当当前场景 lead 非空才 set extra**（单一守门点），§7.6.3 视频分支补「且 during 文字非空」；验收加「三段全空时 API 响应 JSON 与 v3.34.1 快照逐字节 diff」自动项。

**B2. `checkIn/checkOut 为空 → 'during'` 与自身注释矛盾，格式异常无定义**
- 注释说「不发指引」，但按 §5.1，stage=during 且 during 文字非空 → 发 lead+视频，行为就变了。格式：现有代码用 `strtotime($check_in)`（OrderQueryWorkflow.php:74），证明字段未必是纯日期串；蓝图 substr 前 10 位对 `2026/11/27` 之类格式会得到垃圾串与 CURDATE() 字符串比较（`'-'`<`'/'`，全错）。
- 建议：先 `preg_match('/^\d{4}-\d{2}-\d{2}$/')` 校验 substr 结果，空/畸形 → 中性 stage（什么都不加），不进三个场景。

**B3. sendKfImage/VideoMessage 返回 bool，40007/40008 自愈拿不到 errcode**
- 现有 40007 自愈依赖 Ex 式返回（wecom_kf.php:439 `if (!$result['ok'] && $result['errcode'] === 40007)`）。§7.6.1 把新函数定义成 `: bool`，§7.4 又要求按 40007/40008 自愈——自相矛盾。
- 建议：定义为 `['ok'=>bool,'errcode'=>int]`（对齐 sendKfMiniprogramMessageEx 先例）。

**B4. 文本长度无上限规则**
- lead 后台随便写：M1 = lead+订单信息(+房卡原因) 合并后可超企微 text 限制；M2 合并指引「≤600 字」与「文字一步不漏」冲突时无拆分规则；wecom_kf.php:362 的 mb_substr(600) 只截 pipeline 文本，通道层新拼的消息不经此点。
- 建议：后台保存时限 lead ≤200 字；M2 超 600 → 拆两条且图片上限同步降为 ≤1；§5.3 加一行「超长指引」行为。

**B5. 全局 flock 阻塞锁内新增媒体 IO，最坏持锁 ~2 分钟**
- processKfEvent 用阻塞 `flock(LOCK_EX)`（wecom_kf.php:243-249）。新增 3 图（下载 10s+上传 30s 各）+ 视频 10MB 上传都在锁内，其它客户回调排队。
- 建议：图片下载/上传超时降到 5s/15s、单轮媒体预算封顶（如 20s，超时放弃剩余图片）；验收加双人并发下单响应延迟实测。

**B6. 视频上传的生产通道限制未进发布清单（可能直接 413）**
- mp4 4–10MB 要过 nginx（默认 client_max_body_size=1M）与 php-fpm（upload_max_filesize/post_max_size 默认 2M）。蓝图 §10 发布步骤只有 scp+CREATE TABLE+reload；生产 nginx conf 的 body size 本地无法核实。
- 建议：发布步骤加「远程 `nginx -T | grep client_max_body_size` 与 php ini 确认，必要时加 location 级 12m + pool ini，备份→nginx -t→reload」；这是验收 9.4 的前置条件。

**B7. verify.php 四个新 action 未写鉴权与上传防护**
- 先例 save_center_config 等全部 `adminGuard()`（config.php:164）。蓝图 §7.7 未写 adminGuard；上传需按 persona.php:81 的 $_FILES 模式（getBody 只解析 JSON，config.php:110），并加 finfo MIME 校验 + 文件名 sanitize（防 `x.mp4.php`/路径穿越）。
- 建议：4 个 action 全部 adminGuard；上传白名单 .mp4 + finfo video/mp4 + 随机化存储文件名。

**B8. 试传 access_token 的依赖未理清**
- getAccessToken() 定义在 wecom_kf.php:828，而 wecom_kf.php 顶层即路由（:94-103），verify.php 或 media.php require 它会直接执行回调路由。§7.4 只说「可提取共用」。
- 建议：蓝写明写「token+upload 函数提取到中立 include（只依赖 config.php），wecom_kf.php 改为 require 它并保留原函数名」，并把「thumb 链路不变」列入回归项（蓝图已提，落成勾项）。

**B9. 匹配规则「照抄 RoomQueryFlow」名实不符，且 display_name 包含可歧义**
- RoomQueryFlow 纯数字=房号优先再序号（RoomQueryFlow.php:170-185）、非数字=room_code 包含（:186-193）；蓝图写「序号→房号→display_name 包含」，顺序相反且 display_name 是新维度。「和德」同时包含于「和德新天地/和德公馆」→ 首中即选 → 把错房的指引发给客人。
- 建议：display_name 命中多于一间 → 视为失败重新问；匹配规则伪码写进蓝图，单测覆盖歧义用例。

**B10. 过期时钟只统一了一半**
- 写入用 MySQL NOW()（CST 挂钟），读取若照 RoomQueryFlow::isExpired 的 strtotime+time()（:383-387，PHP=UTC）会产生 8h 偏差。orderStage 自称纯函数却内嵌 SELECT CURDATE()。
- 建议：读写一律 SQL（`WHERE stage=1 AND expires_at > NOW()`）；orderStage 改为传入 `$today` 参数（SQL 调用点取 CURDATE()），本地单测即可覆盖凌晨边界。

**B11. 指引/视频送达不写 chat_logs——房管端又瞎了（v3.34 同款缺口）**
- OrderGuidePick 提前 return，不走 ChatPipeline::writeChatLog；图片/视频发送也无事件行。v3.34 专为发卡补了 writeRoomCardChatEvent（roomcard_v37.php:176），本蓝图未对齐。
- 建议：指引/视频发送写 assistant 事件行（「已发送入住指引：文字+3图」「已发送入住指引视频」），全 catch helper；§9.5 加验收。

**B12. 媒体缓存键依赖 COS URL 稳定性，未验证且无淘汰**
- 若中台下发的图片 URL 带签名 query，每轮 hash 不同 → 缓存永不命中 + kf_media_cache.json 无限增长 + 每单重新上传。本地无法直连中台验证 URL 是否稳定。
- 建议：缓存键用 host+path（剥 query）；写入时淘汰 >7 天条目；验收加「同房源两次取 URL 比对是否一致」实测项。

**B13. 回归门禁强度不足；golden baseline 是死档**
- regression_chat.php 的订单用例 F1 仅 --live（:61），--core 完全不含订单查询；tests/golden_chat_baseline.json 是 2026-08-13 v3.3 快照，全仓无消费脚本（grep 无引用），不是门禁。
- 建议：§9.1 改为「--core 全绿 + 配置全空窗口跑 --live（覆盖 F1/房间流）+ 上线前 curl 存一份 v3.34.1 响应快照做逐字 diff」。

### 建议（不阻断）

- C1. §9.1「两条消息」表述不准：现生产无卡场景=2 条、单卡=文字+卡、2 卡=4 条。改为按（无卡/单卡/2 卡）三形态各列基准。
- C2. 选房答「①」非数字匹配不上；匹配函数归一化 ①–⑩→1–10。
- C3. pick 轮 web/H5 的 reply 文本未定义（建议=该房指引文字，图片 URL 进备用字段）。
- C4. 限流旧债：企微回调 REMOTE_ADDR=腾讯服务器 IP（config.php:118 只信 REMOTE_ADDR），20/min 全渠道共享；pick 多一次 pipeline 调用略加剧。知会即可，不在本期修。
- C5. stage=1 挂 24h 期间客人实际已到店/离店仍会收到「未到入住指引」快照，低概率，报告为已知可接受风险。
- C6. 新表 fail-open 未写死：发布顺序是先 scp 后 CREATE TABLE，表未建时 tryHandle 查询抛 PDOException → ChatPipeline 兜底 500，全部客服静默。蓝图明写「tryHandle 内部 catch、失败 return null」。
- C7. 视频落盘目录未指定（建议非 web 直达目录/不可猜文件名）。
- C8. uploadImageToWeCom 上传失败不记 errcode（roomcard_v37.php:291-293），复用同款写法时补上 errcode 日志。

## 二、安全垫审计（「三段全空 = 逐字一致」逐条核验）

| # | 承诺面 | 核验结论 |
|---|---|---|
| 1 | H5/web reply 逐字 | ✅ 成立：订单信息拼接块（OrderQueryWorkflow.php:72-76）不动，lead/guide 仅非空才拼；尾句按 channel 的 v3.34.1 逻辑（:70-71）蓝图未触碰。**前提**：B1 单一守门、B2 空日期中性 stage 落地。 |
| 2 | wechat_kf 两条消息 | ⚠️ 有条件成立：roomCardTextPlan / 发卡 / 40007 自愈 / chat_logs 事件均不改（§7.3/§7.6.3 ✓），plan 文本来源 $replyText 不变。**但「两条」只是无卡场景的形状**——单卡=文字+卡、2 卡=4 条（wecom_kf.php:419-435），验收表述需按场景分列（C1）。 |
| 3 | API 响应形状 | ✅ ReplyRenderer 仅 !empty 加 `order_guide` 新键，既有键不动；非订单场景 extra 本为空。**残余风险**：order_guide 恒含 stage 时空配置也会透出（B1），建议工作流端源头不 set。 |
| 4 | 指引/视频不发 | ✅ 由 lead 非空开关（§5.1）+ 视频未上传不发（§5.3）✓；§7.6.3 视频分支需补「during 非空」字样（B1）。 |
| 5 | 敏感字段 | ✅ 载荷只出 CenterRoom::checkin 白名单 {title,text,images}（CenterRoom.php:197-217），无房源整包；order_guide_sessions 候选只存 room_no/code/display_name/baoyu_room_no ✓。注意 pick 轮 chat_logs 若按 B11 新增事件行，content 只用展示名。 |
| 6 | 新表/新键回滚 | ✅ 键不预插、读空=关（§8）；表可留；回滚 = 拷回代码+reload ✓。唯一时序坑：表创建前的代码运行窗口需 fail-open（C6）。 |
| 7 | 房卡话术 | ✅ 不改发卡与中台 msg 透传（AGENTS §17 对齐）；「房卡原因并入 M1」仅在超 5 条降级时发生，属新场景非垫内 ✓。 |

**安全垫结论：设计成立，实现断点三处（B1、B2、A2），按建议封堵后可信。**

## 三、5 条预算审计（逐场景实加）

| 场景 | 消息序列（条数） | 判定 |
|---|---|---|
| 未到入住·单房·3+图（lead 非空） | M1(lead+订单+房卡原因并入)1 + M2 指引 1 + 图3 = **5** | ✅ 用满；指引 >600 拆两条 → **6** ⚠️（B4） |
| 未到入住·单房·0 图 | M1 1 + M2 1 + 房卡原因 1 = 3 | ✅ |
| 未到入住·多房·第一轮 | M1(lead+订单+选房问句)1 + 房卡原因 1 = 2 | ✅（但房卡原因归属未定义，A1 连带） |
| 未到入住·多房·选房轮 | 指引 1 + 图 ≤3 = ≤4 | ✅；⚠️ 房号 ≥8 位时叠加房卡分支误发消息（A2） |
| 入住期间·有卡·单卡 | 订单信息 1 + 卡 1 + 视频 1 = 3 | ✅（§5.2 表） |
| 入住期间·无卡 | 订单信息 1 + 原因 1 + 视频 1 = 3 | ✅ |
| 入住期间·2 卡 | 订单信息 1 + **间数引导 1** + 卡 2 + 视频 1 = **5** | ⚠️ 贴线（§5.2 表未计引导，A1） |
| 入住期间·3 卡 | 1+1+3+1 = **6** | ❌ 超限，第 6 条（视频）发不出 |
| 退房后 | M1(lead+订单)1 + 原因(已过期)1 = 2 | ✅ |
| 三段全空（现生产） | 无卡 2 / 单卡 2 / 2卡 4 / 3卡 5 / 4卡 6 | 4 卡=6 为**旧债**（现生产已存在，非本期引入） |

浪费额度：多房第一轮只用 1–2/5，无实质浪费（额度随客人下一条消息刷新）。真正的浪费是 A2 误发的「未找到该渠道订单号」占 1 条且误导客户。

## 四、验收清单可执行性

| 验收项 | 可否本地 | 前置条件 / 建议改写 |
|---|---|---|
| 9.1 逐字一致（H5 订单查询） | ❌ 本地 | 中台 IP 限制；上线前生产 curl 存 v3.34.1 快照，上线后 diff（脚本化，纳入发布步骤） |
| 9.1 --core 全绿 | ✅ | 间隔 1.5s 防撞限；**加 --live 才覆盖订单用例 F1**（B13） |
| 9.1 企微发卡实测 | ❌ 本地 | 生产 + 真实微信端 |
| 9.2 三时段判定 | ⚠️ 部分 | orderStage 参数化 $today（B10）后本地纯函数单测可全覆盖边界；生产凌晨 00:00–08:00 窗口实测需当日入住测试单，建议改判为「SQL 注入模拟 + 当日抽查」 |
| 9.2/9.3 指引内容实拍 | ❌ 本地 | 生产企微端人工核验；房源样本用 §4.4 四间 |
| 9.4 视频上传 | ❌ 本地 | **前置：B6 的 nginx/ini 确认与调整**；生产后台实测 |
| 9.4 40007 自愈 | ❌ 本地 | 生产手改 logs/kf_media_cache.json；注意 SSH 防爆破（一次登录多干活） |
| 9.5 敏感字段 grep | ✅ | 代码写完后对新增文件+响应样本 grep，可本地 |
| 9.6 后台三框/上传 | ⚠️ | 后台页面本地可开（MAMP 8889 需启动，当前未启动）；上传项随 B6 走生产 |
| 多房选房全链路 | ❌ 本地 | 需真实多房测试订单（如 123456789 系）+ 生产 |

## 五、必须在写代码前解决清单

1. A1 预算公式补「间数引导文字」+ 3 卡超限降级策略 + 多房轮房卡原因归属（改 §5.2）
2. A2 pick 轮跳过房卡链路（改 §7.6.3/7.6.4，给到插入点行号）
3. A3 pick 前置放行：转人工/退出/订单意图消息；TTL 重新定标（改 §5.4/§7.5）
4. A4 order_guide_sessions 加 attempts 字段（改 §5.4 DDL）
5. B1 单一守门点：lead 空则不 set extra（改 §7.1/§7.2）
6. B2 空/畸形日期 → 中性 stage（改 §7.1）
7. B6 发布清单补 nginx client_max_body_size + php ini 核查（改 §10）
8. B7 四个新 action 全部 adminGuard + 上传 finfo/文件名防护（改 §7.7）
9. B8 token/上传函数抽中立 include 的落点写死（改 §7.4）
10. B10 过期判定统一 SQL 时钟 + orderStage 参数化 today（改 §5.4/§7.1）

## 六、未覆盖项

- 中台返回的 check_in/check_out 实际格式、COS 图片 URL 是否带签名、真实房号位数：本地 IP 受限无法求证，已列为编码前置实测项（写码当天在生产机 curl 一次即可，蓝图文档未含，需 Work 补证）。
- 生产 nginx client_max_body_size / php-fpm 现值未读取（需 SSH）。
- 本审计为蓝图静态审计：无运行时执行；「未实测」各项已标注。

## 最终状态

**FAIL**（蓝图审计）：方向与素材扎实（官方约束、生产实测、口径引用经核对基本无误——ReplyRenderer 字段、agent.php:82 过滤、时区、发卡链路、匹配先例引用均与代码实况一致），但上述 4 个阻断缺陷会把 bug 直接写进实现，B6 会让验收 9.4 在生产当场失败。按 AGENTS.md §1 流程，修订蓝图后复审，不阻塞预期进度。

VERDICT: PASS WITH RISKS

# QA 验收报告 — v3.35 订单查询分时段回复 + 入住指引送达（上线前独立验收）

## 元信息

| 项 | 值 |
|---|---|
| 项目路径 | /Users/smiler/Documents/GitHub/ai-bisskefu |
| Commit | 基线 2199631（v3.34.1）；本次改动**未提交，在工作区**（7 改 + 12 新增） |
| 分支 | 工作区（git status：7 M + untracked） |
| 本轮改动范围 | admin/settings.html(+180) CenterRoom(+36) ChatPipeline(+11) ReplyRenderer(+7) OrderQueryWorkflow(+170) verify.php(+163) wecom_kf.php(+469)；新增 OrderGuidePick/wecom_kf_media/wecom_kf_order_guide/wecom_kf_token + 3 单测 + migration + ACCEPT.sh |
| 验收时间 | 2026-11-27（CST） |
| QA 环境 | 本地 Web 127.0.0.1:8899（PHP 内置服务器，PHP 8.3.30）；MySQL 127.0.0.1:8889/aibisskefu_com；中台本地直调（真实数据） |

## 版本核对

- `git log --oneline -1` → `2199631 v3.34.1: 微信客服渠道订单信息不再输出「前往微信客服获取」尾句` ✅ 与派单基线一致
- `git status --short` → 7 个 M + 12 个 untracked，改动确在工作区未提交（与派单一致）✅
- `git diff --stat` → 957 insertions / 79 deletions，与派单描述吻合

## 验收标准来源

- [x] 蓝图 rev2.3 `.trae/documents/v3.35-order-guide-blueprint.md`（主）
- [x] 派单描述（A–I 覆盖清单）
- [x] 前两轮审计报告（QA-REPORT_blueprint-v3.35 / rev2 + CodeBuddy 合并审），**只复核不重复报**

## 复测记录（QA 独立执行，未复用实现者结论）

| # | 验收标准 | 测试方法 | 命令/操作 | 关键原始输出 | 结论 |
|---|---|---|---|---|---|
| 1 | A 安全垫逐字节（web） | 8899 真实 curl 对比 baseline | `POST /api/chat.php order_query:123456789` | reply 与基线**逐字节一致**（strlen 均 156）；7 个稳定字段深度相等；差异仅 `elapsed_ms`（基线注记已声明剔除） | ✅ |
| 2 | A 无 order_guide 键/无多余字段 | harness2 A2/A5 | `array_key_exists('order_guide',$d)` | false；普通咨询也不带；键集合 = 基线 7 键 + elapsed_ms | ✅ |
| 3 | A wechat_kf 空配置形态 | harness2 A6* | channel=wechat_kf 查单 | 无引导前缀、无 order_guide、无尾句、含订单主体（= v3.34.1 行为） | ✅ |
| 4 | A 微信客服四形态条数（无卡2/单卡2/2卡4/3卡5） | 代码核对 | `git diff -U6 api/wecom_kf.php` | orderGuide=null 时走 `else` 分支，`roomCardTextPlan/间数引导/failTip` 原代码**原行搬移零改动**；`wecom_kf_roomcard_v37.php` diff 为空（ACCEPT[4] 复证） | ✅（码核） |
| 5 | B 5 条预算全矩阵 | 自建 harness1（43 断言，独立推导期望值） | `php qa_v335_harness1.php` | S1~S23 全 PASS：单房3图=5、0图=2、700字拆2+图降2=5、1500截1200、多房=1、1卡+视频=3、2卡=5、3卡并入rc=5、4卡两级降级=5（照常发卡弃视频）、5卡管家=2 无卡无视频、无卡+视频=2、退房后=1、M1 溢出截原因保 lead（=590） | ✅ |
| 6 | B 5 卡管家= M1+管家提示、无云房卡、联系人与 handoff.reply 一致 | harness1 S12/S13/S18* + 通道码核 | `PromptEngine::handoffReply(null,$db)` | 管家消息 = 固定文案 + `agent.handoff.reply`（LF 归一，与 DB 逐字相等）；通道 `ogButlerMode → break 发卡循环`；chat_logs 事件文案「订单含 N 间房，云房卡未发送…」 | ✅（条数/文案本地，实发生产） |
| 7 | C 三时段六形态 + 凌晨锚 MySQL | 复跑 test_v335_order_stage 17/17 + 码核 | `grep CURDATE api/Workflow/OrderQueryWorkflow.php` | `:157 SELECT CURDATE() d` 为唯一 $today 来源；orderStage 纯函数无 PHP date()/time()；unknown→中性（lead 强制 ''） | ✅ |
| 8 | C 启用场景渠道差异 | harness2 C1/C2（真实中台 during 单） | 保存 lead 后 chat.php 双渠道 | web reply=lead+订单 前缀；wechat_kf reply **不含** lead（指引走 extra 由通道分条）；stage=during rooms=[]（不查房源） | ✅ |
| 9 | D 五种回复行为 + 2h TTL | harness2 D0~D10（真实 DB+真实中台） | openSession→chat.php 回「1/①/房号/和德/新单号/退出/谢谢」 | 序号/①/房号命中且只带选中那间+stage=2；「和德」歧义→重新问不猜（rooms=0）attempts=1，连续2次清 stage；16位新单号→走 OrderQueryWorkflow 正常查单且 stage=0；「退出」清 stage 走正常流程；「谢谢」不当退出（attempts=1）；TTL 写入 `TIMESTAMPDIFF(NOW,expires_at)=120min`，过期后不再命中；closeSession→stage=2+候选NULL | ✅ |
| 10 | D 选房补写 chat_logs | harness2 D1g/D1g | SELECT chat_logs WHERE session_id=qa | user+assistant 两行，workflow=OrderGuidePick | ✅ |
| 11 | E 三框保存/回显/清空/201拒/缺字段 | harness3 E6~E10 + 浏览器真机 | curl + settings.html UI | 保存 code=0 回显一致；201 字拒「不能超过 200 字（当前 201 字）」；缺字段「参数不完整」；清空后回显全空=关闭场景；UI 点保存→DB→回显闭环通过 | ✅ |
| 12 | E 鉴权矩阵（含 P1-1 回归） | harness3 E1/E2/E3/E4/E17/E18 | 无 token / `Bearer`+token 无空格 / Basic / 正确 Bearer | 401 / **401** / 401 / 200；上传端点无 token 401、坏头 401、正确头打到企微墙 | ✅ |
| 13 | E 上传矩阵 | harness3 E11~E16 | 真 mp4/假 mp4/avi/x.mp4.php/11MB/缺字段 | 真 mp4 → 502「企微上传校验失败（errcode=-1 access_token 获取失败）」（本地 corp_secret 过期墙，= 已知 U7）；假 mp4 finfo 拒「检测为 text/plain」；avi/evil.mp4.php/10733669B>10MB/缺 file 全部 400；**六条路径 uploads/guide 文件数前后不变（不落盘）** | ✅ |
| 14 | E 删除幂等+清配置+删文件 | harness3 E19~E21 | 预置配置+文件→delete×2 | code=0；源文件已删；4 键全空；二次删除仍 0；回显 filename 空 | ✅ |
| 15 | F 缓存键三处一致/TTL/淘汰 | harness1 F1~F8 + E19d | mediaCacheKey(path,'video') 三口径比对 | 发送侧==试传/上传侧（同 path 串+type）✅；61h 后 get=null、8 天条目 set 时淘汰、image/video 不撞键、query 剥除 ✅；**删除/替换侧用 realpath 键清不掉发送侧写入的键（E19d 实证残留）** | ⚠️ 见 P3-1 |
| 16 | G 敏感字段 | grep 全部 diff 新增行 + 新增文件 + 本轮 chat_logs | lock_password/cleaning_password/room_password/lock_user_name/owner | 代码仅 writeGuideChatEvent 黑名单正则与注释命中；本轮全部 qa_test chat_logs 内容零命中；无房源整包 JSON 痕迹；上传落盘名 `guide_video_<ts>_<hex>.mp4` 服务端随机（原名仅展示 strip_tags）；新增 SQL 全 prepared/pcSet | ✅ |
| 17 | H ACCEPT.sh 重跑 | `bash ACCEPT.sh`（终态跑两遍） | 5 步全绿 exit 0 | php -l×9 / 3 单测 / token 契约 / 红线(roomcard_v37 零 diff) / 安全垫逐字节 | ✅ |
| 18 | H 回归 --core | `php scripts/regression_chat.php http://127.0.0.1:8899 --core` | 「通过: 9 失败: 0 请求失败: 0 跳过: 7」 | 房间咨询流（入住时间/WiFi/云房卡/转人工/退款/宠物）全部正常 | ✅ |
| 19 | H checkin() 未改坏 | diff + 调用点 grep | `git diff CenterRoom.php` 只有新增 `checkinAll`；`checkin(` 仅 `RoomQueryService.php:272`（未改动文件）使用；checkinAll 仅订单指引链路调用 | ✅ |
| 20 | I 发布面 | 码核+DB 核+蓝图生产实测记录 | migration 文件存在（IF NOT EXISTS）；本地表已建、生产待建；配置键读空=关（生产现不存在，蓝图 §12）；`roomcard.guide_video.path` 键本地缺失时 pcGet 默认 '' 行为不变（实测 path 键此前不存在仍正常） | ✅ |
| 21 | 已修 7 项复核 | 见下节 | — | — | ✅ |

## 对已修 7 项的复核结论

| 项 | 结论 | 证据 |
|---|---|---|
| P1-1 后台视频上传鉴权头 | **已修**。settings.html 上传 fetch 用 `{'Authorization':'Bearer '+token}` 且不再带 Content-Type（不破坏 multipart）。回归实测：无 token→401、`Bearer`无空格→401、Basic→401、正确头→通过鉴权（推进到企微 502 墙） | admin/settings.html:1194-1197；harness3 E1~E4/E17/E18 |
| P1-2 视频缓存键 type | **已修**（发送侧 `mediaCacheKey($path,'video')` 与 uploadMediaFromFile/试传侧同键，harness1 F1 实测相等）。**但残留新问题**：删除/替换侧清理用 `realpath` 后路径，发送/写入侧用配置里的原始 path（含 `/api/..` 段），两串 hash 不同 → 删除/替换时 `mediaCacheClear` 清的是从未写入过的键（E19d 实证：`c5a5c6da…` 残留）。无客人可见影响（新视频随机文件名必然新键；残留条目 ≤60h/7d 自动淘汰），**P3** | wecom_kf.php sendKfGuideVideo；verify.php 删除/替换块 `mediaCacheKey($oldReal,'video')` |
| P2-1 butler 计入预算 | **已修**。`total()` butlerMode 计 1 条（wecom_kf_order_guide.php:89）；实测 5卡/6卡=2 条、无卡无视频消息 | harness1 S12/S13 |
| P2-2 4 卡单测入参 | **已修**。scripts/test_v335_order_guide_messages.php:134-145 入参改为与通道层一致（roomCountText 非空、无图），断言两级降级→5、rc 并入 M1。QA 用独立 harness 同场景推导一致（S10/S11） | 文件行号如上；harness1 S10 |
| P2-3 死代码 | **已修**。`api/Workflow/OrderGuidePickWorkflow.php` 已删；`hasPending/matchOrderCandidate` 全仓零引用（grep 空）。残留一段无主注释（OrderGuidePick.php:160-161，纯外观） | grep 输出空 |
| P2-5 单房清旧选房状态 | **已修**。`closeSession()` 存在且被 workflow 单房分支调用（OrderQueryWorkflow diff candidates==1 分支）；实测 stage 1→2、候选 NULL；另注：客人以订单号/「订单」字样再查单时 tryHandle 逃生门已先清 stage（双重防线，行为正确） | harness2 D9 |
| P2-6 事件行黑名单过滤 | **已修（范围见 P3-2）**。`writeGuideChatEvent` 写前 `preg_replace` 屏蔽 `字段[:=：]值` 形态（wecom_kf.php:1198-1203）；pick 事件内容只由 display_name/room_code 拼装；本轮 chat_logs 全量扫描零敏感命中 | harness2 G1/G2 |

## 发现

### 本轮新发现（均非阻断）

**[P2] 选房轮选中「有图无文字」房源时，企微侧回复为空文本 → 该轮发送失败，指引图片发不出去**
- 分类：本轮引入（P1-4 旁路的边角）。触发条件：启用场景 + 多房选房 + 选中房源 steps 只有图无 title/text（实测该类房源 ≥185 间）。
- 机制：`OrderGuidePick::tryHandle` 命中后 `guideTextFromSteps(steps)=''` → reply=''（OrderGuidePick.php:291）→ wecom `:637 sendKfMessage($from,'')` 无空串防护（wecom_kf.php:1123 起，空 content 直发企微必被拒）→ `sent=false → batchHandled=false`，图片分支不执行、msgId 不落 dedup → 游标不推进、微信重投；重投轮 stage 已=2 → 走正常流程回「1」，图片最终没发。
- 证据：checkinAll 允许 text='' 的 step（CenterRoom.php:103-108 有意为之）；pick 命中路径 reply 完全来自 guideText（OrderGuidePick.php:291）；`sendKfMessage` 无 `trim()===''` 早退。空 errcode 具体值未本地实测（token 墙），链路为码级确认。
- 建议：pick 轮 reply 为空时兜底一句「该房入住指引见图片～」，或空文本时跳过文字改先发图。交 Work 修。复现率：必现（条件组合下）。

**[P3-1] 删除/替换视频的 media_id 缓存清理键与写入侧不一致**（见上表 P1-2 行）——verify.php 清理用 realpath，发送/试传侧用配置原串（含 `/api/..`），清理落空；仅缓存文件残留，≤60h/7d 自愈。建议清理时同时清两个口径或统一以 realpath 落库 path。

**[P3-2] writeGuideChatEvent 黑名单只过滤 `字段名[:=：]值` 形态**；若中台展示类字段里出现不带字段名前缀的裸密码，仍会入事件行。现内容源均为展示名，风险很低。

**[P3-3] 启用场景「有卡但企微全败」时不再补发失败提示**：v3.34 failTip 分支被加了 `!is_array($orderGuide)` 条件（wecom_kf.php:578），而 hasCards 时 M1 不含房卡原因——注释「房卡原因已在 M1 内」在该场景不准确，客人只收到 M1 无失败感知（chat_logs 有记录）。边缘场景。

**[P3-4] 媒体自愈对任何非 0 errcode 都重传素材**（含 45009 限流等与素材无关错误）→ 偶发多一次 10MB 重传。策略即如此（P2-6 宽口径），成本可接受。

**[P3-5] checkinAll 跳过 title 有值但 text/images 皆空的 step**（CenterRoom.php:103），标题随之丢；`api/OrderGuidePick.php:160` 残留无主注释。外观级。

### 已知风险的复核（确认被正确标注，未重复报）

- P2-4「中台 precheckin 异常返回 4 卡 → 6 条」：harness1 S21 实测编排确为 6 条且不可再降（卡+订单信息不可降级）→ 标注准确，本期不修成立。
- 4 卡=6 条旧债：仅存在于未启用场景（企微自行截断），启用场景 4 卡=5 条（S10）。
- 20/min 限流全渠道共享、uploads/guide 公网直达（内容无 PII）：现状与蓝图 §3.9/C7 一致。

## 未覆盖项（上线前必做，均因本地环境）

1. **企微真实发送全链路**（本地 corp_secret 过期 gettoken 40001）：生产后台传一次真实 mp4 走完「试传成功→配置写入→入住期间查单发卡后收到视频」；手改 `logs/kf_media_cache.json` 把 media_id 改坏 → 验证 40007 自愈重传；企微实收核对无卡2/单卡2/2卡4/3卡5 四形态条数顺序。
2. **5 卡管家模式生产实收**：等/造 cardCount≥5 订单，确认客人实收「M1+管家提示」2 条、无云房卡、提示与后台 handoff.reply 一致；顺带确认 cardCount=4 不误触发。
3. **凌晨 00:00–08:00 窗口抽查**（生产 PHP=UTC/MySQL=CST）：当天入住测试单在该窗口查一次，确认落 during（锚 CURDATE）。
4. **上线前后生产逐字 diff**：上线前（配置全空）curl 存快照（基线已于 2026-11-27 采集）；上线 reload 后同法再采一次，与基线逐字节 diff，不等即回滚。
5. **发布前置通道项**（生产实测已查清，执行属上线动作）：`/etc/php-fpm.d/www.conf` `[www]` 加 `php_admin_value[upload_max_filesize]=12M`、`post_max_size=13M` → `systemctl reload php-fpm`（nginx 50m 不动）；`mkdir -p uploads/guide && chown apache:apache`；生产库执行 `sql/migration_v3.35_order_guide.sql`（scp 之后）；配置键不预插。回滚 = 拷回 bak 目录 + reload，表/空键留置无害，**可执行**。

## 最终状态

**PASS WITH RISKS** — 本地可验的全部关卡（安全垫逐字节、5 条预算矩阵、时段判定、选房状态机五路+TTL、后台鉴权/上传/删除矩阵、缓存 TTL、敏感字段、ACCEPT 全绿、--core 回归 9/9）全部通过，无 P0/P1；但企微媒体实发、5 卡管家实收、凌晨窗口、上线逐字 diff 四项只能在生产闭环（本地 corp_secret 过期为环境墙非代码缺陷），另有 1 项 P2（选房轮空文本）与 4 项 P3 交 Work 排期，不阻断上线但应列入观察项。

## 自验证清单

- [x] 每条结论有实际执行证据（命令+harness 输出/文件行号）
- [x] 未实际执行的测试未写入「已通过」（企微实发等全部列入未覆盖项）
- [x] FAIL 项排除夹具问题（harness2 首轮 29 假失败=我脚本 URL 插值 bug，修复后复跑；A3/A4 差异仅 elapsed_ms，基线注记声明剔除；C3 期望文案是我猜错，实为与 v3.34.1 相同的 ORDER_NOT_FOUND_REPLY，已比对 v3.34.1 源码同常量同行为）
- [x] 新旧问题已区分（P2-4/限流/uploads 公网 = 旧债/已知；P2 空文本、P3-1 等 = 本轮新发现）
- [x] 报告中无编造内容；测试数据（qa%/reg%/accept% 会话、chat_logs、选房会话表、uploads/guide 测试文件、缓存键）已清理，三段话术与视频配置已复位全空

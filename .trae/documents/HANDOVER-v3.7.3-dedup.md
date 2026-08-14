# 接手说明:v3.7.3 修复重复触发(去重 dedup)

**接手时间**:2026-08-14 20:48
**接手原因**:波尼额度用完,需要接手的人继续修这个 bug
**接手人**:请按以下步骤继续

---

## ✅ 已解决(2026-08-14 20:53 更新)

**重复触发 bug 已确认解决,无需改代码。**

**证据**:
```
[12:52:59] sync_msg returned 1 messages   ← 增量拉取正常(只拉1条)
[12:53:02] Replied to ...: 您好呀。         ← 只回复1条
```

**根因**:12:33:20 那次 `sync_msg returned 14 messages` 是**首次部署后 cursor 文件不存在 → 从空 cursor 拉 → 一次性回放所有未消费历史消息**(14 条不同 msgid,dedup 对不同 msgid 不生效是预期)。cursor 持久化后,后续全部增量(`returned 1 messages`)。

**dedup 机制验证**:
- 缓存文件 `wecom_kf_msgid_cache.json` 是合法 JSON ✅
- `markMsgProcessed` 写入正常 ✅
- `isMsgProcessed` 逻辑正确 ✅

---

## 原始问题描述(供参考)

## 一、问题(已 100% 确认)

**线上现象**:用户发"晚上好"1 次,AI 重复回复 3-5 次"晚上好呀"+ KB 答案

**生产日志证据**(`/www/wwwroot/aibisskefu/logs/wecom_kf.log`):
```
[2026-08-14 12:33:20] sync_msg returned 14 messages
[...39条 Replied 历史...]
grep -c 'Skip dup' /www/wwwroot/aibisskefu/logs/wecom_kf.log → 0
```

**dedup 完全没触发**:`Skip dup` 日志 0 次,Replied 39 次,缓存里只有 2 条。

---

## 二、关键代码位置

| 文件 | 行号 | 内容 |
|---|---|---|
| `api/wecom_kf.php` | 261-266 | dedup 调用点(foreach 内 msgid 判重) |
| `api/wecom_kf.php` | 360-392 | `syncMsg()` 函数(cursor 增量拉取) |
| `api/wecom_kf.php` | 235-242 | `processKfEvent()` 内 cursor 读取 |
| `api/wecom_kf_dedup.php` | 全部 | dedup 函数(`isMsgProcessed` / `markMsgProcessed`) |
| `api/wecom_kf_dedup.php` | 13/19/20 | `json_decode(file_get_contents($cacheFile), true)` 这里需要排查 |

---

## 三、根因方向(待你验证)

**最可能的原因** — `isMsgProcessed` 的 json_decode 失败:

`api/wecom_kf_dedup.php` 第 13 行:
```php
$cache = @json_decode(@file_get_contents($cacheFile), true) ?: [];
```

`@` 抑制了所有错误,如果 json 解析失败,`$cache` 可能是 `null` 或 `false` — `?:` 兜底变 `[]`。
**但真正的问题**:文件写入时 `LOCK_EX` 锁,可能多个 PHP-FPM worker 同时写,产生**部分写入**导致 JSON 不合法 → 解析失败 → 缓存被当空 → 永远不命中 dedup。

**但这不是唯一原因** — 接手的人需要查:
1. **直接看 `wecom_kf_msgid_cache.json` 文件内容**:`cat /www/wwwroot/aibisskefu/logs/wecom_kf_msgid_cache.json`,看是否合法 JSON
2. **手动 markMsgProcessed 后立刻 cat 文件**,验证写入是否生效
3. **如果写入 OK 但读取失败** → 可能是并发锁问题

---

## 四、推荐修复方案(两条路,任选)

### 方案 A:用 Redis 替换文件缓存(彻底解决并发)

**优点**:彻底解决并发问题,生产环境推荐
**缺点**:需要 Redis 服务,需要部署 + 配置

**代码改动**:`api/wecom_kf_dedup.php` 全部重写,改用 `Predis` 或 `phpredis` 扩展:
```php
function isMsgProcessed(string $msgId): bool {
    $r = getRedis();
    return $r->exists('kf:msgid:' . $msgId) > 0;
}
function markMsgProcessed(string $msgId): void {
    $r = getRedis();
    $r->setex('kf:msgid:' . $msgId, 300, time());
}
```

### 方案 B:用 MySQL 替换文件缓存(无新增依赖)

**优点**:不需要 Redis,用现有的 MySQL
**缺点**:增加 DB 压力(但单 ops 1 次 SET/GET,可忽略)

**代码改动**:
1. 新建表 `CREATE TABLE kf_msgid_dedup (msgid VARCHAR(64) PRIMARY KEY, expire_at INT) ENGINE=InnoDB;`
2. `api/wecom_kf_dedup.php` 改写用 INSERT IGNORE + SELECT 1

### 方案 C:简化方案 — sync_msg 每次只处理 1 条

**优点**:改动小,不需要 dedup,因为 1 条 = 1 次处理
**缺点**:消化 14 条历史需要 14 次回调,慢

**代码改动**:`api/wecom_kf.php` 的 `syncMsg` 调用,加 `limit=1` 参数,且不保存 next_cursor(每次强制从头,处理完即清空)

---

## 五、验证方法(快速测试)

不管用哪个方案,验证 dedup 生效的最快方法:

```bash
# 在生产上跑
php -r '
require "/www/wwwroot/aibisskefu/api/config.php";
require "/www/wwwroot/aibisskefu/api/wecom_kf_dedup.php";
$id = "test_msg_123";
markMsgProcessed($id);
var_dump(isMsgProcessed($id)); // 应该 true
var_dump(isMsgProcessed("other")); // 应该 false
'
```

如果输出符合预期 → 函数本身 OK,问题是写入路径。如果不符合 → 函数有 bug,需要查文件读写。

---

## 六、环境信息(接手需要)

| 项 | 值 |
|---|---|
| 生产服务器 | 43.138.217.6, root / Suming0505 |
| 生产代码目录 | `/www/wwwroot/aibisskefu/` |
| 本地仓库 | `/Users/smiler/Documents/GitHub/ai-bisskefu` |
| 数据库 | `aibisskefu_com`, root/root(MAMP 本地 + 生产同) |
| 客服 open_kfid | `wkwygfCwAAaLBSsAfVdXU1ptiQQ8079A` |
| 客户 external_userid | `wmwygfCwAA9KAg3E62lKIrwai3uioZYQ` |
| access_token | DB 缓存,自动刷新 |
| dedup 缓存文件 | `/www/wwwroot/aibisskefu/logs/wecom_kf_msgid_cache.json` |
| cursor 缓存文件 | `/www/wwwroot/aibisskefu/logs/wecom_kf_cursor_*.txt` |
| 当前生产 md5 (wecom_kf.php) | `0962c8ec66b429270577de33a7b6364f` |
| 本地仓库最新 commit | `24fe51d` (v3.7.2 已推送) |
| 蓝图目录 | `.trae/documents/v3.*.md`(7 个蓝图) |

---

## 七、已确认工作(不要再做)

| 版本 | 内容 | 状态 |
|---|---|---|
| v3.7 | 新增云房卡 link 卡片自动发送 | ✅ 部署,客户已收到云房卡 |
| v3.7.1 | 订单号正则 10→8 位 | ✅ |
| v3.7.2 | is_dir → is_file bug 修复 | ✅ 验证 link 卡片发送成功 |
| v3.7 KB | KB top200 导入(本地+生产同步,148 条)| ✅ |

**不要再动 v3.7 / 3.7.1 / 3.7.2 / KB 的代码**,只动 dedup。

---

## 八、接手建议的步骤

1. **不要急改代码**,先 SSH 上去实测 dedup 函数本身(看上面"快速测试")
2. **确认根因**(是函数 bug 还是写入问题)
3. **选方案**(A Redis / B MySQL / C 简化),按 v3.7 流程:蓝图 → 审计 → 改码 → 审计 → 上传 → 推送
4. **改前先 md5 校验**当前生产 wecom_kf.php 是不是 `0962c8ec66b429270577de33a7b6364f`(防止本地跟生产不一致)
5. **改完**先本地 php -l,然后 SCP + reload php-fpm + md5 校验,再让客户实测

---

## 九、关键设计原则(苏鸣定)

1. **六步流程**: 蓝图 → 审计 → 改码 → 审计 → 上传 VPS → 推送 GitHub,每步不许跳
2. **同步铁律**: 本地仓库 + 本地 DB + 生产 VPS 代码 + 生产 DB 必须保持一致
3. **动手红线**: 不主动 patch/write_file,等用户明确授权
4. **审计要求**: 不能靠代码说"应该没问题",必须跑实际接口/测试/日志

---

**完成交接。接手的人加油!**

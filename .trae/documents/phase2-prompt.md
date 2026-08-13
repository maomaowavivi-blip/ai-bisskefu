# Phase 2 实施任务：Core 读 AgentConfig（行为等价切换）

## 总体目标

将 `PromptEngine.php` 和 `chat.php` 中所有硬编码的配置值，改为从 Phase 1 已落地的 `AgentConfig` 读取。**切换后 AI 回复必须与现网逐字一致。**

## 铁律
1. PHP 7 兼容
2. 每个 AgentConfig 读取都有 fallback 默认值（即当前硬编码的值），配置缺失时回退到现网行为
3. `agent_defaults.json` 和 `platform_config` 的值已由 Phase 1 灌好，跟硬编码默认值相同
4. 不改 `order_query:` 冻结块（chat.php L425-481），不改 `callGateway()`
5. 不改 `rewriteQuery()`、`searchKnowledge()`、`buildMascotLayer()`——它们没有行业相关内容

---

## 文件 1：api/PromptEngine.php

### 1.1 删除 SidecarIntent 依赖（L12）

删除：
```php
require_once __DIR__ . '/sidecar/SidecarIntent.php';
```

新增：
```php
require_once __DIR__ . '/AgentConfig.php';
```

### 1.2 `buildSystem()`（L41）— 新增 AgentConfig 参数

方法签名改为：
```php
public static function buildSystem(array $persona, array $history = [], ?AgentConfig $config = null): string
```

内部 5 处硬编码替换：

**替换 A — L57-62 话术禁止 6 条**：从 `$config->getJson('agent.rules.speech_bans')` 读取。如果返回非空数组，遍历拼接 `1. xxx` / `2. xxx`；如果为空，回退到当前硬编码文本。

**替换 B — L60 预订平台+品牌**：从 `$config->get('agent.rules.booking_platforms')` 和 `$config->get('agent.rules.booking_brand_hint')` 读取。为空则回退 `'携程、美团、去哪儿'` / `'宿家民宿'`。

**替换 C — L65-66 转人工提示**：从 `$config->get('agent.rules.handoff_system_hint')` 读取。`\n` 拆分为两行。为空则回退当前两行硬编码文本。

**替换 D — L69 回复格式字数**：从 `$config->getInt('agent.reply.min_chars', 20)` 和 `$config->getInt('agent.reply.max_chars', 80)` 读取，拼入格式文本。当前文本为：
```
【回复格式】只输出一个完整的陈述句（20-80字），句号结束，不追加任何内容
```
改为动态拼接 min/max。

### 1.3 `buildProhibitionLayer()`（L133）— 新增 AgentConfig 参数

方法签名改为：
```php
public static function buildProhibitionLayer(?AgentConfig $config = null): string
```

两处替换：

**替换 A — L134 fallback 文案**：`$fallback = $config ? $config->get('agent.fallback.reply', self::NO_KB_FALLBACK_REPLY) : self::NO_KB_FALLBACK_REPLY;`

**替换 B — L140 禁止举例列表**：从 `$config->getJson('agent.prohibition.examples')` 读取。非空则用 `/` 拼接替换硬编码的 `地址/路线/导航/停车/车位/收费/WiFi/门禁/房价/押金/退改政策/设施/设备/订单状态/入住时间/周边配套`。为空则回退硬编码。

### 1.4 `buildUserTurn()`（L189）— 新增 AgentConfig 参数

方法签名改为：
```php
public static function buildUserTurn(string $message, array $kbItems, ?AgentConfig $config = null): string
```

**替换 — L194-195 无 KB 时的约束文本**：fallback 从 config 读取。
```php
$fallback = $config ? $config->get('agent.fallback.reply', self::NO_KB_FALLBACK_REPLY) : self::NO_KB_FALLBACK_REPLY;
```
替换 `self::NO_KB_FALLBACK_REPLY` 为 `$fallback`。

### 1.5 `buildMessages()`（L256）— 透传 AgentConfig

方法签名改为：
```php
public static function buildMessages(
    array  $persona,
    array  $history,
    string $message,
    array  $kbItems,
    string $sessionId = '',
    string $rewrittenQuery = '',
    ?AgentConfig $config = null
): array
```

内部两处调用透传 `$config`：
- `self::buildSystem($persona, $history, $config)`  （注意 history 参数保留，虽然内部不用）
- `self::buildUserTurn($message, $kbItems, $config)`

### 1.6 `finalizeReply()`（L154）— 新增 AgentConfig 参数

方法签名改为：
```php
public static function finalizeReply(string $reply, array $kbItems, ?AgentConfig $config = null): string
```

两处替换：
- L162 比较：`if ($reply === $fallback)` 其中 `$fallback` 从 config 读取
- L170 返回：`return $fallback`
- L159 `self::isPreservedReplyWithoutKb($reply)` → `self::isPreservedReplyWithoutKb($reply, $config)`

### 1.7 `isPreservedReplyWithoutKb()`（L174）— 新增 AgentConfig 参数

方法签名改为：
```php
private static function isPreservedReplyWithoutKb(string $reply, ?AgentConfig $config = null): bool
```

**替换 — L175 + L178 保留标记**：从 `$config->getJson('agent.fallback.preserved_markers')` 读取。非空则用该数组替换硬编码的 `['不太方便讨论', '没法聊', '无法回应']`。L175 的 `转接人工` 也要改为检查 config 数组中是否包含 `'转接人工'`（或者保留 L175 的子串匹配逻辑，保持与现网一致——`mb_strpos($reply, '转接人工') !== false`）。

实现建议：config 数组里每个 marker 都做 `mb_strpos` 检查（和现网 L178 foreach 逻辑一致），'转接人工' 也纳入同一个数组循环。

### 1.8 `directReplyFromKb()`（L221）— 新增 AgentConfig 参数

方法签名改为：
```php
public static function directReplyFromKb(string $message, array $kbItems, ?PDO $db = null, ?AgentConfig $config = null): ?string
```

**替换 — L235**：`SidecarIntent::isYunfangkaCredentialQuery($message)` 改为 `$config && $config->isCredentialQuery($message)`。当 `$config` 为 null 时跳过凭证检查（或回退到 SidecarIntent，但在 Phase 2 统一用 AgentConfig）。

### 1.9 `_isRoomRoutedAnswer()`（L471）— 改为读配置

改为：
```php
private static function _isRoomRoutedAnswer(string $answer, ?AgentConfig $config = null): bool {
    $phrases = $config ? $config->getJson('agent.routing.sidecar_route_phrases') : [];
    if (empty($phrases)) {
        $phrases = ['请提供订单号', '提供订单号', '查询订单后', '请先查询订单'];
    }
    foreach ($phrases as $mark) {
        if (mb_strpos($answer, $mark) !== false) return true;
    }
    return false;
}
```

调用方 `directReplyFromKb()` L232 改为 `self::_isRoomRoutedAnswer((string)($item['answer'] ?? ''), $config)`。

### 1.10 `_isYunfangkaCredentialEntry()`（L481）— 改为读配置

改为：
```php
private static function _isYunfangkaCredentialEntry(array $entry, ?AgentConfig $config = null): bool {
    $marker = $config ? $config->get('agent.routing.credential_kb_marker', '请在云房卡中查看') : '请在云房卡中查看';
    $answer = (string)($entry['answer'] ?? '');
    return mb_strpos($answer, $marker) !== false;
}
```

调用方 L235 改为 `self::_isYunfangkaCredentialEntry($item, $config)`。

### 1.11 `_messageMatchesKbEntry()`（L487）— policyPatterns 读配置

在方法开头新增 policyPatterns 来源判断：
```php
$policyPatterns = [];
if ($config) {
    $rawPatterns = $config->getJson('agent.kb.policy_patterns', []);
    foreach ($rawPatterns as $rp) {
        $p = $rp['pattern'] ?? '';
        $n = $rp['blob_needle'] ?? '';
        if ($p !== '') {
            $policyPatterns[$p] = $n;
        }
    }
}
if (empty($policyPatterns)) {
    // fallback 到现网硬编码 15 条（L518-534 完整保留，一模一样）
    $policyPatterns = [
        '/几点.*入住/u' => '入住',
        '/入住.*几点/u' => '入住',
        '/几点.*退房/u' => '退房',
        '/退房.*几点/u' => '退房',
        '/(中午|必须).{0,6}(几点|走|退)/u' => '退房',
        '/(可以|能|能否).{0,4}带.{0,2}宠物/u' => '宠物',
        '/宠物/u' => '宠物',
        '/(退款|退钱|退费|申请退款)/u' => '退款',
        '/云房卡/u' => '云房卡',
        '/(WiFi|wifi|无线).{0,6}密码/u' => 'WiFi',
        '/(刷脸|公安.{0,4}核验|实名)/u' => '刷脸',
        '/(交|付|缴).{0,4}押金/u' => '押金',
        '/(门禁|门锁).{0,4}密码/u' => '门禁',
        '/(接送机|接机|送机|寄存行李|生日布置)/u' => '增值',
        '/(预订|订房|下单|代订|帮我订|帮你订)/u' => '预订',
    ];
}
```

方法签名新增 `?AgentConfig $config = null` 参数。

调用方 `directReplyFromKb()` L238 改为 `self::_messageMatchesKbEntry($message, $item, $config)`。

### 1.12 迁入 `filterReply()`（从 chat.php L181-249）

把 `chat.php` 中 `filterReply()` 函数的完整代码（L181-249）迁入 `PromptEngine`，作为公开静态方法：

```php
public static function filterReply(string $reply, string $message, PDO $db, ?AgentConfig $config = null): string
```

两处替换：
- `$salesPatterns`（L191-197）：从 `$config->getJson('agent.filter.sales_patterns')` 读取。为空则回退硬编码 7 条。
- `$badEndings`（L207-211）：从 `$config->getJson('agent.filter.bad_endings')` 读取。为空则回退硬编码 9 条。

**数组顺序必须与现网完全一致（回退值和 JSON 默认值都是现网顺序）。**

保留其余逻辑不变（HandoffTriggers 检查、规则1-5 的处理流程）。

---

## 文件 2：api/chat.php

### 2.1 新增 AgentConfig 初始化

在 chat action 开头（约 L335 `$db = getDB();` 之后），新增：
```php
$config = new AgentConfig($db);
```

### 2.2 L363-367 — 凭证判断改用 AgentConfig

将：
```php
require_once __DIR__ . '/sidecar/SidecarIntent.php';

$isOrderQueryCmd = (bool) preg_match('/^order_query:/', $message);
$isHandoffMsg    = HandoffTriggers::matchesMessage($db, $message);
$isYfkCredential = SidecarIntent::isYunfangkaCredentialQuery($message);
```

改为：
```php
$isOrderQueryCmd = (bool) preg_match('/^order_query:/', $message);
$isHandoffMsg    = HandoffTriggers::matchesMessage($db, $message);
$isYfkCredential = $config->isCredentialQuery($message);
```

删除 `require_once __DIR__ . '/sidecar/SidecarIntent.php';`（这行不再需要）。

### 2.3 L370-374 — early KB 直答透传 config

将：
```php
$earlyKb = PromptEngine::directReplyFromKb($message, [], $db);
```

改为：
```php
$earlyKb = PromptEngine::directReplyFromKb($message, [], $db, $config);
```

### 2.4 L523-525 — policy KB 直答透传 config

将：
```php
$policyReply = PromptEngine::directReplyFromKb($message, $kbItems, $db);
```

改为：
```php
$policyReply = PromptEngine::directReplyFromKb($message, $kbItems, $db, $config);
```

### 2.5 L181-249 — 删除 `filterReply()` 函数定义

整个 `filterReply()` 函数体从 chat.php 删除（已迁入 PromptEngine）。

### 2.6 L678 — 调用改为 PromptEngine::filterReply

将：
```php
$reply  = filterReply($reply, $message, $db);
```

改为：
```php
$reply  = PromptEngine::filterReply($reply, $message, $db, $config);
```

### 2.7 L679 — finalizeReply 透传 config

将：
```php
$reply  = PromptEngine::finalizeReply($reply, $kbItems);
```

改为：
```php
$reply  = PromptEngine::finalizeReply($reply, $kbItems, $config);
```

### 2.8 L667 — buildMessages 透传 config

将：
```php
$messages = PromptEngine::buildMessages($persona, $history, $message, $kbItems, $sessionId, $rewrittenQuery);
```

改为：
```php
$messages = PromptEngine::buildMessages($persona, $history, $message, $kbItems, $sessionId, $rewrittenQuery, $config);
```

### 2.9 L642 — guideReply 读配置

将：
```php
$guideReply = '如需查询订单，请点击下方「订单查询」按钮，或直接发送 order_query:订单号～';
```

改为：
```php
$guideReply = $config->get('agent.order.guide_reply', '如需查询订单，请点击下方「订单查询」按钮，或直接发送 order_query:订单号～');
```

### 2.10 L673 — max_tokens 读配置

将：
```php
'max_tokens'  => 150,
```

改为：
```php
'max_tokens'  => $config->getInt('agent.llm.max_tokens', 150),
```

### 2.11 L95-121 — checkInputSafety 政治词可选覆盖

函数签名新增参数：
```php
function checkInputSafety(string $msg, ?AgentConfig $config = null): ?string {
```

L96 政治词数组：
```php
$political = ['法轮功', '天安门', '六四事件', '台独', '藏独', '港独'];
```

改为：
```php
$political = $config ? $config->getJson('agent.safety.political', []) : [];
if (empty($political)) {
    $political = ['法轮功', '天安门', '六四事件', '台独', '藏独', '港独'];
}
```

L353 调用处改为：
```php
$safetyReply = checkInputSafety($message, $config);
```

成人词库和注入词库**保持不变**（硬编码，固定 Core 安全防护）。

### 2.12 L658-665 — 走 AI 前的 KB 直答透传 config

将：
```php
$directKbReply = PromptEngine::directReplyFromKb($message, $kbItems, $db);
```

改为：
```php
$directKbReply = PromptEngine::directReplyFromKb($message, $kbItems, $db, $config);
```

### 2.13 L425-481 — order_query 冻结块

**不改。** 保持原样。

---

## 文件 3：api/openapi.php

openapi.php 也走相同的 AI 调用路径，需要同步透传 AgentConfig。

在 openapi.php 的 chat 处理逻辑中（约在调用 PromptEngine 方法之前）：
```php
$config = new AgentConfig($db);
```

然后将 `$config` 透传给所有 PromptEngine 方法调用（和 chat.php 同样的改法）。

---

## 文件 4：scripts/regression_chat.php（新增）

创建回归测试脚本，按蓝图 附录 A 的 16 条用例，逐条发 HTTP 请求到 chat.php，断言回复包含期望子串。

```php
<?php
// scripts/regression_chat.php
// Phase 2 回归测试：验证 AgentConfig 切换后 AI 行为不变

$baseUrl = $argv[1] ?? 'http://localhost:8888';  // MAMP 默认端口
$mode    = $argv[2] ?? '--core';  // --core 只跑不依赖网关的用例，--live 全量

$cases = [
    ['id' => '1',  'msg' => '几点入住',              'expect' => '14:00',        'mode' => 'core'],
    ['id' => '2',  'msg' => '中午几点必须走',         'expect' => '12:00',        'mode' => 'core'],
    ['id' => '3',  'msg' => 'WiFi密码多少',           'expect' => '云房卡',        'mode' => 'core'],
    ['id' => '4',  'msg' => '云房卡是什么',           'expect' => '云房卡',        'mode' => 'core'],
    ['id' => '5',  'msg' => '我想续住一晚',           'expect' => '转接人工',      'mode' => 'core'],
    ['id' => '6',  'msg' => '我要退款',               'expect' => '平台',          'mode' => 'core'],
    ['id' => '7',  'msg' => '可以带宠物吗',           'expect' => '宠物',          'mode' => 'core'],
    ['id' => '13', 'msg' => '附近好吃吗',             'expect' => '没有查到',      'mode' => 'core'],
    ['id' => '14', 'msg' => '南宁今天适合出门吗',     'expect' => '没有查到',      'mode' => 'core'],
    // 以下依赖 Sidecar + 网关，仅 --live 模式跑
    ['id' => 'F1', 'msg' => 'order_query:1128148162721995', 'expect' => '查询成功', 'mode' => 'live'],
    ['id' => '8',  'msg' => '房间地址在哪',           'expect' => '订单号',        'mode' => 'live'],
    ['id' => '9',  'msg' => '',                       'expect' => '',              'mode' => 'live', 'note' => '需要上一条 session 发订单号'],
    ['id' => '10', 'msg' => '有停车场吗',             'expect' => '停车',          'mode' => 'live', 'note' => '需绑单后'],
    ['id' => '11', 'msg' => '垃圾放哪',               'expect' => '垃圾',          'mode' => 'live', 'note' => '需绑单后'],
    ['id' => '12', 'msg' => '房间有洗衣机吗',         'expect' => '',              'mode' => 'live', 'note' => '需绑单后，不含温馨提示全文'],
    ['id' => '15', 'msg' => '谢谢',                   'expect' => '好的',          'mode' => 'live', 'note' => '需绑单后'],
];

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($cases as $c) {
    if ($mode === '--core' && ($c['mode'] ?? '') === 'live') {
        echo "SKIP  #{$c['id']} {$c['msg']} (需要网关/Sidecar)\n";
        $skipped++;
        continue;
    }

    $sessionId = 'reg_' . $c['id'] . '_' . time();
    $body = json_encode(['session_id' => $sessionId, 'message' => $c['msg']], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($baseUrl . '/api/chat.php?action=chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    $reply = $data['data']['reply'] ?? '';

    if ($c['expect'] === '') {
        echo "PASS  #{$c['id']} {$c['msg']} → {$reply}\n";
        $passed++;
    } elseif (mb_strpos($reply, $c['expect']) !== false) {
        echo "PASS  #{$c['id']} {$c['msg']} → {$reply}\n";
        $passed++;
    } else {
        echo "FAIL  #{$c['id']} {$c['msg']}\n  expect: {$c['expect']}\n  actual: {$reply}\n";
        $failed++;
    }
}

echo "\n==========\n";
echo "通过: {$passed}  失败: {$failed}  跳过: {$skipped}\n";
exit($failed > 0 ? 1 : 0);
```

---

## 验证 Checklist

1. **`regression_chat.php --core` 全部通过**（#1-#7, #13, #14）
2. 后台「AI 行为规则」Tab 修改 `agent.fallback.reply` → 刷新 → 再发 #14 用例 → 回复变为新文案
3. **16 条用例子串断言全部通过**（核心和网关用例）
4. `openapi.php` 通过 API Key 调用，回复与 chat.php 一致

---

## 不改的清单（明确排除）
- chat.php L425-481（order_query 冻结块）
- callGateway() 函数
- rewriteQuery()、searchKnowledge()、buildMascotLayer()
- RoomQueryFlow、SidecarIntent 等 Sidecar 相关文件（Phase 3 才动）
- checkInputSafety 的成人词和注入词（固定 Core）

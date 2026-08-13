<?php
// api/PromptEngine.php
// 企业客服提示词拼装器
//
// 设计要点：
//  - system prompt 静态化：禁止层 + 吉祥物语气层（不含品牌故事/业务事实）
//  - 多轮历史用标准 role 数组传递（不是文本 dump 到 system）
//  - 知识库注入到最后一条 user 消息（RAG 标准模式）
//  - rewriteQuery 智能跳过：无代词/首问时不调用 AI

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AgentConfig.php';
require_once __DIR__ . '/HandoffTriggers.php';

class PromptEngine {

    // 历史多轮的字符预算（中文约 1.5字/token，1200 字 ≈ 800 token）
    const HISTORY_CHAR_BUDGET     = 1200;

    // 知识库限制
    const KB_MAX_ITEMS            = 3;
    const KB_ITEM_MAX_CHARS       = 200;

    // 问题改写
    // 默认使用 DeepSeek V4 Flash + 关闭推理（thinking.disabled），改写场景不需要推理
    // 实测对比：开推理 93 tokens / 关推理 7 tokens（省 92%）
    const DEFAULT_REWRITE_MODEL    = 'deepseek-v4-flash';
    const DEFAULT_REWRITE_TIMEOUT  = 4;     // 关推理后响应快，4 秒够用
    const DEFAULT_REWRITE_MAXTOKEN = 100;   // 关推理后 content 通常 5-20 token
    const MIN_REWRITE_LENGTH       = 8;     // 短于此长度且含代词才改写

    // ──────────────────────────────────────────
    // 公开接口
    // ──────────────────────────────────────────

    /**
     * 构建 system prompt（静态，只含人设+规则，不依赖 history）
     * 拼装顺序：第一层禁止（事实）→ 话术禁止 → 转人工 → 吉祥物语气（不含品牌故事）
     * 保留 history 参数仅为向后兼容，内部不使用
     * 注：brand_story 不进入 prompt，品牌/企业事实应录入知识库，经 RAG 召回
     */
    public static function buildSystem(array $persona, array $history = [], ?AgentConfig $config = null): string {
        $name         = trim($persona['name']          ?? '客服');
        $tagline      = trim($persona['description']   ?? '');
        $personality  = trim($persona['personality']  ?? '');
        $speakStyle   = trim($persona['speak_style']  ?? '');
        $serviceRules = trim($persona['service_rules'] ?? '');
        $principles   = trim($persona['principles']    ?? '');
        $emotionStrategy = trim($persona['emotion_strategy'] ?? '');

        $platforms = ($config && ($v = $config->get('agent.rules.booking_platforms'))) ? $v : '携程、美团、去哪儿';
        $brand     = ($config && ($v = $config->get('agent.rules.booking_brand_hint'))) ? $v : '宿家民宿';
        $bookingLine = '4. 引导客人在本客服或 AI 对话中预订、下单；若被问如何订房，只指引至' . $platforms . '搜索' . $brand;

        $parts = [];

        // ── 第一层：事实禁止（最高优先级，覆盖人设与历史）──
        $parts[] = self::buildProhibitionLayer($config);

        // ── 第二层：话术与行为禁止 ──
        $parts[] = '';
        $parts[] = '【第二层·话术禁止】以下内容绝对不能出现在回复中';
        $speechBans = ($config) ? $config->getJson('agent.rules.speech_bans', []) : [];
        if (!empty($speechBans)) {
            foreach ($speechBans as $i => $ban) {
                if ($i === 3) {
                    $parts[] = $bookingLine;
                } else {
                    $parts[] = ($i + 1) . '. ' . $ban;
                }
            }
        } else {
            $parts[] = '1. 推荐房源、换房、升级、预订、下单等任何引导消费的话术';
            $parts[] = '2. 追问房源、小区、房型、订单平台等信息';
            $parts[] = '3. 反问式追问(如"您是想了解什么吗""需要我帮您吗")';
            $parts[] = $bookingLine;
            $parts[] = '5. 任何万能结尾，如"有任何问题随时找我"、"有我在"等';
            $parts[] = '6. 回答后追加任何第二句、第三句';
        }

        $parts[] = '';
        $handoffHint = ($config) ? trim((string)$config->get('agent.rules.handoff_system_hint', '')) : '';
        if ($handoffHint !== '') {
            foreach (explode("\n", $handoffHint) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $parts[] = $line;
                }
            }
        } else {
            $parts[] = '【必须直接转人工】以下问题只回复"正在为您转接人工客服，请稍候。"不做任何其他回答';
            $parts[] = '涉及：发票、续住、换房、退款、投诉、赔偿、押金纠纷等；具体以系统「转人工规则」词库为准';
        }

        $parts[] = '';
        $minChars = $config ? $config->getInt('agent.reply.min_chars', 20) : 20;
        $maxChars = $config ? $config->getInt('agent.reply.max_chars', 80) : 80;
        $parts[] = "【回复格式】只输出一个完整的陈述句（{$minChars}-{$maxChars}字），句号结束，不追加任何内容";

        // ── 吉祥物语气层（仅代入角色，不含品牌/业务事实）──
        $parts = array_merge($parts, self::buildMascotLayer($name, $tagline, $personality, $speakStyle));

        // ── 服务规范（行为边界，非业务事实）───────────────────────────────
        if ($serviceRules) {
            // 修复 2：按句号切分，只过滤含"掌柜"的转接话术句，其余规则保留
            $sentences = preg_split('/[。]/u', $serviceRules, -1, PREG_SPLIT_NO_EMPTY);
            $filtered = array_filter($sentences, function ($s) {
                return mb_strpos(trim($s), '掌柜') === false;
            });
            $simpleRules = implode('。', array_map('trim', $filtered)) . '。';
            if ($simpleRules !== '。') {
                $parts[] = '';
                $parts[] = '【服务边界】' . $simpleRules . '遇到上述问题回复"正在为您转接人工客服，请稍候。"';
            }
        }

        if ($principles) {
            $parts[] = '';
            // 修复 3：不再用 50 字符正则截断（会把整段中文原则删空）
            $parts[] = '【处事原则】' . trim($principles);
        }

        // 修复 1：情绪应对策略进入 prompt（行为层，无业务事实风险）
        if ($emotionStrategy) {
            $parts[] = '';
            $parts[] = '【情绪应对】' . $emotionStrategy;
        }

        return implode("\n", $parts);
    }

    /**
     * 吉祥物语气层：名字 + 性格 + 说话风格，用于 OC 代入，不承载品牌故事或业务事实
     */
    public static function buildMascotLayer(string $name, string $tagline, string $personality, string $speakStyle): array {
        $parts = [];
        $identity = "你是{$name}，企业的 AI 客服吉祥物。";
        if ($tagline !== '') {
            $identity .= $tagline;
        }
        $parts[] = '';
        $parts[] = '【吉祥物身份】' . $identity;
        $parts[] = '说明：此段仅决定语气与表达方式；企业介绍、品牌故事、地址设施等业务事实只能从【参考资料】获取。';

        if ($personality !== '') {
            $cleanPersonality = self::_sanitizeMascotText(
                trim(preg_replace('/[,，]。+用了.+来/u', '。', $personality))
            );
            if ($cleanPersonality !== '') {
                $parts[] = '';
                $parts[] = '【性格】' . $cleanPersonality;
            }
        }

        if ($speakStyle !== '') {
            $cleanStyle = self::_sanitizeMascotText(
                trim(preg_replace('/\n{3,}/', "\n\n", preg_replace('/常用表达[：:][^。]+$/m', '', $speakStyle)))
            );
            if ($cleanStyle !== '') {
                $parts[] = '';
                $parts[] = '【风格】' . $cleanStyle;
            }
        }

        return $parts;
    }

    /**
     * 第一层·事实禁止 — AI 客服不得陈述未核实信息
     */
    public static function buildProhibitionLayer(?AgentConfig $config = null): string {
        $fallback = $config ? $config->get('agent.fallback.reply', self::NO_KB_FALLBACK_REPLY) : self::NO_KB_FALLBACK_REPLY;
        $examples = ($config) ? $config->getJson('agent.prohibition.examples', []) : [];
        if (empty($examples)) {
            $examplesText = '地址/路线/导航/停车/车位/收费/WiFi/门禁/房价/押金/退改政策/设施/设备/订单状态/入住时间/周边配套';
        } else {
            $examplesText = implode('/', $examples);
        }
        return implode("\n", [
            '【第一层·事实禁止】（最高优先级，覆盖人设、历史对话与常识）',
            '你是企业 AI 客服，只能陈述已核实的事实，绝不允许猜测、推断、编造或含糊其辞。',
            '1. 事实唯一来源：本轮用户消息中的【参考资料】。除【参考资料】原文外，任何信息都不得当作事实输出。',
            "2. 无【参考资料】，或资料无法直接回答时：只回复「{$fallback}」，不得补充任何具体细节。",
            "3. 无资料时禁止断言的具体内容：{$examplesText}。",
            '4. 禁止使用「可能、大概、应该、一般、通常、基本、大多、多数、建议、或许、估计、听说」等不确定或兜底措辞来拼凑答案。',
            '5. 禁止把吉祥物人设、历史对话、行业常识、其他城市/其他房源经验当作事实依据。',
            '6. 有【参考资料】时：只能复述或同义改写资料中与问题直接相关的内容；资料未写明的部分一律不得补充。',
            '7. 执行顺序：先判断是否有可用【参考资料】→ 无则固定拒答 → 有则严格限定在资料范围内 → 再遵守话术禁止与回复格式。',
        ]);
    }

    /** 知识库无命中时的唯一允许回复 */
    const NO_KB_FALLBACK_REPLY = '这边暂时没有查到准确信息，建议您联系前台确认。';

    /**
     * 回复终检：无【参考资料】时只允许固定拒答，防止模型绕过 system 约束
     */
    public static function finalizeReply(string $reply, array $kbItems, ?AgentConfig $config = null): string {
        if (!empty(self::_filterKbItems($kbItems))) {
            return trim($reply);
        }
        $fallback = $config ? $config->get('agent.fallback.reply', self::NO_KB_FALLBACK_REPLY) : self::NO_KB_FALLBACK_REPLY;
        $reply = trim($reply);
        if (self::isPreservedReplyWithoutKb($reply, $config)) {
            return $reply;
        }
        if ($reply === $fallback) {
            return $reply;
        }
        if (mb_strpos($reply, '没有查到') !== false || mb_strpos($reply, '暂未') !== false) {
            if (mb_strpos($reply, '前台') !== false) {
                return $fallback;
            }
        }
        return $fallback;
    }

    /** 无 KB 时仍须保留的固定回复（转人工、安全拦截等） */
    private static function isPreservedReplyWithoutKb(string $reply, ?AgentConfig $config = null): bool {
        $markers = ($config) ? $config->getJson('agent.fallback.preserved_markers', []) : [];
        if (empty($markers)) {
            $markers = ['转接人工', '不太方便讨论', '没法聊', '无法回应'];
        }
        foreach ($markers as $mark) {
            if ($mark !== '' && mb_strpos($reply, $mark) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 构建最后一条 user 消息（含知识上下文 + 本轮事实约束）
     */
    public static function buildUserTurn(string $message, array $kbItems, ?AgentConfig $config = null): string {
        $lines = [];
        $validKb = self::_filterKbItems($kbItems);
        $fallback = $config ? $config->get('agent.fallback.reply', self::NO_KB_FALLBACK_REPLY) : self::NO_KB_FALLBACK_REPLY;

        if (empty($validKb)) {
            $lines[] = '【本轮约束】当前无【参考资料】。';
            $lines[] = '不得输出任何具体业务事实，只回复：' . $fallback;
        } else {
            $lines[] = '【本轮约束】只能依据下列【参考资料】作答；资料未写明的信息一律不得补充、推断或编造。';
            $lines[] = '';
            $lines[] = '【参考资料】';

            $idx = 1;
            foreach ($validKb as $item) {
                $q = trim($item['question'] ?? '');
                $a = mb_substr(trim($item['answer'] ?? ''), 0, self::KB_ITEM_MAX_CHARS);
                $lines[] = "[资料{$idx}] 问：{$q}";
                $lines[] = "        答：{$a}";
                $idx++;
            }
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = $message;

        return implode("\n", $lines);
    }

    /**
     * 通用政策 KB 直答（无需订单号），优先于 Sidecar 房间流与转人工触发词。
     */
    public static function directReplyFromKb(string $message, array $kbItems, ?PDO $db = null, ?AgentConfig $config = null): ?string {
        $candidates = self::_filterKbItems($kbItems);
        if ($db !== null) {
            $extra = self::searchKnowledge($db, $message, self::KB_MAX_ITEMS);
            $candidates = self::_mergeKbItems($candidates, $extra);
        }
        if (empty($candidates)) {
            return null;
        }

        foreach ($candidates as $item) {
            if (self::_isRoomRoutedAnswer((string)($item['answer'] ?? ''), $config)) {
                continue;
            }
            if ($config && self::_isYunfangkaCredentialEntry($item, $config) && !$config->isCredentialQuery($message)) {
                continue;
            }
            if (self::_messageMatchesKbEntry($message, $item, $config)) {
                return trim((string)$item['answer']);
            }
        }

        return null;
    }

    /**
     * 后处理过滤：修复模型不听话时加的戏
     * 规则优先级：转人工 > 消推销 > 消万能结尾
     */
    public static function filterReply(string $reply, string $message, PDO $db, ?AgentConfig $config = null): string {
        $reply = trim($reply);
        if ($reply === '') return $reply;

        // ── 规则1：命中后台维护的转人工触发词 ─────────────────
        if (HandoffTriggers::matchesMessage($db, $message)) {
            return '正在为您转接人工客服，请稍候。';
        }

        // ── 规则2：去掉推销话术（推荐房源、换房、升级等）────────
        $salesPatterns = ($config) ? $config->getJson('agent.filter.sales_patterns', []) : [];
        if (empty($salesPatterns)) {
            $salesPatterns = [
                '/推荐.*房型/u', '/建议.*升级/u', '/建议.*换/u',
                '/看看.*套房/u', '/适合.*人数.*房型/u',
                '/可以.*看看/u', '/可以.*选择/u',
            ];
        }
        foreach ($salesPatterns as $pattern) {
            $pattern = self::_ensureRegexPattern($pattern);
            if ($pattern === '') {
                continue;
            }
            if (preg_match($pattern, $reply)) {
                // 保留第一句陈述，丢弃后续推销
                $firstSentence = preg_split('/[。！？\n]/u', $reply);
                $reply = trim($firstSentence[0]);
                if (mb_substr($reply, -1) !== '。') $reply .= '。';
                break;
            }
        }

        // ── 规则3：去掉万能结尾 ─────────────────────────────────
        $badEndings = ($config) ? $config->getJson('agent.filter.bad_endings', []) : [];
        if (empty($badEndings)) {
            $badEndings = [
                '有任何问题随时找我', '有我在', '随时联系我',
                '有什么可以帮您', '随时为您服务', '请告诉我',
                '需要的话', '可以告诉我', '我帮您',
            ];
        }
        foreach ($badEndings as $ending) {
            if (mb_strpos($reply, $ending) !== false) {
                $reply = trim(preg_replace('/' . preg_quote($ending, '/u') . '.*$/u', '', $reply));
                $reply = trim(preg_replace('/[，,、\s]+$/u', '', $reply));
                if ($reply !== '' && !preg_match('/[。！？]$/u', $reply)) $reply .= '。';
                break;
            }
        }

        // ── 规则4：去掉以"您"开头的后续问句 ─────────────────────
        $lines = preg_split('/[。！？]/u', $reply);
        if (count($lines) > 1) {
            $first = trim($lines[0]);
            $hasSecond = false;
            for ($i = 1; $i < count($lines); $i++) {
                $l = trim($lines[$i]);
                if ($l === '') continue;
                if (mb_strpos($l, '您') === 0 || mb_strpos($l, '您方便') !== false) {
                    $hasSecond = true;
                    break;
                }
            }
            if ($hasSecond && $first !== '') {
                $reply = $first;
                if (mb_substr($reply, -1) !== '。') $reply .= '。';
            }
        }

        // ── 规则5：超过2句时只保留第一句 ───────────────────────
        $sentences = preg_split('/[。！？]/u', $reply);
        $sentences = array_filter(array_map('trim', $sentences));
        if (count($sentences) > 2) {
            $reply = $sentences[0];
            if (mb_substr($reply, -1) !== '。') $reply .= '。';
        }

        return $reply;
    }

    /**
     * 组装完整 messages 数组（标准多轮 role 格式）
     *
     * @param array  $persona        人设配置
     * @param array  $history        历史对话（含 role/content 的数组）
     * @param string $message        本轮用户消息
     * @param array  $kbItems        本轮检索到的知识库条目
     * @param string $sessionId      会话ID（仅用于调试日志）
     * @param string $rewrittenQuery 改写后问题（仅用于调试日志）
     */
    public static function buildMessages(
        array  $persona,
        array  $history,
        string $message,
        array  $kbItems,
        string $sessionId = '',
        string $rewrittenQuery = '',
        ?AgentConfig $config = null
    ): array {
        $messages = [
            ['role' => 'system', 'content' => self::buildSystem($persona, $history, $config)],
        ];

        // ★ 关键：多轮历史用 role 数组传递，按字符预算从最新往回保留
        foreach (self::_trimHistory($history) as $msg) {
            $role    = $msg['role']    ?? '';
            $content = trim($msg['content'] ?? '');
            if ($content && in_array($role, ['user', 'assistant'], true)) {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        // 最后一条 user：本轮问题 + 知识库参考资料
        $messages[] = [
            'role'    => 'user',
            'content' => self::buildUserTurn($message, $kbItems, $config),
        ];

        if (self::_debugMode()) {
            $logQuery = $rewrittenQuery ?: $message;
            self::_writeDebugLog($sessionId, $message, $logQuery, count($kbItems), $kbItems, $messages);
        }

        return $messages;
    }

    /**
     * 知识库检索：FULLTEXT 优先，降级到 LIKE
     */
    public static function searchKnowledge(PDO $db, string $query, int $maxCount = null): array {
        $maxCount = $maxCount ?? self::KB_MAX_ITEMS;
        $q = mb_substr(trim($query), 0, 100);
        if (mb_strlen($q) < 2) return [];

        try {
            $stmt = $db->prepare("
                SELECT question, answer,
                       MATCH(question, answer, keywords)
                       AGAINST(? IN NATURAL LANGUAGE MODE) AS score
                FROM kb_entries
                WHERE status = 1
                  AND MATCH(question, answer, keywords)
                      AGAINST(? IN NATURAL LANGUAGE MODE) > 0
                ORDER BY score DESC
                LIMIT ?
            ");
            $stmt->execute([$q, $q, $maxCount]);
            $rows = $stmt->fetchAll();
            if (!empty($rows)) return $rows;
        } catch (Exception $e) {
            error_log('FULLTEXT search error: ' . $e->getMessage());
        }

        $keywordHits = self::_searchByKeywords($db, $q, $maxCount);
        if (!empty($keywordHits)) {
            return $keywordHits;
        }

        // v3.4：n-gram LIKE 消歧义（解决口语变体 FULLTEXT 匹配不到的问题）
        $ngramHits = self::_searchByNgram($db, $q, $maxCount);
        if (!empty($ngramHits)) {
            return $ngramHits;
        }

        return self::_searchFallback($db, $q, $maxCount);
    }

    /** 按 keywords 字段逗号分词匹配（弥补 FULLTEXT 对口语短句不敏感） */
    private static function _searchByKeywords(PDO $db, string $query, int $maxCount): array {
        $stmt = $db->query('SELECT question, answer, keywords FROM kb_entries WHERE status = 1');
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kws = array_filter(array_map('trim', explode(',', (string)($row['keywords'] ?? ''))));
            foreach ($kws as $kw) {
                if ($kw === '') {
                    continue;
                }
                if (mb_strpos($query, $kw) !== false || mb_strpos($kw, $query) !== false) {
                    $matches[] = ['question' => $row['question'], 'answer' => $row['answer']];
                    break;
                }
            }
        }
        return array_slice($matches, 0, $maxCount);
    }

    /**
     * v3.4：n-gram LIKE 消歧义
     * 解决：FULLTEXT 对口语变体（"几点可以入住呀"→"几点入住"）敏感，
     *       keywords 字段常为空，导致用户口语提问落入 UNKNOWN。
     * 策略：把查询按 2/3/4 字窗口切 n-gram，任一 n-gram 命中 KB 问题的 LIKE，
     *       返回该条（按命中长度降序，命中越长越可信）。
     */
    private static function _searchByNgram(PDO $db, string $query, int $maxCount): array {
        $clean = preg_replace('/[啊呀哦呢吧呗嘛哈喽嗯呃]+/u', '', trim($query)); // 去语气词
        $clean = preg_replace('/[\s\W_]+/u', '', $clean); // 去空白和符号
        $len = mb_strlen($clean);
        if ($len < 2) return [];

        // 生成候选 n-gram（优先长 n-gram）
        $grams = [];
        for ($n = min(6, $len); $n >= 2; $n--) {
            for ($i = 0; $i + $n <= $len; $i++) {
                $grams[] = mb_substr($clean, $i, $n);
            }
        }
        // 去重保序
        $grams = array_values(array_unique($grams));
        if (empty($grams)) return [];

        // 单次查询拉取所有问题（数量通常几百条，可控）
        $stmt = $db->query('SELECT id, question, answer FROM kb_entries WHERE status = 1');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return [];

        $best = []; // gram_len => row
        foreach ($grams as $g) {
            if (isset($best[mb_strlen($g)])) continue; // 同长度已有命中
            foreach ($rows as $row) {
                if (mb_strpos($row['question'], $g) !== false || mb_strpos($row['keywords'] ?? '', $g) !== false) {
                    $best[mb_strlen($g)] = ['question' => $row['question'], 'answer' => $row['answer'], 'gram' => $g];
                    break;
                }
            }
        }
        krsort($best); // 命中 n 越大越优先
        return array_slice(array_values($best), 0, $maxCount);
    }

    /**
     * 改写问题：消解代词，便于知识库检索
     * 智能跳过：首问、问题独立无代词时不调用 AI
     */
    public static function rewriteQuery(string $message, array $history = [], string $sessionId = ''): string {
        // 智能跳过 1：无用户历史（首问）→ 不需要改写
        $userHistory = [];
        foreach ($history as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $content = trim($msg['content'] ?? '');
                if ($content) $userHistory[] = $content;
            }
        }
        if (empty($userHistory)) return $message;

        // 智能跳过 2：问题不含代词且长度够 → 已经独立完整
        if (!self::_hasPronoun($message) && mb_strlen($message) >= self::MIN_REWRITE_LENGTH) {
            return $message;
        }

        // 拼接历史
        $numberedHistory = '';
        foreach ($userHistory as $i => $h) {
            $numberedHistory .= ($i + 1) . ') ' . $h . "\n";
        }

        $fewShot = <<<'EXAMPLES'
示例：
历史：你住哪里？/ 能寄存行李吗？
当前：它几点可以取？
改写：行李寄存几点可以取？

历史：有哪些房型？/ 大床房多少钱？/ 取消收手续费吗？
当前：那取消怎么收费？
改写：大床房取消怎么收费？

历史：WiFi密码多少？/ 房间有冰箱吗？
当前：它也可以放东西吗？
改写：冰箱也可以放东西吗？
EXAMPLES;

        $rewritePrompt = "你是问题改写助手。根据用户的历史提问和当前提问，把当前提问改写成不依赖上下文就能独立理解的完整问题。\n\n"
            . "规则：\n"
            . "1. 消解\"它/这个/那个/刚才说的\"等代词，替换成具体名词。\n"
            . "2. 历史出现多个实体时，代词默认指向【最近提到的实体】，除非明说\"最开始的那个/第一个\"。\n"
            . "3. 改写后必须有具体主语；无主语疑问句（如\"能退吗？\"\"多少钱？\"）必须补主语。\n"
            . "4. 如果当前问题已独立完整，原样返回。\n"
            . "5. 只输出改写后的问题本身，不要解释/引号/标签。\n\n"
            . $fewShot . "\n"
            . "历史提问（旧→新）：\n{$numberedHistory}\n"
            . "当前提问：{$message}\n\n"
            . "改写后：";

        try {
            $result = callAI([
                ['role' => 'user', 'content' => $rewritePrompt],
            ], [
                'model'       => self::_rewriteModel(),
                'temperature' => 0.1,
                'max_tokens'  => self::DEFAULT_REWRITE_MAXTOKEN,
                'timeout'     => self::_rewriteTimeout(),
                'thinking'    => ['type' => 'disabled'],  // 改写场景关闭推理，省 90% tokens
            ]);

            $rewritten = self::_sanitizeRewrite(trim($result['content'] ?? ''));

            // 清洗后为空、或与原文相同 → 用原文
            if ($rewritten === '' || $rewritten === $message) {
                return $message;
            }

            if (self::_debugMode()) {
                self::_writeRewriteLog($sessionId, $message, $rewritten);
            }

            return $rewritten;
        } catch (Exception $e) {
            error_log('rewriteQuery failed: ' . $e->getMessage());
            return $message;
        }
    }

    // ──────────────────────────────────────────
    // 内部方法
    // ──────────────────────────────────────────

    private static function _coreRules(): string {
        return self::buildProhibitionLayer();
    }

    private static function _sanitizeMascotText(string $text): string {
        if ($text === '') return '';
        // 去掉容易诱发编造业务事实的句子（地理、交通、房源分布等）
        $text = preg_replace('/[^。！？\n]{0,8}熟悉[^。！？\n]{0,40}(交通|美食|周边|玩法|区域|路线)[^。！？\n]*[。！？]?/u', '', $text);
        $text = preg_replace('/[^。！？\n]{0,20}(设有|均有|遍布|深耕)[^。！？\n]{0,40}(房源|门店|区域|城市)[^。！？\n]*[。！？]?/u', '', $text);
        $text = preg_replace('/你你/u', '你', $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

    private static function _filterKbItems(array $kbItems): array {
        $valid = [];
        foreach ($kbItems as $item) {
            $q = trim($item['question'] ?? '');
            $a = trim($item['answer'] ?? '');
            if ($q !== '' && $a !== '') {
                $valid[] = $item;
            }
        }
        return $valid;
    }

    /** @param array<int, array<string, mixed>> $primary */
    private static function _mergeKbItems(array $primary, array $extra): array {
        $seen = [];
        $merged = [];
        foreach (array_merge($primary, $extra) as $item) {
            $key = trim((string)($item['question'] ?? '')) . "\0" . trim((string)($item['answer'] ?? ''));
            if ($key === "\0" || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $item;
        }
        return $merged;
    }

    private static function _isRoomRoutedAnswer(string $answer, ?AgentConfig $config = null): bool {
        $phrases = $config ? $config->getJson('agent.routing.sidecar_route_phrases', []) : [];
        if (empty($phrases)) {
            $phrases = ['请提供订单号', '提供订单号', '查询订单后', '请先查询订单'];
        }
        foreach ($phrases as $mark) {
            if (mb_strpos($answer, $mark) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $entry */
    private static function _isYunfangkaCredentialEntry(array $entry, ?AgentConfig $config = null): bool {
        $marker = $config ? $config->get('agent.routing.credential_kb_marker', '请在云房卡中查看') : '请在云房卡中查看';
        $answer = (string)($entry['answer'] ?? '');
        return mb_strpos($answer, $marker) !== false;
    }

    /** @param array<string, mixed> $entry */
    private static function _messageMatchesKbEntry(string $message, array $entry, ?AgentConfig $config = null): bool {
        $msg = trim($message);
        if ($msg === '') {
            return false;
        }

        $kws = array_filter(array_map('trim', explode(',', (string)($entry['keywords'] ?? ''))));
        foreach ($kws as $kw) {
            if ($kw !== '' && mb_strpos($msg, $kw) !== false) {
                return true;
            }
        }

        $question = trim((string)($entry['question'] ?? ''));
        if ($question !== '') {
            $qCore = preg_replace('/[？?。！!]/u', '', $question);
            if ($qCore !== '' && (mb_strpos($qCore, $msg) !== false || mb_strpos($msg, $qCore) !== false)) {
                return true;
            }
            if (mb_strlen($msg) >= 3 && mb_strlen($qCore) >= 4 && mb_strpos($qCore, mb_substr($msg, 0, 4)) !== false) {
                return true;
            }
        }

        foreach ((array)($entry['similar'] ?? []) as $sim) {
            $sim = trim((string)$sim);
            if ($sim !== '' && ($msg === $sim || mb_strpos($msg, $sim) !== false || mb_strpos($sim, $msg) !== false)) {
                return true;
            }
        }

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
        $blob = $question . ' ' . implode(',', $kws);
        foreach ($policyPatterns as $pattern => $needle) {
            if (preg_match($pattern, $msg) && mb_strpos($blob, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 清洗改写模型输出，剥掉模型可能附带的解释、引号、前缀等噪声
     * 防御任何不守规矩的模型（DeepSeek/MiniMax 都可能偶发输出花式格式）
     */
    private static function _ensureRegexPattern($pattern) {
        $pattern = trim((string)$pattern);
        if ($pattern === '') {
            return '';
        }
        if ($pattern[0] !== '/') {
            $pattern = '/' . str_replace('/', '\/', $pattern) . '/u';
        }
        return $pattern;
    }

    private static function _sanitizeRewrite(string $text): string {
        if ($text === '') return '';

        // 取首行（多行时只保留第一行，丢弃后续解释）
        $lines = preg_split('/\r?\n/', $text);
        $text  = trim($lines[0] ?? '');

        // 剥常见前缀（改写后/改写：/答：/Rewritten: 等）
        $text = preg_replace('/^[\s]*(改写后|改写|结果|答|输出|rewritten|result|output)[\s]*[:：][\s]*/iu', '', $text);

        // 剥首尾引号和括号注释
        $text = preg_replace('/[（(][^）)]*[）)]/u', '', $text);          // 删除全角/半角圆括号内注释
        $text = preg_replace('/^[「『"\'\s]+/u', '', $text);              // 首部引号/空白
        $text = preg_replace('/[」』"\'\s]+$/u', '', $text);              // 尾部引号/空白

        // 去掉 Markdown 强调符号
        $text = trim($text, "*_` \t\n\r\0\x0B");

        return trim($text);
    }

    /**
     * 检测消息是否包含代词（用于判断是否需要改写）
     */
    private static function _hasPronoun(string $message): bool {
        $pronouns = [
            '它', '这个', '那个', '这', '那', '他', '她', '该', '此',
            '刚才', '上面', '前面', '之前说', '你说', '你刚说', '刚说',
        ];
        foreach ($pronouns as $p) {
            if (mb_strpos($message, $p) !== false) return true;
        }
        return false;
    }

    /**
     * 按字符预算从最新一轮往前保留历史，超出截断
     */
    private static function _trimHistory(array $history): array {
        $budget   = self::HISTORY_CHAR_BUDGET;
        $used     = 0;
        $selected = [];

        foreach (array_reverse($history) as $msg) {
            $content = trim($msg['content'] ?? '');
            if (!$content) continue;
            $len = mb_strlen($content);
            if ($used + $len > $budget) break;
            $used += $len;
            array_unshift($selected, $msg);
        }

        return $selected;
    }

    private static function _searchFallback(PDO $db, string $query, int $maxCount): array {
        $like = '%' . $query . '%';
        $stmt = $db->prepare("
            SELECT question, answer
            FROM kb_entries
            WHERE status = 1
              AND (question LIKE ? OR answer LIKE ? OR keywords LIKE ?)
            ORDER BY hit_count DESC
            LIMIT ?
        ");
        $stmt->execute([$like, $like, $like, $maxCount]);
        return $stmt->fetchAll();
    }

    private static function _debugMode(): bool {
        return envVal('PROMPT_ENGINE_DEBUG', 'false') === 'true';
    }

    private static function _rewriteModel(): string {
        return envVal('PROMPT_ENGINE_REWRITE_MODEL', self::DEFAULT_REWRITE_MODEL);
    }

    private static function _rewriteTimeout(): int {
        return intval(envVal('PROMPT_ENGINE_REWRITE_TIMEOUT', self::DEFAULT_REWRITE_TIMEOUT));
    }

    private static function _writeDebugLog(
        string $sessionId,
        string $originalMessage,
        string $rewrittenQuery,
        int    $kbCount,
        array  $kbItems,
        array  $messages
    ): void {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

        $date    = date('Y-m-d');
        $logFile = $logDir . "/prompt_engine_{$date}.log";
        $time    = date('Y-m-d H:i:s');

        $kbJson  = json_encode($kbItems, JSON_UNESCAPED_UNICODE);
        $msgJson = json_encode($messages, JSON_UNESCAPED_UNICODE);

        $logLine  = "[{$time}] [{$sessionId}]\n";
        $logLine .= "原始: {$originalMessage}\n";
        $logLine .= "改写: {$rewrittenQuery}\n";
        $logLine .= "召回({$kbCount}): {$kbJson}\n";
        $logLine .= "消息: {$msgJson}\n";
        $logLine .= "---\n";

        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    private static function _writeRewriteLog(string $sessionId, string $original, string $rewritten): void {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

        $date    = date('Y-m-d');
        $logFile = $logDir . "/prompt_engine_{$date}.log";
        $time    = date('Y-m-d H:i:s');

        $logLine = "[{$time}] [{$sessionId}] REWRITE: '{$original}' -> '{$rewritten}'\n";
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}

<?php
/**
 * api/IntentClassifier.php
 *
 * v3.3 PR1 — 规则 fast path 分类器
 *
 * 设计原则：
 * - LLM 不参与 Intent 判定（蓝图 §六 修正 3）
 * - 规则失败 → 直接 UNKNOWN → UnknownWorkflow 用 LLM 生成兜底话术
 * - Intent 永远由规则判定，避免 fast path 被 LLM 拖慢（30ms → 1.5s）
 *
 * 修正 11：凭证识别用 AgentConfig 实例方法（与现有 chat.php 一致）
 * 修正 12：history 中有近期订单号 → 增强凭证识别
 *
 * 调用入口：IntentClassifier::classify($message, $ctx)
 *   - $ctx 必须包含: db, config, history(可选), session(可选)
 *   - 返回 IntentContext
 */

declare(strict_types=1);

require_once __DIR__ . '/Intent.php';
require_once __DIR__ . '/HandoffTriggers.php';
require_once __DIR__ . '/sidecar/SidecarIntent.php';
require_once __DIR__ . '/RoomQueryFlow.php';
require_once __DIR__ . '/PromptEngine.php';

final class IntentClassifier
{
    /**
     * 主入口：分类一条用户消息
     *
     * @param string $message 用户消息（已 trim）
     * @param array $ctx 必须包含:
     *   - 'db': PDO
     *   - 'config': AgentConfig 实例
     *   - 'history': array 历史消息（可选）
     *   - 'session': array 会话状态（可选）
     * @return IntentContext
     */
    public static function classify(string $message, array $ctx): IntentContext
    {
        $config = $ctx['config'] ?? null;
        $history = $ctx['history'] ?? [];
        $session = $ctx['session'] ?? [];

        if (!($config instanceof \AgentConfig)) {
            throw new \InvalidArgumentException('IntentClassifier requires AgentConfig in ctx');
        }

        // 0. 闲聊优先（修复：晚上好/谢谢/再见 应该走 SMALL_TALK，不能被 KB 抢答）
        if (self::isSmallTalk($message)) {
            return IntentContext::of(
                Intent::SMALL_TALK,
                0.95,
                [],
                'rule:small_talk'
            );
        }

        // 0.5 隐私套话拒绝（v3.9：查手机号/别人订单等隐私查询 → 直接拒绝）
        //     必须在订单判定之前，否则"查186xxxx的订单"会走 ORDER_QUERY
        if (preg_match('/查.*(手机号|电话|身份证|别人的订单|他的订单|她.*订单|帮.*查.*订单|那个客人)/u', $message)) {
            return IntentContext::of(
                Intent::KNOWLEDGE,
                0.95,
                ['privacy_refuse' => true],
                'rule:privacy_refuse'
            );
        }

        // 0.6 AI 身份问答（v3.9：你是AI还是真人 → 自然回答）
        //     必须在转人工之前，否则"你是真人吗"触发 HandoffTriggers 转人工
        if (preg_match('/你是(不是)?\s*(AI|ai|机器人|人工|真人|客服小柚|小柚)/u', $message)) {
            return IntentContext::of(
                Intent::SMALL_TALK,
                0.9,
                [],
                'rule:ai_identity'
            );
        }

        // 1. 转人工（含 priority，修正 18）
        $match = HandoffTriggers::matchKeyword($ctx['db'], $message);
        if ($match !== null) {
            $priority = is_array($match) && isset($match['priority']) ? (int)$match['priority'] : 99;
            return IntentContext::of(
                Intent::HUMAN,
                1.0,
                [],
                'rule:handoff_keyword',
                [],
                $priority
            );
        }

        // 2. 凭证类 → 云房卡引导（独立 Workflow，修正 1）
        //    修正 11：优先用 AgentConfig 实例方法（与 chat.php:319 一致）
        //    修正 12：history 增强判定
        $isCredential = $config->isCredentialQuery($message);
        $hasRecentOrderNo = self::detectRecentOrderNo($history);
        if ($isCredential || ($hasRecentOrderNo && self::isPotentialCredential($message))) {
            return IntentContext::of(
                Intent::ROOM_PASSWORD_QUERY,
                $isCredential ? 0.95 : 0.75,
                $hasRecentOrderNo ? ['order_no' => $hasRecentOrderNo] : [],
                $isCredential ? 'rule:credential' : 'rule:credential_with_history'
            );
        }

        // 3. 房间意图（业务意图优先于 KB，避免"order_query:xxx" 被 KB 误判）
        $roomKeywords = RoomQueryFlow::getRoomKeywords($ctx['db']);
        if (RoomQueryFlow::isRoomIntent($message, $roomKeywords)) {
            $slots = [];
            if (SidecarIntent::looksLikeOrderNo($message)) {
                $slots['order_no'] = $message;
            }
            return IntentContext::of(Intent::ROOM_QUERY, 0.9, $slots, 'rule:sidecar_keyword');
        }

        // 4. 查订单（业务意图优先于 KB）
        if (preg_match('/^order_query:|订单|查单/u', $message)
            || SidecarIntent::looksLikeOrderNo($message)) {
            return IntentContext::of(Intent::ORDER_QUERY, 0.85, [], 'rule:order_keyword');
        }

        // 5. 闲聊二次（isChitchat）
        if (SidecarIntent::isChitchat($message)) {
            return IntentContext::of(Intent::SMALL_TALK, 0.8, [], 'rule:chitchat');
        }

        // 6. 售前判定（v3.8 修正：提到 KB 之前，避免"怎么订房"被 KB 抢答）
        //      业务规则：AI 不接单，订房/价格/空房类问题统一回 OTA
        if (self::isPreSalesQuery($message)) {
            return IntentContext::of(Intent::PRE_SALES, 0.9, [], 'rule:pre_sales');
        }

        // 7. KB 早期命中（v2.0 修正：放在业务意图之后，避免"order_query:xxx"被 KB 误判）
        //      例如"退订怎么操作"含"退"字可能被 RoomQueryFlow 误判 → 仍走 KB
        try {
            $earlyKb = PromptEngine::searchKnowledge($ctx['db'], $message, 3);
            if (!empty($earlyKb)) {
                return IntentContext::of(Intent::KNOWLEDGE, 0.7, [], 'rule:kb_match_early', $earlyKb);
            }
        } catch (\Exception $e) {
            error_log('[IntentClassifier] KB early search failed: ' . $e->getMessage());
        }

        // 8. 都失败 → UNKNOWN（不调 LLM！交由 UnknownWorkflow 生成兜底话术）
        return IntentContext::of(Intent::UNKNOWN, 0.0, [], 'fallback');
    }

    /**
     * 修正 12 辅助方法：从 history 中提取最近一条订单号
     *
     * @param array $history 多轮历史
     * @return string|null 16位以上订单号
     */
    /**
     * 闲聊强匹配（修复：避免"晚上好"被 KB 误判为 KNOWLEDGE/凭证/订单类）
     * 优先级最高，先于转人工、凭证、KB 检索
     */
    private static function isSmallTalk(string $message): bool
    {
        $msg = trim($message);
        if ($msg === '') return false;

        // 强匹配模式（v3.6：去掉 \b——中文后无词边界导致"你好呀"不命中，
        // 改用 lookahead 限定后缀为语气词/标点/结尾，避免"你好烦"误判）
        $patterns = [
            '/^(晚上好|早上好|中午好|下午好|早安|晚安|你好|您好|嗨|哈喽)(?=[呀啊嘛哦哟~～！!。，]|$)/iu',
            '/^(hi|hello|hey)\b[\s~～!！]*/iu',
            '/^(谢谢|感谢|thank\s*you|多谢|thx|3q)(?=[呀啦哦啊~～！!。，]|$)/iu',
            '/^(拜拜|再见|bye\b[\s~～!！]*|886|溜了|走了)(?=[呀啦哦~～！!。，]|$)/iu',
            '/^(在吗|在么|有人吗|在不|在不在)/iu',
            '/^(好的|收到|明白了|ok\b|OK\b|好|嗯嗯)(?=[呀啦哦~～！!。，]|$)/iu',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $msg)) return true;
        }

        // 长度 < 5 且全中文语气词
        if (mb_strlen($msg) <= 5) {
            $small = ['嗯', '哦', '啊', '哈', '呵', '嘿', '唉', '嘻', '嗯嗯', '哈哈', '呵呵', '嘿嘿', '好的', '收到'];
            if (in_array($msg, $small, true)) return true;
        }

        return false;
    }

    /**
     * 售前问题判定（v2.0 PRD 2.3.4）
     * KB 没命中 + 售前关键词 → 引导到 OTA 平台
     *
     * 售前 = 没订单号 + 问"怎么订/价格/空房/房型"
     * 售后 = 有订单号 + 问 WiFi/门锁/地址/退订
     */
    private static function isPreSalesQuery(string $message): bool
    {
        $msg = trim($message);
        if ($msg === '') return false;

        $patterns = [
            // 怎么订
            '/怎么订|如何订|在哪订|怎么预/u',
            // 价格
            '/多少钱|什么价|价位|房费|价格/u',
            // 空房
            '/有空房|有房吗|还有房|空房吗|房型/u',
            // 现场订
            '/可以现场|现场订|到店订|当天订/u',
            // 砍价/优惠(v3.9 新增)
            '/便宜|优惠|打折|折扣|减价|特价|砍价|讲讲价|优惠点/u',
            // 私下交易(v3.9 新增:拒绝绕过平台)
            '/微信转|私下|直接订|不走平台|绕过平台|转账订|红包订|加微信/u',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $msg)) return true;
        }

        return false;
    }

    private static function detectRecentOrderNo(array $history): ?string
    {
        if (!is_array($history) || empty($history)) {
            return null;
        }
        // 只看最近 5 条
        $start = max(0, count($history) - 5);
        for ($i = count($history) - 1; $i >= $start; $i--) {
            $msg = $history[$i] ?? null;
            if (!is_array($msg)) continue;
            // 兼容 'content' 和 'text' 字段
            $content = $msg['content'] ?? ($msg['text'] ?? '');
            if (!is_string($content)) continue;
            // 16位以上数字 = 携程/美团订单号
            if (preg_match('/\d{16,}/', $content, $m)) {
                return $m[0];
            }
        }
        return null;
    }

    /**
     * 修正 12 辅助方法：消息可能涉及凭证但未直接命中关键词
     * （如「密码」「WiFi」「门锁」「刷脸」「押金」等弱信号）
     *
     * @param string $message
     * @return bool
     */
    private static function isPotentialCredential(string $message): bool
    {
        $weakSignals = [
            '密码', 'wifi', 'wi-fi', '门锁', '门禁', '刷脸', '押金',
            '开锁', '进不去', '连不上', '上不了网',
        ];
        $msg = mb_strtolower($message, 'UTF-8');
        foreach ($weakSignals as $sig) {
            if (mb_strpos($msg, $sig) !== false) {
                return true;
            }
        }
        return false;
    }
}
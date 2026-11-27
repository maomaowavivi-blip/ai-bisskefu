<?php
/**
 * api/OrderGuidePick.php
 *
 * v3.35 — 多房订单入住指引选房状态机与匹配（蓝图 §5.4 / §7.5）
 *
 * 状态表 order_guide_sessions（DDL：sql/migration_v3.35_order_guide.sql）：
 *   stage 0=idle 1=wait_room_pick 2=bound；TTL=2 小时（qa A3）。
 *   过期判断一律 SQL NOW()——生产 PHP=UTC / MySQL=CST 差 8 小时，
 *   PHP time()/strtotime() 参与会差 8h（qa B10 / AGENTS.md §15）。
 *
 * 不吞消息原则（qa A3）：stage=1 期间只有能匹配候选的消息才走选房；
 * 不匹配一律 return null 走正常流程，不追问、不拦截。
 *
 * fail-open（qa C6）：所有 DB 访问内部全 catch。新表未建的发布窗口内
 * 不能让客服全线 500 —— 一律按「无选房状态」处理（false / null）。
 */

declare(strict_types=1);

require_once __DIR__ . '/CenterRoom.php';
require_once __DIR__ . '/Intent.php';

final class OrderGuidePick
{
    // ──────────────────────────────────────────
    // 纯函数（无 DB，可单测）
    // ──────────────────────────────────────────

    /**
     * 选房输入归一化（§5.4.1）：①-⑩ → 1-10（qa C2）、trim、去全部空白、小写
     */
    public static function normalizePickText(string $text): string
    {
        $map = [
            '①' => '1', '②' => '2', '③' => '3', '④' => '4', '⑤' => '5',
            '⑥' => '6', '⑦' => '7', '⑧' => '8', '⑨' => '9', '⑩' => '10',
        ];
        $t = trim(strtr($text, $map));
        $t = (string)preg_replace('/\s+/u', '', $t);
        return mb_strtolower($t, 'UTF-8');
    }

    /**
     * 选房匹配伪码（§5.4.1），纯函数。
     *
     * 规则顺序：序号(1..N) → room_code 全等 → 双向包含(按候选去重计数) → 8-30 位数字。
     * 「和德」这类同时命中多间 → ambiguous（重新问，不猜）。
     *
     * @param array[] $candidates [{baoyu_room_no, room_code, display_name}, ...]（顺序即序号）
     * @return array{hit: ?array, ambiguous: bool, newOrderDigits: ?string}
     */
    public static function matchCandidate(string $message, array $candidates): array
    {
        $out = ['hit' => null, 'ambiguous' => false, 'newOrderDigits' => null];
        $t = self::normalizePickText($message);
        if ($t === '' || $candidates === []) {
            return $out;
        }

        // ① 序号：纯数字且 1..N
        if (preg_match('/^\d{1,2}$/', $t) === 1) {
            $n = (int)$t;
            if ($n >= 1 && $n <= count($candidates)) {
                $out['hit'] = $candidates[$n - 1];
                return $out;
            }
        }

        // ② room_code 完全相等
        foreach ($candidates as $c) {
            $code = self::normalizePickText((string)($c['room_code'] ?? ''));
            if ($code !== '' && $t === $code) {
                $out['hit'] = $c;
                return $out;
            }
        }

        // ②b 纯数字全等候选的 baoyu_room_no（宝寓房号是 6-9 位数字，不受 ④ 的 8 位下限约束）
        //    必须排在 ④「新订单号」判定之前：测试房的 baoyu_room_no=123456789 本身就是 9 位数字，
        //    若先按 8-30 位判新订单号，客人回房号会被误判成新订单（qa 验收 D7 缺口）。
        if (preg_match('/^\d{4,30}$/', $t) === 1) {
            foreach ($candidates as $c) {
                $no = trim((string)($c['baoyu_room_no'] ?? ''));
                if ($no !== '' && $t === $no) {
                    $out['hit'] = $c;
                    return $out;
                }
            }
        }

        // ③ 双向包含（room_code / display_name），较短一方长度 <2 不计，按候选去重计数
        //    （displayName() 已把 room_code 拼进 display_name，同一候选只算 1 次，qa B9）
        $matched = [];
        foreach ($candidates as $i => $c) {
            foreach (['room_code', 'display_name'] as $f) {
                $hay = self::normalizePickText((string)($c[$f] ?? ''));
                if ($hay === '') continue;
                if (min(mb_strlen($t), mb_strlen($hay)) < 2) continue; // 防 t="2" 命中所有含 2 的房号
                if (mb_strpos($hay, $t) !== false || mb_strpos($t, $hay) !== false) {
                    $matched[$i] = true;
                }
            }
        }
        if (count($matched) === 1) {
            $keys = array_keys($matched);
            $out['hit'] = $candidates[$keys[0]];
            return $out;
        }
        if (count($matched) > 1) {
            $out['ambiguous'] = true; // 「和德」→ 判歧义重问，不猜
            return $out;
        }

        // ④ 8-30 位连续数字：命中候选房号 → 选中；不命中 → 新订单号（逃生门，qa A2）
        if (preg_match('/\b(\d{8,30})\b/u', $t, $mm) === 1) {
            $digits = $mm[1];
            foreach ($candidates as $c) {
                if ($digits === trim((string)($c['room_code'] ?? ''))
                    || $digits === trim((string)($c['baoyu_room_no'] ?? ''))) {
                    $out['hit'] = $c;
                    return $out;
                }
            }
            $out['newOrderDigits'] = $digits;
        }

        return $out;
    }

    /**
     * 选房问句（§5.2 文案）：
     *   您一共订了 2 间房，请回复序号选择（回复 1 或 2 即可）：
     *   ① 和德新天地 2号楼C单元33楼3303
     */
    public static function buildPickText(array $rooms): string
    {
        $n = count($rooms);
        $marks = ['①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨', '⑩'];
        $lines = [];
        foreach (array_values($rooms) as $i => $r) {
            $name = trim((string)($r['display_name'] ?? ''));
            $code = trim((string)($r['room_code'] ?? ''));
            if ($name === '') $name = $code;
            if ($name === '') $name = '房间' . ($i + 1);
            $mark = $marks[$i] ?? ((string)($i + 1) . '.');
            $lines[] = $mark . ' ' . $name;
        }
        return "您一共订了 {$n} 间房，请回复序号选择（回复 1 或 {$n} 即可）：\n" . implode("\n", $lines);
    }

    /**
     * steps → 指引文字（§5.2.3：所有 step 的 title+text 用 \n 拼接，中台原文不改一字）
     * @param array[] $steps CenterRoom::checkinAll() 输出
     */
    public static function guideTextFromSteps(array $steps): string
    {
        $parts = [];
        foreach ($steps as $st) {
            if (!is_array($st)) continue;
            $title = trim((string)($st['title'] ?? ''));
            $text  = trim((string)($st['text'] ?? ''));
            if ($title !== '') $parts[] = $title;
            if ($text  !== '') $parts[] = $text;
        }
        return implode("\n", $parts);
    }

    // ──────────────────────────────────────────
    // DB 状态读写（全部内部 catch，fail-open）
    // ──────────────────────────────────────────

    /** stage=1 且未过期是否存在（表缺失/DB 异常 → false，客服不受影响） */

    /**
     * 关闭本会话的选房状态（单房/0 候选时调用，P2-5）。
     * 只把 stage 置 2 并清空候选，不删行（留痕便于排查）；内部 catch → fail-open。
     */
    public static function closeSession(\PDO $db, string $sessionId): void
    {
        try {
            $st = $db->prepare(
                'UPDATE order_guide_sessions SET stage = 2, room_candidates = NULL
                 WHERE session_id = ? AND stage = 1'
            );
            $st->execute([$sessionId]);
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] closeSession failed (fail-open): ' . $e->getMessage());
        }
    }

    /** OrderQueryWorkflow 多房订单写 stage=1 + 候选（写入时机统一在 workflow 侧，P2-6） */
    public static function openSession(\PDO $db, string $sessionId, string $orderNo, array $candidates): void
    {
        try {
            $slim = [];
            foreach ($candidates as $c) {
                $slim[] = [
                    'baoyu_room_no' => (string)($c['baoyu_room_no'] ?? ''),
                    'room_code'     => (string)($c['room_code'] ?? ''),
                    'display_name'  => (string)($c['display_name'] ?? ''),
                    'room_id'       => isset($c['room_id']) ? (int)$c['room_id'] : 0,
                ];
            }
            $st = $db->prepare(
                'INSERT INTO order_guide_sessions (session_id, order_no, stage, attempts, room_candidates, expires_at)
                 VALUES (?, ?, 1, 0, ?, NOW() + INTERVAL 2 HOUR)
                 ON DUPLICATE KEY UPDATE order_no=VALUES(order_no), stage=1, attempts=0,
                   room_candidates=VALUES(room_candidates), expires_at=VALUES(expires_at)'
            );
            $st->execute([$sessionId, $orderNo, json_encode($slim, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] openSession failed (fail-open): ' . $e->getMessage());
        }
    }

    /**
    /**
     * 现查中台取该房指引（不缓存快照，qa C5）。指引链路一律 checkinAll()（P1-4：保留有图无字 step）。
     * @return array{ok:bool, steps:array, room_code:string, display_name:string, baoyu_room_no:string}
     */
    public static function buildGuide(\PDO $db, array $candidate): array
    {
        $no = trim((string)($candidate['baoyu_room_no'] ?? ''));
        $out = ['ok' => false, 'steps' => [], 'room_code' => '', 'display_name' => '', 'baoyu_room_no' => $no];
        if ($no === '') return $out;
        try {
            $room = CenterRoom::loadByBaoyuRoomNo($db, $no);
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] buildGuide load failed: ' . $e->getMessage());
            return $out;
        }
        if (!is_array($room)) return $out;
        $out['ok']           = true;
        $out['steps']        = CenterRoom::checkinAll($room);
        $out['room_code']    = trim((string)($room['room_code'] ?? ''));
        $out['display_name'] = CenterRoom::displayName($room);
        return $out;
    }

    /**
     * ChatPipeline 早退入口（§7.5.1，在 IntentClassifier::classify() 之前调用）。
     *
     * 命中 → 现查指引、stage=2、返回与正常路径形状一致的 [code,msg,data]
     *        （含 intent/confidence/workflow/reasoning + order_guide，P2-11；chat_logs 已补写）。
     * 不命中/无状态/异常 → null（正常流程，不追问）。
     */
    public static function tryHandle(
        \PDO $db,
        string $sessionId,
        string $message,
        string $channel = 'web',
        string $visitorHash = '',
        string $ip = ''
    ): ?array {
        try {
            $row = self::loadPending($db, $sessionId);
            if ($row === null) return null; // 无 stage=1 或已过期（SQL NOW()）

            $candidates = self::decodeCandidates($row);
            if (count($candidates) < 2) {
                self::clearStage($db, $sessionId);
                return null;
            }

            $msg = trim($message);

            // 逃生门（§5.4）：转人工 / 查单意图 / 明确退出词 → 立即清 stage 走正常流程
            if (self::isEscapeMessage($db, $msg)) {
                self::clearStage($db, $sessionId);
                return null;
            }

            $m = self::matchCandidate($msg, $candidates);

            // 歧义（「和德」命中多间）→ attempts+1 并重新发一次选房问句（不猜，qa B9）
            if ($m['ambiguous']) {
                self::bumpAttempts($db, $sessionId);
                $pickText = self::buildPickText($candidates);
                return self::buildChannelResult(
                    $db, $sessionId, $pickText,
                    ['stage' => 'precheckin', 'mode' => 'pick', 'lead' => '',
                     'rooms' => [], 'multi' => true, 'pick_text' => $pickText],
                    'rule:order_guide_pick_ambiguous', $message, $channel, $visitorHash, $ip
                );
            }

            if ($m['hit'] !== null) {
                $guide = self::buildGuide($db, $m['hit']);
                if (!$guide['ok'] || $guide['steps'] === []) {
                    // 房源缺失/指引为空：置 stage=2 后走正常流程（不发指引、不说「暂无」、不再循环问房）
                    self::bindStage2($db, $sessionId);
                    return null;
                }
                self::bindStage2($db, $sessionId);
                $room = [
                    'baoyu_room_no' => (string)$guide['baoyu_room_no'],
                    'room_code'     => (string)$guide['room_code'],
                    'display_name'  => (string)$guide['display_name'],
                    'has_guide'     => true,
                    'steps'         => $guide['steps'],
                ];
                return self::buildChannelResult(
                    $db, $sessionId, self::guideTextFromSteps($guide['steps']),
                    ['stage' => 'precheckin', 'mode' => 'pick', 'lead' => '',
                     'rooms' => [$room], 'multi' => false, 'pick_text' => ''],
                    'rule:order_guide_pick', $message, $channel, $visitorHash, $ip
                );
            }

            if ($m['newOrderDigits'] !== null) {
                // 8-30 位数字不匹配任何候选 → 新订单号，清 stage 走正常查单（qa A2）
                self::clearStage($db, $sessionId);
                return null;
            }

            // 不匹配 → attempts+1（连续 2 次清 stage，仅作自愈不作拦截，§5.4），走正常流程
            self::bumpAttempts($db, $sessionId);
            return null;
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] tryHandle failed (fail-open): ' . $e->getMessage());
            return null;
        }
    }

    // ──────────────────────────────────────────
    // 内部
    // ──────────────────────────────────────────

    private static function loadPending(\PDO $db, string $sessionId): ?array
    {
        try {
            $st = $db->prepare(
                'SELECT session_id, order_no, stage, attempts, room_candidates
                 FROM order_guide_sessions
                 WHERE session_id = ? AND stage = 1 AND expires_at > NOW() LIMIT 1'
            );
            $st->execute([$sessionId]);
            $row = $st->fetch();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] loadPending failed (fail-open): ' . $e->getMessage());
            return null;
        }
    }

    private static function clearStage(\PDO $db, string $sessionId): void
    {
        try {
            $st = $db->prepare('UPDATE order_guide_sessions SET stage = 0, attempts = 0 WHERE session_id = ?');
            $st->execute([$sessionId]);
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] clearStage failed: ' . $e->getMessage());
        }
    }

    private static function bindStage2(\PDO $db, string $sessionId): void
    {
        try {
            $st = $db->prepare(
                'UPDATE order_guide_sessions SET stage = 2, attempts = 0, expires_at = NOW() + INTERVAL 2 HOUR WHERE session_id = ?'
            );
            $st->execute([$sessionId]);
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] bindStage2 failed: ' . $e->getMessage());
        }
    }

    private static function bumpAttempts(\PDO $db, string $sessionId): void
    {
        try {
            // MySQL UPDATE 赋值从左到右取新值：attempts 先 +1，stage 条件里的 attempts 已是新值。
            // 连续不匹配 2 次 → 清 stage（放弃选房走正常流程，§5.4/A4）。
            $st = $db->prepare(
                'UPDATE order_guide_sessions SET attempts = attempts + 1, stage = IF(attempts >= 2, 0, stage) WHERE session_id = ?'
            );
            $st->execute([$sessionId]);
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] bumpAttempts failed: ' . $e->getMessage());
        }
    }

    private static function decodeCandidates(array $row): array
    {
        $c = json_decode((string)($row['room_candidates'] ?? ''), true);
        if (!is_array($c)) return [];
        return array_values(array_filter($c, static function ($x) { return is_array($x); }));
    }

    /**
     * 逃生门判定（§5.4）。⚠️ P2-9：不把「谢谢」当退出词（客套话会打断选房）。
     * 退出词只认整条消息就是短促明确的一个词，避免误伤正文。
     */
    private static function isEscapeMessage(\PDO $db, string $msg): bool
    {
        if ($msg === '') return false;
        if (mb_strpos($msg, 'order_query:') !== false
            || mb_strpos($msg, '订单') !== false
            || mb_strpos($msg, '查单') !== false) {
            return true;
        }
        if (preg_match('/^(退出|不用了|取消|算了)[。.!！?？]?$/u', $msg) === 1) {
            return true;
        }
        try {
            require_once __DIR__ . '/HandoffTriggers.php';
            if (HandoffTriggers::matchKeyword($db, $msg) !== null) {
                return true; // 转人工关键词优先于选房
            }
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] escape keyword check failed (non-blocking): ' . $e->getMessage());
        }
        return false;
    }

    /**
     * 与 ChatPipeline 正常路径形状一致的通道结构（P2-11），并补写 chat_logs 两行。
     */
    private static function buildChannelResult(
        \PDO $db,
        string $sessionId,
        string $replyText,
        array $orderGuide,
        string $reasoning,
        string $message,
        string $channel,
        string $visitorHash,
        string $ip
    ): array {
        $data = [
            'reply'          => $replyText,
            'is_verified'    => false,
            'handoff_status' => -1,
            'intent'         => Intent::ORDER_QUERY, // 选房是订单查询的续接轮
            'confidence'     => 0.9,
            'workflow'       => 'OrderGuidePick',
            'reasoning'      => $reasoning,
            'order_guide'    => $orderGuide,
        ];
        self::writePickChatLog($db, $sessionId, $message, $replyText, $reasoning, $channel, $visitorHash, $ip);
        return ['code' => 0, 'msg' => 'ok', 'data' => $data];
    }

    /**
     * 选房轮补写 chat_logs（qa B11 / P2-11）：tryHandle 提前 return 不走 ChatPipeline::writeChatLog，
     * 这里按正常路径同构写 user + assistant 两行。内容只用展示文字，无敏感字段。全 catch。
     */
    private static function writePickChatLog(
        \PDO $db,
        string $sessionId,
        string $message,
        string $replyText,
        string $reasoning,
        string $channel,
        string $visitorHash,
        string $ip
    ): void {
        try {
            $stmt = $db->prepare(
                'INSERT INTO chat_logs (session_id, channel, intent, confidence, slots, workflow, rendered_type, role, content, has_verified, visitor_hash, source_ip, tokens)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $slots = json_encode(['reasoning' => $reasoning], JSON_UNESCAPED_UNICODE);
            $stmt->execute([$sessionId, $channel, Intent::ORDER_QUERY, 0.9, $slots,
                'OrderGuidePick', 'text', 'user', $message, 0, $visitorHash, $ip, 0]);
            $stmt->execute([$sessionId, $channel, Intent::ORDER_QUERY, 0.9, $slots,
                'OrderGuidePick', 'text', 'assistant', $replyText, 0, $visitorHash, $ip, 0]);
        } catch (\Throwable $e) {
            error_log('[OrderGuidePick] writePickChatLog failed: ' . $e->getMessage());
        }
    }
}

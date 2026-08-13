<?php

require_once __DIR__ . '/sidecar/OrderRoomMapper.php';
require_once __DIR__ . '/sidecar/RoomQueryService.php';
require_once __DIR__ . '/sidecar/SidecarIntent.php';
require_once __DIR__ . '/HandoffTriggers.php';

class RoomQueryFlow {
    private const SESSION_TTL_MINUTES = 30;
    private const ASK_ORDER_REPLY = '查询房间地址/设施需要订单号，请发送要咨询的那笔订单号～';
    private const ORDER_INVALID_REPLY = '未查到该订单，请核对订单号后重新发送～';
    private const MAP_FAIL_REPLY = '无法匹配该订单对应的房源资料，请联系前台确认～';
    private const SIDECAR_MISS_REPLY = '暂未找到该房间的相关资料，请联系前台确认～';
    private const CHITCHAT_REPLY = '好的～有需要再问我。';

    public static function getRoomKeywords(PDO $db): array {
        return SidecarIntent::getEntryKeywords($db);
    }

    public static function isRoomIntent(string $message, array $roomKeywords): bool {
        return SidecarIntent::matchesEntry($message, $roomKeywords);
    }

    public static function looksLikeOrderNo(string $message): bool {
        return SidecarIntent::looksLikeOrderNo($message);
    }

    /**
     * @return array|null handled response for chatResponse, or null to fall through
     */
    public static function handle(PDO $db, string $sessionId, string $message, array $roomKeywords, string $visitorHash, string $ip): ?array {
        $isRoomIntent = self::isRoomIntent($message, $roomKeywords);
        $state = self::loadSession($db, $sessionId);

        if ($state && self::isExpired($state)) {
            self::clearSession($db, $sessionId);
            $state = null;
        }

        if (preg_match('/^room_pick:(\d+)$/', $message, $m)) {
            return self::handleRoomPick($db, $sessionId, (int)$m[1], $state, $visitorHash, $ip);
        }

        if ($state) {
            $step = (int)($state['step'] ?? 0);
            if ($step === 1) {
                return self::handleStep1Order($db, $sessionId, $message, $state, $isRoomIntent, $visitorHash, $ip);
            }
            if ($step === 2) {
                return self::handleStep2Pick($db, $sessionId, $message, $state, $visitorHash, $ip);
            }
            if ($step === 3) {
                return self::handleStep3FollowUp($db, $sessionId, $message, $state, $visitorHash, $ip);
            }
        }

        if (!$isRoomIntent) {
            return null;
        }

        self::upsertSession($db, $sessionId, [
            'step' => 1,
            'question' => $message,
            'order_no' => '',
            'room_id' => '',
            'sidecar_room_id' => 0,
            'room_candidates' => null,
            'bound_at' => null,
            'expires_at' => null,
        ]);

        return self::buildResult(self::ASK_ORDER_REPLY, 1);
    }

    private static function handleStep1Order(PDO $db, string $sessionId, string $message, array $state, bool $isRoomIntent, string $visitorHash, string $ip): array {
        if ($handoff = self::handoffIfTriggered($db, $sessionId, $message)) {
            return $handoff;
        }

        if ($isRoomIntent && !self::looksLikeOrderNo($message)) {
            self::upsertSession($db, $sessionId, [
                'step' => 1,
                'question' => $message,
                'order_no' => $state['order_no'] ?? '',
                'room_id' => $state['room_id'] ?? '',
                'sidecar_room_id' => (int)($state['sidecar_room_id'] ?? 0),
                'room_candidates' => $state['room_candidates'] ?? null,
                'bound_at' => $state['bound_at'] ?? null,
                'expires_at' => $state['expires_at'] ?? null,
            ]);
            return self::buildResult(self::ASK_ORDER_REPLY, 1);
        }

        $orderNo = trim($message);
        if (!self::looksLikeOrderNo($orderNo)) {
            return self::buildResult(self::ASK_ORDER_REPLY, 1);
        }

        $orderData = callGateway($db, 'query_order', ['order_no' => $orderNo]);
        if (!$orderData) {
            return self::buildResult(self::ORDER_INVALID_REPLY, 1);
        }

        $candidates = OrderRoomMapper::mapOrderData((array)$orderData);
        if (empty($candidates)) {
            return self::buildResult(self::MAP_FAIL_REPLY, 1);
        }

        $question = trim((string)($state['question'] ?? ''));

        if (count($candidates) === 1) {
            $pick = $candidates[0];
            return self::bindAndAnswer($db, $sessionId, $orderNo, (int)$pick['sidecar_room_id'], $question, null, $visitorHash, $ip);
        }

        $cards = self::candidatesToRichContent($candidates);
        $json = json_encode($candidates, JSON_UNESCAPED_UNICODE);
        self::upsertSession($db, $sessionId, [
            'step' => 2,
            'question' => $question,
            'order_no' => $orderNo,
            'room_id' => '',
            'sidecar_room_id' => 0,
            'room_candidates' => $json,
            'bound_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_TTL_MINUTES * 60),
        ]);

        $reply = '该订单包含 ' . count($candidates) . ' 间房，请选择要咨询的房间：';
        return self::buildResult($reply, 2, $cards);
    }

    private static function handleStep2Pick(PDO $db, string $sessionId, string $message, array $state, string $visitorHash, string $ip): array {
        if ($handoff = self::handoffIfTriggered($db, $sessionId, $message)) {
            return $handoff;
        }

        $candidates = self::decodeCandidates($state['room_candidates'] ?? '');
        if (empty($candidates)) {
            self::resetToAskOrder($db, $sessionId, (string)($state['question'] ?? ''));
            return self::buildResult(self::ASK_ORDER_REPLY, 1);
        }

        $pickedId = null;
        if (preg_match('/^room_pick:(\d+)$/', $message, $m)) {
            $pickedId = (int)$m[1];
        } elseif (preg_match('/^\d+$/', trim($message))) {
            $idx = (int)trim($message) - 1;
            if (isset($candidates[$idx])) {
                $pickedId = (int)$candidates[$idx]['sidecar_room_id'];
            }
        } else {
            foreach ($candidates as $c) {
                $code = trim((string)($c['room_code'] ?? ''));
                if ($code !== '' && (trim($message) === $code || mb_strpos($message, $code) !== false)) {
                    $pickedId = (int)$c['sidecar_room_id'];
                    break;
                }
            }
        }

        if (!$pickedId || !self::candidateAllowed($candidates, $pickedId)) {
            $cards = self::candidatesToRichContent($candidates);
            return self::buildResult('请从下方卡片选择房间，或发送房间号～', 2, $cards);
        }

        return self::bindAndAnswer(
            $db,
            $sessionId,
            (string)($state['order_no'] ?? ''),
            $pickedId,
            (string)($state['question'] ?? ''),
            null,
            $visitorHash,
            $ip
        );
    }

    private static function handleRoomPick(PDO $db, string $sessionId, int $pickedId, ?array $state, string $visitorHash, string $ip): array {
        if (!$state || (int)($state['step'] ?? 0) !== 2) {
            return self::buildResult('请先发送房间相关问题并提供订单号～', 0);
        }
        return self::handleStep2Pick($db, $sessionId, 'room_pick:' . $pickedId, $state, $visitorHash, $ip);
    }

    private static function handleStep3FollowUp(PDO $db, string $sessionId, string $message, array $state, string $visitorHash, string $ip): ?array {
        if (SidecarIntent::isFlowExitMessage($message, $db)) {
            self::clearSession($db, $sessionId);
            if (SidecarIntent::isHandoffMessage($message, $db)) {
                return self::buildHandoffResult();
            }
            return null;
        }

        if (SidecarIntent::isChitchat($message)) {
            self::touchSession($db, $sessionId, $message, $state);
            return self::buildResult(self::CHITCHAT_REPLY, 3, [], true);
        }

        if (SidecarIntent::isGeneralKbQuestion($message)) {
            return null;
        }

        $sidecarId = (int)($state['sidecar_room_id'] ?? 0);
        if ($sidecarId <= 0) {
            self::resetToAskOrder($db, $sessionId, $message);
            return self::buildResult(self::ASK_ORDER_REPLY, 1);
        }

        self::touchSession($db, $sessionId, $message, $state);
        return self::answerFromSidecar($db, $sessionId, $sidecarId, $message, true, 3, $visitorHash, $ip);
    }

    private static function touchSession(PDO $db, string $sessionId, string $message, array $state): void {
        self::upsertSession($db, $sessionId, [
            'step' => 3,
            'question' => $message,
            'order_no' => (string)($state['order_no'] ?? ''),
            'room_id' => (string)($state['room_id'] ?? ''),
            'sidecar_room_id' => (int)($state['sidecar_room_id'] ?? 0),
            'room_candidates' => null,
            'bound_at' => $state['bound_at'] ?? date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_TTL_MINUTES * 60),
        ]);
    }

    private static function bindAndAnswer(PDO $db, string $sessionId, string $orderNo, int $sidecarId, string $question, ?array $candidates, string $visitorHash, string $ip): array {
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + self::SESSION_TTL_MINUTES * 60);
        self::upsertSession($db, $sessionId, [
            'step' => 3,
            'question' => $question,
            'order_no' => $orderNo,
            'room_id' => (string)$sidecarId,
            'sidecar_room_id' => $sidecarId,
            'room_candidates' => $candidates ? json_encode($candidates, JSON_UNESCAPED_UNICODE) : null,
            'bound_at' => $now,
            'expires_at' => $expires,
        ]);
        return self::answerFromSidecar($db, $sessionId, $sidecarId, $question, true, 3, $visitorHash, $ip);
    }

    private static function answerFromSidecar(PDO $db, string $sessionId, int $sidecarId, string $question, bool $verified, int $step, string $visitorHash, string $ip): array {
        $roomData = RoomQueryService::queryBySidecarId($sidecarId, $question, $verified, $sessionId);
        if (!$roomData || !empty($roomData['_not_found'])) {
            return self::buildResult(self::SIDECAR_MISS_REPLY, $step, [], $verified);
        }

        $reply = trim((string)($roomData['reply'] ?? $roomData['text'] ?? ''));
        if ($reply === '') {
            return self::buildResult(self::SIDECAR_MISS_REPLY, $step, [], $verified);
        }

        $rich = self::imagesToRichContent($roomData['images'] ?? ($roomData['image_urls'] ?? []));
        return self::buildResult($reply, $step, $rich, $verified);
    }

    private static function imagesToRichContent($images): array {
        if (is_string($images)) $images = [$images];
        if (!is_array($images)) return [];
        $rich = [];
        foreach ($images as $img) {
            $imgUrl = is_string($img) ? $img : ($img['url'] ?? '');
            if (!$imgUrl) continue;
            $rich[] = [
                'type' => 'image_link',
                'image_url' => $imgUrl,
                'link_url' => is_array($img) ? ($img['link_url'] ?? '#') : '#',
                'title' => is_array($img) ? ($img['title'] ?? '查看图片') : '查看图片',
                'description' => is_array($img) ? ($img['description'] ?? '') : '',
            ];
        }
        return $rich;
    }

    private static function candidatesToRichContent(array $candidates): array {
        $cards = [];
        foreach ($candidates as $c) {
            $cards[] = [
                'type' => 'room_pick',
                'sidecar_room_id' => (int)$c['sidecar_room_id'],
                'room_index' => (int)($c['room_index'] ?? 0),
                'room_code' => (string)($c['room_code'] ?? ''),
                'title' => (string)($c['title'] ?? $c['display_name'] ?? ''),
                'description' => (string)($c['description'] ?? ''),
            ];
        }
        return $cards;
    }

    private static function candidateAllowed(array $candidates, int $pickedId): bool {
        foreach ($candidates as $c) {
            if ((int)($c['sidecar_room_id'] ?? 0) === $pickedId) return true;
        }
        return false;
    }

    private static function decodeCandidates(?string $json): array {
        if (!$json) return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function buildHandoffResult(): array {
        $result = self::buildResult('正在为您转接人工客服，请稍候。', 0);
        $result['handoff_status'] = 0;
        return $result;
    }

    private static function handoffIfTriggered(PDO $db, string $sessionId, string $message): ?array {
        if (!SidecarIntent::isHandoffMessage($message, $db)) {
            return null;
        }
        self::clearSession($db, $sessionId);
        return self::buildHandoffResult();
    }

    private static function buildResult(string $reply, int $step, array $richContent = [], bool $isVerified = false): array {
        return [
            'handled' => true,
            'reply' => $reply,
            'rich_content' => $richContent,
            'room_query_step' => $step,
            'is_verified' => $isVerified,
            'handoff_status' => -1,
        ];
    }

    private static function loadSession(PDO $db, string $sessionId): ?array {
        try {
            $st = $db->prepare('SELECT * FROM room_query_sessions WHERE session_id = ? LIMIT 1');
            $st->execute([$sessionId]);
            $row = $st->fetch();
            if (!$row) return null;
            if ((int)($row['step'] ?? 0) === 0) return null;
            return $row;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function isExpired(array $state): bool {
        $exp = $state['expires_at'] ?? null;
        if (!$exp) return false;
        return strtotime((string)$exp) < time();
    }

    private static function clearSession(PDO $db, string $sessionId): void {
        try {
            $db->prepare('DELETE FROM room_query_sessions WHERE session_id = ?')->execute([$sessionId]);
        } catch (Exception $e) {}
    }

    private static function resetToAskOrder(PDO $db, string $sessionId, string $question): void {
        self::upsertSession($db, $sessionId, [
            'step' => 1,
            'question' => $question,
            'order_no' => '',
            'room_id' => '',
            'sidecar_room_id' => 0,
            'room_candidates' => null,
            'bound_at' => null,
            'expires_at' => null,
        ]);
    }

    private static function upsertSession(PDO $db, string $sessionId, array $fields): void {
        $sql = 'INSERT INTO room_query_sessions (session_id, room_id, question, step, order_no, sidecar_room_id, room_candidates, bound_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            room_id = VALUES(room_id),
            question = VALUES(question),
            step = VALUES(step),
            order_no = VALUES(order_no),
            sidecar_room_id = VALUES(sidecar_room_id),
            room_candidates = VALUES(room_candidates),
            bound_at = VALUES(bound_at),
            expires_at = VALUES(expires_at)';
        $db->prepare($sql)->execute([
            $sessionId,
            (string)($fields['room_id'] ?? ''),
            (string)($fields['question'] ?? ''),
            (int)($fields['step'] ?? 0),
            (string)($fields['order_no'] ?? ''),
            (int)($fields['sidecar_room_id'] ?? 0),
            $fields['room_candidates'] ?? null,
            $fields['bound_at'] ?? null,
            $fields['expires_at'] ?? null,
        ]);
    }
}

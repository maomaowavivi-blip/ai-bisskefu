<?php
/**
 * api/Workflow/OrderQueryWorkflow.php
 *
 * v3.3 PR2 — 订单查询 Workflow（冻结块，修正 2：只搬不改）
 *
 * 设计：完全复制 chat.php:431-470 的 order_query: 冻结逻辑
 * 不重构、不优化、不变量重命名
 *
 * 已知 chat.php:431-470 处理：
 *   - order_query: 前缀识别
 *   - 提取订单号
 *   - PMS 网关 callGateway('query_order')
 *   - 写入 order_context_cache（24h TTL）
 *   - 返回 rich_content 云房卡卡片
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';

final class OrderQueryWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        $message = $this->intentCtx->slots['original_message'] ?? '';
        if ($message === '') {
            return WorkflowResult::text('订单号不能为空', 'OrderQueryWorkflow');
        }

        // 提取订单号
        if (preg_match('/^order_query:(.+)$/u', $message, $m)) {
            $orderNo = trim($m[1]);
        } else {
            $orderNo = $message;
        }

        if ($orderNo === '') {
            // 修正：order_query: 但无订单号，给引导话术（与旧 chat.php 行为一致）
            return WorkflowResult::text(
                '如需查询订单，请点击下方「订单查询」按钮，或直接发送 order_query:订单号～',
                'OrderQueryWorkflow'
            );
        }

        // v3.9:替代 callGateway 坏网关,直接调 channelOrder 查订单
        try {
            $orderData = self::queryChannelOrder($this->db, $orderNo);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] queryChannelOrder failed: ' . $e->getMessage());
            return WorkflowResult::text(
                '暂时没有查找到您的云房卡，请稍后重试或联系管家。',
                'OrderQueryWorkflow'
            );
        }

        // 查单失败(订单不存在)
        if (!$orderData) {
            return WorkflowResult::text(
                '暂时没有查找到您的云房卡，请稍后重试或联系管家。',
                'OrderQueryWorkflow'
            );
        }

        // 查单成功 → 文本订单信息(web 端不依赖 thumb/云房卡)
        $reply = "订单查询成功：\n"
            . ($orderData['unit_name'] ? "房型：{$orderData['unit_name']}\n" : '')
            . ($orderData['check_in'] ? "入住：" . date('Y-m-d', strtotime($orderData['check_in'])) . "\n" : '')
            . ($orderData['check_out'] ? "离店：" . date('Y-m-d', strtotime($orderData['check_out'])) . "\n" : '')
            . ($orderData['price'] ? "房价：¥{$orderData['price']}\n" : '')
            . '如需查看云房卡请前往微信客服发送订单号获取。';

        // 写入 order_context_cache(24h TTL) — 保留原逻辑
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO order_context_cache (session_id, order_no, room_id, room_list, expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE order_no=VALUES(order_no), room_id=VALUES(room_id),
                   room_list=VALUES(room_list), expires_at=VALUES(expires_at)'
            );
            $stmt->execute([
                $this->sessionId,
                $orderNo,
                $orderData['room_ids'] ?? 0,
                json_encode($orderData, JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s', time() + 86400),
            ]);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] cache write failed: ' . $e->getMessage());
        }

        return WorkflowResult::text($reply, 'OrderQueryWorkflow');
    }

    /**
     * v3.9:直接调 channelOrder API 查订单(替代已失效的 callGateway)
     * 实测确认返回字段: check_in / check_out / unit_name / price / state
     */
    private static function queryChannelOrder(\PDO $db, string $channelOrderId): ?array
    {
        $user = trim(pcGet($db, 'roomcard.username', ''));
        $pwd  = trim(pcGet($db, 'roomcard.password', ''));
        if ($user === '' || $pwd === '') {
            error_log('[OrderQueryWorkflow] roomcard.username/password not configured');
            return null;
        }

        $url = "https://apicenter.sujia365.com/index.php/openapi/order/channelOrder"
            . "?username=" . urlencode($user)
            . "&pwd=" . $pwd
            . "&channel_order_id=" . urlencode($channelOrderId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) return null;
        $data = json_decode($resp, true);
        if (!isset($data['code']) || intval($data['code']) !== 1 || empty($data['data']['order'])) {
            return null;
        }
        return $data['data']['order'];
    }
}
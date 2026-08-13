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

        // 调 PMS 网关（与 chat.php:455 一致）
        try {
            $orderData = callGateway($this->db, 'query_order', ['order_no' => $orderNo]);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] callGateway failed: ' . $e->getMessage());
            return WorkflowResult::text(
                '点击聊天窗口「订单查询」，或发送 order_query:您的订单号，即可查看订单信息与云房卡。',
                'OrderQueryWorkflow'
            );
        }

        // 查单失败
        if (!$orderData || (isset($orderData['code']) && $orderData['code'] !== 0)) {
            return WorkflowResult::text(
                '❌ 未查询到订单信息，请检查订单号是否正确，或联系客服处理～',
                'OrderQueryWorkflow'
            );
        }

        // 查单成功 → 写 order_context_cache（24h TTL）+ 返回云房卡卡片
        $roomList = $orderData['data']['room_list'] ?? [];
        $roomId = $orderData['data']['room_id'] ?? 0;
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);

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
                $roomId,
                json_encode($roomList, JSON_UNESCAPED_UNICODE),
                $expiresAt,
            ]);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] cache write failed: ' . $e->getMessage());
        }

        // 返云房卡卡片
        $card = [
            'type' => 'yunfangka_card',
            'title' => '云房卡',
            'description' => '订单 ' . $orderNo . ' 已查询，点击查看云房卡',
            'image_link' => '',
            'action_url' => '',
        ];

        $reply = '订单查询成功！请查看下方云房卡办理入住～';
        $result = WorkflowResult::card($reply, [$card], 'OrderQueryWorkflow');
        $result->extra['order_cache'] = [
            'order_no' => $orderNo,
            'room_id' => $roomId,
            'room_list' => $roomList,
        ];
        return $result;
    }
}
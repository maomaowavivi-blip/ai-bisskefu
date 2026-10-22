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
 *   - 数据中台订单查询 CenterApi::orderByChannelOrderId()（v3.26 取代旧 apicenter）
 *   - 写入 order_context_cache（24h TTL）
 *   - 返回 rich_content 云房卡卡片
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../CenterApi.php';

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

        // v3.26:数据中台订单查询(取代已下线的旧 apicenter channelOrder / 自建网关)
        try {
            $orderResult = CenterApi::orderByChannelOrderId($this->db, $orderNo);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] CenterApi failed: ' . $e->getMessage());
            $orderResult = null;
        }

        // 查单失败(订单不存在 / 中台异常)
        if (!$orderResult || empty($orderResult['order'])) {
            return WorkflowResult::text(
                '暂时没有查找到您的云房卡，请稍后重试或联系管家。',
                'OrderQueryWorkflow'
            );
        }

        $orderData  = $orderResult['order'];
        $paidAmount = (string)($orderResult['paid_amount'] ?? '');

        // 查单成功 → 文本订单信息(web 端不依赖 thumb/云房卡)
        $reply = "订单查询成功：\n"
            . ($orderData['unit_name'] ? "房型：{$orderData['unit_name']}\n" : '')
            . ($orderData['check_in'] ? "入住：" . date('Y-m-d', strtotime($orderData['check_in'])) . "\n" : '')
            . ($orderData['check_out'] ? "离店：" . date('Y-m-d', strtotime($orderData['check_out'])) . "\n" : '')
            . ($paidAmount !== '' ? "实付金额：¥{$paidAmount}\n" : '')
            . '如需查看云房卡请前往微信客服发送订单号获取。';

        // 写入 order_context_cache(24h TTL)
        //
        // v3.26 修正两处历史错误（该表自建表起从未写入成功过，错误被 catch 吞掉）：
        //   1) 生产表没有 created_at 列（是 verified_at），写 created_at 直接报 1054 Unknown column
        //   2) room_list 是 varchar(500) 且注释为「逗号分隔的房间号列表」，
        //      旧代码存整个订单对象 JSON（1051 字符）必然超长
        // 现按真实表结构写入：room_id / room_list 都存订单的 room_ids（逗号分隔房间号）。
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO order_context_cache (session_id, order_no, room_id, room_list, expires_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE order_no=VALUES(order_no), room_id=VALUES(room_id),
                   room_list=VALUES(room_list), expires_at=VALUES(expires_at)'
            );
            $roomIds = (string)($orderData['room_ids'] ?? '');
            $stmt->execute([
                $this->sessionId,
                $orderNo,
                $roomIds,
                $roomIds,
                date('Y-m-d H:i:s', time() + 86400),
            ]);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] cache write failed: ' . $e->getMessage());
        }

        return WorkflowResult::text($reply, 'OrderQueryWorkflow');
    }
}
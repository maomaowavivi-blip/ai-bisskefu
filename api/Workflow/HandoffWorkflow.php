<?php
/**
 * api/Workflow/HandoffWorkflow.php
 *
 * v3.3 PR2 — 转人工 Workflow
 *
 * 处理 HandoffTriggers 命中的消息
 * 创建 human_handoffs 记录 + 返固定转人工话术
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../HandoffTriggers.php';

final class HandoffWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        $message = $this->intentCtx->slots['original_message'] ?? '';
        $priority = $this->intentCtx->priority;  // 修正 18：P0-P4

        // 写入 human_handoffs 表
        $handoffId = null;
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO human_handoffs (session_id, status, priority, reason, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $status = 'pending';
            $reason = mb_substr($message, 0, 200);
            $stmt->execute([$this->sessionId, $status, $priority, $reason]);
            $handoffId = (int)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[HandoffWorkflow] create handoff failed: ' . $e->getMessage());
        }

        return WorkflowResult::text(
            '正在为您转接人工客服，请稍候。',
            'HandoffWorkflow'
        )->withHandoffStatus(0)->withExtra(['handoff_id' => $handoffId]);
    }
}
<?php
/**
 * api/Workflow/HandoffWorkflow.php
 *
 * v3.7 — 转人工改为 400 电话话术
 *
 * 处理 HandoffTriggers 命中的消息
 * 不再写 human_handoffs 表，不再切真人客服
 * 统一回复"请拨打 400-155-9959 联系管家"
 *
 * Intent HUMAN 分类和 HandoffTriggers 词库保留（意图识别继续跑）
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../HandoffTriggers.php';

final class HandoffWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        $message = $this->intentCtx->slots['original_message'] ?? '';
        $priority = $this->intentCtx->priority;  // 保留 priority 供未来扩展

        // v3.7：不再写 human_handoffs 表（不再切真人客服）
        // 但保留 priority 在 extra 中，方便后续按 priority 区分话术

        return WorkflowResult::text(
            '请拨打 400-155-9959 联系管家',
            'HandoffWorkflow'
        )->withHandoffStatus(0)->withExtra(['priority' => $priority]);
    }
}
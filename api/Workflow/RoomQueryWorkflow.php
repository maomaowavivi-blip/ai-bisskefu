<?php
/**
 * api/Workflow/RoomQueryWorkflow.php
 *
 * v3.3 PR2 — 房间查询 Workflow（包一层现有 RoomQueryFlow::handle）
 *
 * 设计：
 * - 不重写 RoomQueryFlow，直接复用
 * - 仅做"RoomQueryFlow 的 Workflow 包装"
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../RoomQueryFlow.php';

final class RoomQueryWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        $roomKeywords = RoomQueryFlow::getRoomKeywords($this->db);

        $flowResult = RoomQueryFlow::handle(
            $this->db,
            $this->sessionId,
            $this->intentCtx->slots['original_message'] ?? '',  // 本轮用户消息（从 IntentClassifier 传的）
            $roomKeywords,
            $this->visitorHash,
            $this->ip
        );

        // RoomQueryFlow::handle 返回 ?array {reply, is_verified, handoff_status, handled, room_pick, ...}
        if ($flowResult === null || empty($flowResult['handled'])) {
            return WorkflowResult::text('房间信息查询功能暂未上线，请拨打 400-155-9959 联系我们。', 'RoomQueryWorkflow');
        }

        $reply = (string)($flowResult['reply'] ?? '');
        $isVerified = !empty($flowResult['is_verified']);
        $handoffStatus = (int)($flowResult['handoff_status'] ?? -1);

        $result = WorkflowResult::text($reply, 'RoomQueryWorkflow');
        $result->isVerified = $isVerified;
        $result->handoffStatus = $handoffStatus;

        // room_pick 卡片（修正：保留原始字段）
        if (!empty($flowResult['room_pick'])) {
            $result->roomPick = $flowResult['room_pick'];
            $result->renderType = 'list';
        }

        // rich_content（如有）
        if (!empty($flowResult['rich_content'])) {
            $result->richContent = $flowResult['rich_content'];
            $result->renderType = 'card';
        }

        return $result;
    }
}
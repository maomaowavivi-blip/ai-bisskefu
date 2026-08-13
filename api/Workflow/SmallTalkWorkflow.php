<?php
/**
 * api/Workflow/SmallTalkWorkflow.php
 *
 * v3.3 PR2 — 闲聊 Workflow
 *
 * 处理寒暄/日常问候（"你好""谢谢""再见"等）
 * 走 LLM 生成情感回复
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../PromptEngine.php';
require_once __DIR__ . '/../sidecar/SidecarIntent.php';

final class SmallTalkWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        $message = $this->intentCtx->slots['original_message'] ?? '';

        // 完全寒暄（"谢谢""好的""再见"）→ 固定回复
        if (SidecarIntent::isFlowExitMessage($message, $this->db)) {
            return WorkflowResult::text('好的～有需要再问我。', 'SmallTalkWorkflow');
        }

        // 其他闲聊（天气、问候等）→ 走 LLM
        try {
            $stmt = $this->db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
            $persona = $stmt->fetch() ?: [];

            // 不喂 KB（闲聊不需要）
            $messages = PromptEngine::buildMessages(
                $persona,
                $this->intentCtx->sessionState['history'] ?? [],
                $message,
                [],
                $this->sessionId,
                $message,
                $this->config
            );

            $result = callAI($messages, [
                'max_tokens' => 80,  // 闲聊更短
                'temperature' => 0.5,  // 闲聊可以稍活跃
                'thinking' => ['type' => 'disabled'],
            ]);

            $reply = $result['content'] ?? '';
            $reply = PromptEngine::filterReply($reply, $message, $this->db, $this->config);

            return WorkflowResult::text($reply, 'SmallTalkWorkflow');
        } catch (\Throwable $e) {
            error_log('[SmallTalkWorkflow] failed: ' . $e->getMessage());
            return WorkflowResult::text('您好，有什么可以帮您？', 'SmallTalkWorkflow');
        }
    }
}
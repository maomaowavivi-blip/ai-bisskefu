<?php
/**
 * api/Workflow/UnknownWorkflow.php
 *
 * v3.3 PR2 — 兜底 Workflow
 *
 * 决策点 5：调 LLM，但带 4 道保险（蓝图 §十六）
 * 1. 先试 KB 弱匹配
 * 2. 有弱匹配 → 走 KnowledgeWorkflow
 * 3. 无弱匹配 → 调 LLM（硬超时 1.2s + max_tokens=80 + temperature=0.2）
 * 4. 必经 finalizeReply 内容指纹校验
 * 5. LLM 超时/失败 → 固定话术
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../PromptEngine.php';

final class UnknownWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        $message = $this->intentCtx->slots['original_message'] ?? '';

        // 第 1 道保险：先试 KB 弱匹配
        try {
            $kbItems = PromptEngine::searchKnowledge($this->db, $message, 3);
            if (!empty($kbItems)) {
                // 委托给 KnowledgeWorkflow（包一层）
                $kbCtx = IntentContext::of(Intent::KNOWLEDGE, 0.5, [], 'fallback_to_kb', $kbItems);
                $kw = new KnowledgeWorkflow($this->db, $this->config, $kbCtx, $this->sessionState, $this->sessionId, $this->visitorHash, $this->ip);
                return $kw->handle();
            }
        } catch (\Throwable $e) {
            error_log('[UnknownWorkflow] KB search failed: ' . $e->getMessage());
        }

        // 第 2 道保险：调 LLM（带硬超时）
        try {
            $stmt = $this->db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
            $persona = $stmt->fetch() ?: [];

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
                'max_tokens' => 80,
                'temperature' => 0.2,
                'thinking' => ['type' => 'disabled'],
                'timeout' => 1200,  // 第 3 道保险：硬超时 1.2 秒
            ]);

            $reply = $result['content'] ?? '';

            // 第 4 道保险：内容指纹校验
            $reply = PromptEngine::filterReply($reply, $message, $this->db, $this->config);
            $reply = PromptEngine::finalizeReply($reply, [], $this->config);

            return WorkflowResult::text($reply, 'UnknownWorkflow');
        } catch (\Throwable $e) {
            error_log('[UnknownWorkflow] LLM fallback failed: ' . $e->getMessage());
        }

        // 第 5 道保险：固定话术
        return WorkflowResult::text(
            '这边暂时没有查到准确信息，建议您联系前台确认。',
            'UnknownWorkflow'
        );
    }
}
<?php
/**
 * api/Workflow/KnowledgeWorkflow.php
 *
 * v3.3 PR2 — 知识库 Workflow
 *
 * 处理 KB 直答 + LLM 兜底
 * 也被 REFUND_QUERY 复用（修正 18）
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../PromptEngine.php';

final class KnowledgeWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        $message = $this->intentCtx->slots['original_message'] ?? '';

        // v3.9:隐私拒绝 fast path（IntentClassifier 标记的隐私查询 → 直接拒绝，不走 KB/LLM）
        if (!empty($this->intentCtx->slots['privacy_refuse'])) {
            return WorkflowResult::text(
                '为保护客人隐私，我无法查询他人订单或手机号对应的订单。如需查询，请提供您本人的订单号～',
                'KnowledgeWorkflow'
            );
        }

        // 1. KB 关键词直答（早期 fast path）
        try {
            $earlyKb = PromptEngine::directReplyFromKb($message, [], $this->db, $this->config);
            if ($earlyKb !== null) {
                return WorkflowResult::text($earlyKb, 'KnowledgeWorkflow');
            }
        } catch (\Throwable $e) {
            error_log('[KnowledgeWorkflow] directReplyFromKb failed: ' . $e->getMessage());
        }

        // 2. KB 检索（已有 kbItems 由 IntentClassifier 预召回）
        $kbItems = $this->intentCtx->kbItems;
        if (empty($kbItems)) {
            try {
                $kbItems = PromptEngine::searchKnowledge($this->db, $message, 3);
            } catch (\Throwable $e) {
                error_log('[KnowledgeWorkflow] searchKnowledge failed: ' . $e->getMessage());
                $kbItems = [];
            }
        }

        // 3. KB 命中 → 政策类直答
        if (!empty($kbItems)) {
            try {
                $policyReply = PromptEngine::directReplyFromKb($message, $kbItems, $this->db, $this->config);
                if ($policyReply !== null) {
                    return WorkflowResult::text($policyReply, 'KnowledgeWorkflow');
                }
            } catch (\Throwable $e) {
                error_log('[KnowledgeWorkflow] policyReply failed: ' . $e->getMessage());
            }
        }

        // 4. LLM 兜底
        try {
            // 加载 persona
            $stmt = $this->db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
            $persona = $stmt->fetch() ?: [];

            $messages = PromptEngine::buildMessages(
                $persona,
                $this->intentCtx->sessionState['history'] ?? [],
                $message,
                $kbItems,
                $this->sessionId,
                $message,  // rewrittenQuery = message（PR2 不做改写）
                $this->config
            );

            $result = callAI($messages, [
                'max_tokens' => $this->config->getInt('agent.llm.max_tokens', 150),
                'temperature' => 0.2,  // 修正：0.5 → 0.2 防飘逸
                'thinking' => ['type' => 'disabled'],
            ]);

            $reply = $result['content'] ?? '';
            $reply = PromptEngine::filterReply($reply, $message, $this->db, $this->config);
            $reply = PromptEngine::finalizeReply($reply, $kbItems, $this->config);

            return WorkflowResult::text($reply, 'KnowledgeWorkflow');
        } catch (\Throwable $e) {
            error_log('[KnowledgeWorkflow] LLM fallback failed: ' . $e->getMessage());
            return WorkflowResult::text(
                '这边暂时没有查到准确信息，建议您联系前台确认。',
                'KnowledgeWorkflow'
            );
        }
    }
}
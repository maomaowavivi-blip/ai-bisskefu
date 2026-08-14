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
        // v3.8 修复：不再用 buildMessages（它带"无资料只回 fallback"约束，会锁死兜底话术）
        // 改用轻量 prompt，让 LLM 能生成自然的兜底回应
        try {
            $stmt = $this->db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
            $persona = $stmt->fetch() ?: [];
            $name = trim($persona['name'] ?? '客服');

            $system = "你是{$name}，柚光民宿的 AI 客服。用户的问题暂时无法通过知识库回答，请用友好、简短的中文回应。\n"
                . "规则：\n"
                . "1. 不要编造任何民宿业务事实（入住时间、房价、设施等一律不谈，除非有明确依据）\n"
                . "2. 如果问题涉及具体业务，引导用户提供订单号或转人工\n"
                . "3. 回复 20-60 字，一句话即可\n"
                . "4. 不要使用\"这边暂时没有查到准确信息\"等客服兜底话术";

            $messages = [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $message],
            ];

            $result = callAI($messages, [
                'max_tokens' => 80,
                'temperature' => 0.3,
                'thinking' => ['type' => 'disabled'],
                'timeout' => 1200,  // 第 3 道保险：硬超时 1.2 秒
            ]);

            $reply = $result['content'] ?? '';

            // 第 4 道保险：内容过滤
            $reply = PromptEngine::filterReply($reply, $message, $this->db, $this->config);

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
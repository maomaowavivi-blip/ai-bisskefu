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

            // v3.5 修复：不再用 buildMessages（它带"无资料只回 fallback"约束，会锁死闲聊）
            // 改用轻量闲聊 prompt，让 LLM 自然回应
            $name        = trim($persona['name']        ?? '客服');
            $personality = trim($persona['personality'] ?? '友好、亲切');
            $speakStyle  = trim($persona['speak_style'] ?? '');

            $system = "你是{$name}，柚光民宿的 AI 客服。客人正在和你闲聊（打招呼/寒暄/表达情绪），请用自然、简短、友好的中文回应。\n"
                . "性格：{$personality}\n"
                . "说话风格：{$speakStyle}\n"
                . "规则：\n"
                . "1. 只回应闲聊内容，不要编造任何民宿业务事实（入住时间、房价、设施等一律不谈，除非客人明确问）\n"
                . "2. 回复 20-60 字，一句话即可\n"
                . "3. 可以自然引导：如果客人有具体问题，可以提示\"有什么入住或订单问题都可以问我~\"\n"
                . "4. 不要使用\"这边暂时没有查到准确信息\"等客服兜底话术";

            $messages = [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $message],
            ];

            $result = callAI($messages, [
                'max_tokens' => 80,  // 闲聊更短
                'temperature' => 0.7,  // 闲聊可以更活跃
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
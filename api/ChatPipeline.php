<?php
/**
 * api/ChatPipeline.php
 *
 * v3.3 PR2 — 串联 Classifier → Router → Renderer
 *
 * 修正 5：拆出 chat.php 主循环
 * 修正 13：限流逻辑内联（不开新文件）
 * 修正 19：开关读 platform_config.pipeline.enabled
 * 修正 20-21：Pipeline 返回 [code, msg, data] 结构，不调 chatResponse
 * 修正 24：顶部 try/catch
 * 修正 25：不缓存开关（每次读 platform_config，~1ms）
 * 修正 31：放弃 static cache（PHP-FPM 多进程行为不一致）
 * 修正 32：Pipeline 入口只 new AgentConfig 一次
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Intent.php';
require_once __DIR__ . '/IntentClassifier.php';
require_once __DIR__ . '/IntentRouter.php';
require_once __DIR__ . '/SessionState.php';
require_once __DIR__ . '/ReplyRenderer.php';

final class ChatPipeline
{
    /**
     * 主入口
     *
     * @param string $sessionId
     * @param string $message
     * @param array $history
     * @param \PDO $db
     * @param string $channel web / wechat_mp / wechat_msg / openapi / api
     * @param string $visitorHash
     * @param string $ip
     * @return array { code, msg, data }
     */
    public static function process(
        string $sessionId,
        string $message,
        array $history,
        \PDO $db,
        string $channel = 'web',
        string $visitorHash = '',
        string $ip = ''
    ): array {
        try {
            // 修正 32：Pipeline 入口只 new 一次
            $config = new \AgentConfig($db);

            // 修正 19 + 25：每次读 platform_config，不缓存
            // 注：AgentConfig::get() 只查 agent.* / plugin.* 字段，pipeline.enabled 不在其列
            // 这里直接 SQL 查 platform_config
            $pipelineEnabled = false;
            try {
                $stmt = $db->query("SELECT value FROM platform_config WHERE `key` = 'pipeline.enabled' LIMIT 1");
                $val = $stmt->fetchColumn();
                $pipelineEnabled = ($val === 'true');
            } catch (\Throwable $e) {
                error_log('[ChatPipeline] read pipeline.enabled failed: ' . $e->getMessage());
            }
            if (!$pipelineEnabled) {
                throw new \RuntimeException('Pipeline disabled');
            }

            // 修正 28：history 防御
            $history = is_array($history) ? $history : [];

            // 修正 13：限流内联（20 req/min/IP）
            $rateResult = self::enforceRateLimit($db, $ip);
            if ($rateResult !== null) {
                return $rateResult;
            }

            // 安全拦截（保留现有 chat.php checkInputSafety）
            $safetyReply = checkInputSafety($message, $config);
            if ($safetyReply !== null) {
                return [
                    'code' => 0,
                    'msg' => 'ok',
                    'data' => ReplyRenderer::renderText($safetyReply),
                ];
            }

            // 修正 30：Persona 加载
            $stmt = $db->query('SELECT * FROM persona_config ORDER BY id DESC LIMIT 1');
            $persona = $stmt->fetch() ?: [];

            // 加载会话状态（SessionState）
            $sessionState = SessionState::load($db, $sessionId);
            // 把 history 塞进 sessionState（给 PromptEngine 用）
            $sessionState['history'] = $history;

            // Intent 分类
            $intentCtx = IntentClassifier::classify($message, [
                'db' => $db,
                'config' => $config,
                'history' => $history,
                'session' => $sessionState,
            ]);
            // 把本轮消息塞进 slots，供 Workflow 使用
            $intentCtx->slots['original_message'] = $message;

            // 路由 → Workflow
            $result = IntentRouter::route(
                $db,
                $config,
                $intentCtx,
                $sessionState,
                $sessionId,
                $visitorHash,
                $ip
            );

            // 渲染
            $data = ReplyRenderer::render($result, $channel);
            // 决策 3：打点字段（前端 console 用）
            $data['intent'] = $intentCtx->intent;
            $data['confidence'] = $intentCtx->confidence;
            $data['workflow'] = $result->workflowName;
            $data['reasoning'] = $intentCtx->reasoning;

            // 写 chat_logs（intent / workflow 已填）
            self::writeChatLog($db, $sessionId, $message, $intentCtx, $result, $channel, $visitorHash, $ip);

            return [
                'code' => 0,
                'msg' => 'ok',
                'data' => $data,
            ];
        } catch (\PDOException $e) {
            error_log('[ChatPipeline] DB error: ' . $e->getMessage() . ' | session=' . $sessionId);
            return ['code' => 500, 'msg' => '系统繁忙，请稍后再试', 'data' => null];
        } catch (\Throwable $e) {
            error_log('[ChatPipeline] Fatal: ' . $e->getMessage() . ' | trace=' . $e->getTraceAsString());
            return ['code' => 500, 'msg' => '系统繁忙，请稍后再试', 'data' => null];
        }
    }

    /**
     * 修正 13：限流内联（不开新文件）
     * 复制 chat.php:287-303 逻辑
     * 返回 null 表示通过；返回 array 表示超限（429）
     */
    private static function enforceRateLimit(\PDO $db, string $ip): ?array
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $ip = $xff ? trim(explode(',', $xff)[0]) : ($_SERVER['REMOTE_ADDR'] ?? $ip);
        $rateKey = 'rl:' . $ip;
        $now = date('Y-m-d H:i:s');
        $st = $db->prepare("SELECT count, window_start FROM rate_limits WHERE key_str = ?");
        $st->execute([$rateKey]);
        $rl = $st->fetch();
        if ($rl && strtotime($rl['window_start']) > time() - 60) {
            $db->prepare("UPDATE rate_limits SET count = count + 1 WHERE key_str = ?")->execute([$rateKey]);
        } else {
            $db->prepare("INSERT INTO rate_limits (key_str, count, window_start) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE count = 1, window_start = ?")->execute([$rateKey, $now, $now]);
        }
        $stmt = $db->prepare("SELECT count FROM rate_limits WHERE key_str = ?");
        $stmt->execute([$rateKey]);
        $currentCount = intval($stmt->fetchColumn());
        if ($currentCount > 20) {
            // 修正 20：不能调 chatResponse，返统一结构
            return ['code' => 429, 'msg' => '请求过于频繁，请稍后再试', 'data' => null];
        }
        return null;
    }

    /**
     * 写 chat_logs（带 intent / workflow 字段）
     */
    private static function writeChatLog(
        \PDO $db,
        string $sessionId,
        string $message,
        IntentContext $intentCtx,
        WorkflowResult $result,
        string $channel,
        string $visitorHash,
        string $ip
    ): void {
        try {
            $stmt = $db->prepare(
                'INSERT INTO chat_logs (session_id, channel, intent, confidence, slots, workflow, rendered_type, role, content, has_verified, visitor_hash, source_ip, tokens)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            // user message
            $stmt->execute([
                $sessionId,
                $channel,
                $intentCtx->intent,
                $intentCtx->confidence,
                json_encode($intentCtx->slots, JSON_UNESCAPED_UNICODE),
                $result->workflowName,
                $result->renderType,
                'user',
                $message,
                0,
                $visitorHash,
                $ip,
                0,
            ]);
            // assistant reply
            $stmt->execute([
                $sessionId,
                $channel,
                $intentCtx->intent,
                $intentCtx->confidence,
                json_encode($intentCtx->slots, JSON_UNESCAPED_UNICODE),
                $result->workflowName,
                $result->renderType,
                'assistant',
                $result->text,
                $result->isVerified ? 1 : 0,
                $visitorHash,
                $ip,
                0,  // Pipeline 不算 tokens（统计成本改用 callAI usage）
            ]);
        } catch (\Throwable $e) {
            error_log('[ChatPipeline] writeChatLog failed: ' . $e->getMessage());
        }
    }
}
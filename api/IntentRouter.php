<?php
/**
 * api/IntentRouter.php
 *
 * v3.3 PR2 — Intent → Workflow 路由
 *
 * 设计：switch 表驱动，未命中走 UnknownWorkflow
 */

declare(strict_types=1);

require_once __DIR__ . '/Intent.php';
require_once __DIR__ . '/Workflow/AbstractWorkflow.php';
require_once __DIR__ . '/Workflow/YunfangkaCredentialWorkflow.php';
require_once __DIR__ . '/Workflow/RoomQueryWorkflow.php';
require_once __DIR__ . '/Workflow/OrderQueryWorkflow.php';
require_once __DIR__ . '/Workflow/KnowledgeWorkflow.php';
require_once __DIR__ . '/Workflow/SmallTalkWorkflow.php';
require_once __DIR__ . '/Workflow/UnknownWorkflow.php';
require_once __DIR__ . '/Workflow/HandoffWorkflow.php';
require_once __DIR__ . '/Workflow/PreSalesWorkflow.php';

final class IntentRouter
{
    /**
     * Intent → Workflow 类名映射
     */
    private const WORKFLOW_MAP = [
        Intent::ROOM_QUERY          => 'RoomQueryWorkflow',
        Intent::ROOM_PASSWORD_QUERY => 'YunfangkaCredentialWorkflow',  // 修正 1：独立
        Intent::ORDER_QUERY         => 'OrderQueryWorkflow',
        Intent::REFUND_QUERY        => 'KnowledgeWorkflow',  // 修正 18：复用 KB
        Intent::KNOWLEDGE           => 'KnowledgeWorkflow',
        Intent::SMALL_TALK          => 'SmallTalkWorkflow',
        Intent::HUMAN               => 'HandoffWorkflow',
        Intent::PRE_SALES           => 'PreSalesWorkflow',  // v2.0：售前引导到 OTA
        Intent::UNKNOWN             => 'UnknownWorkflow',
    ];

    /**
     * 路由并执行
     *
     * @return WorkflowResult
     */
    public static function route(
        \PDO $db,
        \AgentConfig $config,
        IntentContext $intentCtx,
        array $sessionState,
        string $sessionId,
        string $visitorHash = '',
        string $ip = ''
    ): WorkflowResult {
        $className = self::WORKFLOW_MAP[$intentCtx->intent] ?? 'UnknownWorkflow';

        /** @var AbstractWorkflow $workflow */
        switch ($className) {
            case 'RoomQueryWorkflow':
                $workflow = new RoomQueryWorkflow($db, $config, $intentCtx, $sessionState, $sessionId, $visitorHash, $ip);
                break;
            case 'YunfangkaCredentialWorkflow':
                $workflow = new YunfangkaCredentialWorkflow($db, $config, $intentCtx, $sessionState, $sessionId, $visitorHash, $ip);
                break;
            case 'OrderQueryWorkflow':
                $workflow = new OrderQueryWorkflow($db, $config, $intentCtx, $sessionState, $sessionId, $visitorHash, $ip);
                break;
            case 'KnowledgeWorkflow':
                $workflow = new KnowledgeWorkflow($db, $config, $intentCtx, $sessionState, $sessionId, $visitorHash, $ip);
                break;
            case 'SmallTalkWorkflow':
                $workflow = new SmallTalkWorkflow($db, $config, $intentCtx, $sessionState, $sessionId, $visitorHash, $ip);
                break;
            case 'HandoffWorkflow':
                $workflow = new HandoffWorkflow($db, $config, $intentCtx, $sessionState, $sessionId, $visitorHash, $ip);
                break;
            case 'PreSalesWorkflow':
                $workflow = new PreSalesWorkflow($db, $config, $intentCtx, $sessionState, $sessionId, $visitorHash, $ip);
                break;
            case 'UnknownWorkflow':
            default:
                $workflow = new UnknownWorkflow($db, $config, $intentCtx, $sessionState, $sessionId, $visitorHash, $ip);
                break;
        }
        return $workflow->handle();
    }
}
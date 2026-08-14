<?php
/**
 * api/Workflow/PreSalesWorkflow.php
 *
 * v2.0 — 售前问题 Workflow（v2.0 PRD 2.3）
 *
 * 处理"怎么订房/价格/有空房吗"等售前问题
 * 固定回复引导到 OTA 平台（美团/携程/飞猪等）
 *
 * 业务规则：AI 不接单，所有售前统一回 OTA
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';

final class PreSalesWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        $message = $this->intentCtx->slots['original_message'] ?? '';

        // v3.9:拒绝私下交易(保证资金安全,引导平台)
        if (preg_match('/微信转|私下|直接订|不走平台|绕过平台|转账订|红包订|加微信/u', $message)) {
            return WorkflowResult::text(
                '抱歉，为保证您的资金安全，我们不接受私下转账订房。请前往美团、携程、飞猪等 OTA 平台搜索「柚光民宿」下单，官方渠道有平台保障～',
                'PreSalesWorkflow'
            );
        }

        $reply = '请到美团、携程、飞猪等 OTA 平台搜索"柚光民宿"订房，期待与您相见~';

        return WorkflowResult::text($reply, 'PreSalesWorkflow');
    }
}

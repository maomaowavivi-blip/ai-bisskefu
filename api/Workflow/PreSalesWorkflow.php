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
        $reply = '请到美团、携程、飞猪等 OTA 平台搜索"柚光民宿"订房，期待与您相见~';

        return WorkflowResult::text($reply, 'PreSalesWorkflow');
    }
}

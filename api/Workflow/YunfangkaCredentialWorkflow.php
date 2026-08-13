<?php
/**
 * api/Workflow/YunfangkaCredentialWorkflow.php
 *
 * v3.3 PR2 — 凭证类 Workflow（修正 1：独立 Workflow，不复用 KnowledgeWorkflow）
 *
 * 处理 WiFi/门锁/押金/刷脸等凭证类查询
 * 行为：固定话术 + 云房卡 rich_content 卡片
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../sidecar/SidecarIntent.php';

final class YunfangkaCredentialWorkflow extends AbstractWorkflow
{
    public function handle(): WorkflowResult
    {
        // 优先用 SidecarIntent 提供的固定话术（保持一致）
        $reply = SidecarIntent::yunfangkaCredentialReply();

        // 如果 history 中有订单号（修正 12），前缀「刚才订单 XXX 关联的云房卡...」
        $orderNo = $this->intentCtx->slots['order_no'] ?? '';
        if ($orderNo !== '') {
            $reply = '刚才订单 ' . $orderNo . ' 关联的云房卡已展示在下方。' . $reply;
        }

        // 云房卡 rich_content 卡片（前端会渲染为可点击卡片）
        $card = [
            'type' => 'yunfangka_card',
            'title' => '点击查看云房卡',
            'description' => '办理公安刷脸核验 / 在线交押金 / 查看 WiFi 密码与门锁密码',
            'image_link' => '',
            'action_url' => '',
        ];

        return WorkflowResult::card($reply, [$card], 'YunfangkaCredentialWorkflow');
    }
}
<?php
/**
 * api/ReplyRenderer.php
 *
 * v3.3 PR2 — WorkflowResult → API 响应
 *
 * 修正 6：保持前端 rich_content + room_pick 双字段契约
 * 修正 16：企微渠道（wechat_mp / wechat_msg）不带 rich_content
 *
 * 返回结构（不含 elapsed_ms，由 chatResponse 统一补）：
 *   reply, is_verified, handoff_status, rich_content?, room_pick?
 */

declare(strict_types=1);

require_once __DIR__ . '/Workflow/AbstractWorkflow.php';

final class ReplyRenderer
{
    /**
     * WorkflowResult → API 响应数组
     *
     * @param WorkflowResult $result
     * @param string $channel web / wechat_mp / wechat_msg / openapi / api
     * @return array
     */
    public static function render(WorkflowResult $result, string $channel = 'web'): array
    {
        $data = [
            'reply' => $result->text,
            'is_verified' => $result->isVerified,
            'handoff_status' => $result->handoffStatus,
        ];

        // 修正 16：企微渠道不带 rich_content（企微渲染跟 H5 不同）
        $wechatChannels = ['wechat_mp', 'wechat_msg'];
        if (!empty($result->richContent) && !in_array($channel, $wechatChannels, true)) {
            $data['rich_content'] = $result->richContent;
        }

        if (!empty($result->roomPick)) {
            $data['room_pick'] = $result->roomPick;
        }

        return $data;
    }

    /**
     * 简单文本渲染（用于安全拦截、固定话术等早期出口）
     */
    public static function renderText(string $text): array
    {
        return [
            'reply' => $text,
            'is_verified' => false,
            'handoff_status' => -1,
        ];
    }
}
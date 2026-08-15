<?php
/**
 * api/Workflow/YunfangkaCredentialWorkflow.php
 *
 * v3.3 PR2 — 凭证类 Workflow
 * v3.8 — 改为 urlLink 文本引导(不显示任何密码)
 *
 * 处理 WiFi/门锁/押金/刷脸等凭证类查询
 * 行为:返回文本引导,客户点 urlLink 进小程序查看密码
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';

final class YunfangkaCredentialWorkflow extends AbstractWorkflow
{
    // v3.8:小程序 urlLink(程序员已配置 urlLink.generate)
    private const URL_LINK = 'https://wxmpurl.cn/f1c4BdFdHDn';

    public function handle(): WorkflowResult
    {
        // v3.8:文本引导,绝不返回密码
        $reply = "WiFi 密码、门锁密码、押金缴纳及公安验证,请在小程序内查看 👇\n\n";
        $reply .= self::URL_LINK;

        return WorkflowResult::text($reply, 'YunfangkaCredentialWorkflow');
    }
}
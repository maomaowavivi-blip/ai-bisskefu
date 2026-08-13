<?php
/**
 * api/Workflow/AbstractWorkflow.php
 *
 * v3.3 PR2 — Workflow 基类 + WorkflowResult 数据结构
 *
 * 设计：
 * - 所有 Workflow 继承本类，实现 handle() 方法
 * - WorkflowResult 是统一输出，ReplyRenderer 负责转 API 响应
 * - 不引入框架，保持 PHP 7.4+ 兼容
 */

declare(strict_types=1);

require_once __DIR__ . '/../Intent.php';

/**
 * Workflow 输出结果
 *
 * 字段：
 * - text: 文本回复（必填）
 * - richContent: rich_content 卡片数组（可选，云房卡、政策类）
 * - roomPick: room_pick 选房列表（可选，一单多房）
 * - isVerified: 用户是否已 SMS 验证（默认 false）
 * - handoffStatus: 人工接管状态 - -1=无 0=已转 1=接管中 2=已结束（默认 -1）
 * - workflowName: 当前 Workflow 名称（用于打点）
 * - renderType: 渲染类型（text/card/list/file）
 */
final class WorkflowResult
{
    public string $text = '';
    public array $richContent = [];
    public ?array $roomPick = null;
    public bool $isVerified = false;
    public int $handoffStatus = -1;
    public string $workflowName = '';
    public string $renderType = 'text';

    /** @var array<string,mixed> 内部扩展字段（orderCache / handoffId 等） */
    public array $extra = [];

    public static function text(string $text, string $workflowName = ''): self
    {
        $r = new self();
        $r->text = $text;
        $r->workflowName = $workflowName;
        $r->renderType = 'text';
        return $r;
    }

    public static function card(string $text, array $richContent, string $workflowName = ''): self
    {
        $r = self::text($text, $workflowName);
        $r->richContent = $richContent;
        $r->renderType = 'card';
        return $r;
    }

    public static function roomPick(string $text, array $roomPick, string $workflowName = ''): self
    {
        $r = self::text($text, $workflowName);
        $r->roomPick = $roomPick;
        $r->renderType = 'list';
        return $r;
    }

    public function withHandoffStatus(int $status): self
    {
        $this->handoffStatus = $status;
        return $this;
    }

    public function withExtra(array $extra): self
    {
        $this->extra = array_merge($this->extra, $extra);
        return $this;
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'rich_content' => $this->richContent,
            'room_pick' => $this->roomPick,
            'is_verified' => $this->isVerified,
            'handoff_status' => $this->handoffStatus,
            'workflow_name' => $this->workflowName,
            'render_type' => $this->renderType,
            'extra' => $this->extra,
        ];
    }
}

/**
 * Workflow 基类
 *
 * 子类必须实现 handle() 方法，返回 WorkflowResult
 */
abstract class AbstractWorkflow
{
    protected \PDO $db;
    protected \AgentConfig $config;
    protected IntentContext $intentCtx;
    protected array $sessionState;
    protected string $sessionId;
    protected string $visitorHash;
    protected string $ip;

    public function __construct(
        \PDO $db,
        \AgentConfig $config,
        IntentContext $intentCtx,
        array $sessionState,
        string $sessionId,
        string $visitorHash = '',
        string $ip = ''
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->intentCtx = $intentCtx;
        $this->sessionState = $sessionState;
        $this->sessionId = $sessionId;
        $this->visitorHash = $visitorHash;
        $this->ip = $ip;
    }

    /**
     * 处理并返回结果
     */
    abstract public function handle(): WorkflowResult;
}
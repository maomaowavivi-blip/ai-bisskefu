<?php
/**
 * api/Intent.php
 *
 * v3.3 PR1 — Intent 枚举 + IntentContext 数据结构
 *
 * 设计：
 * - Intent 是字符串常量，方便 DB 存储和审计（chat_logs.intent 字段）
 * - IntentContext 持有分类结果和上下文，Workflow 之间通过它传递状态
 * - 不依赖框架，纯 PHP 7.4+ 兼容
 */

declare(strict_types=1);

/**
 * Intent 枚举
 *
 * 命名规则：SHOUTING_SNAKE_CASE
 * 维度：按"用户想问什么"分类（≈ 审计维度）
 * Workflow 复用：Intent 数量 >= Workflow 数量（部分 Intent 走同一个 Workflow）
 */
final class Intent
{
    /** 房间事实（地址/停车/垃圾/设备等，需要订单号绑定） */
    const ROOM_QUERY = 'ROOM_QUERY';

    /** 凭证类（WiFi/门锁/押金/刷脸）→ 云房卡引导，独立 Workflow */
    const ROOM_PASSWORD_QUERY = 'ROOM_PASSWORD_QUERY';

    /** 查订单（冻结块，不可重构） */
    const ORDER_QUERY = 'ORDER_QUERY';

    /** 退款（独立 Intent 用于审计，复用 KnowledgeWorkflow） */
    const REFUND_QUERY = 'REFUND_QUERY';

    /** 通用 FAQ（KB 命中） */
    const KNOWLEDGE = 'KNOWLEDGE';

    /** 闲聊 */
    const SMALL_TALK = 'SMALL_TALK';

    /** 兜底（UnknownWorkflow 用 LLM 生成） */
    const UNKNOWN = 'UNKNOWN';

    /** 转人工（含 P0-P4 优先级） */
    const HUMAN = 'HUMAN';

    /** 售前问题（订房/价格/空房等）→ 引导到 OTA 平台 */
    const PRE_SALES = 'PRE_SALES';

    /** 所有 Intent 值（用于校验） */
    const ALL = [
        self::ROOM_QUERY,
        self::ROOM_PASSWORD_QUERY,
        self::ORDER_QUERY,
        self::REFUND_QUERY,
        self::KNOWLEDGE,
        self::SMALL_TALK,
        self::UNKNOWN,
        self::HUMAN,
        self::PRE_SALES,
    ];

    /**
     * 校验 Intent 字符串是否合法
     */
    public static function isValid(string $intent): bool
    {
        return in_array($intent, self::ALL, true);
    }
}

/**
 * Intent 分类上下文
 *
 * 字段说明：
 * - intent: Intent 常量值
 * - confidence: 0.0 ~ 1.0，越高表示分类越确定
 * - slots: 抽取的实体（如 order_no）
 * - reasoning: 分类原因（rule:xxx / fallback），用于审计
 * - kbItems: 预召回的 KB 条目（给 KnowledgeWorkflow 用，避免重复查询）
 * - sidecarHits: 预召回的 Sidecar 房间事实（暂未启用，预留）
 * - sessionState: 当前会话状态（room_query_sessions / order_context_cache / handoff）
 * - priority: HUMAN 用（P0=0 最紧急，P4=4 普通）
 */
final class IntentContext
{
    public string $intent = Intent::UNKNOWN;
    public float $confidence = 0.0;
    public array $slots = [];
    public string $reasoning = 'fallback';
    public array $kbItems = [];
    public array $sidecarHits = [];
    public array $sessionState = [];
    public int $priority = 99;

    public function __construct(
        string $intent = Intent::UNKNOWN,
        float $confidence = 0.0,
        array $slots = [],
        string $reasoning = 'fallback',
        array $kbItems = [],
        array $sidecarHits = [],
        array $sessionState = [],
        int $priority = 99
    ) {
        if (!Intent::isValid($intent)) {
            throw new \InvalidArgumentException("Invalid intent: {$intent}");
        }
        $this->intent = $intent;
        $this->confidence = $confidence;
        $this->slots = $slots;
        $this->reasoning = $reasoning;
        $this->kbItems = $kbItems;
        $this->sidecarHits = $sidecarHits;
        $this->sessionState = $sessionState;
        $this->priority = $priority;
    }

    /**
     * 工厂方法（蓝图 §六约定）
     */
    public static function of(
        string $intent,
        float $confidence = 0.0,
        array $slots = [],
        string $reasoning = 'fallback',
        array $kbItems = [],
        int $priority = 99
    ): self {
        return new self($intent, $confidence, $slots, $reasoning, $kbItems, [], [], $priority);
    }

    /**
     * 转数组（用于日志/调试）
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'slots' => $this->slots,
            'reasoning' => $this->reasoning,
            'kb_items_count' => count($this->kbItems),
            'priority' => $this->priority,
        ];
    }
}
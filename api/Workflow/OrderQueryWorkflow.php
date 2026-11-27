<?php
/**
 * api/Workflow/OrderQueryWorkflow.php
 *
 * v3.3 PR2 — 订单查询 Workflow（冻结块，修正 2：只搬不改）
 *
 * 设计：完全复制 chat.php:431-470 的 order_query: 冻结逻辑
 * 不重构、不优化、不变量重命名
 *
 * 已知 chat.php:431-470 处理：
 *   - order_query: 前缀识别
 *   - 提取订单号
 *   - 数据中台订单查询 CenterApi::orderByChannelOrderId()（v3.26 取代旧 apicenter）
 *   - 写入 order_context_cache（24h TTL）
 *   - 返回 rich_content 云房卡卡片
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractWorkflow.php';
require_once __DIR__ . '/../CenterApi.php';

final class OrderQueryWorkflow extends AbstractWorkflow
{
    /**
     * v3.35 — 单房入住指引装载（蓝图 §7.1.3 / P1-4）。
     * 指引链路用 CenterRoom::checkinAll()：保留 text 为空但有图的 step（checkin() 会连图丢弃）。
     * 房源查不到（room_ids 有值但中台无房）→ null，调用方只发 M1、不报错（§5.3）。
     *
     * @return array{baoyu_room_no:string,room_code:string,display_name:string,has_guide:bool,steps:array}|null
     */
    private function loadGuideByRoomNo(string $roomNo): ?array
    {
        if ($roomNo === '') return null;
        try {
            $room = CenterRoom::loadByBaoyuRoomNo($this->db, $roomNo);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] room load failed: ' . $e->getMessage());
            return null;
        }
        if (!is_array($room)) return null;
        $steps = CenterRoom::checkinAll($room);
        return [
            'baoyu_room_no' => $roomNo,
            'room_code'     => trim((string)($room['room_code'] ?? '')),
            'display_name'  => CenterRoom::displayName($room),
            'has_guide'     => ($steps !== []),
            'steps'         => $steps,
        ];
    }

    public function handle(): WorkflowResult
    {
        $message = $this->intentCtx->slots['original_message'] ?? '';
        if ($message === '') {
            return WorkflowResult::text('订单号不能为空', 'OrderQueryWorkflow');
        }

        // 提取订单号
        if (preg_match('/^order_query:(.+)$/u', $message, $m)) {
            $orderNo = trim($m[1]);
        } else {
            $orderNo = $message;
        }

        if ($orderNo === '') {
            // 修正：order_query: 但无订单号，给引导话术（与旧 chat.php 行为一致）
            return WorkflowResult::text(
                '如需查询订单，请点击下方「订单查询」按钮，或直接发送 order_query:订单号～',
                'OrderQueryWorkflow'
            );
        }

        // v3.26:数据中台订单查询(取代已下线的旧 apicenter channelOrder / 自建网关)
        try {
            $orderResult = CenterApi::orderByChannelOrderId($this->db, $orderNo);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] CenterApi failed: ' . $e->getMessage());
            $orderResult = null;
        }

        // 查单失败(订单不存在 / 中台异常)
        if (!$orderResult || empty($orderResult['order'])) {
            return WorkflowResult::text(
                ORDER_NOT_FOUND_REPLY,
                'OrderQueryWorkflow'
            );
        }

        $orderData  = $orderResult['order'];

        // 查单成功 → 文本订单信息(web 端不依赖 thumb/云房卡)
        // v3.33:按苏鸣要求不再向客户展示实付金额(paid_amount 出参保留在 CenterApi,需要时可回加)
        // v3.34.1:尾句「前往微信客服获取」仅对非微信客服渠道输出——wechat_kf 客人已在
        //         微信客服、房卡就在下一条消息里，该句多余（苏鸣 2026-11-20）。
        //         channel 由 ChatPipeline 注入 slots（v3.30 先例，:126 行）。
        $channel    = (string)($this->intentCtx->slots['channel'] ?? '');
        $cardGuide  = ($channel === 'wechat_kf') ? '' : '如需查看云房卡请前往微信客服发送订单号获取。';
        $reply = "订单查询成功：\n"
            . ($orderData['unit_name'] ? "房型：{$orderData['unit_name']}\n" : '')
            . ($orderData['check_in'] ? "入住：" . date('Y-m-d', strtotime($orderData['check_in'])) . "\n" : '')
            . ($orderData['check_out'] ? "离店：" . date('Y-m-d', strtotime($orderData['check_out'])) . "\n" : '')
            . $cardGuide;

        // 写入 order_context_cache(24h TTL)
        //
        // v3.26 修正两处历史错误（该表自建表起从未写入成功过，错误被 catch 吞掉）：
        //   1) 生产表没有 created_at 列（是 verified_at），写 created_at 直接报 1054 Unknown column
        //   2) room_list 是 varchar(500) 且注释为「逗号分隔的房间号列表」，
        //      旧代码存整个订单对象 JSON（1051 字符）必然超长
        // 现按真实表结构写入：room_id / room_list 都存订单的 room_ids（逗号分隔房间号）。
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO order_context_cache (session_id, order_no, room_id, room_list, expires_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE order_no=VALUES(order_no), room_id=VALUES(room_id),
                   room_list=VALUES(room_list), expires_at=VALUES(expires_at)'
            );
            $roomIds = (string)($orderData['room_ids'] ?? '');
            $stmt->execute([
                $this->sessionId,
                $orderNo,
                $roomIds,
                $roomIds,
                date('Y-m-d H:i:s', time() + 86400),
            ]);
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] cache write failed: ' . $e->getMessage());
        }

        // v3.30：写 session↔订单绑定(session_order_links，写点1，蓝图 §3.2-1)
        // helper 内部全 catch，外层再包 try/catch 双保险，任何情况不影响客户回复。
        try {
            require_once __DIR__ . '/../helpers/session_order_link.php';
            linkSessionOrder(
                $this->db,
                $this->sessionId,
                $orderNo,
                (string)($orderData['room_ids'] ?? ''),
                (string)($this->intentCtx->slots['channel'] ?? ''),
                'order_query'
            );
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] session_order_link failed: ' . $e->getMessage());
        }

        // ──────────────────────────────────────────
        // v3.35 订单查询分时段回复（蓝图 §7.1）
        //
        // P2-10 调用顺序：算 stage → 读 lead → lead 空立即 return（不查中台房源，
        // 零额外耗时）→ 否则才取指引数据。
        // 时段锚 MySQL CURDATE()：生产 php-fpm=UTC / MySQL=CST 差 8 小时，
        // PHP date() 在凌晨 0-8 点会算错一天（§3.4 / AGENTS.md §15），禁用。
        // ──────────────────────────────────────────
        $today = '';
        try {
            $today = (string)$this->db->query('SELECT CURDATE() d')->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[OrderQueryWorkflow] CURDATE failed: ' . $e->getMessage());
        }

        $stageKey = orderStage($today, (string)($orderData['check_in'] ?? ''), (string)($orderData['check_out'] ?? ''));

        // 引导文字（兼该场景开关，§6）。配置读法一律 pcGet 直查 DB（AGENTS.md §10），
        // 禁 AgentConfig::get()（进程级静态缓存会让「后台保存了不生效」）。
        $lead = '';
        if ($stageKey !== 'unknown') {
            $lead = trim((string)pcGet($this->db, 'order_reply.text.' . $stageKey, ''));
        }

        // ── 单一守门点（§5.1 / qa B1，最关键）──
        // lead 为空（含 stage=unknown / 三段配置全空）→ 不 set extra、不拼任何附加内容，
        // reply 与 v3.34.1 逐字节一致（安全垫，.trae/documents/baseline-v3.34.1-order-query.json）。
        if ($lead === '') {
            return WorkflowResult::text($reply, 'OrderQueryWorkflow');
        }

        require_once __DIR__ . '/../OrderGuidePick.php';

        $guideText = '';
        $rooms     = [];
        $multi     = false;
        $pickText  = '';

        if ($stageKey === 'precheckin') {
            // 仅「未到入住」需要中台房源指引（§7.1.3；during/after 不查房源，零额外中台调用）
            $roomNos = [];
            foreach (explode(',', (string)($orderData['room_ids'] ?? '')) as $rid) {
                $rid = trim($rid);
                if ($rid !== '' && !isset($roomNos[$rid])) $roomNos[$rid] = true;
            }
            $roomNos = array_keys($roomNos);

            if (count($roomNos) === 1) {
                // 单房：现查指引（checkinAll 保留有图无字 step，P1-4）
                // ⚠️ PHP 会把纯数字字符串数组 key 自动转成 int → array_keys() 返回 int。
                // loadGuideByRoomNo(string) 是严格类型，必须显式转回 string（生产真实房号 1622089 触发过 TypeError）。
                $g = $this->loadGuideByRoomNo((string)$roomNos[0]);
                if ($g !== null) {
                    $guideText = OrderGuidePick::guideTextFromSteps($g['steps']);
                    $rooms[] = $g;
                }
                // 房源查不到 → rooms 保持空，只发 M1（§5.3 不报错不打断）
            } elseif (count($roomNos) >= 2) {
                // 多房：只出候选、不查指引（客人选中才现查，qa C5）
                $candidates = [];
                try {
                    require_once __DIR__ . '/../sidecar/OrderRoomMapper.php';
                    $candidates = OrderRoomMapper::mapOrderData($orderData);
                } catch (\Throwable $e) {
                    error_log('[OrderQueryWorkflow] room candidates failed: ' . $e->getMessage());
                }
                if (count($candidates) >= 2) {
                    $multi = true;
                    foreach ($candidates as $c) {
                        $rooms[] = [
                            'baoyu_room_no' => (string)($c['baoyu_room_no'] ?? ''),
                            'room_code'     => (string)($c['room_code'] ?? ''),
                            'display_name'  => (string)($c['display_name'] ?? ''),
                        ];
                    }
                    $pickText = OrderGuidePick::buildPickText($rooms);
                    // stage=1 统一由 workflow 侧写（P2-6）：通道层不写，否则 H5 渠道进不了选房。
                    // helper 内部全 catch，表未建时 fail-open。
                    OrderGuidePick::openSession($this->db, $this->sessionId, $orderNo, $rooms);
                } elseif (count($candidates) === 1) {
                    // 单房 → 不进选房流程；同时清掉本会话可能残留的旧选房状态（P2-5：
                    // 客人先查过多房订单、没回复又查了单房订单时，旧候选不该再被命中）
                    OrderGuidePick::closeSession($this->db, $this->sessionId);
                    // 中台侧去重后只剩一间 → 按单房给指引
                    $g = $this->loadGuideByRoomNo((string)($candidates[0]['baoyu_room_no'] ?? ''));
                    if ($g !== null) {
                        $guideText = OrderGuidePick::guideTextFromSteps($g['steps']);
                        $rooms[] = $g;
                    }
                }
                // 0 候选（房源都查不到）→ 只发 M1
            }
            // room_ids 为空（实测 33%）→ 什么都不加，只发 M1（§5.3）
        }

        // 载荷（§7.1.4；只有 lead 非空才走到这里 —— 单一守门点之上已 return）
        $orderGuide = [
            'stage'     => $stageKey,
            'mode'      => 'order', // pick=选房轮（OrderGuidePick 产出）；订单轮固定 order（P0）
            'lead'      => $lead,
            'rooms'     => $rooms,
            'multi'     => $multi,
            'pick_text' => $pickText,
        ];
        $result = WorkflowResult::text($reply, 'OrderQueryWorkflow')->withExtra(['order_guide' => $orderGuide]);

        // 渠道差异（§7.1.5）：wechat_kf 的 reply 只含订单信息（与今天一致，指引由通道层分条发）；
        // 其它渠道（web/H5/openapi）无图片消息能力 → reply = lead + 订单信息 + 指引文字，
        // 图片 URL 留在 extra.order_guide 备用字段（C7）。
        if ($channel !== 'wechat_kf') {
            $final = $lead . "\n\n" . $reply;
            if (!$multi && $guideText !== '') $final .= "\n\n" . $guideText;
            if ($pickText !== '')             $final .= "\n\n" . $pickText;
            $result->text = $final;
        }

        return $result;
    }
}

/**
 * v3.35 — 订单时段判定（蓝图 §7.1.1，纯函数可本地单测，qa B2/B10）
 *
 * @param string $today    'Y-m-d'，调用方用 SELECT CURDATE() 取得（锚 MySQL 钟）
 * @param string $checkIn  中台 check_in（实测 'Y-m-d H:i:s'，取前 10 位）
 * @param string $checkOut 中台 check_out
 * @return string precheckin | during | after | unknown
 *
 * 空/畸形日期 → 'unknown'（中性：不拼引导文字、不发指引、不发视频，行为与今天一致）。
 * 'Y-m-d' 字典序 = 时间序，无需时区转换。
 */
if (!function_exists('orderStage')) {
    function orderStage(string $today, string $checkIn, string $checkOut): string
    {
        if ($today === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) return 'unknown';
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $checkIn, $mIn))  return 'unknown';
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $checkOut, $mOut)) return 'unknown';
        $ci = $mIn[1];
        $co = $mOut[1];
        if ($today <  $ci) return 'precheckin';
        if ($today <= $co) return 'during';
        return 'after';
    }
}

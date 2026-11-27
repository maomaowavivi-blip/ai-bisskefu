<?php
/**
 * api/wecom_kf_order_guide.php
 *
 * v3.35 — 订单指引轮消息编排纯函数（蓝图 §5.2 / §5.2.1 / §5.2.3 / §7.6.2）
 *
 * 纯函数：不碰 DB、不发消息、不读配置——只做「内容 + 数量 → 发送消息序列」。
 * 由通道层 api/wecom_kf.php 调用；scripts/test_v335_order_guide_messages.php 可直接单测。
 *
 * $ctx 键（调用方保证 lead 非空——单一守门点 §5.1 已过滤 lead 为空的场景）：
 *   stage          'precheckin' | 'during' | 'after'
 *   lead           引导文字
 *   orderText      订单信息（Pipeline 已生成）
 *   cardReason     云房卡原因话术（无卡场景才传，已清洗；'' = 无）
 *   cardCount      本轮实际可发的云房卡张数（0 = 无卡场景）
 *   roomCountText  「您一共订了 N 间房」间数引导（cardCount>=2 时有；'' = 无）
 *   roomPickText   多房选房问句（'' = 非多房）
 *   guideText      入住指引文字（steps 拼接而来；中台原文，不改一字）
 *   guideImages    指引图片 URL 列表（本函数负责截到 ≤3）
 *   videoPath      云房卡使用指引视频源文件路径（'' = 无视频；发送时才换 media_id）
 *   butlerContact  管家联系方式（管家模式用；'' = 用兜底文案）
 *
 * 管家模式（§5.2.4）：cardCount >= 5 → 卡装不下 → 不发卡，改为「M1 + 管家提示」两条。
 *
 * 返回：按发送顺序排列的消息数组，元素形态：
 *   ['type'=>'text',  'content'=>string]      发一条文字
 *   ['type'=>'cards']                          房卡发送位（房卡由通道层自己发，不可降级）
 *   ['type'=>'video', 'path'=>string]          视频（重新 upload 换 media_id 后发）
 *   ['type'=>'image', 'url'=>string]           指引图片（下载 + upload 后发）
 *
 * 预算公式（§5.2.1）：
 *   total = 1(M1) + cardCount(卡) + [间数引导] + [指引文字] + [视频] + 图数   ≤ 5
 * 降级优先级（依次直到 ≤5）：
 *   ① 间数引导并入 M1 → ② 删最后一张图 → ③ 弃视频 → ④ 指引文字合并拆条/截断
 *   ≥5 张卡属既有无解旧债（订单信息与房卡不可降级），本函数尽力输出、不吞消息。
 */

declare(strict_types=1);

if (!function_exists('buildOrderGuideMessages')) {
    /**
     * @param array $ctx 见文件头注释
     * @return array[] 有序消息数组
     */
    function buildOrderGuideMessages(array $ctx): array
    {
        $lead       = trim((string)($ctx['lead'] ?? ''));
        $orderText  = trim((string)($ctx['orderText'] ?? ''));
        $cardReason = trim((string)($ctx['cardReason'] ?? ''));
        $cardCount  = max(0, (int)($ctx['cardCount'] ?? 0));
        $butlerContact = trim((string)($ctx['butlerContact'] ?? ''));
        // v3.35 管家模式（§5.2.4）：cardCount>=5 → 5 条预算里 M1 占 1 条，只剩 4 条给卡 → 卡装不下。
        // 房卡是核心交付、不可降级 → 唯一出路：放弃发卡，改发一条管家提示（苏鸣 2026-11-27 定）。
        $butlerMode = ($cardCount >= 5);
        $roomCount  = trim((string)($ctx['roomCountText'] ?? ''));
        $pickText   = trim((string)($ctx['roomPickText'] ?? ''));
        $guideText  = (string)($ctx['guideText'] ?? ''); // 中台原文：内部空白不 trim（§「中台写什么发什么」）
        $videoPath  = trim((string)($ctx['videoPath'] ?? ''));

        $images = [];
        foreach ((array)($ctx['guideImages'] ?? []) as $u) {
            $u = trim((string)$u);
            if ($u !== '') $images[] = $u;
        }

        // ── 文本长度规则（§5.2.3）──
        // 指引文字 >1200 截到 1200；>600 拆 2 条，并把图片上限同步降到 2（保证总条数 ≤5）
        $guideParts = [];
        if ($guideText !== '') {
            if (mb_strlen($guideText) > 1200) {
                $guideText = mb_substr($guideText, 0, 1200);
            }
            if (mb_strlen($guideText) > 600) {
                $guideParts = [mb_substr($guideText, 0, 600), mb_substr($guideText, 600)];
                $images = array_slice($images, 0, 2);
            } else {
                $guideParts = [$guideText];
            }
        }
        $images = array_slice($images, 0, 3); // 图 ≤3（C6）

        // ── 预算降级（§5.2.1），只降可降的：间数引导/图/视频/指引文字 ──
        $roomCountMerged = false;
        $videoOn  = ($videoPath !== '' && !$butlerMode); // 管家模式没有卡 → 不发「云房卡使用指引视频」
        $imgList  = $images;

        $total = static function (bool $rcMerged, array $imgs, bool $video, int $guidePartCount) use ($cardCount, $roomCount, $butlerMode): int {
            // 管家模式：卡占 0 条，但多一条管家提示（§5.2.4）
            $n = 1 + ($butlerMode ? 1 : $cardCount) + $guidePartCount + count($imgs) + ($video ? 1 : 0);
            if ($roomCount !== '' && !$rcMerged) $n += 1;
            return $n;
        };

        $guard = 0;
        while ($total($roomCountMerged, $imgList, $videoOn, count($guideParts)) > 5 && $guard < 32) {
            $guard++;
            if (!$roomCountMerged && $roomCount !== '') { $roomCountMerged = true; continue; } // ①
            if (count($imgList) > 0) { array_pop($imgList); continue; }                        // ②
            if ($videoOn) { $videoOn = false; continue; }                                      // ③
            if (count($guideParts) > 1) { $guideParts = [mb_substr($guideText, 0, 600)]; continue; } // ④ 合并拆条并截断
            break; // 订单信息与房卡不可降级（≥5 卡旧债，§5.2）
        }

        // ── M1 = lead + 订单信息（+ 房卡原因 + 选房问句 / 并入的间数引导，追加在末尾）──
        // M1 溢出规则（§5.2.3）：超 600 先截房卡原因（中台话术，只影响尾巴），再溢出整体截 600
        $core = $lead;
        if ($orderText !== '') $core .= "\n\n" . $orderText;
        $m1 = $core;
        if ($cardReason !== '' && $cardCount === 0) {
            $budget = 600 - mb_strlen($core) - 2 - 10; // 房卡原因截到 600-len(core)-10
            if ($budget >= 12) {
                $m1 = $core . "\n\n" . ($budget < mb_strlen($cardReason) ? mb_substr($cardReason, 0, $budget) : $cardReason);
            }
            // 空间不足（budget<12）时房卡原因直接省略，选房问句/订单信息优先保住
        }
        if ($pickText !== '') $m1 .= "\n" . $pickText;
        if ($roomCountMerged && $roomCount !== '') $m1 .= "\n" . $roomCount;
        if (mb_strlen($m1) > 600) {
            $m1 = mb_substr($m1, 0, 600); // 通道层新拼消息不经 wecom_kf.php:362 的 mb_substr，自己截
        }

        // ── 序列（§5.2）：M1 → [间数引导] → 卡 → 视频 → 指引文字 → 图 ──
        // meta 标记各 text 的来源角色（m1/room_count/guide），供通道层写事件行统计，不参与内容。
        $msgs = [['type' => 'text', 'meta' => 'm1', 'content' => $m1]];
        if ($roomCount !== '' && !$roomCountMerged) {
            $msgs[] = ['type' => 'text', 'meta' => 'room_count', 'content' => $roomCount];
        }
        if ($butlerMode) {
            // 管家模式（§5.2.4）：不产生 cards 占位 → 通道层据此跳过发卡循环
            $butlerText = sprintf('您一共订了 %d 间房，房间较多，云房卡请直接联系管家获取：', $cardCount);
            if ($butlerContact !== '') $butlerText .= "\n" . $butlerContact;
            $msgs[] = ['type' => 'text', 'meta' => 'butler', 'content' => $butlerText];
        } elseif ($cardCount > 0) {
            // count 字段供调用方/单测换算实际条数（一个占位 = cardCount 张卡）
            $msgs[] = ['type' => 'cards', 'count' => $cardCount];
        }
        if ($videoOn) {
            $msgs[] = ['type' => 'video', 'path' => $videoPath];
        }
        foreach ($guideParts as $p) {
            $msgs[] = ['type' => 'text', 'meta' => 'guide', 'content' => $p];
        }
        foreach ($imgList as $u) {
            $msgs[] = ['type' => 'image', 'url' => $u];
        }
        return $msgs;
    }
}

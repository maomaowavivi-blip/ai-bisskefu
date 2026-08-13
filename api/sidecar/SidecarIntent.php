<?php

/**
 * Sidecar 房间意图统一词表（step=1 进流 + RoomQueryService 域匹配）
 * 后台 gateway.room_keywords 仅作扩展，不会覆盖/删减基础词表
 */
class SidecarIntent {
    /** step=1：识别「房间/在店」类问题，进入房间流 */
    public static function baseEntryKeywords(): array {
        return [
            // 到达 / 位置
            '怎么去', '如何到', '路线', '导航', '地址', '在哪里', '在哪儿', '定位', '地图',
            '地铁', '公交', '机场', '高铁', '哪个门', '几号楼', '电梯',
            // 停车
            '停车', '停车场', '车位', '车放哪', '停车费',
            // WiFi / 门禁
            'wifi', 'WiFi', '无线', '无线网', '网络', '网密码', '上网',
            '门禁', '门锁', '密码锁', '单元门', '怎么进', '房卡', '钥匙', '大门',
            // 入住
            '怎么入住', '怎么进去', '入住指引', '房间指引', '房间号', '几号房', '办理入住',
            // 设备
            '怎么用', '怎么开', '如何使用', '使用说明', '操作',
            '空调', '电视', '洗衣机', '热水', '热水器', '冰箱', '电磁炉', '微波炉', '吹风机',
            // 在店（续住/换房走转人工；统一退房时间走通用 KB，不含裸「退房」）
            '设施', '设备', '垃圾', '保洁', '延迟退房',
        ];
    }

    public static function getEntryKeywords(PDO $db): array {
        $merged = self::baseEntryKeywords();
        $saved = trim(pcGet($db, 'gateway.room_keywords', ''));
        if ($saved !== '') {
            $extra = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $saved)));
            $merged = array_merge($merged, $extra);
        }
        return array_values(array_unique($merged));
    }

    public static function matchesEntry(string $message, array $keywords): bool {
        if (preg_match('/云房卡|电子房卡/u', $message)
            && preg_match('/(是什么|怎么用|有什么|干什么|干嘛|多少钱|要付钱)/u', $message)
            && !self::isYunfangkaCredentialQuery($message)) {
            return false;
        }
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_strpos($message, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /** step=3：退出 Sidecar，交回订单/转人工等流程 */
    public static function isFlowExitMessage(string $message, PDO $db): bool {
        if (preg_match('/^(🔍\s*)?(我要|我想)?\s*(查|查询|想查)\s*(订单|快递|物流|入住)/u', $message)) {
            return true;
        }
        return self::isHandoffMessage($message, $db);
    }

    /** step=3：简短寒暄，保留绑单，不走 Sidecar */
    public static function isChitchat(string $message): bool {
        $msg = trim($message);
        if ($msg === '') {
            return false;
        }
        foreach (['谢谢', '感谢', '好的', '好哒', '没问题', '知道了', '收到', 'ok', 'OK', '嗯嗯', '明白'] as $kw) {
            if ($msg === $kw || mb_strpos($msg, $kw) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function wifiKeywords(): array {
        return ['wifi', 'WiFi', '无线', '无线网', '网络', '网密码', '上网', '密码'];
    }

    public static function accessKeywords(): array {
        return ['门禁', '门锁', '密码锁', '怎么进', '怎么入住', '单元门', '房卡', '钥匙', '大门'];
    }

    public static function addressKeywords(): array {
        return ['地址', '在哪', '在哪里', '在哪儿', '怎么去', '如何到', '路线', '导航', '交通', '定位', '地图', '地铁', '公交', '机场', '哪个门', '电梯'];
    }

    public static function parkingKeywords(): array {
        return ['停车', '停车场', '车位', '车放', '停车费', '车停哪里', '车停哪', '停哪里', '停在哪'];
    }

    /** step=3：泛攻略/统一政策，回落通用 KB，不走 Sidecar */
    public static function isGeneralKbQuestion(string $message): bool {
        $msg = trim($message);
        if ($msg === '') {
            return false;
        }
        foreach (['附近', '好吃', '美食', '吃什么', '推荐吃', '特色', '景点', '天气', '青秀山', '旅游'] as $kw) {
            if (mb_strpos($msg, $kw) !== false) {
                return true;
            }
        }
        if (preg_match('/(中午|必须).{0,8}(几点|走|退)/u', $msg)) {
            return true;
        }
        foreach (['几点退房', '几点入住', '入住时间', '退房时间'] as $kw) {
            if (mb_strpos($msg, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function tipsKeywords(): array {
        return ['垃圾', '保洁', '延迟退房', '退房', '一次性', '打扫', '卫生'];
    }

    /** 设备 / 设施类咨询（Sidecar 结构化表优先，禁止回落整段 guide  chunk） */
    public static function deviceKeywords(): array {
        return [
            '空调', '电视', '洗衣机', '烘干', '热水', '热水器', '冰箱', '电磁炉', '微波炉', '吹风机',
            '晾衣架', '厨具', '设施', '设备',
        ];
    }

    public static function isDeviceQuery(string $message): bool {
        $msg = trim($message);
        if ($msg === '') {
            return false;
        }
        if (preg_match('/(什么设施|有哪些设施|房间设施|有什么设备|带.{0,2}(洗衣机|空调|电视|冰箱))/u', $msg)) {
            return true;
        }
        if (preg_match('/(有|有没有).{0,6}(洗衣机|空调|电视|冰箱|热水|微波炉|电磁炉|吹风机)/u', $msg)) {
            return true;
        }
        foreach (self::deviceKeywords() as $kw) {
            if ($kw !== '' && mb_strpos($msg, $kw) !== false) {
                return true;
            }
        }
        if (preg_match('/(怎么用|如何使用|怎么开|如何使用).{0,8}(空调|电视|洗衣机|冰箱|热水|微波炉|电磁炉|吹风机)/u', $msg)) {
            return true;
        }
        return false;
    }

    /** @return string[] */
    public static function matchedDeviceKeywords(string $message): array {
        $matched = [];
        foreach (self::deviceKeywords() as $kw) {
            if ($kw === '设施' || $kw === '设备') {
                continue;
            }
            if (mb_strpos($message, $kw) !== false) {
                $matched[] = $kw;
            }
        }
        return array_values(array_unique($matched));
    }

    /**
     * 判断是否为订单号（避免把 8 字中文 FAQ 误判为订单号）
     */
    public static function looksLikeOrderNo(string $message): bool {
        $m = trim($message);
        if ($m === '') {
            return false;
        }
        if (preg_match('/^\d{10,30}$/', $m)) {
            return true;
        }
        if (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9\-_#\/\.]{8,30}$/u', $m)) {
            return false;
        }
        $digitCount = preg_match_all('/\d/u', $m);
        $cjkCount = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $m);
        if ($cjkCount >= 4 && $digitCount === 0) {
            return false;
        }
        if ($digitCount >= 10) {
            return true;
        }
        if ($digitCount >= 8 && $cjkCount <= 2) {
            return true;
        }
        return false;
    }

    public static function isHandoffMessage(string $message, PDO $db): bool {
        return HandoffTriggers::matchesMessage($db, $message);
    }

    /** 云房卡内办理：WiFi/门锁密码、在线交押金、公安刷脸核验（客服不直报密码） */
    public static function yunfangkaCredentialReply(): string {
        return 'WiFi密码、门锁密码、在线交押金及公安刷脸核验，请在云房卡中查看。请点击聊天窗口「订单查询」，或发送 order_query:您的订单号，查询成功后点击云房卡即可。';
    }

    /**
     * 是否应引导至云房卡（非故障/纠纷类）
     */
    public static function isYunfangkaCredentialQuery(string $message): bool {
        $msg = trim($message);
        if ($msg === '') {
            return false;
        }

        foreach (['密码错误', '打不开', '开不了', '进不去', '进不了', '连不上', '上不了网', '断网', '没网络', '失效', '不对', '没反应', '押金不退', '扣押金', '乱扣费', '押金纠纷'] as $fault) {
            if (mb_strpos($msg, $fault) !== false) {
                return false;
            }
        }

        if (preg_match('/(交|付|缴).{0,4}押金/u', $msg) || preg_match('/押金.{0,6}(怎么交|在哪|如何|多少)/u', $msg)) {
            return true;
        }

        foreach (['刷脸', '公安', '核验', '实名认证', '实名登记', '人脸核验', '人脸验证', '身份核验'] as $kw) {
            if (mb_strpos($msg, $kw) !== false) {
                return true;
            }
        }

        foreach (['wifi', 'WiFi', '无线', '无线网', '网络', '网密码', '上网'] as $kw) {
            if (mb_strpos($msg, $kw) !== false) {
                return true;
            }
        }

        foreach (['门禁', '门锁', '密码锁', '门锁密码', '进门密码', '单元门', '大门密码', '钥匙密码'] as $kw) {
            if (mb_strpos($msg, $kw) !== false) {
                return true;
            }
        }

        if (preg_match('/(WiFi|wifi|无线).{0,4}密码/u', $msg) || preg_match('/密码.{0,4}(WiFi|wifi|无线|门锁|门禁)/u', $msg)) {
            return true;
        }

        return false;
    }
}

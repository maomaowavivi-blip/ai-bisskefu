-- ============================================================
-- v2.0 数据库迁移：API密钥 / 向量语义检索 / 人工接管
-- ============================================================

-- 1. API 密钥表（外部集成用）
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '密钥名称/备注',
    `api_key`     VARCHAR(64)  NOT NULL COMMENT '生成的API密钥',
    `enabled`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0禁用 1启用',
    `last_used_at` DATETIME    DEFAULT NULL COMMENT '最后使用时间',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_api_key` (`api_key`),
    INDEX `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='外部API密钥';

-- 2. 人工接管表（AI无法回答时自动创建，管理员接管）
CREATE TABLE IF NOT EXISTS `human_handoffs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `session_id`  VARCHAR(64)  NOT NULL COMMENT '客户会话ID',
    `status`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0待处理 1已接管 2已结束',
    `reason`      VARCHAR(500) DEFAULT '' COMMENT '触发原因（AI诊断信息）',
    `taken_by`    INT UNSIGNED DEFAULT NULL COMMENT '接管的管理员ID',
    `taken_at`    DATETIME     DEFAULT NULL COMMENT '接管时间',
    `ended_at`    DATETIME     DEFAULT NULL COMMENT '结束时间',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_session` (`session_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人工接管记录';

-- 3. 人工接管消息表（管理员与客户在接管期间的对话）
CREATE TABLE IF NOT EXISTS `handoff_messages` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `handoff_id`  INT UNSIGNED NOT NULL COMMENT '关联human_handoffs.id',
    `role`        VARCHAR(20)  NOT NULL COMMENT 'admin / user / system',
    `content`     TEXT         NOT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_handoff` (`handoff_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='接管对话消息';

-- 4. kb_entries 新增向量字段
ALTER TABLE `kb_entries`
    ADD COLUMN IF NOT EXISTS `embedding_vector` LONGTEXT DEFAULT NULL COMMENT '向量嵌入(JSON数组)' AFTER `similar_questions`,
    ADD COLUMN IF NOT EXISTS `embedding_updated_at` DATETIME DEFAULT NULL COMMENT '向量更新时间' AFTER `embedding_vector`;

-- 5. chat_logs 新增 channel 字段
ALTER TABLE `chat_logs`
    ADD COLUMN IF NOT EXISTS `channel` VARCHAR(20) NOT NULL DEFAULT 'web' COMMENT '消息来源: web/api' AFTER `session_id`;

-- 6. 转人工触发词表（支持按优先级管理）
-- 完整默认词库见 api/HandoffTriggers.php::defaultSeed()
-- 部署后执行：php scripts/sync_handoff_triggers.php  或后台「补全系统默认词库」
CREATE TABLE IF NOT EXISTS `handoff_triggers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `keyword` VARCHAR(100) NOT NULL COMMENT '触发词',
  `priority` TINYINT NOT NULL DEFAULT 0 COMMENT '0=P0紧急 1=P1高 2=P2中 3=P3常规 4=兜底',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_keyword` (`keyword`),
  INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='转人工触发词';

-- 初始触发词（按优先级）
INSERT IGNORE INTO `handoff_triggers` (`keyword`, `priority`) VALUES
-- P0 紧急
('密码错误', 0), ('密码不对', 0), ('打不开门', 0), ('进不去', 0),
('开不了门', 0), ('没收到密码', 0), ('没有密码', 0), ('门锁故障', 0),
('门锁没反应', 0), ('红灯', 0), ('进不了门', 0), ('漏水', 0),
('漏气', 0), ('煤气味', 0), ('着火', 0), ('火灾', 0),
('触电', 0), ('漏电', 0), ('停电', 0), ('跳闸', 0),
('受伤', 0), ('被锁', 0), ('找不到位置', 0), ('找不到门', 0),
('针孔摄像头', 0), ('偷拍', 0), ('陌生人', 0),
-- P1 高优先级
('没热水', 1), ('热水器坏了', 1), ('忽冷忽热', 1),
('空调不制冷', 1), ('空调不制热', 1), ('空调坏了', 1),
('WiFi连不上', 1), ('断网', 1), ('没网络', 1),
('马桶堵了', 1), ('下水堵了', 1), ('异味', 1),
('床单脏', 1), ('没打扫', 1), ('蟑螂', 1), ('虫子', 1),
('投诉', 1), ('退款', 1), ('退钱', 1), ('赔偿', 1), ('差评', 1), ('不符', 1),
-- P2 中优先级
('提前入住', 2), ('早入住', 2), ('延迟退房', 2), ('晚退房', 2),
('续住', 2), ('取消订单', 2), ('改期', 2),
('遥控器', 2), ('怎么用', 2), ('找不到', 2),
('吹风机', 2), ('毛巾不够', 2), ('加被子', 2), ('加枕头', 2),
('卫生纸没了', 2), ('加床', 2),
-- P3 常规
('落东西', 3), ('忘带', 3), ('充电器', 3),
('发票', 3), ('押金', 3), ('扣费', 3),
('停车位', 3), ('带宠物', 3), ('多一个人', 3), ('太吵', 3), ('装修噪音', 3),
-- 兜底
('转人工', 4), ('找人工', 4), ('人工客服', 4), ('有人吗', 4), ('人呢', 4);

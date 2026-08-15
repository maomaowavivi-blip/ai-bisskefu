-- v3.15 beta security hardening
-- 执行前请先备份 aibisskefu_com 与 sujia_ai_sidecar_dev。
-- 本迁移不保存任何凭据；执行后 api/chat.php 不再运行时建表。

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `key_str` varchar(64) NOT NULL,
  `count` int NOT NULL DEFAULT 1,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`key_str`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='接口与登录限流';

CREATE TABLE IF NOT EXISTS `order_verify_sessions` (
  `session_id` varchar(64) NOT NULL,
  `order_no` varchar(512) NOT NULL DEFAULT '',
  `phone` varchar(20) NOT NULL DEFAULT '',
  `phone_hash` varchar(64) NOT NULL DEFAULT '',
  `phone_mask` varchar(13) NOT NULL DEFAULT '',
  `step` tinyint NOT NULL DEFAULT 0 COMMENT '0=none 1=wait_order 2=wait_phone 3=wait_code 4=verified',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单验证会话';

CREATE TABLE IF NOT EXISTS `room_query_sessions` (
  `session_id` varchar(64) NOT NULL,
  `room_id` varchar(64) NOT NULL DEFAULT '',
  `question` text NOT NULL,
  `step` tinyint NOT NULL DEFAULT 0 COMMENT '0=idle 1=wait_order 2=wait_room_pick 3=bound',
  `order_no` varchar(64) NOT NULL DEFAULT '' COMMENT '关联订单号',
  `sidecar_room_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Sidecar ai_room_profile.id',
  `room_candidates` mediumtext NULL COMMENT 'step=2 候选 JSON',
  `bound_at` datetime NULL COMMENT 'step=3 绑定时间',
  `expires_at` datetime NULL COMMENT '会话过期',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='房间查询会话';

-- 现有 v3.14 生产表可能是运行时创建的 64 位订单号列，统一到当前使用上限。
ALTER TABLE `order_verify_sessions` MODIFY COLUMN `order_no` varchar(512) NOT NULL DEFAULT '';

-- v3.11 以后不再提供人工接管，先完成备份再删除历史状态表。
DROP TABLE IF EXISTS `human_handoffs`;
DROP TABLE IF EXISTS `handoff_messages`;
DROP TABLE IF EXISTS `handoff_triggers`;

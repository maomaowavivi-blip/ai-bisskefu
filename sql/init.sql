-- ============================================================
-- ai-bisskefu 数据库初始化脚本
-- 新客户部署时用这个文件一次性建库
-- ============================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------
-- 管理员表（复用原 OC 系统的 users 表结构，只保留管理员）
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '管理员用户名',
  `password` varchar(255) NOT NULL COMMENT '密码hash',
  `role` tinyint(4) NOT NULL DEFAULT 3 COMMENT '3=管理员',
  `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=正常 0=封禁',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

-- -----------------------------------------------------------
-- 企业吉祥物人设配置（替代原 OC 的 advanced_config）
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `persona_config` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '吉祥物名称',
  `avatar_url` varchar(500) DEFAULT '' COMMENT '头像',
  `greeting` varchar(200) DEFAULT '你好呀~' COMMENT '默认欢迎语',
  `description` varchar(200) DEFAULT '' COMMENT '一句话描述',
  `brand_story` text DEFAULT NULL COMMENT '品牌故事（原w-world-hook）',
  `personality` text DEFAULT NULL COMMENT '性格描述（原f3）',
  `speak_style` text DEFAULT NULL COMMENT '说话风格（原f4）',
  `service_rules` text DEFAULT NULL COMMENT '服务规范/禁用语（原f2+f5）',
  `emotion_strategy` text DEFAULT NULL COMMENT '情绪应对策略（原sc_*）',
  `principles` text DEFAULT NULL COMMENT '处事原则（原f5-principles）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企业吉祥物人设配置';

-- -----------------------------------------------------------
-- 知识库分类
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kb_categories` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '分类名称',
  `parent_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父分类ID',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='知识库分类';

-- -----------------------------------------------------------
-- 知识库条目
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kb_entries` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
  `question` varchar(500) NOT NULL COMMENT '标准问题',
  `answer` text NOT NULL COMMENT '标准答案',
  `keywords` varchar(500) DEFAULT '' COMMENT '关键词（逗号分隔）',
  `similar_questions` text DEFAULT NULL COMMENT '相似问法（JSON数组）',
  `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用',
  `hit_count` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '命中次数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`),
  FULLTEXT KEY `ft_search` (`question`, `answer`, `keywords`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='知识库条目';

-- -----------------------------------------------------------
-- SMS 验证码日志（频率限制 + 审计）
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_verify_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone_hash` varchar(64) NOT NULL COMMENT '手机号SHA256',
  `phone_mask` varchar(20) NOT NULL COMMENT '脱敏显示 138****5678',
  `otp_hash` varchar(64) NOT NULL COMMENT '验证码SHA256',
  `session_id` varchar(64) NOT NULL COMMENT '会话ID',
  `source_ip` varchar(45) DEFAULT NULL COMMENT '请求IP',
  `status` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=已发送 1=已验证 2=已过期',
  `expires_at` datetime NOT NULL COMMENT '5分钟有效期',
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phone_hash` (`phone_hash`),
  KEY `idx_source_ip` (`source_ip`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短信验证日志';

-- -----------------------------------------------------------
-- 对话记录（仅做历史查看）
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL COMMENT '会话ID',
  `channel` varchar(20) NOT NULL DEFAULT 'web' COMMENT '渠道 web/wechat_mp/wechat_msg',
  `role` varchar(10) NOT NULL COMMENT 'user/assistant',
  `content` text NOT NULL COMMENT '消息内容',
  `has_verified` tinyint(4) NOT NULL DEFAULT 0 COMMENT '本轮是否已验证',
  `visitor_hash` varchar(64) NOT NULL DEFAULT '' COMMENT '设备指纹，未验证时的用户标识',
  `source_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '来源IP',
  `tokens` int(11) NOT NULL DEFAULT 0 COMMENT 'token数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_visitor` (`visitor_hash`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='对话记录';

-- -----------------------------------------------------------
-- 平台配置（AI Key 等运行时配置）
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `platform_config` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL COMMENT '配置键',
  `value` text NOT NULL COMMENT '配置值',
  `remark` varchar(200) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台配置表';

-- -----------------------------------------------------------
-- 外部 API 密钥（openapi.php）
-- -----------------------------------------------------------
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

-- -----------------------------------------------------------
-- 初始数据
-- -----------------------------------------------------------

-- 默认管理员（密码: admin123）
INSERT INTO `users` (`username`, `password`, `role`, `status`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3, 1);

-- 默认吉祥物人设
INSERT INTO `persona_config` (`name`, `greeting`, `brand_story`, `personality`, `speak_style`, `service_rules`, `principles`) VALUES
('小智', '您好呀~ 我是小智，很高兴为您服务！😊', '我们是一家用心服务客户的企业', '热情、耐心、专业', '语气亲切自然，用“您”称呼客户，适当使用语气词', '不泄露客户隐私、不猜测订单信息、不确定的事不说', '客户第一、诚信为本、专业高效');

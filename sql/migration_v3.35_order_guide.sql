-- v3.35 订单查询分时段回复 + 入住指引送达：多房选房会话表（蓝图 §5.4）
--
-- 用法（AGENTS.md §2 同步铁律）：先 scp 代码 → 再在本地与生产 DB 各执行本文件 → reload。
-- 建表前的窗口内 OrderGuidePick::hasPending/tryHandle 内部全 catch → fail-open，客服链路不受影响（qa C6）。
-- 回滚：表留着无害，可不删。
--
-- 时钟口径：expires_at 写入用 SQL `NOW() + INTERVAL 2 HOUR`、读取用 `expires_at > NOW()`。
-- 生产 PHP=UTC / MySQL=CST 差 8 小时（AGENTS.md §15），过期判断禁止 PHP time()/strtotime() 参与。

CREATE TABLE IF NOT EXISTS `order_guide_sessions` (
  `session_id`      varchar(64) NOT NULL,
  `order_no`        varchar(64) NOT NULL DEFAULT '',
  `stage`           tinyint NOT NULL DEFAULT 0 COMMENT '0=idle 1=wait_room_pick 2=bound',
  `attempts`        tinyint NOT NULL DEFAULT 0 COMMENT '连续不匹配次数',
  `room_candidates` mediumtext NULL COMMENT '候选 JSON [{baoyu_room_no,room_code,display_name}]',
  `expires_at`      datetime NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='v3.35 订单指引多房选房会话';

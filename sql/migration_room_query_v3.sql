-- room_query_sessions v3: 房间查询状态机（RoomQueryFlow）
ALTER TABLE room_query_sessions
  ADD COLUMN `sidecar_room_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sidecar ai_room_profile.id' AFTER `order_no`;

ALTER TABLE room_query_sessions
  ADD COLUMN `room_candidates` MEDIUMTEXT NULL COMMENT 'step=2 候选 JSON' AFTER `sidecar_room_id`;

ALTER TABLE room_query_sessions
  ADD COLUMN `bound_at` DATETIME NULL COMMENT 'step=3 绑定时间' AFTER `room_candidates`;

ALTER TABLE room_query_sessions
  ADD COLUMN `expires_at` DATETIME NULL COMMENT '会话过期' AFTER `bound_at`;

ALTER TABLE room_query_sessions
  MODIFY COLUMN `step` TINYINT NOT NULL DEFAULT 0 COMMENT '0=idle 1=wait_order 2=wait_room_pick 3=bound';

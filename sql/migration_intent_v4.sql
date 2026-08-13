-- sql/migration_intent_v4.sql
-- v3.3 PR1 — Intent 架构审计字段
--
-- 前提：chat_logs 表必须已存在（本 migration 仅 ADD COLUMN / ADD KEY）
-- 异常情况：若表不存在，请先跑 sql/init.sql
--
-- 字段用途：
--   intent        — Intent 分类结果（ROOM_QUERY / KNOWLEDGE 等），用于审计/打点
--   confidence    — 分类置信度 0.00-1.00，用于调试分类质量
--   slots         — 抽取的实体（JSON：order_no 等），用于多轮引用
--   workflow      — 执行的 Workflow（YunfangkaCredentialWorkflow 等），用于性能分析
--   rendered_type — 渲染类型（text / card / list / file），前端按类型处理
--
-- 修正 4：所有字段 NOT NULL DEFAULT '' / 0.00，避免 strict mode 报错
--          不需要修改任何已有的 INSERT chat_logs 语句（默认值自动填充）
-- 修正 26：加 KEY idx_intent + idx_intent_workflow 复合索引（分析用）

ALTER TABLE chat_logs
  ADD COLUMN `intent`        VARCHAR(40)  NOT NULL DEFAULT ''         COMMENT 'Intent 分类结果' AFTER `channel`,
  ADD COLUMN `confidence`    DECIMAL(3,2) NOT NULL DEFAULT 0.00       COMMENT '分类置信度 0.00-1.00' AFTER `intent`,
  ADD COLUMN `slots`         JSON         NULL                         COMMENT '抽取的实体（JSON）' AFTER `confidence`,
  ADD COLUMN `workflow`      VARCHAR(40)  NOT NULL DEFAULT ''         COMMENT '执行的 Workflow' AFTER `slots`,
  ADD COLUMN `rendered_type` VARCHAR(20)  NOT NULL DEFAULT 'text'     COMMENT 'text/card/list/file' AFTER `workflow`,
  ADD KEY `idx_intent` (`intent`),
  ADD KEY `idx_intent_workflow` (`intent`, `workflow`),
  ADD KEY `idx_created_intent` (`created_at`, `intent`);
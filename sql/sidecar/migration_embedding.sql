-- sujia_ai_sidecar_dev 向量字段（在 sidecar 库执行）
ALTER TABLE ai_knowledge_chunk
  ADD COLUMN IF NOT EXISTS embedding_vector JSON NULL COMMENT '向量JSON数组' AFTER chunk_text,
  ADD COLUMN IF NOT EXISTS embedding_model VARCHAR(50) NULL AFTER embedding_vector,
  ADD COLUMN IF NOT EXISTS embedding_updated_at DATETIME NULL AFTER embedding_model;

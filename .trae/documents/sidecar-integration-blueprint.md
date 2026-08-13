# Sidecar 房间知识接入 — 修改方案蓝图 v1.0

> **状态**：✅ 已实施（2026-05-26）  
> **灵魂铁律**：`.cursor/rules/dev-workflow-soul.mdc`

## 已完成

- Phase 0：MAMP ↔ 工作区基线对齐
- Phase 1：`getSidecarDB()` + `.env` SIDECAR_DB_*
- Phase 2：`ChunkBuilder` → 4241 知识块
- Phase 3：`Vectorizer` + `embedding_vector` 字段（待配置 API Key 后批量向量化）
- Phase 4：`RoomQueryService` 替代远程 `query_room`
- Phase 5：`admin/settings.html` Sidecar 运维卡片 + PRD 更新

## 测试记录

```bash
curl http://localhost:8888/aibisskefu/api/sidecar.php?action=stats
curl "http://localhost:8888/aibisskefu/api/sidecar.php?action=query_room&room_id=1021&question=地址在哪里&is_verified=1"
```

- 房间 1021 地址查询 ✅
- WiFi 未验证拦截 ✅
- 停车指引 + 图片 ✅

## 待办

- 配置 MiniMax Embedding Key 后执行「批量向量化」
- 在 ai-sujia 补录 `ai_room_device_guide` 设备说明

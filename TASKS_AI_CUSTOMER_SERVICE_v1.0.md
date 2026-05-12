# 商用AI客服 - 实施任务清单 v1.0

## Phase 1：MVP（核心改造）

### Task 1：数据库表创建
- [ ] 创建 `kb_categories` 表（知识库分类）
- [ ] 创建 `kb_entries` 表（知识条目）
- [ ] 创建 `kb_documents` 表（文档知识）
- [ ] 创建 `enterprise_api_config` 表（API配置）

### Task 2：PromptEngine.php 改造
- [ ] 新增 `_buildCorporateIdentityLayer()` 企业人设构建
- [ ] 新增 `_buildServiceRuleLayer()` 服务规范层
- [ ] 重构 `_buildKnowledgeLayer()` 支持知识库检索注入
- [ ] 新增 `_buildOrderAbilityLayer()` 订单能力描述
- [ ] 新增 `buildEnterpriseSystemPrompt()` 企业模式入口

### Task 3：知识库 API
- [ ] 新建 `api/knowledge.php` → 分类 CRUD
- [ ] 新建 `api/knowledge.php` → 条目 CRUD
- [ ] 新建 `api/knowledge.php` → 检索接口（关键词 + AI 语义）
- [ ] 新建 `api/knowledge.php` → 批量导入

### Task 4：聊天接口新增
- [ ] `chat.php` 新增 `enterprise_chat` action
- [ ] 企业人设 + 知识库 + 记忆的完整拼装链路
- [ ] 支持切换为原 OC 模式 / 企业客服模式

### Task 5：企业管理后台
- [ ] 新建 `enterprise-admin.html` 页面框架
- [ ] 吉祥物人设配置页（复用 oc-advanced.html 七步逻辑）
- [ ] 知识库管理页（分类 + 条目 CRUD）
- [ ] API配置页

### Task 6：客服聊天组件
- [ ] 新建 `customer-chat.html` 客服聊天窗
- [ ] iframe 嵌入支持
- [ ] 聊天记录本地缓存
- [ ] 移动端适配

### Task 7：部署测试
- [ ] 宝塔新站配置
- [ ] 数据库导入
- [ ] 功能测试
- [ ] 知识库检索测试
- [ ] 人格化测试

## Phase 2：增强功能

- [ ] 订单 API 对接 + 测试
- [ ] Excel 批量导入知识库
- [ ] 客户画像页面（基于记忆系统）
- [ ] 对话记录查看
- [ ] 基础数据统计

## Phase 3：商用化

- [ ] SaaS 套餐管理
- [ ] 多渠道接入（微信/企微）
- [ ] 转人工机制
- [ ] AI 自动学习知识库

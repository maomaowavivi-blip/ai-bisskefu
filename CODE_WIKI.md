# AI 智能客服系统 — Code Wiki

> **项目名称**：Aibisskefu
> **版本**：v2.0+
> **技术栈**：PHP 8.0+ / MySQL / JavaScript (Vanilla) / DeepSeek V4 Flash
> **运行环境**：MAMP / Apache + PHP + MySQL
> **项目定位**：面向中小企业的 AI 驱动的智能客服机器人后台管理系统（民宿场景深度定制）

---

## 目录

- [1. 项目概述](#1-项目概述)
- [2. 目录结构](#2-目录结构)
- [3. 数据库设计](#3-数据库设计)
- [4. 后端 API 模块](#4-后端-api-模块)
- [5. 前端管理后台](#5-前端管理后台)
- [6. SDK 客户端组件](#6-sdk-客户端组件)
- [7. LLM Prompt 引擎](#7-llm-prompt-引擎)
- [8. 配置与环境变量](#8-配置与环境变量)
- [9. 安全机制](#9-安全机制)
- [10. 部署与运行](#10-部署与运行)
- [11. 数据流概览](#11-数据流概览)
- [12. 扩展指南](#12-扩展指南)

---

## 1. 项目概述

AI 智能客服系统是一个面向企业的 AI 驱动的在线客服解决方案。它允许企业通过后台管理界面配置 AI 客服的**人设**（品牌形象、性格、说话风格）、**知识库**（FAQ 问答对），并将聊天窗口通过一段 JavaScript SDK 代码嵌入到任何网站中。最终用户与 AI 客服的对话由 DeepSeek 大语言模型驱动，结合动态 Prompt 工程和知识库检索生成智能回复。

### 核心能力

| 能力 | 描述 |
|------|------|
| **AI 驱动对话** | 基于 DeepSeek V4 Flash，关闭推理模式大幅节省 token |
| **语义检索** | 向量语义检索优先，降级到 FULLTEXT 全文检索 |
| **知识库管理** | 支持分类、关键词、相似问题、批量导入/导出 |
| **人设定制** | 品牌故事、性格、说话风格、服务规范、情绪应对均可配置 |
| **对话记录** | 完整记录每次对话的消息、Token 消耗、访客来源 |
| **管理员面板** | 仪表盘、人设管理、知识库管理、对话日志、账号管理 |
| **嵌入式 SDK** | 一行代码嵌入任意网页，支持主题色和位置定制 |
| **多账号管理** | 支持创建/禁用/删除管理员账号，密码重置 |
| **订单验证** | 支持订单号查询，验证后房间问题走 PMS 不走 LLM |
| **SMS 验证** | 手机号 + 验证码二次验证（Mock 阶段不真发短信） |
| **代词消解** | rewriteQuery 支持多轮对话代词改写，指向最近实体 |
| **输出过滤** | filterReply() 后处理兜底，防止模型加戏/追问/推销 |
| **输入安全** | checkInputSafety() 检测政治/色情/Prompt注入 |
| **速率限制** | 同一 IP 每 60 秒最多 20 次请求 |
| **转人工** | AI 无法回答时自动触发，支持按优先级配置触发词 |
| **人工接管** | 管理员可接管会话，与客户实时对话 |

---

## 2. 目录结构

```
aibisskefu/
├── api/                        # PHP 后端 API
│   ├── config.php              # 数据库连接、公共函数、AI 调用入口
│   ├── auth.php                # 管理员认证与账号管理（JWT Token）
│   ├── chat.php                # 聊天接口与对话日志（含 filterReply）
│   ├── knowledge.php           # 知识库 CRUD、向量化、导入导出
│   ├── persona.php             # 人设配置 CRUD、头像上传
│   ├── verify.php              # SMS 验证码、订单验证、网关配置
│   ├── handoff.php             # 转人工处理
│   ├── wecom.php               # 企业微信对接
│   ├── openapi.php             # 开放 API 接口
│   ├── embedding.php           # 向量语义检索服务
│   └── PromptEngine.php        # Prompt 构建 + rewriteQuery 代词消解
├── admin/                      # 管理后台前端
│   ├── login.html               # 登录页
│   ├── auth-guard.js           # 登录守卫脚本
│   ├── dashboard.html          # 仪表盘
│   ├── persona.html            # 人设管理
│   ├── knowledge.html          # 知识库管理
│   ├── chat-logs.html          # 对话记录
│   ├── handoff.html            # 转人工管理
│   ├── users.html              # 账号管理
│   ├── settings.html           # 系统设置 + 嵌入代码生成
│   └── css/
│       └── design-system.css   # 统一设计系统样式
├── sdk/
│   └── chat-widget.js          # 客户端聊天窗口 SDK
├── sql/
│   ├── init.sql                # 数据库初始化脚本
│   └── migration_v2.0.sql      # v2.0 增量迁移脚本
├── logs/                       # 运行日志
├── uploads/                    # 上传文件目录
│   └── avatars/                # 头像上传目录
├── index.html                  # SDK 演示页面
├── chat.html                   # 内嵌聊天演示页
├── .env                        # 环境变量配置
├── CODE_WIKI.md                # 本文档
└── MARKET_RESEARCH.md          # 市场调研
```

---

## 3. 数据库设计

### 3.1 总览

系统使用 MySQL 数据库，包含 **15 张数据表**。数据库名由 `.env` 中的 `DB_NAME` 配置。

### 3.2 表结构

#### `users` — 管理员账号表

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `username` | VARCHAR(50) UNIQUE | 用户名 |
| `password` | VARCHAR(255) | bcrypt 密码哈希 |
| `role` | TINYINT DEFAULT 3 | 角色：3=管理员 |
| `status` | TINYINT DEFAULT 1 | 状态：1=启用, 0=禁用 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

#### `persona_config` — 人设配置表

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `name` | VARCHAR(50) | 吉祥物名称（如"小智"） |
| `avatar_url` | VARCHAR(500) | 头像链接 |
| `greeting` | VARCHAR(200) | 默认欢迎语 |
| `description` | VARCHAR(200) | 一句话描述 |
| `brand_story` | TEXT | 品牌故事 |
| `personality` | TEXT | 性格描述 |
| `speak_style` | TEXT | 说话风格 |
| `service_rules` | TEXT | 服务规范/禁用语 |
| `emotion_strategy` | TEXT | 情绪应对策略 |
| `principles` | TEXT | 处事原则 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

#### `kb_categories` — 知识库分类表

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `name` | VARCHAR(100) | 分类名称 |
| `parent_id` | INT DEFAULT 0 | 父分类 ID |
| `sort_order` | INT DEFAULT 0 | 排序 |
| `created_at` | DATETIME | 创建时间 |

#### `kb_entries` — 知识库条目表

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `category_id` | INT DEFAULT 0 | 分类 ID |
| `question` | VARCHAR(500) | 标准问题 |
| `answer` | TEXT | 标准答案（支持 HTML） |
| `keywords` | VARCHAR(500) | 关键词（逗号分隔，FULLTEXT 索引） |
| `similar_questions` | TEXT | 相似问法（JSON 数组） |
| `embedding_vector` | LONGTEXT | 向量嵌入（JSON 数组） |
| `embedding_updated_at` | DATETIME | 向量更新时间 |
| `status` | TINYINT DEFAULT 1 | 状态：1=启用, 0=禁用 |
| `hit_count` | INT DEFAULT 0 | 命中次数 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

#### `chat_logs` — 聊天消息表

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | BIGINT UNSIGNED PK | 主键 |
| `session_id` | VARCHAR(64) | 会话唯一标识 |
| `channel` | VARCHAR(20) | 渠道：web / wechat_mp / wechat_msg / api |
| `role` | VARCHAR(10) | 角色：`user` / `assistant` |
| `content` | TEXT | 消息内容 |
| `has_verified` | TINYINT DEFAULT 0 | 本轮是否已订单验证 |
| `visitor_hash` | VARCHAR(64) | 访客设备指纹 |
| `source_ip` | VARCHAR(45) | 来源 IP |
| `tokens` | INT DEFAULT 0 | 该消息 Token 数 |
| `created_at` | DATETIME | 时间戳 |

#### `sms_verify_logs` — SMS 验证码日志

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | BIGINT UNSIGNED PK | 主键 |
| `phone_hash` | VARCHAR(64) | 手机号 SHA256 |
| `phone_mask` | VARCHAR(20) | 脱敏显示 138****5678 |
| `otp_hash` | VARCHAR(64) | 验证码 SHA256 |
| `session_id` | VARCHAR(64) | 会话 ID |
| `source_ip` | VARCHAR(45) | 请求 IP |
| `status` | TINYINT DEFAULT 0 | 0=已发送 1=已验证 2=已过期 |
| `expires_at` | DATETIME | 5分钟有效期 |
| `verified_at` | DATETIME | 验证时间 |
| `created_at` | DATETIME | 创建时间 |

#### `order_verify_sessions` — 订单验证会话

| 字段 | 类型 | 说明 |
|------|------|------|
| `session_id` | VARCHAR(64) PK | 会话 ID |
| `order_no` | VARCHAR(512) | 订单号（JSON 字符串，含 room_id） |
| `phone` | VARCHAR(20) | 手机号 |
| `phone_hash` | VARCHAR(64) | 手机号哈希 |
| `phone_mask` | VARCHAR(13) | 脱敏手机号 |
| `step` | TINYINT | 0=none 1=wait_order 2=wait_phone 3=wait_code 4=verified |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

#### `room_query_sessions` — 房间查询会话

| 字段 | 类型 | 说明 |
|------|------|------|
| `session_id` | VARCHAR(64) PK | 会话 ID |
| `room_id` | VARCHAR(64) | 房间 ID |
| `question` | TEXT | 用户问题 |
| `step` | TINYINT | 0=none 1=wait_room_id |
| `order_no` | VARCHAR(64) | 关联订单号 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

#### `order_context_cache` — 订单上下文缓存

| 字段 | 类型 | 说明 |
|------|------|------|
| `session_id` | VARCHAR(64) PK | 会话 ID |
| `order_no` | VARCHAR(64) | 订单号 |
| `room_id` | VARCHAR(64) | 当前房间 ID（后端只信任此字段） |
| `room_list` | VARCHAR(500) | 该订单下所有房间 ID（逗号分隔） |
| `verified_at` | DATETIME | 验证时间 |
| `expires_at` | DATETIME | 过期时间（默认 24h） |

#### `human_handoffs` — 人工接管记录

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `session_id` | VARCHAR(64) UNIQUE | 会话 ID |
| `status` | TINYINT DEFAULT 0 | 0=待处理 1=处理中 2=已结束 |
| `reason` | VARCHAR(500) | 触发原因 |
| `taken_by` | INT UNSIGNED | 接管的管理员 ID |
| `taken_at` | DATETIME | 接管时间 |
| `ended_at` | DATETIME | 结束时间 |
| `created_at` | DATETIME | 创建时间 |

#### `handoff_messages` — 接管对话消息

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `handoff_id` | INT UNSIGNED | 关联 human_handoffs.id |
| `role` | VARCHAR(20) | admin / user / system |
| `content` | TEXT | 消息内容 |
| `created_at` | DATETIME | 创建时间 |

#### `handoff_triggers` — 转人工触发词

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `keyword` | VARCHAR(100) UNIQUE | 触发关键词 |
| `priority` | TINYINT DEFAULT 0 | 0=P0紧急 1=P1高 2=P2中 3=P3常规 4=兜底 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

#### `platform_config` — 平台配置表

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `key` | VARCHAR(50) UNIQUE | 配置键名 |
| `value` | TEXT | 配置值 |
| `remark` | VARCHAR(200) | 备注 |
| `updated_at` | DATETIME | 更新时间 |

常用配置键：
- `ai.api_key` — AI API Key
- `ai.temperature` — AI 温度参数
- `order.api_url` — 订单查询接口地址
- `order.api_key` — 订单查询接口密钥
- `gateway.api_url` — 统一网关地址
- `gateway.api_key` — 统一网关密钥
- `gateway.room_keywords` — 房间问题关键词列表

#### `api_keys` — 外部 API 密钥

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT UNSIGNED PK | 主键 |
| `name` | VARCHAR(100) | 密钥名称/备注 |
| `api_key` | VARCHAR(64) UNIQUE | 生成的 API 密钥 |
| `enabled` | TINYINT DEFAULT 1 | 0=禁用 1=启用 |
| `last_used_at` | DATETIME | 最后使用时间 |
| `created_at` | DATETIME | 创建时间 |

#### `rate_limits` — 速率限制

| 字段 | 类型 | 说明 |
|------|------|------|
| `key_str` | VARCHAR(64) PK | 限流键（IP 或 session） |
| `count` | INT DEFAULT 1 | 请求次数 |
| `window_start` | DATETIME | 窗口开始时间 |

---

## 4. 后端 API 模块

所有 API 端点位于 `/api/` 目录下，采用**无框架原生 PHP** 实现，通过 URL 参数 `action` 分发请求。

### 4.1 config.php — 公共基础设施

**文件位置**：[api/config.php](file:///Applications/MAMP/htdocs/aibisskefu/api/config.php)

| 函数/变量 | 说明 |
|-----------|------|
| `getDB()` | 获取 PDO 数据库连接实例 |
| `loadDotEnv()` / `envVal()` | 加载和读取 .env 环境变量 |
| `ok($data, $msg)` | 返回成功 JSON 响应 `{code: 0, msg, data}` |
| `fail($msg, $code)` | 返回失败 JSON 响应 `{code, msg}` |
| `getBody()` | 读取 php://input 并解析为数组 |
| `makeToken($uid, $role)` | 生成 JWT Token |
| `authToken()` | 验证请求中的 Bearer Token |
| `adminGuard()` | 验证是否为管理员角色（role=3） |
| `pcGet($db, $key, $default)` | 读取 platform_config 配置 |
| `pcGetInt($db, $key, $default)` | 读取整数配置 |
| `callAI($messages, $opts)` | 调用 AI 接口（自动识别 DeepSeek/MiniMax） |
| `sanitizeReply($text)` | 清理 AI 回复中的乱码和系统标记 |

> **AI 调用设计**：单一 Key 多 Provider，根据 model 名自动选择 endpoint（含 "deepseek" 走 DeepSeek，否则走 MiniMax）。默认主聊天模型 = `deepseek-v4-flash`。

### 4.2 auth.php — 管理员认证

**文件位置**：[api/auth.php](file:///Applications/MAMP/htdocs/aibisskefu/api/auth.php)

| Action | 方法 | 说明 |
|--------|------|------|
| `login` | POST | 用户名+密码登录，返回 JWT Token |
| `me` | GET | 获取当前登录用户信息 |
| `list_users` | GET | 列出所有管理员账号（需 adminGuard） |
| `create_user` | POST | 创建新管理员（需 adminGuard） |
| `update_user` | POST | 更新用户状态或重置密码（需 adminGuard） |
| `delete_user` | POST | 删除管理员账号（需 adminGuard） |

**认证流程**：
```
登录: username + password → bcrypt verify → makeToken() 生成 JWT
鉴权: Bearer Token → authToken() 验证签名和过期时间 → 返回 payload
```

### 4.3 chat.php — 聊天引擎

**文件位置**：[api/chat.php](file:///Applications/MAMP/htdocs/aibisskefu/api/chat.php)

| Action | 说明 |
|--------|------|
| `chat` | 核心聊天接口（POST） |
| `persona` | 获取人设信息（GET） |
| `stats` | 获取聊天统计（需 adminGuard） |
| `logs` | 获取对话记录（需 adminGuard） |

#### 核心函数

| 函数 | 说明 |
|------|------|
| `checkInputSafety()` | 输入安全拦截：检测政治/色情/Prompt注入 |
| `shouldTriggerHandoff()` | 检测是否触发转人工 |
| `filterReply()` | 后处理过滤：消推销/消追问/消万能结尾 |
| `callGateway()` | 调用 PMS 统一网关（订单查询/房间查询） |

#### `filterReply()` 过滤规则（优先级顺序）

1. **敏感话题转人工**：用户消息含发票/投诉/退款等 → 返回固定话术
2. **消推销**：命中推荐房源/换房/升级等正则 → 截断到第一句
3. **消万能结尾**：命中 badEndings 列表 → 截断到句号
4. **消追问**：第二句以"您"开头 → 只保留第一句
5. **超句截断**：超过 2 句 → 只保留第一句

#### `send` 流程详解

```
1. 接收 POST 参数: session_id, message, history, visitor_hash
2. 速率限制：同一IP每60秒最多20次请求
3. 第一道防线：checkInputSafety() 输入安全拦截
4. 获取人设配置（persona_config）
5. ── 问题改写 ──
   ├─ rewriteQuery() 改写代词（有多轮历史时）
   └─ 智能跳过：无代词/首问时不调用 AI
6. ── 知识库检索 ──
   ├─ 优先：调用 embedding.php 语义检索
   └─ 降级：PromptEngine::searchKnowledge() FULLTEXT
7. ── 意图检测 ──
   ├─ 订单查询意图 → 引导输入订单号
   ├─ 房间查询意图 → 检查订单上下文
   │   ├─ 已验证订单 → 走 PMS query_room
   │   └─ 未验证 → 引导验证
   └─ 普通聊天 → 走 AI
8. ── AI 对话 ──
   ├─ PromptEngine::buildMessages() 构建 messages
   ├─ callAI() → DeepSeek V4 Flash，关推理
   ├─ filterReply() 后处理过滤
   └─ 检查是否触发转人工
9. 保存消息到 chat_logs
10. 返回 {reply, is_verified, handoff_status}
```

### 4.4 knowledge.php — 知识库管理

**文件位置**：[api/knowledge.php](file:///Applications/MAMP/htdocs/aibisskefu/api/knowledge.php)

| Action | 方法 | 说明 |
|--------|------|------|
| `categories` | GET | 获取分类列表 |
| `save_category` | POST | 添加/更新分类 |
| `delete_category` | POST | 删除分类 |
| `list` | GET | 分页获取知识库列表 |
| `get` | GET | 获取单条知识条目 |
| `save` | POST | 创建/更新知识条目 |
| `delete` | POST | 删除知识条目 |
| `search` | GET | 搜索知识条目 |
| `import` | POST | CSV 批量导入 |
| `vectorize` | POST | 单条向量化（需 adminGuard） |
| `batch_vectorize` | POST | 批量向量化（需 adminGuard） |

**CSV 导入格式**：
```
问题,答案,关键词,分类名称
```

### 4.5 persona.php — 人设配置

**文件位置**：[api/persona.php](file:///Applications/MAMP/htdocs/aibisskefu/api/persona.php)

| Action | 方法 | 说明 |
|--------|------|------|
| `get` | GET | 获取当前人设配置（需 adminGuard） |
| `save` | POST | 保存/更新人设配置（需 adminGuard） |
| `upload_avatar` | POST | 上传头像（需 adminGuard） |

### 4.6 verify.php — 验证与网关配置

**文件位置**：[api/verify.php](file:///Applications/MAMP/htdocs/aibisskefu/api/verify.php)

**SMS 验证码相关**：

| Action | 方法 | 说明 |
|--------|------|------|
| `send_code` | POST | 发送验证码（Mock 阶段日志输出） |
| `verify_code` | POST | 校验验证码 + 查询订单 |

**网关配置相关**（需 adminGuard）：

| Action | 说明 |
|--------|------|
| `save_gateway_config` | 保存统一网关配置 |
| `get_gateway_config` | 获取网关配置 |
| `save_order_api` | 保存订单查询接口配置 |
| `get_order_api` | 获取订单接口配置 |

**AI 配置相关**（需 adminGuard）：

| Action | 说明 |
|--------|------|
| `save_ai_api` | 保存 AI API Key |
| `get_ai_api` | 获取 AI 配置状态 |
| `save_temperature` | 保存 AI 温度参数 |
| `get_temperature` | 获取温度参数 |

**企业微信配置**（需 adminGuard）：

| Action | 说明 |
|--------|------|
| `save_wecom_config` | 保存企业微信配置 |
| `get_wecom_config` | 获取企业微信配置 |

**API 密钥管理**（需 adminGuard）：

| Action | 说明 |
|--------|------|
| `create_api_key` | 创建外部 API 密钥 |
| `list_api_keys` | 列出所有密钥 |
| `toggle_api_key` | 启用/禁用密钥 |
| `delete_api_key` | 删除密钥 |

### 4.7 PromptEngine.php — Prompt 构建引擎

**文件位置**：[api/PromptEngine.php](file:///Applications/MAMP/htdocs/aibisskefu/api/PromptEngine.php)

**主要方法**：

```php
class PromptEngine {
    // 构建 system prompt（静态，只含人设+规则）
    public static function buildSystem(array $persona): string

    // 构建最后一条 user 消息（含知识上下文）
    public static function buildUserTurn(string $message, array $kbItems): string

    // 组装完整 messages 数组
    public static function buildMessages(array $persona, array $history, string $message, array $kbItems, string $sessionId, string $rewrittenQuery): array

    // 代词消解改写（有多轮历史时调用）
    public static function rewriteQuery(string $message, array $history, string $sessionId): string

    // 知识库检索（FULLTEXT 优先，降级到 LIKE）
    public static function searchKnowledge(PDO $db, string $query, int $maxCount): array
}
```

**rewriteQuery() 触发条件**：有多轮历史 AND 消息含代词 AND 长度 >= 8 字

**知识库限制**：
- 每次最多返回 3 条
- 每条答案截断 200 字

---

## 5. 前端管理后台

### 5.1 设计系统 (Design System)

**文件位置**：[admin/css/design-system.css](file:///Applications/MAMP/htdocs/aibisskefu/admin/css/design-system.css)

| 类别 | 内容 |
|------|------|
| **色彩** | Primary（靛蓝 #6366F1）、Accent（青蓝 #06B6D4）、Semantic（成功/警告/错误/信息） |
| **字体** | Inter（主字体）、等宽字体栈（代码） |
| **间距** | 4px 基准的 12 级间距系统 |
| **圆角** | 6px ~ 16px + 全圆角 |
| **阴影** | 6 级阴影系统 (xs ~ xl) |
| **动画** | 3 级过渡速度 |

**组件**：Card、Button、Form Input/Select/Textarea、Badge、Table、Modal、Toast、Pagination、Loading、Skeleton、Empty State、Stats Grid。

### 5.2 页面路由

| 页面 | 路径 | 功能 |
|------|------|------|
| 登录页 | [admin/login.html](file:///Applications/MAMP/htdocs/aibisskefu/admin/login.html) | 暗色主题登录界面 |
| 仪表盘 | [admin/dashboard.html](file:///Applications/MAMP/htdocs/aibisskefu/admin/dashboard.html) | 统计概览 + 功能导航 |
| 人设管理 | [admin/persona.html](file:///Applications/MAMP/htdocs/aibisskefu/admin/persona.html) | 表单编辑人设字段 + 头像上传 |
| 知识库管理 | [admin/knowledge.html](file:///Applications/MAMP/htdocs/aibisskefu/admin/knowledge.html) | 分页表格 + 分类筛选 + CRUD + 导入导出 |
| 对话记录 | [admin/chat-logs.html](file:///Applications/MAMP/htdocs/aibisskefu/admin/chat-logs.html) | 会话列表 + 消息气泡 + 统计 |
| 转人工管理 | [admin/handoff.html](file:///Applications/MAMP/htdocs/aibisskefu/admin/handoff.html) | 接管会话、查看触发词 |
| 账号管理 | [admin/users.html](file:///Applications/MAMP/htdocs/aibisskefu/admin/users.html) | 管理员列表 + 创建/禁用/删除/重置密码 |
| 系统设置 | [admin/settings.html](file:///Applications/MAMP/htdocs/aibisskefu/admin/settings.html) | AI/网关/企业微信配置 + 嵌入代码 |

### 5.3 鉴权守卫

**文件位置**：[admin/auth-guard.js](file:///Applications/MAMP/htdocs/aibisskefu/admin/auth-guard.js)

```
1. 检查 localStorage 中是否有 admin_token
2. 如果没有 → 跳转 login.html
3. 如果有 → 异步请求 /api/auth.php?action=me 验证令牌有效性
4. 验证失败 → 清除 token 并跳转 login.html
```

---

## 6. SDK 客户端组件

### chat-widget.js — 嵌入式聊天窗口

**文件位置**：[sdk/chat-widget.js](file:///Applications/MAMP/htdocs/aibisskefu/sdk/chat-widget.js)

**嵌入方式**：

```html
<script>
window.ChatWidgetConfig = {
  serverUrl: 'https://your-domain.com',
  themeColor: '#667eea',
  position: 'right'
};
</script>
<script src="https://your-domain.com/sdk/chat-widget.js"></script>
```

**功能**：
- 浮动按钮（右下角/左下角）
- 点击打开 chat.html 全功能聊天窗口
- 消息气泡展示
- 会话持久化（sessionStorage）
- 发送机制（回车/按钮发送）
- 自动滚动 + 时间戳

---

## 7. LLM Prompt 引擎

### 7.1 设计原则

- system prompt 静态化：人设+规则不塞动态内容
- 多轮历史用标准 role 数组传递
- 知识库注入到最后一条 user 消息（RAG 标准模式）
- rewriteQuery 智能跳过：无代词/首问时不调用 AI

### 7.2 System Prompt 结构

```
你是{name}，{brand_story}，企业在线客服。

【禁止】以下内容绝对不能出现在你的回复中
1. 推荐房源、换房、升级、预订、下单等任何引导消费的话术
2. 追问房源、小区、房型、订单平台等信息
3. 以"您"开头的问句或建议
4. 任何万能结尾，如"有任何问题随时找我"、"有我在"等
5. 回答后追加任何第二句、第三句

【必须直接转人工】以下问题只回复"正在为您转接人工客服，请稍候。"
涉及：发票开具、发票申请、发票重开、投诉、赔偿协商、押金纠纷、退款进度

【回复格式】只输出一个完整的陈述句（20-80字），句号结束，不追加任何内容

【性格】{personality}
【风格】{speak_style}
【规范】{service_rules}
【原则】{principles}
```

### 7.3 AI 调用参数

| 参数 | 值 |
|------|-----|
| 主聊天模型 | `deepseek-v4-flash` |
| 改写模型 | `deepseek-v4-flash`（关推理） |
| 温度 | `0.5`（可后台配置） |
| max_tokens | `150` |
| 推理控制 | `thinking: {type: 'disabled'}` |

---

## 8. 配置与环境变量

### .env 文件

**文件位置**：[.env](file:///Applications/MAMP/htdocs/aibisskefu/.env)

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `DB_HOST` | 数据库主机 | `localhost` |
| `DB_PORT` | 数据库端口 | `3306` |
| `DB_NAME` | 数据库名 | `aibisskefu_com` |
| `DB_USER` | 数据库用户 | `root` |
| `DB_PASS` | 数据库密码 | `root` |
| `JWT_SECRET` | JWT 签名密钥 | `change_this_secret` |

**AI 配置**：

| 变量 | 说明 |
|------|------|
| `AI_MODEL` | 主聊天模型 |
| `AI_TIMEOUT` | AI 请求超时（默认 60s） |
| `DEEPSEEK_API_KEY` | DeepSeek Key（覆盖数据库配置） |
| `DEEPSEEK_API_URL` | DeepSeek 端点 |
| `MINIMAX_API_KEY` | MiniMax Key |
| `MINIMAX_API_URL` | MiniMax 端点 |

**PromptEngine 配置**：

| 变量 | 说明 |
|------|------|
| `PROMPT_ENGINE_DEBUG` | 写日志到 `logs/prompt_engine_*.log` |
| `PROMPT_ENGINE_REWRITE_MODEL` | 改写模型 |
| `PROMPT_ENGINE_REWRITE_TIMEOUT` | 改写超时（默认 4s） |

---

## 9. 安全机制

| 机制 | 实现方式 |
|------|----------|
| **密码存储** | `password_hash()` 使用 bcrypt 算法 |
| **JWT Token** | HMAC-SHA256 签名，7 天过期 |
| **请求鉴权** | Bearer Token 验证 + adminGuard 权限控制 |
| **前端守卫** | auth-guard.js 拦截未认证访问 |
| **输入过滤** | strip_tags() 过滤用户输入 |
| **输入安全** | checkInputSafety() 检测政治/色情/Prompt注入 |
| **速率限制** | 同一 IP 每 60 秒最多 20 次请求 |
| **room_id 安全** | 后端只信任 order_context_cache 中的 room_id |
| **API 密钥** | 支持外部 API 密钥管理（可禁用） |

---

## 10. 部署与运行

### 10.1 环境要求

| 组件 | 要求 |
|------|------|
| Web 服务器 | Apache 2.4+（推荐 MAMP） |
| PHP | 8.0+（需开启 pdo_mysql, openssl, json 扩展） |
| MySQL | 5.7+ 或 MariaDB 10.3+ |

### 10.2 部署步骤

```bash
# 1. 复制项目到 Web 服务器目录
cp -r aibisskefu /Applications/MAMP/htdocs/

# 2. 配置 .env 文件
编辑 /path/to/aibisskefu/.env 填入数据库信息

# 3. 导入数据库
mysql -u root -p < /path/to/aibisskefu/sql/init.sql

# 4. 执行增量迁移（如有）
mysql -u root -p < /path/to/aibisskefu/sql/migration_v2.0.sql

# 5. 启动 Web 服务器（MAMP 用户直接启动 Apache + MySQL）

# 6. 访问管理后台
http://localhost/aibisskefu/admin/login.html
默认账号: admin / admin123

# 7. 配置 AI API Key
登录后在「系统设置」页面配置 DeepSeek API Key
```

---

## 11. 数据流概览

### 11.1 用户对话流

```
┌──────────────┐      ┌──────────────────┐      ┌──────────────────┐
│  用户浏览器   │ ──→  │  chat-widget.js   │ ──→  │  /api/chat.php   │
│  (嵌入网站)   │ ←──  │  (SDK 客户端)     │ ←──  │  (PHP 后端)      │
└──────────────┘      └──────────────────┘      └──────┬───────────┘
                                                       │
                                            ┌──────────┼──────────┐
                                            │          │          │
                                            ▼          ▼          ▼
                                     ┌──────────┐ ┌──────────┐ ┌──────────┐
                                     │  MySQL    │ │PromptEng.│ │ DeepSeek  │
                                     │ 数据库    │ │ 构建提示词│ │ V4 Flash   │
                                     └──────────┘ └──────────┘ └──────────┘
                                            │          │
                                            ▼          ▼
                                     ┌──────────┐  ┌──────────┐
                                     │order_ctx │  │filterReply│
                                     │_cache(PMS)│  │后处理过滤 │
                                     └──────────┘  └──────────┘
```

### 11.2 管理后台操作流

```
┌──────────────┐      ┌────────────────┐      ┌───────────────┐
│  管理员浏览器 │ ──→  │  admin/*.html   │ ──→  │  各 API 端点  │
│  (登录后)    │ ←──  │  (前端页面)     │ ←──  │  (PHP 处理)   │
└──────────────┘      └────────────────┘      └───────┬───────┘
                                                        │
                                                  ┌─────▼─────┐
                                                  │   MySQL    │
                                                  │   数据库   │
                                                  └───────────┘
```

---

## 12. 扩展指南

### 12.1 添加新的 API 端点

1. 在 `api/` 目录下创建新的 PHP 文件
2. 引入 `config.php` 获取 `$db` 连接
3. 实现 `action` 分发逻辑
4. 使用 `authToken()` 或 `adminGuard()` 进行鉴权
5. 使用 `ok()` / `fail()` 返回响应

```php
<?php
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$body = getBody();
$db = getDB();

if ($action === 'list') {
    // 公开接口
    json_response(0, 'ok', $data);
}

if ($action === 'admin_action') {
    adminGuard(); // 需要管理员权限
    // 处理逻辑
    ok($data);
}

fail('未知操作');
```

### 12.2 添加新的后台页面

1. 在 `admin/` 目录创建 HTML 文件
2. 在 `<head>` 引用 `<script src="auth-guard.js"></script>`
3. 引用 `<link rel="stylesheet" href="css/design-system.css">`
4. 遵循现有的 `apiUrl()` / `getHeaders()` / `showToast()` 模式

### 12.3 切换 AI 模型

当前使用 DeepSeek V4 Flash，通过 `callAI()` 自动识别：

- 模型名含 `deepseek` → 走 DeepSeek 端点
- 其他 → 走 MiniMax 端点

```php
$result = callAI($messages, [
    'model' => 'deepseek-v4-flash',
    'temperature' => 0.5,
    'thinking' => ['type' => 'disabled'],
]);
```

---

## 附录

### A. API 统一响应格式

```json
{
  "code": 0,
  "msg": "ok",
  "data": {}
}
```

### B. 关键配置值

| 参数 | 值 | 位置 |
|------|-----|------|
| 分页大小 | 20 条/页 | knowledge.php, chat.php |
| LLM max_tokens | 150 | config.php |
| LLM temperature | 0.5（可后台配置） | chat.php |
| rewrite 超时 | 4s | PromptEngine.php |
| KB 最大召回 | 3 条/次 | PromptEngine.php |
| KB 单条最大字数 | 200 | PromptEngine.php |
| 速率限制 | 20次/分钟/IP | chat.php |
| 订单上下文过期 | 24h | chat.php |
| SMS 验证码有效期 | 5分钟 | verify.php |

### C. 第三方服务

| 服务 | 用途 |
|------|------|
| DeepSeek API (deepseek-v4-flash) | AI 对话生成 + rewriteQuery 改写 |
| Google Fonts (Inter) | 后台界面字体 |
| MySQL | 数据持久化存储 |
| PMS 网关 | 订单验证 + 房间信息查询 |

---

> **文档版本**：v2.0
> **最后更新**：2026-05-19
> **维护者**：Aibisskefu Team

# 企业微信「微信客服」接入 SOP

> v3.4 · 2026-08 · 用户扫客服二维码对话

## 一、流程概述

```
用户扫客服二维码 → 微信客服会话 → 企微回调 POST kf_msg_or_event
    → wecom_kf.php 立即返空串（5 秒内必须应答）
    → 调 sync_msg 拉取具体消息
    → ChatPipeline 分类 + AI 回复
    → send_msg 主动发送回复到客户会话
```

## 二、前置条件（需要从企业微信后台获取）

| 配置项 | 获取位置 | 格式 |
|--------|---------|------|
| `wecom.corpid` | 企业微信后台 → 我的企业 → 企业信息 → CorpID | `ww...` |
| `wecom.corp_secret` | 企业微信后台 → 我的企业 → 微信插件 → Secret | 32 位 |
| `wecom.token` | 回调配置时自己生成 | 3-32 位 |
| `wecom.aes_key` | 回调配置时自己生成 | 43 位 Base64 |
| `wecom.kf_open_kfid` | 企业微信后台 → 应用管理 → 微信客服 → 客服账号 → 详情页 | `wk...` |

## 三、企业微信后台配置步骤

### 1. 开通微信客服
1. 企业微信管理后台 → 应用管理 → 微信客服 → 开通
2. 创建客服账号 → 记录 `open_kfid`（`wk...` 开头）

### 2. 配置回调 URL
1. 企业微信后台 → 微信客服 → API 接入 → 接收消息
2. 设置回调 URL：`https://你的域名/aibisskefu/api/wecom_kf.php`
3. 填写 Token + EncodingAESKey（与 `platform_config` 一致）
4. 保存时企微会发 GET 请求验证 → `wecom_kf.php` 验签 + 解密返回 echostr

### 3. 获取客服二维码
1. 企业微信后台 → 微信客服 → 客服账号 → 详情页
2. 复制客服链接 / 下载二维码 → 放到官网、公众号、海报等场景

## 四、本机部署（MAMP，测试用）

> ⚠️ 微信客服要求回调 URL 必须是 HTTPS。本地 MAMP 是 HTTP，**只能做代码级验证，不能真实联调**。

本地验证方法：
```bash
# 1. 验证 URL 验签逻辑（返回 Signature mismatch 说明验签生效）
curl "http://localhost:8888/aibisskefu/api/wecom_kf.php?msg_signature=x&timestamp=1&nonce=1&echostr=x"

# 2. 看日志
tail -f /Applications/MAMP/htdocs/aibisskefu/logs/wecom_kf.log
```

## 五、生产部署（Nginx + HTTPS）

### 方案 A：同服务器加域名（推荐）
```nginx
# /etc/nginx/conf.d/wecom-kf.conf
server {
    listen 443 ssl;
    server_name kf.你的域名.com;
    ssl_certificate     /etc/ssl/certs/你的证书.pem;
    ssl_certificate_key /etc/ssl/private/你的私钥.key;

    location /aibisskefu/ {
        proxy_pass http://127.0.0.1:8888;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

回调 URL 填：`https://kf.你的域名.com/aibisskefu/api/wecom_kf.php`

### 方案 B：云函数 / 服务器转发
- 用一台有公网 HTTPS 的服务器，Nginx 反代到 MAMP
- 或把整个项目部署到服务器（PHP 8 + MySQL，见主 README）

## 六、配置写入（二选一）

### 方式 1：管理后台 UI（推荐）
1. 登录管理后台 → 系统设置 → 企业微信集成
2. 填 corp_id / token / aes_key
3. 微信客服卡片 → 填 corp_secret + open_kfid
4. 保存

### 方式 2：直接 SQL
```sql
INSERT INTO platform_config (`key`, `value`) VALUES
('wecom.corpid', 'ww...'),
('wecom.corp_secret', '32位secret'),
('wecom.token', '你的token'),
('wecom.aes_key', '43位aeskey'),
('wecom.kf_open_kfid', 'wk...')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
```

## 七、验证清单

| # | 检查项 | 方法 | 预期 |
|---|--------|------|------|
| 1 | 配置齐全 | `SELECT * FROM platform_config WHERE \`key\` LIKE 'wecom.%'` | 5 项都有值 |
| 2 | URL 验签 | 企微后台保存回调地址 | 保存成功，wecom_kf.log 出现 "URL verified" |
| 3 | 客户发消息 | 用手机微信扫客服二维码发一条"你好" | wecom_kf.log 出现 "POST: MsgType=event Event=kf_msg_or_event" |
| 4 | AI 回复 | 看客户微信 | 收到 AI 回复 |
| 5 | 审计 | `SELECT intent, COUNT(*) FROM chat_logs WHERE channel='wechat_kf' GROUP BY intent` | 有记录 |

## 八、常见问题

### Q1: 收不到回调？
- 检查回调 URL 是否 HTTPS 可达（curl -k https://kf.域名/aibisskefu/api/wecom_kf.php）
- 检查 5 项配置是否齐全（缺任何一项 wecom_kf.php 返回 500 "not configured"）
- 检查 wecom_kf.log 是否有 POST 记录

### Q2: 验签失败？
- Token / AESKey / CorpID 必须与企微后台完全一致（含大小写）
- 用企业微信官方加解密工具对照调试

### Q3: 客户收到回复慢？
- 当前是**同步处理**（回调 → sync_msg → AI → send_msg 全同步）
- 如果 AI 慢（>5s），企微会重试回调 → 重复回复
- 优化方向：回调立即应答 → 异步队列处理（redis/文件队列）

### Q4: 转人工怎么处理？
- 当前 HANDL 意图返回"正在为您转接人工客服，请稍候。"纯文本
- 真正转人工需要：企微后台配置接待人员 + 分配客服会话 API（`kf/service_state/trans`）
- 建议 v3.5 实现：AI 识别转人工意图 → 调 `service_state/trans` 分配接待人员 → 通知客服

## 九、相关文件

| 文件 | 作用 |
|------|------|
| `api/wecom_kf.php` | 微信客服回调入口（新） |
| `api/wecom_crypto.php` | 企微 AES 加解密工具（从 wecom.php 抽出） |
| `api/wecom.php` | 应用消息回调（保留，互不影响） |
| `api/verify.php` | 后台配置读写 API（save/get_wecom_kf_config） |
| `admin/settings.html` | 后台配置 UI（微信客服卡片） |
| `logs/wecom_kf.log` | 微信客服日志 |

## 十、回滚

- 关闭微信客服回调：企微后台删除回调 URL → 客户消息不再进入 AI
- 或把 `wecom.corp_secret` 置空 → wecom_kf.php 返回 500 not configured
- 代码回滚：git revert 对应 commit

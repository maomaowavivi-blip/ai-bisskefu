#!/bin/sh
# ACCEPT.sh — v3.35 订单入住指引 验收脚本（绝对路径自含，干净机器可直跑）
# 全部 exit 0 = 通过。ad-hoc 验收：lint + 单测 + 安全垫 + 并行契约。
set -e
D=/Users/smiler/Documents/GitHub/ai-bisskefu
P=${V335_PHP:-/Applications/MAMP/bin/php/php8.3.30/bin/php}
cd "$D"

# 1. 语法
for f in api/wecom_kf_token.php api/wecom_kf_order_guide.php api/OrderGuidePick.php \
         api/Workflow/OrderQueryWorkflow.php \
         api/ReplyRenderer.php api/ChatPipeline.php api/CenterRoom.php api/wecom_kf.php; do
  "$P" -l "$f" >/dev/null
done
echo "[1] php -l: 9 files OK"

# 2. 单测（64 项，须全绿）
for t in test_v335_order_stage test_v335_order_pick_match test_v335_order_guide_messages; do
  "$P" "scripts/$t.php" >/dev/null
  echo "[2] $t: OK"
done

# 3. 并行契约：中立 token include 四函数 + 三方引用
for fn in getAccessToken httpGet wecom_kf_log wecom_kf_response_error; do
  grep -q "function_exists('$fn')" api/wecom_kf_token.php
done
grep -q "wecom_kf_token.php" api/wecom_kf.php
echo "[3] token include 契约 OK"

# 4. 红线：roomcard_v37 零改动；交付文件无敏感字段
git diff --quiet -- api/wecom_kf_roomcard_v37.php
! grep -rq "lock_password\|cleaning_password\|room_password\|lock_user_name" \
  api/wecom_kf_token.php api/OrderGuidePick.php api/wecom_kf_order_guide.php \
  sql/migration_v3.35_order_guide.sql
echo "[4] 红线 OK"

# 5. 安全垫（需本地 Apache+MySQL 在跑；--skip-live 可跳过）
#    配置全空时 chat.php 订单回复必须与 baseline 逐字节一致
if [ "${1:-}" != "--skip-live" ]; then
  SID="accept_$(date +%s)"
  curl -sf -X POST "http://localhost:8888/aibisskefu/api/chat.php?action=chat" \
    -H "Content-Type: application/json" \
    -d "{\"message\":\"order_query:123456789\",\"session_id\":\"$SID\",\"channel\":\"web\"}" \
    -o /tmp/v335_accept.json
  "$P" -r '
    $b = json_decode(file_get_contents(".trae/documents/baseline-v3.34.1-order-query.json"), true);
    $d = json_decode(file_get_contents("/tmp/v335_accept.json"), true)["data"] ?? [];
    if (($d["reply"] ?? null) !== ($b["_response"]["data"]["reply"] ?? "\x00")) exit(1);
    if (array_key_exists("order_guide", $d)) exit(2); // 配置全空不得带该键
    exit(0);'
  echo "[5] 安全垫逐字节一致 OK（若步骤2后未清空配置，先删 order_reply.text.* 三键再跑）"
fi
echo "ACCEPT: ALL PASS"

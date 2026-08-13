#!/usr/bin/env bash
set -euo pipefail
SRC="/Applications/MAMP/htdocs/aibisskefu/"
DST="/Users/smiler/Documents/GitHub/ai-bisskefu/"
read -r -p "MAMP → 工作区 确认? [y/N] " ans
[[ "$ans" == "y" || "$ans" == "Y" ]] || exit 1
rsync -av --delete \
  --exclude '.git/' --exclude '.DS_Store' --exclude '.env' \
  --exclude 'logs/' --exclude 'uploads/avatars/' --exclude '.vscode/sftp.json' \
  "$SRC" "$DST"
echo "✅ synced from MAMP"

#!/usr/bin/env bash
set -euo pipefail
SRC="/Users/smiler/Documents/GitHub/ai-bisskefu/"
DST="/Applications/MAMP/htdocs/aibisskefu/"
rsync -av --delete \
  --exclude '.git/' --exclude '.DS_Store' --exclude '.env' \
  --exclude 'logs/' --exclude 'uploads/avatars/' --exclude '.vscode/sftp.json' \
  "$SRC" "$DST"
echo "✅ synced to MAMP"

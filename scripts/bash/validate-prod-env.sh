#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-/var/www/sistema-erp}"

required=(
  "$ROOT/backend/public"
  "$ROOT/backend/storage/app/private"
  "$ROOT/backend/storage/logs"
  "$ROOT/frontends/mobile"
  "$ROOT/frontends/desktop"
  "$ROOT/documentacao"
)

for path in "${required[@]}"; do
  if [ -d "$path" ]; then
    echo "OK  $path"
  else
    echo "FALHA $path"
    exit 1
  fi
done

echo "Ambiente de producao validado."


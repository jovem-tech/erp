#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUTPUT_FILE=""

for argument in "$@"; do
  case "$argument" in
    --output=*) OUTPUT_FILE="${argument#*=}" ;;
    *) echo "Uso: $0 [--output=caminho-relativo.md]" >&2; exit 1 ;;
  esac
done

search_php() {
  local pattern="$1"
  shift

  if command -v rg >/dev/null 2>&1; then
    rg -n --glob '*.php' "$pattern" "$@" || true
  else
    grep -RInE --include='*.php' "$pattern" "$@" || true
  fi
}

render_inventory() {
  echo "# Inventario reproduzivel de uso de arquivos"
  echo
  echo "Gerado em: $(date --iso-8601=seconds)"
  echo
  echo "## Chamadas de filesystem e upload no backend"
  echo '```text'
  search_php \
    'Storage::|UploadedFile|storeAs\(|putFileAs\(|writeStream\(|readStream\(|fopen\(|file_get_contents\(|file_put_contents\(|unlink\(|rename\(' \
    "$ROOT_DIR/backend/app" "$ROOT_DIR/backend/routes" | sed "s#$ROOT_DIR/##g"
  echo '```'
  echo
  echo "## Rotas relacionadas a arquivos"
  echo '```text'
  search_php \
    'photo|foto|document|arquivo|anexo|attachment|signature|assinatura|logo|background|export|import' \
    "$ROOT_DIR/backend/routes" | sed "s#$ROOT_DIR/##g"
  echo '```'
  echo
  echo "## Colunas e tabelas de referencia"
  echo '```text'
  search_php \
    "storage_path|storage_disk|mime_type|byte_size|hash_sha256|sha256|arquivo|caminho|nome_arquivo|anexo" \
    "$ROOT_DIR/backend/database/migrations" "$ROOT_DIR/backend/app/Models" | sed "s#$ROOT_DIR/##g"
  echo '```'
  echo
  echo "## Configuracao de discos e roots"
  echo '```text'
  search_php \
    "'disks'|'root'|'storage'|'legacy_public'|'private'|'chat-media'|'managed-files'" \
    "$ROOT_DIR/backend/config/filesystems.php" "$ROOT_DIR/backend/config/file-manager.php" | sed "s#$ROOT_DIR/##g"
  echo '```'
}

if [[ -z "$OUTPUT_FILE" ]]; then
  render_inventory
  exit 0
fi

if [[ "$OUTPUT_FILE" = /* || "$OUTPUT_FILE" == *".."* ]]; then
  echo "O output deve ser um caminho relativo sem traversal." >&2
  exit 1
fi

TARGET="$ROOT_DIR/$OUTPUT_FILE"
mkdir -p "$(dirname "$TARGET")"
render_inventory > "$TARGET"
echo "Inventario salvo em $OUTPUT_FILE"

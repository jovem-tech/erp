#!/usr/bin/env bash
# bump-version.sh — incrementa VERSION e registra entrada em CHANGELOG.md
# Uso: ./scripts/bump-version.sh --tier=major|minor|patch|hotfix --desc="descrição" [--files="a.php,b.php"]

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION_FILE="$ROOT_DIR/VERSION"
CHANGELOG_FILE="$ROOT_DIR/CHANGELOG.md"

usage() {
  echo "Uso: $0 --tier=major|minor|patch|hotfix --desc=\"descrição curta\" [--files=\"arq1,arq2\"]"
  exit 1
}

TIER=""
DESC=""
FILES=""

for arg in "$@"; do
  case $arg in
    --tier=*)  TIER="${arg#*=}" ;;
    --desc=*)  DESC="${arg#*=}" ;;
    --files=*) FILES="${arg#*=}" ;;
    *) usage ;;
  esac
done

[[ -z "$TIER" || -z "$DESC" ]] && usage

if [[ ! -f "$VERSION_FILE" ]]; then
  echo "3.5.3.0" > "$VERSION_FILE"
fi

IFS='.' read -r MAJOR MINOR PATCH HOTFIX < "$VERSION_FILE"
MAJOR=${MAJOR:-3}; MINOR=${MINOR:-5}; PATCH=${PATCH:-3}; HOTFIX=${HOTFIX:-0}

case "$TIER" in
  major)  MAJOR=$((MAJOR+1)); MINOR=0; PATCH=0; HOTFIX=0 ;;
  minor)  MINOR=$((MINOR+1)); PATCH=0; HOTFIX=0 ;;
  patch)  PATCH=$((PATCH+1)); HOTFIX=0 ;;
  hotfix) HOTFIX=$((HOTFIX+1)) ;;
  *) usage ;;
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}.${HOTFIX}"
echo "$NEW_VERSION" > "$VERSION_FILE"

# Sincroniza shared/version.php (versao de exibicao MAJOR.MINOR.PATCH usada
# pelo app e por scripts/php/sync-agent-docs.php)
SHARED_VERSION_FILE="$ROOT_DIR/shared/version.php"
if [[ -d "$ROOT_DIR/shared" ]]; then
  printf '<?php\n\nreturn %s%s.%s.%s%s;\n' "'" "$MAJOR" "$MINOR" "$PATCH" "'" > "$SHARED_VERSION_FILE"
fi

DATE=$(date '+%Y-%m-%d %H:%M')
AUTHOR=$(git config user.name 2>/dev/null || echo "desconhecido")

ENTRY="## v$NEW_VERSION — $DATE
- **Tier:** $TIER
- **Autor/Agente:** $AUTHOR
- **Descrição:** $DESC"

if [[ -n "$FILES" ]]; then
  ENTRY="$ENTRY
- **Arquivos:** $FILES"
fi

if [[ ! -f "$CHANGELOG_FILE" ]]; then
  printf "# Changelog — Sistema ERP Jovem Tech\n\n%s\n" "$ENTRY" > "$CHANGELOG_FILE"
else
  TMP=$(mktemp)
  {
    head -n 2 "$CHANGELOG_FILE"
    echo "$ENTRY"
    echo ""
    tail -n +3 "$CHANGELOG_FILE"
  } > "$TMP"
  # mktemp cria o arquivo com 600 (so o dono le); sem este chmod, o `mv` troca
  # o inode do CHANGELOG.md por esse temporario e a permissao restritiva "vaza"
  # para o arquivo final — quebrando qualquer leitor que nao seja o dono (ex.:
  # www-data lendo CHANGELOG.md pela aba Documentacao do desktop).
  chmod 644 "$TMP"
  mv "$TMP" "$CHANGELOG_FILE"
fi

echo "Versão atualizada: $NEW_VERSION (tier: $TIER)"

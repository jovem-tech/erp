#!/usr/bin/env bash
# versionar.sh — versionamento do Sistema ERP, pensado para ser rodado direto pelo
# usuário, sem depender de IA (Claude/Codex) para classificar a mudança.
#
# Reaproveita o que já existe:
#   - a mesma heurística objetiva de scripts/classify-change.sh (aplicada ao que
#     ainda não foi commitado: staged + working tree, contra HEAD)
#   - scripts/bump-version.sh (quem de fato grava VERSION/CHANGELOG.md/shared/version.php)
#   - scripts/bash/sync-agent-docs.sh (quando existir), para regenerar
#     documentacao/04-governanca-ai/manifesto-do-sistema.md e
#     documentacao/04-governanca-ai/contexto-sistema.json com a nova versão
#
# Modo interativo (padrão): pergunta o tier (sugerindo um, com o motivo) e a
# descrição, monta a lista de arquivos sozinho e chama bump-version.sh.
#
# Modo não-interativo (compatível com o bump-version.sh de sempre):
#   ./scripts/versionar.sh --tier=major|minor|patch|hotfix --desc="descrição" [--files="a,b"]
#
# Grava VERSION/CHANGELOG.md/shared/version.php e, quando disponível, sincroniza
# os artefatos gerados da documentação de agentes. Não dá commit nem push. Isso
# é trabalho do scripts/bash/deploy-completo.sh, de propósito, para manter
# "registrar a versão" e "publicar no git" como passos independentes.
#
# Ver VERSIONING.md para os critérios completos de classificação.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUMP_SCRIPT="$ROOT_DIR/scripts/bump-version.sh"
SYNC_AGENT_DOCS_SCRIPT="$ROOT_DIR/scripts/bash/sync-agent-docs.sh"

collect_changed_files() {
  local name_status untracked

  name_status="$(git diff HEAD --name-status 2>/dev/null || true)"
  untracked="$(git ls-files --others --exclude-standard 2>/dev/null || true)"

  {
    echo "$name_status" | awk '{print $2}'
    echo "$untracked"
  } | grep -v -E '^(VERSION|CHANGELOG\.md|shared/version\.php)$' | grep -v '^$' | sort -u | paste -sd, -
}

run_post_version_sync() {
  if [[ -f "$SYNC_AGENT_DOCS_SCRIPT" ]]; then
    echo ">>> Sincronizando documentação gerada para agentes"
    bash "$SYNC_AGENT_DOCS_SCRIPT"
  fi
}

cd "$ROOT_DIR"

GIT_TOPLEVEL="$(git -C "$ROOT_DIR" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$GIT_TOPLEVEL" ]]; then
  echo "ERRO: $ROOT_DIR não está dentro de um repositório git acessível." >&2
  exit 1
fi

TIER=""
DESC=""
FILES=""

for arg in "$@"; do
  case $arg in
    --tier=*)  TIER="${arg#*=}" ;;
    --desc=*)  DESC="${arg#*=}" ;;
    --files=*) FILES="${arg#*=}" ;;
    -h|--help)
      echo "Uso interativo: $0"
      echo "Uso direto:     $0 --tier=major|minor|patch|hotfix --desc=\"descrição\" [--files=\"a,b\"]"
      echo "                Se --files for omitido, a lista de arquivos alterados é detectada automaticamente."
      exit 0
      ;;
    *)
      echo "Argumento desconhecido: $arg" >&2
      exit 1
      ;;
  esac
done

# --- Modo não-interativo: se tier e descrição já vieram por flag, não pergunta nada ---
if [[ -n "$TIER" && -n "$DESC" ]]; then
  if [[ -z "$FILES" ]]; then
    FILES="$(collect_changed_files)"
  fi
  bash "$BUMP_SCRIPT" --tier="$TIER" --desc="$DESC" ${FILES:+--files="$FILES"}
  run_post_version_sync
  exit 0
fi

# --- Coleta o que ainda não foi commitado (staged + working tree) contra HEAD ---
NAME_STATUS=$(git diff HEAD --name-status 2>/dev/null || true)
NUMSTAT=$(git diff HEAD --numstat 2>/dev/null || true)
RAW_DIFF=$(git diff HEAD 2>/dev/null || true)
UNTRACKED=$(git ls-files --others --exclude-standard 2>/dev/null || true)

if [[ -z "$NAME_STATUS" && -z "$UNTRACKED" ]]; then
  echo "Nenhuma alteração pendente (working tree limpa e nada staged)."
  echo "Nada para versionar."
  exit 0
fi

NEW_FILES=$(( $(echo "$NAME_STATUS" | awk '$1=="A"' | grep -c . || true) + $(echo "$UNTRACKED" | grep -c . || true) ))
DELETED_FILES=$(echo "$NAME_STATUS" | awk '$1=="D"' | grep -c . || true)
TOTAL_FILES=$(( $(echo "$NAME_STATUS" | grep -c . || true) + $(echo "$UNTRACKED" | grep -c . || true) ))
HAS_MIGRATION=$(echo "$NAME_STATUS
$UNTRACKED" | grep -c "database/migrations" || true)
LINES_CHANGED=$(echo "$NUMSTAT" | awk '{added+=$1; removed+=$2} END {print added+removed+0}')
HAS_DROP=$(echo "$RAW_DIFF" | grep -ci "drop table\|drop column\|dropForeign\|dropIndex" || true)

if [[ "$HAS_DROP" -gt 0 || "$DELETED_FILES" -gt 0 || "$TOTAL_FILES" -gt 5 ]]; then
  SUGGESTED="major"
  MOTIVO="DROP em migration, arquivo removido, ou mudança tocando mais de 5 arquivos"
elif [[ "$HAS_MIGRATION" -gt 0 || "$NEW_FILES" -gt 0 ]]; then
  SUGGESTED="minor"
  MOTIVO="migration nova ou arquivo novo criado"
elif [[ "$LINES_CHANGED" -gt 15 ]]; then
  SUGGESTED="patch"
  MOTIVO="alteração de lógica em arquivo já existente, acima do limiar de hotfix"
else
  SUGGESTED="hotfix"
  MOTIVO="diff pequeno, sem migration, sem arquivo novo"
fi

echo "=== Resumo do que ainda não foi commitado ==="
echo "Arquivos novos: $NEW_FILES | removidos: $DELETED_FILES | total: $TOTAL_FILES"
echo "Migrations tocadas: $HAS_MIGRATION | Linhas alteradas: $LINES_CHANGED | Ocorrências de DROP: $HAS_DROP"
echo ""
echo "=== Classificação sugerida: $SUGGESTED ==="
echo "Motivo: $MOTIVO"
echo ""
echo "Níveis disponíveis (VERSIONING.md):"
echo "  1) major  — mudança estrutural grande (DROP em migration, contrato de rota quebrado,"
echo "              novo frontend/arquitetura, ou toca >5 arquivos em áreas não relacionadas)"
echo "  2) minor  — nova funcionalidade não-estrutural (migration aditiva, novo Controller/"
echo "              Service/Model/Job/rota completamente nova)"
echo "  3) patch  — correção de bug em arquivo(s) já existente(s), sem criar arquivo novo"
echo "  4) hotfix — ajuste pontual pequeno (diff <15 linhas, sem migration, sem arquivo novo)"
echo ""

declare -A TIER_BY_NUM=([1]=major [2]=minor [3]=patch [4]=hotfix)
SUGGESTED_NUM=""
for n in "${!TIER_BY_NUM[@]}"; do
  [[ "${TIER_BY_NUM[$n]}" == "$SUGGESTED" ]] && SUGGESTED_NUM="$n"
done

read -r -p "Nível [Enter para aceitar a sugestão \"$SUGGESTED\" ($SUGGESTED_NUM), ou digite 1-4]: " TIER_INPUT
if [[ -z "$TIER_INPUT" ]]; then
  TIER="$SUGGESTED"
elif [[ -n "${TIER_BY_NUM[$TIER_INPUT]:-}" ]]; then
  TIER="${TIER_BY_NUM[$TIER_INPUT]}"
else
  echo "ERRO: opção inválida \"$TIER_INPUT\" (use 1, 2, 3 ou 4)." >&2
  exit 1
fi

while [[ -z "$DESC" ]]; do
  read -r -p "Descrição curta da entrega (aparece no CHANGELOG): " DESC
done

# Lista de arquivos alterados, sem os que o próprio bump-version.sh vai reescrever.
FILES_LIST="$(collect_changed_files)"

echo ""
echo "=== Confirmação ==="
echo "Tier: $TIER"
echo "Descrição: $DESC"
echo "Arquivos: ${FILES_LIST:-(nenhum detectado)}"
read -r -p "Confirma gravar essa entrada em VERSION/CHANGELOG.md? [s/N]: " CONFIRM
if [[ ! "$CONFIRM" =~ ^[sS]$ ]]; then
  echo "Cancelado — nada foi alterado."
  exit 1
fi

bash "$BUMP_SCRIPT" --tier="$TIER" --desc="$DESC" ${FILES_LIST:+--files="$FILES_LIST"}
run_post_version_sync

#!/usr/bin/env bash
# classify-change.sh — sugere a classificação (major/minor/patch/hotfix) de um diff
# com base em critérios objetivos, independente do que o agente de IA reportar.
#
# Uso:
#   ./scripts/classify-change.sh --staged        # analisa o que está no stage (git add)
#   ./scripts/classify-change.sh <commit-hash>    # analisa um commit já feito

set -euo pipefail

MODE="${1:---staged}"

if [[ "$MODE" == "--staged" ]]; then
  NAMES=$(git diff --cached --name-status || true)
  NUMSTAT=$(git diff --cached --numstat || true)
  RAW_DIFF=$(git diff --cached || true)
else
  NAMES=$(git show --name-status --pretty="" "$MODE" || true)
  NUMSTAT=$(git show --numstat --pretty="" "$MODE" || true)
  RAW_DIFF=$(git show "$MODE" || true)
fi

if [[ -z "$NAMES" ]]; then
  echo "Nenhuma alteração encontrada (stage vazio ou commit inválido)."
  exit 1
fi

NEW_FILES=$(echo "$NAMES" | awk '$1=="A"' | grep -c . || true)
DELETED_FILES=$(echo "$NAMES" | awk '$1=="D"' | grep -c . || true)
MODIFIED_FILES=$(echo "$NAMES" | awk '$1=="M"' | grep -c . || true)
TOTAL_FILES=$(echo "$NAMES" | grep -c . || true)
HAS_MIGRATION=$(echo "$NAMES" | grep -c "database/migrations" || true)
LINES_CHANGED=$(echo "$NUMSTAT" | awk '{added+=$1; removed+=$2} END {print added+removed+0}')
HAS_DROP=$(echo "$RAW_DIFF" | grep -ci "drop table\|drop column\|dropForeign\|dropIndex" || true)

echo "=== Resumo do diff ==="
echo "Arquivos novos: $NEW_FILES | modificados: $MODIFIED_FILES | removidos: $DELETED_FILES | total: $TOTAL_FILES"
echo "Migrations tocadas: $HAS_MIGRATION | Linhas alteradas: $LINES_CHANGED | Ocorrências de DROP: $HAS_DROP"
echo ""

if [[ "$HAS_DROP" -gt 0 || "$DELETED_FILES" -gt 0 || "$TOTAL_FILES" -gt 5 ]]; then
  TIER="major"
  MOTIVO="DROP em migration, arquivo removido, ou mudança tocando muitos arquivos"
elif [[ "$HAS_MIGRATION" -gt 0 || "$NEW_FILES" -gt 0 ]]; then
  TIER="minor"
  MOTIVO="nova migration aditiva ou arquivo novo criado"
elif [[ "$LINES_CHANGED" -gt 15 ]]; then
  TIER="patch"
  MOTIVO="alteração de lógica em arquivo existente, acima do limiar de hotfix"
else
  TIER="hotfix"
  MOTIVO="diff pequeno, sem migration, sem arquivo novo"
fi

echo "=== Classificação sugerida: $TIER ==="
echo "Motivo: $MOTIVO"
echo ""
echo "(Isto é uma heurística — revise antes de confirmar com bump-version.sh)"

#!/bin/bash
# deploy-completo.sh — publica o trabalho do ambiente de dev (192.168.1.100) no GitHub,
# do jeito completo: sincroniza `develop`, commita o que estiver pendente, sobe para
# `develop` e promove para `main`. Pensado para ser rodado direto pelo usuário, sem
# depender de IA (Claude/Codex) para o passo de "publicar".
#
# Depois de rodar este script aqui, o outro passo é rodar
# scripts/bash/deploy-producao.sh NA VPS (161.97.93.120) para atualizar produção de
# fato — esse script continua igual, sem mudanças.
#
# A mensagem do commit é lida automaticamente do topo do CHANGELOG.md (tier +
# descrição) — ou seja, rode scripts/versionar.sh ANTES deste, sempre que a mudança
# merecer entrada no changelog (ver VERSIONING.md).
#
# Ver documentacao/10-deploy/workflow-git-multiambiente.md para o fluxo completo.

set -euo pipefail

REPO_ROOT="/var/www/sistema-erp"

if [ "$(pwd)" != "$REPO_ROOT" ] && [ -d "$REPO_ROOT" ]; then
  cd "$REPO_ROOT"
fi

if [ ! -d .git ]; then
  echo "ERRO: $REPO_ROOT não é um repositório git." >&2
  exit 1
fi

echo ">>> [1/6] Sincronizando develop (fast-forward apenas)"
git fetch origin
git checkout develop
git pull --ff-only origin develop

if [[ -n "$(git status --porcelain)" ]]; then
  echo ""
  echo ">>> [2/6] Alterações pendentes encontradas"
  echo "--- git status -s ---"
  git status -s
  echo "--- git diff --stat ---"
  git diff --stat

  RISKY=$(git status --porcelain \
    | awk '{print $2}' \
    | grep -E '(^|/)\.env($|\.)|\.pem$|\.key$|id_rsa|credentials\.json$' \
    | grep -v -E '(^|/)\.env(\.[A-Za-z0-9_-]+)*\.example$' \
    || true)
  if [[ -n "$RISKY" ]]; then
    echo "" >&2
    echo "ERRO: os arquivos abaixo parecem sensíveis e não serão commitados automaticamente:" >&2
    echo "$RISKY" >&2
    echo "Revise manualmente (git add nos arquivos certos, git commit) e rode este script de novo." >&2
    exit 1
  fi

  # Mensagem do commit: extraída do topo do CHANGELOG.md (entrada mais recente).
  TOP_BLOCK=$(awk '/^## v/{n++} n==1' CHANGELOG.md 2>/dev/null || true)
  TOP_VERSION=$(echo "$TOP_BLOCK" | grep -m1 '^## v' | sed -E 's/^## v([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+).*/\1/' || true)
  TOP_TIER=$(echo "$TOP_BLOCK" | grep -m1 -oP '(?<=\*\*Tier:\*\* ).*' || true)
  TOP_DESC=$(echo "$TOP_BLOCK" | grep -m1 -oP '(?<=\*\*Descrição:\*\* ).*' || true)

  if [[ -z "$TOP_DESC" ]]; then
    echo "" >&2
    echo "AVISO: não consegui ler uma descrição no topo do CHANGELOG.md." >&2
    echo "Rode scripts/versionar.sh antes deste script para ter uma mensagem de commit decente." >&2
    read -r -p "Descrição para o commit (obrigatória): " TOP_DESC
    [[ -z "$TOP_DESC" ]] && { echo "Cancelado — nada foi commitado." >&2; exit 1; }
  fi

  CURRENT_VERSION=$(cat VERSION 2>/dev/null || echo "")
  if [[ -n "$CURRENT_VERSION" && -n "$TOP_VERSION" && "$CURRENT_VERSION" != "$TOP_VERSION" ]]; then
    echo "" >&2
    echo "AVISO: VERSION ($CURRENT_VERSION) não bate com o topo do CHANGELOG.md (v$TOP_VERSION)." >&2
    echo "Confira se esqueceu de rodar scripts/versionar.sh antes." >&2
  fi

  COMMIT_MSG="$TOP_DESC"
  if [[ -n "$TOP_VERSION" ]]; then
    COMMIT_MSG="$COMMIT_MSG

v$TOP_VERSION${TOP_TIER:+ ($TOP_TIER)} — commit gerado por scripts/bash/deploy-completo.sh"
  fi

  echo ""
  echo "--- Mensagem do commit ---"
  echo "$COMMIT_MSG"
  echo "--------------------------"
  read -r -p "Confirma commit + push + promoção para main? [s/N]: " CONFIRM
  if [[ ! "$CONFIRM" =~ ^[sS]$ ]]; then
    echo "Cancelado — nada foi alterado."
    exit 1
  fi

  git add -A
  git commit -m "$COMMIT_MSG"
else
  echo ">>> [2/6] Nada pendente para commitar (working tree limpa)"
fi

echo ">>> [3/6] Publicando develop no GitHub"
git push origin develop

echo ">>> [4/6] Promovendo develop para main"

# Lidos de `develop` (via git show), não do working tree depois do checkout —
# depois do "git checkout main" o working tree ainda é o de main até o merge
# de fato acontecer, então ler VERSION/CHANGELOG.md direto do disco aqui
# pegaria a versão ANTIGA de main, não a nova que está vindo de develop.
MERGE_VERSION=$(git show develop:VERSION 2>/dev/null || echo "")
MERGE_DESC=$(git show develop:CHANGELOG.md 2>/dev/null | awk '/^## v/{n++} n==1' | grep -m1 -oP '(?<=\*\*Descrição:\*\* ).*' || true)

git checkout main
git pull --ff-only origin main

MERGE_MSG="merge: promove develop para main"
if [[ -n "$MERGE_VERSION" ]]; then
  MERGE_MSG="merge: promove develop para main (v$MERGE_VERSION${MERGE_DESC:+ — $MERGE_DESC})"
fi

if ! git merge --no-ff develop -m "$MERGE_MSG"; then
  echo "" >&2
  echo "ERRO: conflito ao promover develop para main." >&2
  echo "Resolva os conflitos e rode 'git commit' para concluir o merge," >&2
  echo "ou rode 'git merge --abort' para desistir da promoção agora." >&2
  exit 1
fi

echo ">>> [5/6] Publicando main no GitHub"
git push origin main

echo ">>> [6/6] Voltando para develop"
git checkout develop

echo ""
echo "DEPLOY_COMPLETO_OK (main em $(git rev-parse --short main), v${MERGE_VERSION:-?})"
echo "Agora rode ./scripts/bash/deploy-producao.sh na VPS para publicar em produção."

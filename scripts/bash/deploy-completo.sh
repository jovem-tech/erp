#!/bin/bash
# deploy-completo.sh — publica o trabalho do ambiente de dev (192.168.1.100)
# no GitHub, sincroniza `develop` e promove a versao validada para `main`.
#
# Este script roda NA BANCADA. Depois dele, execute
# scripts/bash/deploy-producao.sh NA VPS (161.97.93.120). O segundo script
# atualiza backend, desktop e PWA mobile com release atomico e rollback.
#
# A mensagem do commit e lida do topo do CHANGELOG.md. Rode
# scripts/versionar.sh antes quando a mudanca merecer uma nova versao.
#
# Ver documentacao/10-deploy/workflow-git-multiambiente.md.

set -Eeuo pipefail

readonly REPO_ROOT="/var/www/sistema-erp"
readonly MOBILE_DIR="${REPO_ROOT}/frontends/mobile"
readonly PNPM_VERSION="10.15.0"

# Caminhos que o proprio sistema regera em runtime. Nunca podem ser
# versionados: o `git add -A` da etapa 2 pega tudo que nao esta no .gitignore,
# e o estrago so aparece na etapa 5 — o arquivo volta a existir no disco (dono
# www-data) e o git recusa a promocao com "untracked working tree files would
# be overwritten by merge".
readonly RUNTIME_ARTIFACT_PATTERNS='^backend/storage/fonts/|^backend/storage/framework/|^backend/bootstrap/cache/|^frontends/[^/]+/\.next/|\.tsbuildinfo$'

MOBILE_VALIDATED=0
# A bancada nunca pode ficar parada em main: o proximo dia de trabalho
# commitaria no lugar errado. ON_MAIN liga entre o checkout de main e a volta
# para develop; KEEP_ON_MAIN so liga quando ha merge em andamento para
# resolver, unico caso em que ficar em main e' correto.
ON_MAIN=0
KEEP_ON_MAIN=0

voltar_para_develop_se_preciso() {
  [[ "$ON_MAIN" -eq 1 ]] || return 0
  [[ "$KEEP_ON_MAIN" -eq 0 ]] || return 0

  printf '\n>>> Devolvendo a bancada para develop\n' >&2
  git checkout develop >&2 && return 0

  # O mesmo arquivo nao rastreado que barra o merge barra o checkout. Nao cabe
  # a um script de deploy apagar arquivo do disco por conta propria, entao aqui
  # so' avisa — o que nao pode e' a bancada ficar em main sem o usuario saber.
  printf 'AVISO: a bancada ficou em MAIN — resolva o que o git apontou acima e\n' >&2
  printf 'rode: git checkout develop\n' >&2
}

trap voltar_para_develop_se_preciso EXIT

require_command() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'ERRO: comando obrigatorio ausente: %s\n' "$1" >&2
    exit 1
  }
}

validate_mobile_assets() {
  local html
  local asset
  local response
  local status
  local content_type
  local -a assets=()

  html="$(
    curl \
      --fail \
      --insecure \
      --silent \
      --show-error \
      --max-time 10 \
      "https://127.0.0.1:8444/os"
  )"

  mapfile -t assets < <(
    grep -oE '(src|href)="(/_next/static/[^"]+\.(js|css))"' <<< "$html" \
      | sed -E 's/^(src|href)="([^"]+)"$/\2/' \
      | sort -u
  )

  [[ "${#assets[@]}" -gt 0 ]] || {
    printf 'ERRO: o HTML do mobile nao referenciou assets Next.js para validacao.\n' >&2
    return 1
  }

  for asset in "${assets[@]}"; do
    response="$(
      curl \
        --insecure \
        --silent \
        --show-error \
        --output /dev/null \
        --max-time 10 \
        --write-out '%{http_code} %{content_type}' \
        "https://127.0.0.1:8444${asset}"
    )"
    read -r status content_type <<< "$response"

    [[ "$status" == "200" ]] || {
      printf 'ERRO: asset mobile retornou HTTP %s: %s\n' "$status" "$asset" >&2
      return 1
    }

    case "$asset" in
      *.js)
        [[ "$content_type" == *javascript* ]] || {
          printf 'ERRO: chunk JS retornou MIME inesperado (%s): %s\n' \
            "$content_type" "$asset" >&2
          return 1
        }
        ;;
      *.css)
        [[ "$content_type" == text/css* ]] || {
          printf 'ERRO: asset CSS retornou MIME inesperado (%s): %s\n' \
            "$content_type" "$asset" >&2
          return 1
        }
        ;;
    esac
  done
}

restart_mobile_dev() {
  printf '\n>>> Reiniciando o PWA mobile com o build validado\n'
  sudo supervisorctl restart sistema-erp-mobile

  for attempt in {1..15}; do
    if curl \
      --fail \
      --insecure \
      --silent \
      --show-error \
      --max-time 5 \
      "https://127.0.0.1:8444/login" \
      >/dev/null; then
      validate_mobile_assets
      printf 'PWA_MOBILE_DEV_OK (tentativa %s)\n' "$attempt"
      return 0
    fi

    sleep 1
  done

  printf 'ERRO: o PWA mobile nao ficou saudavel apos o restart.\n' >&2
  sudo supervisorctl status sistema-erp-mobile >&2 || true
  return 1
}

validate_mobile() {
  printf '\n>>> [3/7] Validando PWA mobile antes do push\n'

  require_command node
  require_command corepack
  require_command curl
  require_command sudo

  cd "$MOBILE_DIR"
  corepack "pnpm@${PNPM_VERSION}" install --frozen-lockfile
  corepack "pnpm@${PNPM_VERSION}" test
  NODE_ENV=production corepack "pnpm@${PNPM_VERSION}" build

  [[ -s .next/BUILD_ID ]] || {
    printf 'ERRO: build mobile nao gerou .next/BUILD_ID.\n' >&2
    exit 1
  }

  restart_mobile_dev

  cd "$REPO_ROOT"
}

if [[ "$(pwd)" != "$REPO_ROOT" && -d "$REPO_ROOT" ]]; then
  cd "$REPO_ROOT"
fi

[[ -d .git ]] || {
  printf 'ERRO: %s nao e um repositorio Git.\n' "$REPO_ROOT" >&2
  exit 1
}

printf '>>> [1/7] Sincronizando develop (fast-forward apenas)\n'
git fetch origin
git checkout develop
git pull --ff-only origin develop

if [[ -n "$(git status --porcelain)" ]]; then
  printf '\n>>> [2/7] Alteracoes pendentes encontradas\n'
  printf '%s\n' '--- git status -s ---'
  git status -s
  printf '%s\n' '--- git diff --stat ---'
  git diff --stat

  RISKY="$(
    git status --porcelain \
      | awk '{print $2}' \
      | grep -E '(^|/)\.env($|\.)|\.pem$|\.key$|id_rsa|credentials\.json$' \
      | grep -v -E '(^|/)\.env(\.[A-Za-z0-9_-]+)*\.example$' \
      || true
  )"

  if [[ -n "$RISKY" ]]; then
    printf '\nERRO: arquivos potencialmente sensiveis nao serao commitados:\n' >&2
    printf '%s\n' "$RISKY" >&2
    printf 'Revise os arquivos e faca o commit manualmente.\n' >&2
    exit 1
  fi

  # So arquivos NOVOS (nao rastreados): o que ja esta versionado foi decisao de
  # alguem e nao cabe a este script desfazer no meio de um deploy.
  ARTIFACTS="$(
    git status --porcelain \
      | sed -n 's/^?? //p' \
      | grep -E "$RUNTIME_ARTIFACT_PATTERNS" \
      || true
  )"

  if [[ -n "$ARTIFACTS" ]]; then
    printf '\nERRO: artefatos gerados em runtime apareceram como arquivos novos:\n' >&2
    printf '%s\n' "$ARTIFACTS" >&2
    printf 'Eles nao podem ser versionados. Acrescente ao .gitignore e, se ja\n' >&2
    printf 'estiverem no indice, rode git rm -r --cached <caminho>. Depois repita.\n' >&2
    exit 1
  fi

  TOP_BLOCK="$(awk '/^## v/{n++} n==1' CHANGELOG.md 2>/dev/null || true)"
  TOP_VERSION="$(
    printf '%s\n' "$TOP_BLOCK" \
      | grep -m1 '^## v' \
      | sed -E 's/^## v([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+).*/\1/' \
      || true
  )"
  TOP_TIER="$(
    printf '%s\n' "$TOP_BLOCK" \
      | grep -m1 -oP '(?<=\*\*Tier:\*\* ).*' \
      || true
  )"
  TOP_DESC="$(
    printf '%s\n' "$TOP_BLOCK" \
      | grep -m1 -oP '(?<=\*\*Descricao:\*\* ).*' \
      || true
  )"

  # Compatibilidade com changelogs antigos que usam "Descricao" acentuada.
  if [[ -z "$TOP_DESC" ]]; then
    TOP_DESC="$(
      printf '%s\n' "$TOP_BLOCK" \
        | grep -m1 -oP '(?<=\*\*Descrição:\*\* ).*' \
        || true
    )"
  fi

  if [[ -z "$TOP_DESC" ]]; then
    printf '\nAVISO: nao foi encontrada uma descricao no topo do CHANGELOG.md.\n' >&2
    printf 'Rode scripts/versionar.sh antes para gerar a entrada de versao.\n' >&2
    read -r -p "Descricao para o commit (obrigatoria): " TOP_DESC
    [[ -n "$TOP_DESC" ]] || {
      printf 'Cancelado — nada foi commitado.\n' >&2
      exit 1
    }
  fi

  CURRENT_VERSION="$(cat VERSION 2>/dev/null || true)"
  if [[ -n "$CURRENT_VERSION" && -n "$TOP_VERSION" && "$CURRENT_VERSION" != "$TOP_VERSION" ]]; then
    printf '\nAVISO: VERSION (%s) nao bate com o CHANGELOG (v%s).\n' \
      "$CURRENT_VERSION" "$TOP_VERSION" >&2
  fi

  COMMIT_MSG="$TOP_DESC"
  if [[ -n "$TOP_VERSION" ]]; then
    COMMIT_MSG="${COMMIT_MSG}

v${TOP_VERSION}${TOP_TIER:+ (${TOP_TIER})} — commit gerado por scripts/bash/deploy-completo.sh"
  fi

  printf '\n%s\n' '--- Mensagem do commit ---'
  printf '%s\n' "$COMMIT_MSG"
  printf '%s\n' '--------------------------'
  read -r -p "Confirma commit + validacao + push + promocao para main? [s/N]: " CONFIRM

  if [[ ! "$CONFIRM" =~ ^[sS]$ ]]; then
    printf 'Cancelado — nada foi alterado.\n'
    exit 1
  fi

  validate_mobile
  MOBILE_VALIDATED=1

  git add -A
  git diff --cached --check
  git commit -m "$COMMIT_MSG"
else
  printf '>>> [2/7] Nada pendente para commitar (working tree limpa)\n'
fi

if [[ "$MOBILE_VALIDATED" -eq 0 ]]; then
  validate_mobile
fi

printf '\n>>> [4/7] Publicando develop no GitHub\n'
git push origin develop

printf '>>> [5/7] Promovendo develop para main\n'

MERGE_VERSION="$(git show develop:VERSION 2>/dev/null || true)"
MERGE_TOP_BLOCK="$(
  git show develop:CHANGELOG.md 2>/dev/null \
    | awk '/^## v/{n++} n==1' \
    || true
)"
# Mesma tolerancia de acento da mensagem de commit: changelog antigo escreve
# "Descrição", o bump-version.sh atual escreve "Descricao".
MERGE_DESC="$(
  printf '%s\n' "$MERGE_TOP_BLOCK" \
    | grep -m1 -oP '(?<=\*\*Descri(c|ç)(a|ã)o:\*\* ).*' \
    || true
)"

git checkout main
ON_MAIN=1
git pull --ff-only origin main

MERGE_MSG="merge: promove develop para main"
if [[ -n "$MERGE_VERSION" ]]; then
  MERGE_MSG="merge: promove develop para main (v${MERGE_VERSION}${MERGE_DESC:+ — ${MERGE_DESC}})"
fi

if ! git merge --no-ff develop -m "$MERGE_MSG"; then
  # Duas falhas bem diferentes caem aqui. Com MERGE_HEAD existe merge em
  # andamento (conflito de conteudo) e a bancada precisa continuar em main ate
  # o usuario resolver. Sem MERGE_HEAD o merge foi recusado antes de comecar —
  # tipicamente porque um arquivo gerado em runtime existe no disco e foi
  # versionado em develop — e nada foi alterado: "git merge --abort" nem
  # funciona, e deixar a bancada em main so cria o proximo problema.
  if git rev-parse -q --verify MERGE_HEAD >/dev/null; then
    KEEP_ON_MAIN=1
    printf '\nERRO: conflito de conteudo ao promover develop para main.\n' >&2
    printf 'Resolva os arquivos e conclua com git commit, ou desfaca com\n' >&2
    printf 'git merge --abort. A bancada ficou em main: volte para develop\n' >&2
    printf 'com git checkout develop quando terminar.\n' >&2
    exit 1
  fi

  printf '\nERRO: o merge nao chegou a comecar — main continua intacta.\n' >&2
  printf 'Causa tipica (veja a mensagem do git acima): o arquivo apontado e gerado\n' >&2
  printf 'em runtime, existe no disco e foi versionado em develop por engano.\n' >&2
  printf 'Receita, com CAMINHO = o arquivo/pasta que o git apontou:\n' >&2
  printf '  1. mv CAMINHO CAMINHO.tmp        (.tmp ja e ignorado pelo .gitignore)\n' >&2
  printf '  2. git checkout develop\n' >&2
  printf '  3. echo CAMINHO/ >> .gitignore && git rm -r --cached CAMINHO\n' >&2
  printf '  4. rm -rf CAMINHO && mv CAMINHO.tmp CAMINHO   (devolve o dono original)\n' >&2
  printf '  5. rode este script de novo\n' >&2
  exit 1
fi

printf '>>> [6/7] Publicando main no GitHub\n'
git push origin main

printf '>>> [7/7] Voltando para develop\n'
git checkout develop
ON_MAIN=0

printf '\nDEPLOY_COMPLETO_OK (main em %s, v%s)\n' \
  "$(git rev-parse --short main)" \
  "${MERGE_VERSION:-?}"
printf 'Agora rode ./scripts/bash/deploy-producao.sh na VPS.\n'

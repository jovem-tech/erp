#!/bin/bash
# deploy-producao.sh — atualiza a VPS de producao a partir da branch `main`.
#
# Deve ser executado como root NA VPS (161.97.93.120), dentro de
# /var/www/sistema-erp. O deploy:
#   1. impede execucoes concorrentes e cria backup consistente do banco;
#   2. atualiza `main` apenas por fast-forward;
#   3. testa e compila o PWA mobile em um release imutavel;
#   4. atualiza backend e desktop;
#   5. ativa o PWA por troca atomica de symlink;
#   6. executa health checks e faz rollback automatico do PWA se necessario.
#
# Ver documentacao/10-deploy/workflow-git-multiambiente.md e
# documentacao/10-deploy/deploy-producao-contabo-vps.md.

set -Eeuo pipefail
umask 027

readonly REPO_ROOT="/var/www/sistema-erp"
readonly BACKUP_DIR="/var/backups/sistema-erp"
readonly DEPLOY_LOCK="/var/lock/sistema-erp-deploy.lock"

readonly MOBILE_ROOT="/var/www/sistema-erp-mobile"
readonly MOBILE_RELEASES_DIR="${MOBILE_ROOT}/releases"
readonly MOBILE_CURRENT="${MOBILE_ROOT}/current"
readonly MOBILE_ENV_FILE="${REPO_ROOT}/frontends/mobile/.env"
readonly MOBILE_SUPERVISOR_SOURCE="${REPO_ROOT}/infra/linux/supervisor-mobile-vps.conf"
readonly MOBILE_SUPERVISOR_TARGET="/etc/supervisor/conf.d/sistema-erp-mobile.conf"
readonly MOBILE_CACHE_ROOT="/var/cache/sistema-erp/mobile"
readonly MOBILE_LOG_DIR="/var/log/sistema-erp-mobile"
readonly MOBILE_LOCAL_HEALTH_URL="http://127.0.0.1:3100/login"
readonly MOBILE_PUBLIC_HEALTH_URL="https://app.jovemtech.eco.br/login"
readonly MOBILE_KEEP_RELEASES=5
readonly PNPM_VERSION="10.15.0"

readonly STAMP="$(date +%F_%H%M%S)"

MOBILE_NEW_RELEASE=""
MOBILE_PREVIOUS_RELEASE=""
MOBILE_ACTIVATED=0
MYSQL_CREDENTIALS_FILE=""

log() {
  printf '\n>>> %s\n' "$1"
}

die() {
  printf 'ERRO: %s\n' "$1" >&2
  return 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "comando obrigatorio ausente: $1"
}

is_managed_release() {
  local target="${1:-}"
  [[ -n "$target" && "$target" == "${MOBILE_RELEASES_DIR}/"* ]]
}

remove_release_safely() {
  local target="${1:-}"

  is_managed_release "$target" || {
    printf 'AVISO: recusando remover release fora de %s: %s\n' \
      "$MOBILE_RELEASES_DIR" "$target" >&2
    return 1
  }

  rm -rf -- "$target"
}

switch_mobile_release() {
  local target="$1"
  local temporary_link="${MOBILE_CURRENT}.next"

  is_managed_release "$target" || die "release mobile invalido: $target"
  [[ -d "$target" ]] || die "release mobile inexistente: $target"

  rm -f -- "$temporary_link"
  ln -s "$target" "$temporary_link"
  mv -Tf -- "$temporary_link" "$MOBILE_CURRENT"
}

cleanup_mysql_credentials() {
  if [[ -n "$MYSQL_CREDENTIALS_FILE" && -f "$MYSQL_CREDENTIALS_FILE" ]]; then
    rm -f -- "$MYSQL_CREDENTIALS_FILE"
  fi
}

rollback_mobile() {
  printf 'AVISO: revertendo ativacao do PWA mobile.\n' >&2

  if is_managed_release "$MOBILE_PREVIOUS_RELEASE" && [[ -d "$MOBILE_PREVIOUS_RELEASE" ]]; then
    switch_mobile_release "$MOBILE_PREVIOUS_RELEASE"
    supervisorctl restart sistema-erp-mobile >/dev/null 2>&1 || true
  else
    rm -f -- "$MOBILE_CURRENT"
    supervisorctl stop sistema-erp-mobile >/dev/null 2>&1 || true
  fi

  if is_managed_release "$MOBILE_NEW_RELEASE" && [[ -d "$MOBILE_NEW_RELEASE" ]]; then
    remove_release_safely "$MOBILE_NEW_RELEASE" || true
  fi

  MOBILE_ACTIVATED=0
}

handle_error() {
  local exit_code=$?
  local failed_line="${BASH_LINENO[0]:-desconhecida}"

  trap - ERR
  set +e

  printf '\nDEPLOY_FALHOU (codigo=%s, linha=%s)\n' "$exit_code" "$failed_line" >&2

  if [[ "$MOBILE_ACTIVATED" -eq 1 ]]; then
    rollback_mobile
  elif is_managed_release "$MOBILE_NEW_RELEASE" && [[ -d "$MOBILE_NEW_RELEASE" ]]; then
    remove_release_safely "$MOBILE_NEW_RELEASE" || true
  fi

  exit "$exit_code"
}

run_mobile_pnpm() {
  runuser -u www-data -- env \
    HOME="$MOBILE_CACHE_ROOT" \
    COREPACK_HOME="${MOBILE_CACHE_ROOT}/corepack" \
    XDG_CACHE_HOME="${MOBILE_CACHE_ROOT}/cache" \
    corepack "pnpm@${PNPM_VERSION}" "$@"
}

wait_for_mobile_health() {
  local attempt

  for attempt in $(seq 1 15); do
    if curl --fail --silent --show-error --max-time 5 \
      "$MOBILE_LOCAL_HEALTH_URL" >/dev/null; then
      return 0
    fi

    sleep 2
  done

  printf 'ERRO: mobile nao ficou saudavel em %s.\n' "$MOBILE_LOCAL_HEALTH_URL" >&2
  return 1
}

cleanup_old_mobile_releases() {
  local current_release
  local index
  local candidate
  local -a releases=()

  current_release="$(readlink -f "$MOBILE_CURRENT" 2>/dev/null || true)"

  mapfile -t releases < <(
    find "$MOBILE_RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
      | sort -nr \
      | sed 's/^[^ ]* //'
  )

  for ((index = MOBILE_KEEP_RELEASES; index < ${#releases[@]}; index += 1)); do
    candidate="${releases[$index]}"

    if [[ "$candidate" != "$current_release" ]] && is_managed_release "$candidate"; then
      remove_release_safely "$candidate"
    fi
  done
}

trap handle_error ERR
trap cleanup_mysql_credentials EXIT

[[ "$(id -u)" -eq 0 ]] || die "execute este script como root na VPS"

for required_command in \
  flock git mysqldump gzip composer php npm node corepack curl tar \
  runuser supervisorctl systemctl install find grep mktemp readlink sed seq sort; do
  require_command "$required_command"
done

exec 9>"$DEPLOY_LOCK"
flock -n 9 || die "ja existe outro deploy de producao em andamento"

cd "$REPO_ROOT"
[[ -d .git ]] || die "$REPO_ROOT nao e um repositorio Git"

if [[ -n "$(git status --porcelain --untracked-files=normal)" ]]; then
  git status --short >&2
  die "a arvore de trabalho da VPS precisa estar limpa antes do deploy"
fi

log "[1/7] Backup do banco antes do deploy"
install -d -o root -g root -m 0700 "$BACKUP_DIR"

DB_PASSWORD_LINE="$(grep -m1 '^DB_PASSWORD=' backend/.env || true)"
[[ -n "$DB_PASSWORD_LINE" ]] || die "DB_PASSWORD nao encontrado em backend/.env"
DB_PASSWORD_VALUE="${DB_PASSWORD_LINE#DB_PASSWORD=}"

case "$DB_PASSWORD_VALUE" in
  \"*\") DB_PASSWORD_VALUE="${DB_PASSWORD_VALUE:1:${#DB_PASSWORD_VALUE}-2}" ;;
  \'*\') DB_PASSWORD_VALUE="${DB_PASSWORD_VALUE:1:${#DB_PASSWORD_VALUE}-2}" ;;
esac

DB_PASSWORD_VALUE="${DB_PASSWORD_VALUE//\\/\\\\}"
DB_PASSWORD_VALUE="${DB_PASSWORD_VALUE//\"/\\\"}"

MYSQL_CREDENTIALS_FILE="$(mktemp /root/.sistema-erp-mysql.XXXXXX)"
chmod 0600 "$MYSQL_CREDENTIALS_FILE"
printf '[client]\nuser=erp_app\npassword="%s"\n' "$DB_PASSWORD_VALUE" \
  > "$MYSQL_CREDENTIALS_FILE"
unset DB_PASSWORD_LINE DB_PASSWORD_VALUE

mysqldump \
  --defaults-extra-file="$MYSQL_CREDENTIALS_FILE" \
  --single-transaction \
  --routines \
  --triggers \
  --no-tablespaces \
  sistema_hml \
  | gzip > "${BACKUP_DIR}/pre-deploy-${STAMP}.sql.gz"

gzip -t "${BACKUP_DIR}/pre-deploy-${STAMP}.sql.gz"
cleanup_mysql_credentials
MYSQL_CREDENTIALS_FILE=""
printf 'backup ok: %s\n' "${BACKUP_DIR}/pre-deploy-${STAMP}.sql.gz"

log "[2/7] Atualizando codigo (main, fast-forward apenas)"
git fetch origin
git checkout main
git pull --ff-only origin main

[[ -f "$MOBILE_ENV_FILE" ]] || die "arquivo de ambiente mobile ausente: $MOBILE_ENV_FILE"
[[ -f "$MOBILE_SUPERVISOR_SOURCE" ]] \
  || die "configuracao Supervisor mobile ausente: $MOBILE_SUPERVISOR_SOURCE"

grep -Eq '^NEXT_PUBLIC_API_BASE_URL="?https://api-erp\.jovemtech\.eco\.br/api/v1"?$' \
  "$MOBILE_ENV_FILE" \
  || die "NEXT_PUBLIC_API_BASE_URL do mobile nao aponta para a API de producao"

grep -Eq '^NEXT_PUBLIC_APP_URL="?https://app\.jovemtech\.eco\.br"?$' \
  "$MOBILE_ENV_FILE" \
  || die "NEXT_PUBLIC_APP_URL do mobile nao aponta para o dominio de producao"

COMMIT_SHA="$(git rev-parse --short=12 HEAD)"
MOBILE_NEW_RELEASE="${MOBILE_RELEASES_DIR}/${STAMP}_${COMMIT_SHA}"

log "[3/7] Testando e compilando PWA mobile"
install -d -o root -g root -m 0755 "$MOBILE_ROOT"
install -d -o www-data -g www-data -m 0750 \
  "$MOBILE_RELEASES_DIR" \
  "${MOBILE_CACHE_ROOT}/corepack" \
  "${MOBILE_CACHE_ROOT}/pnpm-store" \
  "${MOBILE_CACHE_ROOT}/cache" \
  "$MOBILE_LOG_DIR"

[[ ! -e "$MOBILE_NEW_RELEASE" ]] || die "release mobile ja existe: $MOBILE_NEW_RELEASE"
install -d -o www-data -g www-data -m 0750 "$MOBILE_NEW_RELEASE"

git archive --format=tar HEAD frontends/mobile \
  | tar -x -C "$MOBILE_NEW_RELEASE" --strip-components=2

install -o www-data -g www-data -m 0640 \
  "$MOBILE_ENV_FILE" \
  "${MOBILE_NEW_RELEASE}/.env"

grep -q '^const CACHE_VERSION = ' "${MOBILE_NEW_RELEASE}/public/sw.js" \
  || die "CACHE_VERSION nao encontrado no service worker"

sed -i \
  "s/^const CACHE_VERSION = .*;$/const CACHE_VERSION = 'sistema-erp-mobile-${COMMIT_SHA}';/" \
  "${MOBILE_NEW_RELEASE}/public/sw.js"

grep -Fq "const CACHE_VERSION = 'sistema-erp-mobile-${COMMIT_SHA}';" \
  "${MOBILE_NEW_RELEASE}/public/sw.js" \
  || die "nao foi possivel versionar o cache do PWA"

chown -R www-data:www-data "$MOBILE_NEW_RELEASE"

cd "$MOBILE_NEW_RELEASE"
run_mobile_pnpm install \
  --frozen-lockfile \
  --store-dir "${MOBILE_CACHE_ROOT}/pnpm-store"
run_mobile_pnpm test
NODE_ENV=production run_mobile_pnpm build
[[ -s .next/BUILD_ID ]] || die "build mobile nao gerou .next/BUILD_ID"

log "[4/7] Backend"
cd "${REPO_ROOT}/backend"
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
runuser -u www-data -- php artisan view:cache

log "[5/7] Desktop"
cd "${REPO_ROOT}/frontends/desktop"
composer install --no-dev --optimize-autoloader
if [[ -f package-lock.json ]]; then
  npm ci
fi
npm run build
php artisan migrate --force
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
runuser -u www-data -- php artisan view:cache

log "[6/7] Ativando servicos"
systemctl reload php8.3-fpm
supervisorctl restart 'erp-queue-worker:*'
supervisorctl restart erp-reverb

install -o root -g root -m 0644 \
  "$MOBILE_SUPERVISOR_SOURCE" \
  "$MOBILE_SUPERVISOR_TARGET"

MOBILE_PREVIOUS_RELEASE="$(readlink -f "$MOBILE_CURRENT" 2>/dev/null || true)"
switch_mobile_release "$MOBILE_NEW_RELEASE"
MOBILE_ACTIVATED=1

supervisorctl reread
supervisorctl update
supervisorctl restart sistema-erp-mobile

log "[7/7] Health checks e limpeza"
wait_for_mobile_health

curl \
  --fail \
  --silent \
  --show-error \
  --max-time 10 \
  --retry 5 \
  --retry-delay 2 \
  --retry-all-errors \
  "$MOBILE_PUBLIC_HEALTH_URL" \
  >/dev/null

supervisorctl status sistema-erp-mobile | grep -q 'RUNNING'

MOBILE_ACTIVATED=0
MOBILE_NEW_RELEASE=""
cleanup_old_mobile_releases

cd "$REPO_ROOT"
printf '\nDEPLOY_OK (%s, PWA=%s)\n' \
  "$(git rev-parse --short HEAD)" \
  "$(readlink -f "$MOBILE_CURRENT")"

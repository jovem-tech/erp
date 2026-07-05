#!/bin/bash
# deploy-producao.sh — atualiza a VPS de producao a partir da branch `main`.
#
# So deve ser executado NA VPS (161.97.93.120), dentro de /var/www/sistema-erp.
# Segue a mesma disciplina do fluxo antigo (backup sempre antes de atualizar):
#   1. backup do banco
#   2. git fetch + checkout main + pull --ff-only (nunca merge/rebase automatico)
#   3. composer install + migrate + cache rebuild (backend e desktop)
#   4. reinicia PHP-FPM e Supervisor (queue workers + Reverb)
#
# Ver documentacao/10-deploy/workflow-git-multiambiente.md para o fluxo completo
# (develop -> main -> VPS) e documentacao/10-deploy/deploy-producao-contabo-vps.md
# para o runbook detalhado desta VPS especificamente.

set -euo pipefail

REPO_ROOT="/var/www/sistema-erp"
BACKUP_DIR="/var/backups/sistema-erp"
STAMP=$(date +%F_%H%M)

if [ "$(pwd)" != "$REPO_ROOT" ] && [ -d "$REPO_ROOT" ]; then
  cd "$REPO_ROOT"
fi

if [ ! -d .git ]; then
  echo "ERRO: $REPO_ROOT nao e um repositorio git. Rode a migracao para clone git antes." >&2
  exit 1
fi

echo ">>> [1/5] Backup do banco antes do deploy"
mkdir -p "$BACKUP_DIR"
DB_PASS=$(grep '^DB_PASSWORD=' backend/.env | cut -d= -f2)
mysqldump -u erp_app -p"$DB_PASS" --single-transaction --routines --triggers --no-tablespaces sistema_hml \
  | gzip > "$BACKUP_DIR/pre-deploy-$STAMP.sql.gz"
gzip -t "$BACKUP_DIR/pre-deploy-$STAMP.sql.gz"
echo "backup ok: $BACKUP_DIR/pre-deploy-$STAMP.sql.gz"

echo ">>> [2/5] Atualizando codigo (main, fast-forward apenas)"
git fetch origin
git checkout main
git pull --ff-only origin main

echo ">>> [3/5] Backend"
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan view:clear && php artisan view:cache

echo ">>> [4/5] Desktop"
cd ../frontends/desktop
composer install --no-dev --optimize-autoloader
if [ -f package-lock.json ]; then npm ci; fi
npm run build
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan view:clear && php artisan view:cache

echo ">>> [5/5] Reiniciando servicos"
sudo systemctl reload php8.3-fpm
sudo supervisorctl restart all

echo "DEPLOY_OK ($(git rev-parse --short HEAD))"

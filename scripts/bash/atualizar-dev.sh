#!/bin/bash
# atualizar-dev.sh — sincroniza o ambiente de desenvolvimento (192.168.1.100)
# com a branch `develop` do GitHub.
#
# So deve ser executado no servidor de dev, dentro de /var/www/sistema-erp.
# Mais leve que o deploy de producao (sem backup obrigatorio), mas agora tenta
# reciclar fila/supervisor quando disponiveis para que fluxos assincronos
# documentais entrem em operacao sem passo manual extra no dev.
#
# Ver documentacao/10-deploy/workflow-git-multiambiente.md.

set -euo pipefail

REPO_ROOT="/var/www/sistema-erp"

if [ "$(pwd)" != "$REPO_ROOT" ] && [ -d "$REPO_ROOT" ]; then
  cd "$REPO_ROOT"
fi

if [ ! -d .git ]; then
  echo "ERRO: $REPO_ROOT nao e um repositorio git." >&2
  exit 1
fi

echo ">>> Atualizando codigo (develop, fast-forward apenas)"
git fetch origin
git checkout develop
git pull --ff-only origin develop

echo ">>> Backend"
cd backend
composer install --optimize-autoloader
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan view:clear
# Laravel recompila views no PHP-FPM usando touch(timestamp); por isso os
# artefatos compilados devem ser criados pelo proprio usuario de runtime.
sudo -u www-data -- php artisan view:cache
php artisan queue:restart || true

echo ">>> Desktop"
cd ../frontends/desktop
composer install --optimize-autoloader
if [ -f package-lock.json ]; then npm ci; fi
npm run build
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan view:clear
sudo -u www-data -- php artisan view:cache

echo ">>> Reiniciando serviços de runtime"
sudo systemctl reload php8.5-fpm
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart all

sleep 2
SUPERVISOR_STATUS="$(sudo supervisorctl status)"
echo "$SUPERVISOR_STATUS"

if ! grep -Eq '^sistema-erp-queue-worker_.+[[:space:]]+RUNNING' <<< "$SUPERVISOR_STATUS"; then
  echo "ERRO: os workers de fila não ficaram RUNNING após a atualização." >&2
  exit 1
fi

echo "DEV_ATUALIZADO_OK ($(git rev-parse --short HEAD))"

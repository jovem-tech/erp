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

sudo systemctl reload php8.5-fpm 2>/dev/null || true
sudo supervisorctl restart all 2>/dev/null || true

echo "DEV_ATUALIZADO_OK ($(git rev-parse --short HEAD))"

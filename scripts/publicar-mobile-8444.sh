#!/usr/bin/env bash
# Publica o frontend mobile (Next.js) de forma persistente em
# https://192.168.1.100:8444, atras de Nginx + Supervisor.
#
# Pre-requisitos ja feitos (nao repetir):
# - frontends/mobile/.env apontando para https://192.168.1.100:8443/api/v1
# - backend/.env com CORS_ALLOWED_ORIGINS incluindo https://192.168.1.100:8444
#   (config ja recacheada)
# - pnpm install + pnpm build ja rodados em frontends/mobile
# - permissoes de frontends/mobile ajustadas para o grupo www-data
#
# Este script so contem os passos que exigem root. Rodar com sudo:
#   sudo bash scripts/publicar-mobile-8444.sh

set -euo pipefail

REPO=/var/www/sistema-erp

echo "==> Liberando porta 8444/tcp no UFW (idempotente)"
ufw allow 8444/tcp

echo "==> Instalando site do Nginx para o mobile"
cp "$REPO/infra/linux/nginx-mobile-site.conf" /etc/nginx/sites-available/sistema-erp-mobile.conf
ln -sf /etc/nginx/sites-available/sistema-erp-mobile.conf /etc/nginx/sites-enabled/sistema-erp-mobile.conf

echo "==> Validando e recarregando Nginx"
nginx -t
systemctl reload nginx

echo "==> Instalando processo Supervisor do mobile"
cp "$REPO/infra/linux/supervisor-mobile.conf" /etc/supervisor/conf.d/sistema-erp-mobile.conf
supervisorctl reread
supervisorctl update
supervisorctl status sistema-erp-mobile

echo "==> Pronto. Testando resposta local:"
curl -sk -o /dev/null -w 'HTTP %{http_code}\n' https://127.0.0.1:8444/login

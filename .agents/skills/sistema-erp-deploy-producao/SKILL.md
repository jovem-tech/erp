---
name: sistema-erp-deploy-producao
description: Deploy, atualizacao e diagnostico do Sistema ERP em servidor Ubuntu (LAN ou VPS). Use quando um agente de IA precisar instalar o sistema em servidor novo, publicar atualizacoes de codigo, importar/migrar banco entre MariaDB e MySQL, configurar TLS/Nginx/Supervisor/cron, ou diagnosticar erros de producao (500 em arquivos, CORS, conexao com backend central, filas paradas).
---

# Sistema ERP Deploy Producao

## Quick start

1. Ler `documentacao/10-deploy/deploy-producao-lan-ubuntu.md` (runbook completo com problemas reais mapeados).
2. Ler `references/problemas-conhecidos.md` desta skill antes de diagnosticar qualquer erro de producao.
3. Conferir `documentacao/02-infraestrutura-ambientes/linux-vps.md` para regras de ambiente.

## Fatos do ambiente de producao (deploy de referencia 2026-07-03/04)

- Servidor: Ubuntu Server 26.04 LTS em `192.168.1.100` (LAN), usuario `administrador`.
- Stack real: PHP **8.5** (nao 8.3 — o Ubuntu 26.04 nao empacota 8.3), MySQL 8.4, Redis 8 com senha, Nginx, Supervisor, Node 20.
- Backend em `https://<ip>` (root `/var/www/sistema-erp/backend/public`); desktop em `https://<ip>:8443`.
- TLS autoassinado em `/etc/nginx/ssl/` **e** registrado como CA do sistema (`update-ca-certificates`) para as chamadas server-to-server do desktop.
- Banco unico `sistema_hml` compartilhado com o legado — importar dump antes de `php artisan migrate`.
- Deploy executado como `administrador` (membro do grupo `www-data`); FPM roda como `www-data`.

## Regras mestras

1. Nunca rodar `migrate` em banco vazio: as migrations dependem das tabelas legadas (`usuarios`, `os`, ...).
2. Nunca expor storage privado; arquivos saem apenas por endpoint autenticado.
3. Toda mudanca de deploy/infra atualiza `documentacao/10-deploy/` e, se mudar regra de ambiente, `documentacao/02-infraestrutura-ambientes/linux-vps.md`.
4. Apos alterar `.env` ou codigo em producao: `php artisan config:clear && config:cache && route:cache && view:cache` e, se mudou extensao/ini de PHP, `systemctl restart php8.5-fpm`.
5. Registrar a entrega com `./scripts/bump-version.sh` conforme `VERSIONING.md`.

## Atualizacao de codigo em producao (fluxo curto)

1. Empacotar na origem excluindo `.git`, `vendor`, `node_modules`, caches.
2. `scp` + extrair em `/var/www/sistema-erp` (usar `sudo tar` se houver arquivos de `www-data`).
3. `composer install --no-dev` quando o `composer.lock` mudar; `npm run build` no desktop quando assets mudarem.
4. Limpar/reconstruir caches do Laravel (backend e desktop).
5. `sudo supervisorctl restart all` se codigo de fila/reverb mudou.
6. Rodar o checklist pos-deploy do runbook.

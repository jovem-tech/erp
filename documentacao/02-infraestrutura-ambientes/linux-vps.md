# Servidor Linux (Ubuntu) — LAN ou VPS

## Objetivo

Publicar o backend e os frontends de forma segura e previsível em um servidor
Ubuntu, mantendo o mesmo contrato funcional do ambiente local.

## Regras

- O servidor web deve apontar para `/var/www/sistema-erp/backend/public` (backend)
  e `/var/www/sistema-erp/frontends/desktop/public` (desktop).
- Os logs devem ficar em `backend/storage/logs`.
- Os arquivos privados devem ficar em `backend/storage/app/private`.
- O scheduler deve rodar por cron a cada minuto.
- As filas exigem worker dedicado via Supervisor (produção usa `QUEUE_CONNECTION=redis`).
- O processo Reverb (WebSocket) roda via Supervisor e é exposto por proxy no Nginx (`/app/`).
- O ambiente oficial de produção é servidor Linux Ubuntu (LAN interna ou VPS).

## Realidade de versões (aprendido no deploy de 2026-07-03)

- Ubuntu 26.04 empacota apenas **PHP 8.5** (`php8.5-*`); não existem pacotes `php8.3-*`.
  O requisito `"php": "^8.3"` do backend é atendido pelo 8.5.
- O socket do FPM é `/run/php/php8.5-fpm.sock` — os templates de Nginx referenciam
  8.3 e precisam de ajuste no deploy.
- `php8.5-sqlite3` é obrigatório para o frontend desktop (preferências em SQLite).
- MySQL 8.4 rejeita dumps de MariaDB que definem valores para colunas
  `GENERATED ALWAYS AS ... STORED` — ver correção no runbook de deploy.

## HTTPS

- O middleware `ForceHttps` do backend redireciona todo HTTP para HTTPS quando
  `APP_ENV=production`; o servidor **precisa** de TLS.
- Com domínio público: Let's Encrypt/Certbot.
- Em LAN sem domínio: certificado autoassinado no Nginx **e** registro do
  certificado como CA confiável do sistema (`update-ca-certificates` + restart do FPM),
  senão as chamadas server-to-server do desktop para a API falham na validação TLS.

## Variáveis obrigatórias em produção

- `CORS_ALLOWED_ORIGINS` — origens dos frontends (ex.: `https://<ip>:8443`); sem
  ela o CORS bloqueia `broadcasting/auth` e a API para orígens externas.
- `LEGACY_PUBLIC_PATH` — raiz pública do legado copiada para o servidor, usada
  como fallback de fotos de equipamentos importadas.
- `REDIS_PASSWORD` — Redis sempre com senha (`requirepass`).

## Templates

- `infra/linux/nginx-site.conf`
- `infra/linux/supervisor-queue-worker.conf`
- `infra/linux/supervisor-reverb.conf`
- `infra/linux/cron-scheduler.example`
- `scripts/bash/validate-prod-env.sh`

## Runbook completo

O passo a passo real e detalhado (com problemas e soluções) está em
[`documentacao/10-deploy/deploy-producao-lan-ubuntu.md`](../10-deploy/deploy-producao-lan-ubuntu.md).

## Observação de operação

Em produção não existe dependência de comportamento específico do Windows nem de
paths com letra de unidade. O usuário de deploy deve pertencer ao grupo `www-data`
para executar `artisan` sem sudo depois que o FPM cria arquivos em `storage/`.

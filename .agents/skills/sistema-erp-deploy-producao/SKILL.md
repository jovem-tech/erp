---
name: sistema-erp-deploy-producao
description: Deploy, atualizacao e diagnostico do Sistema ERP em servidor Ubuntu (LAN ou VPS). Use quando um agente de IA precisar instalar o sistema em servidor novo, publicar atualizacoes de codigo, importar/migrar banco entre MariaDB e MySQL, configurar TLS/Nginx/Supervisor/cron, ou diagnosticar erros de producao (500 em arquivos, CORS, conexao com backend central, filas paradas).
---

# Sistema ERP Deploy Producao

## Quick start

1. Ler `documentacao/10-deploy/workflow-git-multiambiente.md` — o deploy e' git-based
   desde 2026-07-05 (`git pull` de `main`), **nao mais** tar+scp.
2. Ler `documentacao/10-deploy/deploy-producao-contabo-vps.md` — runbook da producao
   real (VPS Contabo, subdominios `erp.`/`api-erp.jovemtech.eco.br`).
3. Ler `references/problemas-conhecidos.md` desta skill antes de diagnosticar qualquer erro de producao.
4. `deploy-producao-lan-ubuntu.md` e `linux-vps.md` continuam validos como fundamentos
   gerais (pacotes, MySQL, TLS), mas a topologia e o mecanismo de deploy atuais estao
   nos dois documentos do item 1-2.

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

## Atualizacao de codigo em producao (fluxo atual — git-based)

Desde 2026-07-05 a VPS e' um clone git real de `https://github.com/jovem-tech/erp`
(branch `main`), autenticado por deploy key **somente leitura**. O fluxo
tar+scp usado ate' entao esta descontinuado.

Desde 2026-07-08, atualizar a VPS e' **2 scripts, nenhuma IA envolvida**:

1. No dev (`192.168.1.100`): `./scripts/versionar.sh` (se a mudanca merecer entrada no
   CHANGELOG — ver VERSIONING.md) e depois `./scripts/bash/deploy-completo.sh` — sincroniza
   `develop`, commita o pendente (mensagem tirada do topo do CHANGELOG.md), publica
   `develop` e promove para `main` (pede confirmacao antes). Ver
   workflow-git-multiambiente.md para o passo a passo manual equivalente.
2. Na VPS: `cd /var/www/sistema-erp && ./scripts/bash/deploy-producao.sh` (sem mudancas).
   O script ja faz, nesta ordem: backup do banco -> `git fetch`+`checkout main`+
   `pull --ff-only` -> `composer install` -> `migrate --force` -> rebuild de caches
   (backend e desktop) -> `systemctl reload php8.3-fpm` -> `supervisorctl restart all`.
3. Rodar o checklist pos-deploy do runbook Contabo.

Se precisar atualizar so o servidor de dev (`192.168.1.100`, branch `develop`):
`./scripts/bash/atualizar-dev.sh` (mais leve, sem backup obrigatorio).

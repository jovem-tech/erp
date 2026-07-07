# Runbook de Producao — VPS Contabo (subdominios + dados reais)

**Ambiente:** VPS Contabo `161.97.93.120`, Ubuntu 24.04, MySQL 8.0, PHP 8.3, Nginx.
**Status:** producao real desde 2026-07-05, em paralelo ao sistema legado.
**Complementa:** [deploy-producao-lan-ubuntu.md](deploy-producao-lan-ubuntu.md) (fundamentos
e tabela geral de problemas). Este documento cobre o que e' **especifico da Contabo**:
subdominios, conviver com o legado e reaproveitar os dados reais.

## Topologia atual

| Componente | URL | Porta | Socket FPM |
|---|---|---|---|
| Desktop | `https://erp.jovemtech.eco.br` | 443 | `/run/php/erp-desktop.sock` |
| Backend/API | `https://api-erp.jovemtech.eco.br` | 443 | `/run/php/erp-backend.sock` |
| WebSocket (Reverb) | `wss://erp.jovemtech.eco.br/app/` | proxy 443→8090 | — |
| Legado (intocado) | `https://sistema.jovemtech.eco.br` | 443 | pool do legado |

Nginx faz virtual host por `server_name`, entao ERP e legado coexistem na 443. Cada
subdominio tem certificado Let's Encrypt proprio.

## Principios

1. **Nunca tocar no legado.** Ele roda em `sistema.jovemtech.eco.br` com pool FPM e site
   Nginx proprios. O ERP so **adiciona** pools, sites e tabelas.
2. **Banco compartilhado.** O ERP usa o mesmo `sistema_hml` real do legado (dados dos
   clientes). Migrations do ERP sao aditivas (`Schema::hasTable` pula o que ja existe) e
   usam `laravel_migrations` como repositorio (nao a `migrations` do CI).
3. **Backup antes de qualquer migration.** `mysqldump` completo em
   `/var/backups/pre-erp-deploy/` e verificar integridade.
4. **Backend em 443 (subdominio), nunca porta alta.** Redes restritivas bloqueiam 8443.

## Sequencia de deploy (resumo)

1. **Backup** do banco de producao (`mysqldump --single-transaction --routines --triggers
   --events sistema_hml | gzip`), verificado com `gzip -t`.
2. **Pacotes:** instalar apenas o que faltar (Redis, Supervisor, `php8.3-redis`,
   `php8.3-sqlite3`, `intl`, `bcmath`, `gd`, `zip`, `mbstring`, `xml`, `curl`, `mysql`).
   Reload do `php8.3-fpm` afeta o legado por ~1s (aceitavel).
3. **Codigo** em `/var/www/sistema-erp`; **`chown -R www-data:www-data`** todo o diretorio
   (senao `vendor/` fica sem leitura para o FPM e worker/reverb entram em BACKOFF).
4. **`.env`** (segredos gerados; ler senhas de arquivo, nunca embutir literal em comando):
   - backend: `APP_URL=https://api-erp.jovemtech.eco.br`,
     `DB_DATABASE=sistema_hml`, `LEGACY_PUBLIC_PATH=/var/www/sistema-hml/public`
     (le os uploads do legado direto, somente leitura),
     `CORS_ALLOWED_ORIGINS=https://erp.jovemtech.eco.br`,
     `SANCTUM_STATEFUL_DOMAINS=erp.jovemtech.eco.br`, Redis/Reverb com segredos.
   - desktop: `APP_URL=https://erp.jovemtech.eco.br`,
     `DESKTOP_API_BASE_URL=https://api-erp.jovemtech.eco.br/api/v1`,
     `DESKTOP_BROADCAST_AUTH_URL=https://api-erp.jovemtech.eco.br/broadcasting/auth`,
     `REVERB_HOST=erp.jovemtech.eco.br`, `REVERB_PORT=443`, `REVERB_SCHEME=https`.
5. **Colunas geradas de `os`** (a VPS nao as tem) **antes** do migrate:
   `ALTER TABLE os ADD COLUMN data_abertura_efetiva ... GENERATED ALWAYS AS (...) STORED`
   (idem `data_entrega_efetiva`). Sintaxe MySQL 8 — **sem** `IF NOT EXISTS`.
6. **Migrations aditivas:** `php artisan migrate --force` (cria tabelas do ERP + colunas
   em `orcamentos`; pula as legadas via `hasTable`).
7. **Reconciliar schema** (drift legado x ERP): garantir as colunas que o ERP usa e o
   legado nao tem — ex.: `clientes.referencia`, `usuarios.remember_token_hash` e outras
   (ver nota 2026-07-05-deploy-producao-contabo). Aplicar ALTERs aditivos nulaveis.
8. **Pools FPM** `erp-backend`/`erp-desktop` (nao mexer no `www.conf` do legado); permissoes
   de `storage`/`bootstrap/cache`; SQLite do desktop + `migrate`.
9. **Supervisor** (2 queue workers + Reverb) e **cron** do scheduler.
10. **TLS + Nginx** por subdominio (ver secao abaixo).
11. **Caches:** `config:cache`, `route:cache`, `view:cache` (o `require channels.php` no
    AppServiceProvider ja permite `route:cache` sem quebrar broadcasting).

## TLS e Nginx por subdominio

Para cada subdominio (`erp`, `api-erp`):

1. Criar registro DNS `A → 161.97.93.120` (o operador faz no painel).
2. Bloco HTTP temporario servindo `/.well-known/acme-challenge/` no `root` correspondente.
3. `certbot certonly --webroot -w <public> -d <subdominio> --non-interactive --agree-tos -m <email>`.
4. Bloco 443 definitivo (cert em `/etc/letsencrypt/live/<subdominio>/`), com o bloco 80
   servindo o ACME **antes** do redirect 301 (senao a renovacao quebra em 90 dias).
5. `/etc/hosts` na VPS: `127.0.0.1 erp.jovemtech.eco.br` e `127.0.0.1 api-erp.jovemtech.eco.br`
   — as chamadas internas desktop→backend resolvem para localhost, imunes a cache DNS
   negativo e hairpin NAT.

## Armadilhas especificas (aprendidas em producao)

- **DNS interno:** se a VPS parar de resolver o proprio hostname (cache negativo), o login
  falha com "nao foi possivel conectar ao backend central". Solucao permanente: `/etc/hosts`
  (passo acima) + `resolvectl flush-caches` para o alivio imediato.
- **broadcasting/auth 403:** `channels.php` deve ser carregado com `require`, nao
  `loadRoutesFrom` (este e' ignorado com `route:cache`).
- **UFW:** se for ativar, `ufw allow 22/tcp` **antes** de `ufw enable` (na VPS nao ha
  acesso fisico — a rede de seguranca e' o console web da Contabo).
- **VERSION:** ao deployar so arquivos de codigo, enviar `VERSION`/`CHANGELOG` junto,
  senao o rodape mostra versao defasada. Ideal: publicar no GitHub e deployar por `git pull`.
- **`git pull --ff-only` abortando por "untracked working tree files would be
  overwritten by merge":** acontece quando ha arquivos **nao versionados** na VPS
  (copiados manualmente, sobra de teste direto no servidor, ou de um deploy antigo
  anterior ao git) no mesmo caminho de um arquivo que o commit remoto esta trazendo —
  o `deploy-producao.sh` aborta no passo `[2/5]` **antes** de tocar em codigo/banco
  (o backup do passo `[1/5]` ja foi feito e fica valido). Resolver movendo os arquivos
  conflitantes para fora do caminho (nunca apagar direto, sao untracked e nao tem
  copia em nenhum lugar alem do disco):
  ```bash
  mkdir -p /root/pre-deploy-untracked-backup
  mv <caminho/do/arquivo/conflitante> /root/pre-deploy-untracked-backup/
  # repetir para cada arquivo listado no erro
  ```
  Depois rodar `./scripts/bash/deploy-producao.sh` de novo desde o inicio — e
  idempotente, o unico efeito colateral de repetir e' mais um backup do banco (nao
  ha problema nisso). Se o mesmo erro aparecer para um arquivo fora desse padrao
  (nao e' obviamente uma sobra de teste), parar e investigar o conteudo antes de
  mover, pode ser algo relevante que nunca foi versionado.

## Checklist pos-deploy

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://erp.jovemtech.eco.br/login          # 200
curl -s https://api-erp.jovemtech.eco.br/                                            # {"service":"sistema-erp-api",...}
curl -s -X POST https://api-erp.jovemtech.eco.br/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"x@x.com","password":"errada123"}'                                    # AUTH_INVALID_CREDENTIALS
# broadcasting com token real: 200 (com route:cache ativo)
supervisorctl status                                                                 # RUNNING
curl -s -o /dev/null -w '%{http_code}\n' https://sistema.jovemtech.eco.br/           # 200 (legado intacto)
```

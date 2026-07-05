# Problemas conhecidos de deploy/producao e solucoes validadas

Mapeados no deploy real de 2026-07-03/04 (`192.168.1.100`). Consultar antes de
diagnosticar qualquer erro de producao — a maioria dos sintomas ja tem causa
raiz conhecida.

| Sintoma | Causa raiz | Solucao validada |
|---|---|---|
| `apt` nao encontra `php8.3-*` | Ubuntu 26.04 so empacota PHP 8.5 | instalar `php8.5-*` (atende `"php": "^8.3"`) |
| composer falha em `package:discover` | `bootstrap/cache` ausente (excluido do tar) | `mkdir -p bootstrap/cache` e rodar de novo |
| `migrate` falha com FK para `usuarios` (erro 1824) | banco compartilhado com legado nao importado | importar dump do `sistema_hml` antes do migrate |
| import falha `generated column ... not allowed` (3105) | dump MariaDB com valores para colunas geradas; MySQL 8 rejeita | trocar `GENERATED ALWAYS ... STORED` por `datetime DEFAULT NULL` no dump, importar, recriar via `ALTER TABLE os MODIFY ...` |
| `config:cache` Permission denied | usuario de deploy fora do grupo `www-data` | `usermod -aG www-data <usuario>` (relogar SSH) — o sentido correto e o usuario entrar no grupo `www-data`, nao o contrario |
| todo HTTP redireciona para HTTPS inexistente | middleware `ForceHttps` ativo com `APP_ENV=production` | TLS autoassinado no Nginx (LAN) ou Let's Encrypt (dominio) |
| desktop: "Nao foi possivel conectar ao backend central" | cURL/Guzzle rejeita certificado autoassinado | `cp` do cert para `/usr/local/share/ca-certificates/` + `update-ca-certificates` + `systemctl restart php8.5-fpm` |
| logo/fotos retornam **500** | bug `ForceHttps` chamando `->header()` em `BinaryFileResponse` | corrigido no repo em 2026-07-04 (`headers->set()`); garantir versao atualizada |
| navegador bloqueia `/broadcasting/auth` (CORS) | `CORS_ALLOWED_ORIGINS` ausente no `.env` de producao | definir com a origem do desktop (ex.: `https://<ip>:8443`) e `config:cache` |
| desktop: `could not find driver (sqlite)` | extensao ausente | `apt install php8.5-sqlite3` + restart FPM |
| logo/fotos retornam **404** apos corrigir o 500 | dump nao carrega arquivos fisicos | copiar `backend/storage/app` e uploads legados; configurar `LEGACY_PUBLIC_PATH` |
| jobs de fila nunca executam | `QUEUE_CONNECTION=redis` sem worker | Supervisor com `infra/linux/supervisor-queue-worker.conf` |
| tempo real nao conecta | processo Reverb ausente ou proxy `/app/` faltando no Nginx | `supervisor-reverb.conf` + bloco `location /app/` |
| worker/reverb em BACKOFF ("exited too quickly") | `composer install` como root deixou `vendor/` sem leitura p/ www-data | `chown -R www-data:www-data /var/www/sistema-erp` + `supervisorctl restart all` |
| busca de cliente **500** (`Unknown column referencia`) | schema drift: ERP espera colunas que o legado puro nao tem (adicionadas ad-hoc no XAMPP, fora de migration) | ALTER aditivo nulavel das colunas faltantes a partir das definicoes do XAMPP (ex.: `clientes.referencia`, `usuarios.remember_token_hash`) |
| `broadcasting/auth` **403** (token valido) | `channels.php` via `loadRoutesFrom`, ignorado com `route:cache` → canais nao registrados | `require base_path('routes/channels.php')` no `AppServiceProvider` (corrigido em 3.7.2) |
| login: "nao foi possivel conectar ao backend" **na VPS** | VPS parou de resolver o proprio hostname (cache DNS negativo) | `resolvectl flush-caches` + `/etc/hosts` `127.0.0.1 erp.jovemtech.eco.br` e `api-erp...` |
| API/tempo real bloqueados em rede restritiva | backend em porta alta (8443) | backend em subdominio proprio na 443 (`api-erp.jovemtech.eco.br`) |

## Comandos de diagnostico rapido

```bash
sudo supervisorctl status                       # workers e reverb RUNNING?
sudo nginx -t && sudo systemctl reload nginx    # config valida?
tail -50 /var/www/sistema-erp/backend/storage/logs/laravel.log
tail -50 /var/www/sistema-erp/frontends/desktop/storage/logs/laravel.log
curl -sk -o /dev/null -w '%{http_code}\n' https://<ip>/           # backend 200
curl -sk -o /dev/null -w '%{http_code}\n' https://<ip>:8443/login # desktop 200
redis-cli -a '<senha>' ping                     # PONG
```

## Topologia de PRODUCAO (VPS Contabo, desde 2026-07-05)

- Desktop: `https://erp.jovemtech.eco.br` (443); Backend/API:
  `https://api-erp.jovemtech.eco.br` (443); WebSocket em `wss://erp.jovemtech.eco.br/app/`;
  legado intocado em `https://sistema.jovemtech.eco.br`.
- Cada subdominio com Let's Encrypt proprio (auto-renovacao). `/etc/hosts` na VPS aponta
  `erp` e `api-erp` para `127.0.0.1` (resolucao interna robusta).
- `.env` backend: `APP_URL=https://api-erp.jovemtech.eco.br`,
  `CORS_ALLOWED_ORIGINS=https://erp.jovemtech.eco.br`,
  `SANCTUM_STATEFUL_DOMAINS=erp.jovemtech.eco.br`, `LEGACY_PUBLIC_PATH=/var/www/sistema-hml/public`.
- `.env` desktop: `DESKTOP_API_BASE_URL=https://api-erp.jovemtech.eco.br/api/v1`,
  `DESKTOP_BROADCAST_AUTH_URL=https://api-erp.jovemtech.eco.br/broadcasting/auth`,
  `REVERB_HOST=erp.jovemtech.eco.br` / `REVERB_PORT=443` / `REVERB_SCHEME=https`.
- Runbook: `documentacao/10-deploy/deploy-producao-contabo-vps.md`.

## Topologia do DEV (BANCADA-02, desde 2026-07-04)

- Desktop na **443** (`https://192.168.1.100`); backend/API na **8443**
  (`https://192.168.1.100:8443`). Portas 8444/8445 reservadas para mobile/chat.
- Pools PHP-FPM **separados**: `/run/php/erp-backend.sock` e `/run/php/erp-desktop.sock`.
- `.env` do desktop: `DESKTOP_API_BASE_URL=https://192.168.1.100:8443/api/v1`,
  `DESKTOP_BROADCAST_AUTH_URL=https://192.168.1.100:8443/broadcasting/auth`.
- `.env` do backend: `APP_URL=https://192.168.1.100:8443`,
  `CORS_ALLOWED_ORIGINS=https://192.168.1.100`, `SANCTUM_STATEFUL_DOMAINS=192.168.1.100`.
- Ao trocar portas, **sempre** corrigir esses `.env` em conjunto — senao o login
  quebra com "route api/v1/auth/login could not be found" (o desktop chama a si
  mesmo em vez do backend).

## Regra de firewall (licao aprendida — lockout real em 2026-07-04)

`sudo ufw allow 22/tcp` **antes** de `sudo ufw enable`. Ativar o UFW sem a regra
da 22 corta o SSH na hora (novas conexoes sofrem timeout); na VPS sem acesso
fisico isso exige o console web da Contabo para recuperar.

## Novas variaveis de infra deste ambiente

| Item | Valor/Local |
|---|---|
| Pools FPM | `/etc/php/8.5/fpm/pool.d/erp-{backend,desktop}.conf` (www.conf desativado) |
| Limite upload | `client_max_body_size 25M` (Nginx) + `upload_max_filesize=20M`/`post_max_size=25M` (pool) |
| MySQL tuning | `/etc/mysql/mysql.conf.d/zz-erp.cnf` (buffer pool 1G, slow log) |
| Redis | `maxmemory 1gb` em `/etc/redis/redis.conf` |
| Backup | `/usr/local/bin/erp-backup.sh` + `/etc/cron.d/sistema-erp-backup` (02:00, 7 dias) → `/var/backups/sistema-erp` |
| Backup cred | `/etc/sistema-erp/backup.cnf` (usa `--no-tablespaces`) |
| fail2ban | jail sshd ativo |

## Onde ficam as coisas no servidor

| Item | Caminho |
|---|---|
| Codigo | `/var/www/sistema-erp` |
| Uploads legados (fotos de equipamentos) | `/var/www/sistema-hml-legacy/public/uploads` |
| Sites Nginx | `/etc/nginx/sites-available/sistema-erp{,-desktop}.conf` |
| Certificado TLS | `/etc/nginx/ssl/sistema-erp-selfsigned.{crt,key}` |
| Supervisor | `/etc/supervisor/conf.d/sistema-erp-*.conf` |
| Cron scheduler | `/etc/cron.d/sistema-erp-scheduler` |
| Logs Nginx | `backend/storage/logs/nginx-{access,error}.log` |

# Deploy de Produção — Ubuntu Server (LAN ou VPS)

**Data da execução de referência:** 2026-07-03/04
**Servidor de referência:** `192.168.1.100` (Ubuntu Server 26.04 LTS, LAN interna)
**Executado por:** agente de IA (Claude Code) com acesso SSH, validado pelo administrador
**Resultado:** backend (API) + frontend desktop publicados e operacionais com dados reais

---

## 1. Visão geral

Este guia documenta, passo a passo e com os problemas reais encontrados, o processo
completo de instalação do `sistema-erp` em um servidor Ubuntu limpo. Ele serve como:

- runbook repetível para novos servidores (LAN, homolog ou VPS pública);
- registro histórico do primeiro deploy de produção em rede local;
- material de estudo para o administrador do sistema.

### 1.1 O que fica no ar ao final

| Serviço | URL/Porta | Descrição |
|---|---|---|
| Backend (API central) | `https://<IP>` (443) | Laravel 13, fonte única de verdade |
| Frontend desktop | `https://<IP>:8443` | Laravel/Blade, sessão server-side |
| Reverb (WebSocket) | `wss://<IP>/app/` (proxy 443 → 8090) | Tempo real da Central de Atendimento |
| MySQL 8.4 | localhost:3306 | Banco `sistema_hml` (compartilhado com legado) |
| Redis 8 | localhost:6379 (com senha) | Cache, sessão e filas |
| Supervisor | — | 2× queue worker + 1× reverb |
| Cron | — | `schedule:run` a cada minuto |

### 1.2 Decisão arquitetural importante — banco compartilhado

O backend Laravel **não usa um banco próprio vazio**: ele opera sobre o banco
`sistema_hml` (o mesmo do sistema legado CodeIgniter), reutilizando as tabelas
`usuarios`, `clientes`, `equipamentos`, `os`, etc. As migrations do Laravel apenas
**adicionam** tabelas novas (tokens, notificações, orçamentos, chat, financeiro).

Consequência para o deploy: é obrigatório **importar um dump do `sistema_hml`**
antes de rodar `php artisan migrate`, senão as migrations que criam foreign keys
para `usuarios` falham com erro `1824 Failed to open the referenced table`.

---

## 2. Pré-requisitos

- Servidor Ubuntu Server 24.04+ (o deploy de referência usou 26.04 LTS);
- usuário com sudo (no deploy de referência: `administrador`);
- acesso SSH por senha (o processo instala chave no primeiro acesso);
- máquina de origem com o repositório completo e o banco local (Windows/XAMPP no caso de referência);
- portas liberadas: 22 (SSH), 80/443 (backend), 8080/8443 (desktop).

---

## 3. Passo a passo executado

### 3.1 Acesso SSH e instalação de chave

O primeiro acesso usa senha; em seguida instala-se a chave pública para que todo o
restante do processo seja sem senha:

```bash
# gerar chave local se nao existir
ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519

# instalar a chave no servidor (pede a senha uma unica vez)
ssh administrador@192.168.1.100 "mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys" < ~/.ssh/id_ed25519.pub

# testar
ssh -o BatchMode=yes administrador@192.168.1.100 "echo SSH_OK"
```

> **Nota de segurança:** o processo automatizado usou scripts temporários enviados por
> `scp` com a senha do sudo em variável interna (removidos após o uso), em vez de
> passar a senha em linha de comando de sessão interativa. **Não** foi configurado
> `NOPASSWD` no sudoers — o servidor mantém a exigência de senha para sudo.

### 3.2 Pacotes base

**Problema real encontrado:** o Ubuntu 26.04 não tem pacotes `php8.3-*` — apenas
`php8.5-*`. Como o `composer.json` do backend exige `"php": "^8.3"`, o PHP 8.5
atende à restrição. Instalação:

```bash
sudo apt-get update
sudo apt-get install -y software-properties-common ca-certificates curl unzip git \
  nginx mysql-server redis-server supervisor poppler-utils \
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl \
  php8.5-zip php8.5-bcmath php8.5-gd php8.5-intl php8.5-redis php8.5-common \
  php8.5-sqlite3
```

> **Atenção:** `php8.5-sqlite3` é obrigatório para o **frontend desktop** (que usa
> SQLite local para preferências de usuário). A ausência dele causa
> `could not find driver (Connection: sqlite)` — foi um problema real deste deploy.

> **Miniaturas de PDF:** `poppler-utils` também é obrigatório quando
> `FILE_MANAGER_PDF_THUMBNAILS_ENABLED=true`, pois fornece o binário
> `/usr/bin/pdftocairo`. Sem essa dependência, as miniaturas PDF retornam HTTP
> `503`, embora o arquivo original possa continuar disponível para preview e
> download.

Composer e Node:

```bash
# Composer
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Node 20 (NodeSource)
curl -fsSL https://deb.nodesource.com/setup_20.x -o /tmp/nodesource_setup.sh
sudo -E bash /tmp/nodesource_setup.sh
sudo apt-get install -y nodejs
```

Habilitar serviços:

```bash
sudo systemctl enable --now php8.5-fpm nginx mysql redis-server supervisor
```

Versões instaladas no deploy de referência: PHP 8.5.4, Composer 2.10.2,
Node 20.20.2, npm 10.8.2, MySQL 8.4.10, Redis 8.0.5.

### 3.3 Transferência do código

O código foi empacotado na origem excluindo diretórios pesados/geráveis e enviado
por `scp` (tarball de ~109 MB):

```bash
# na origem (raiz do repo)
tar --exclude='.git' --exclude='vendor' --exclude='node_modules' \
    --exclude='dist' --exclude='build' --exclude='coverage' \
    --exclude='backend/bootstrap/cache' \
    -czf /tmp/sistema-erp.tar.gz .

scp /tmp/sistema-erp.tar.gz administrador@192.168.1.100:/tmp/

# no servidor
sudo mkdir -p /var/www/sistema-erp
sudo chown -R administrador:administrador /var/www/sistema-erp
cd /var/www/sistema-erp && tar -xzf /tmp/sistema-erp.tar.gz && rm /tmp/sistema-erp.tar.gz
```

> **Problema real encontrado:** como `bootstrap/cache` foi excluído do tar, o
> `composer install` falha no `package:discover` com *"The bootstrap/cache directory
> must be present and writable"*. Correção: `mkdir -p backend/bootstrap/cache` antes
> (ou depois) do composer install e rodar `php artisan package:discover` novamente.

### 3.4 Dependências PHP do backend

```bash
cd /var/www/sistema-erp/backend
mkdir -p bootstrap/cache
composer install --no-dev --optimize-autoloader --no-interaction
```

### 3.5 Banco de dados MySQL

Criar usuário dedicado (nunca usar root para a aplicação):

```sql
CREATE DATABASE IF NOT EXISTS sistema_hml CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'erp_app'@'localhost' IDENTIFIED BY '<SENHA_FORTE_GERADA>';
GRANT ALL PRIVILEGES ON sistema_hml.* TO 'erp_app'@'localhost';
FLUSH PRIVILEGES;
```

#### 3.5.1 Dump na origem (MariaDB → MySQL 8)

```bash
mysqldump -u root --single-transaction --routines --triggers --events sistema_hml | gzip > sistema_hml.sql.gz
```

#### 3.5.2 Problema real: colunas geradas (GENERATED ALWAYS AS ... STORED)

A origem era **MariaDB 10.4** e o destino **MySQL 8.4**. O dump da MariaDB inclui
valores explícitos para colunas geradas da tabela `os`
(`data_abertura_efetiva`, `data_entrega_efetiva`), e o MySQL 8 **rejeita** isso:

```
ERROR 3105 (HY000): The value specified for generated column 'data_abertura_efetiva'
in table 'os' is not allowed.
```

**Correção aplicada** (editar o dump antes de importar):

1. substituir as duas definições `GENERATED ALWAYS AS (...) STORED` por
   `datetime DEFAULT NULL` no `CREATE TABLE os` do dump;
2. importar o dump normalmente;
3. recriar as colunas geradas após a importação:

```sql
ALTER TABLE os MODIFY COLUMN data_abertura_efetiva datetime
  GENERATED ALWAYS AS (coalesce(data_abertura,data_entrada,status_atualizado_em,updated_at,created_at)) STORED;
ALTER TABLE os MODIFY COLUMN data_entrega_efetiva datetime
  GENERATED ALWAYS AS (coalesce(data_entrega,data_conclusao,status_atualizado_em,updated_at,created_at)) STORED;
```

#### 3.5.3 Importação e migrations

```bash
gunzip -c sistema_hml.sql.gz | mysql -u erp_app -p sistema_hml
cd /var/www/sistema-erp/backend
php artisan migrate --force
```

Validação do deploy de referência: 5 usuários, 3.598 OS, 1.303 clientes importados;
migrations pendentes aplicadas sem erro após o dump estar presente.

### 3.6 Redis com senha

```bash
# /etc/redis/redis.conf
requirepass <SENHA_REDIS_GERADA>

sudo systemctl restart redis-server
redis-cli -a '<SENHA_REDIS_GERADA>' ping   # deve responder PONG
```

### 3.7 `.env` de produção do backend

Base: `backend/.env.example` + ajustes de produção. Pontos críticos usados:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://192.168.1.100
DB_CONNECTION=mysql
DB_DATABASE=sistema_hml        # banco compartilhado com o legado!
DB_USERNAME=erp_app
DB_PASSWORD=<SENHA_FORTE>
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_PASSWORD=<SENHA_REDIS>
REVERB_APP_ID=<hex aleatorio>
REVERB_APP_KEY=<hex aleatorio>
REVERB_APP_SECRET=<hex aleatorio>
# CORS: obrigatorio em producao — sem isso o desktop e o chat sao bloqueados
CORS_ALLOWED_ORIGINS=https://192.168.1.100:8443
SANCTUM_STATEFUL_DOMAINS=192.168.1.100:8443
# arquivos legados de fotos de equipamentos (ver 3.12)
LEGACY_PUBLIC_PATH=/var/www/sistema-hml-legacy/public
```

```bash
php artisan key:generate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> **Problema real encontrado (CORS):** em `APP_ENV=production` o
> `backend/config/cors.php` só permite origens listadas em `CORS_ALLOWED_ORIGINS`
> (a lista default de localhost vale apenas para `local`/`testing`). Sem essa
> variável, o navegador bloqueia `POST /broadcasting/auth` do desktop com
> *"No 'Access-Control-Allow-Origin' header is present"* e o tempo real não conecta.

### 3.8 Permissões

O PHP-FPM roda como `www-data`; o deploy é feito pelo usuário `administrador`:

```bash
sudo chown -R www-data:www-data backend/storage backend/bootstrap/cache
sudo find backend/storage -type d -exec chmod 775 {} \;
sudo find backend/storage -type f -exec chmod 664 {} \;
sudo chmod -R 775 backend/bootstrap/cache

# permite ao usuario de deploy rodar artisan sem sudo:
sudo usermod -aG www-data administrador   # (relogar o SSH para valer)

# .env legivel apenas por dono e grupo www-data
sudo chgrp www-data backend/.env && chmod 640 backend/.env
```

> **Problema real encontrado:** o sentido do grupo importa — adicionar `www-data`
> ao grupo `administrador` **não** resolve; é o usuário `administrador` que precisa
> entrar no grupo `www-data` para escrever em `storage/` de arquivos criados pelo FPM.

### 3.9 HTTPS com certificado autoassinado (LAN sem domínio)

O middleware `App\Http\Middleware\ForceHttps` do backend **redireciona todo HTTP
para HTTPS quando `APP_ENV=production`**. Em rede local sem domínio público não há
Let's Encrypt, então usa-se certificado autoassinado (válido, com aviso no navegador):

```bash
sudo mkdir -p /etc/nginx/ssl
sudo openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout /etc/nginx/ssl/sistema-erp-selfsigned.key \
  -out /etc/nginx/ssl/sistema-erp-selfsigned.crt \
  -subj "/C=BR/ST=SP/L=Local/O=SistemaERP/CN=192.168.1.100"
sudo chmod 600 /etc/nginx/ssl/sistema-erp-selfsigned.key
```

#### 3.9.1 Confiança do próprio servidor no certificado (server-to-server)

**Problema real encontrado:** o frontend desktop chama a API por HTTPS
(`https://192.168.1.100/api/v1`). O Guzzle/cURL do PHP rejeita certificado
autoassinado com *"Não foi possível conectar ao backend central"*. Correção sem
tocar em código — registrar o certificado como CA confiável do sistema:

```bash
sudo cp /etc/nginx/ssl/sistema-erp-selfsigned.crt /usr/local/share/ca-certificates/
sudo update-ca-certificates
sudo systemctl restart php8.5-fpm
```

### 3.10 Nginx

Dois sites: backend em 443 e desktop em 8443 (com redirecionamentos 80→443 e
8080→8443). Base: `infra/linux/nginx-site.conf`, com dois ajustes obrigatórios:

- `fastcgi_pass unix:/run/php/php8.5-fpm.sock;` (o template referencia 8.3);
- `fastcgi_param HTTPS on;` no bloco PHP (para o Laravel enxergar `$request->secure()`).

Backend (`/etc/nginx/sites-available/sistema-erp.conf`):

```nginx
server {
    listen 80;
    server_name 192.168.1.100;
    return 301 https://$host$request_uri;
}
server {
    listen 443 ssl;
    server_name 192.168.1.100;
    ssl_certificate     /etc/nginx/ssl/sistema-erp-selfsigned.crt;
    ssl_certificate_key /etc/nginx/ssl/sistema-erp-selfsigned.key;
    ssl_protocols TLSv1.2 TLSv1.3;

    root /var/www/sistema-erp/backend/public;
    index index.php index.html;

    access_log /var/www/sistema-erp/backend/storage/logs/nginx-access.log;
    error_log  /var/www/sistema-erp/backend/storage/logs/nginx-error.log;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
    }

    location ~ /\. { deny all; }

    # proxy WebSocket do Reverb (tempo real da Central de Atendimento)
    location /app/ {
        proxy_pass http://127.0.0.1:8090;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
    }
}
```

Desktop (`/etc/nginx/sites-available/sistema-erp-desktop.conf`): igual, com
`listen 8443 ssl;`, redirect `8080 → 8443` e
`root /var/www/sistema-erp/frontends/desktop/public;`.

```bash
sudo ln -sf /etc/nginx/sites-available/sistema-erp.conf /etc/nginx/sites-enabled/
sudo ln -sf /etc/nginx/sites-available/sistema-erp-desktop.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo ufw allow 80/tcp; sudo ufw allow 443/tcp; sudo ufw allow 8080/tcp; sudo ufw allow 8443/tcp
```

### 3.11 Supervisor e cron

Templates prontos em `infra/linux/`:

```bash
sudo cp infra/linux/supervisor-queue-worker.conf /etc/supervisor/conf.d/sistema-erp-queue-worker.conf
sudo cp infra/linux/supervisor-reverb.conf       /etc/supervisor/conf.d/sistema-erp-reverb.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
# sistema-erp-queue-worker_00 RUNNING / _01 RUNNING / sistema-erp-reverb RUNNING

sudo cp infra/linux/cron-scheduler.example /etc/cron.d/sistema-erp-scheduler
sudo chmod 644 /etc/cron.d/sistema-erp-scheduler
```

> Os templates rodam com `user=www-data` e usam `/usr/bin/php` (que já aponta para
> 8.5 via alternatives). Sem o queue worker, jobs de fila (e-mail de recuperação de
> senha, envio de orçamentos) nunca executam, porque `QUEUE_CONNECTION=redis`.

### 3.12 Arquivos de storage (fotos, logo, PDFs)

O dump do MySQL traz apenas os **registros**; os **arquivos físicos** precisam ser
copiados separadamente, senão fotos e logo retornam 404:

```bash
# na origem
cd backend && tar -czf /tmp/backend-storage-app.tar.gz storage/app
cd ../../sistema-hml && tar -czf /tmp/legacy-uploads.tar.gz public/uploads

# no servidor (extrair como root por causa do ownership www-data)
sudo tar -xzf backend-storage-app.tar.gz -C /var/www/sistema-erp/backend
sudo chown -R www-data:www-data /var/www/sistema-erp/backend/storage

sudo mkdir -p /var/www/sistema-hml-legacy
sudo tar -xzf legacy-uploads.tar.gz -C /var/www/sistema-hml-legacy
sudo chown -R www-data:www-data /var/www/sistema-hml-legacy
```

O caminho legado é resolvido pelo backend via `LEGACY_PUBLIC_PATH` (disco
`legacy_public` em `backend/config/filesystems.php`): fotos de equipamentos
importadas do legado que não existem no storage privado novo são buscadas em
`<LEGACY_PUBLIC_PATH>/uploads/equipamentos_perfil/...`.

### 3.13 Frontend desktop

```bash
cd /var/www/sistema-erp/frontends/desktop
mkdir -p bootstrap/cache
composer install --no-dev --optimize-autoloader --no-interaction
npm install && npm run build          # gera public/build (Vite)
```

`.env` do desktop — pontos críticos:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://192.168.1.100:8443
DB_CONNECTION=sqlite                   # banco local so para preferencias
SESSION_DRIVER=file
DESKTOP_API_BASE_URL=https://192.168.1.100/api/v1
REVERB_APP_KEY=<mesma chave do backend>
REVERB_HOST=192.168.1.100
REVERB_PORT=443
REVERB_SCHEME=https
DESKTOP_BROADCAST_AUTH_URL=https://192.168.1.100/broadcasting/auth
```

```bash
php artisan key:generate --force
touch database/database.sqlite && php artisan migrate --force
sudo chown -R www-data:www-data storage bootstrap/cache database
sudo chmod 664 database/database.sqlite && sudo chmod 775 database
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> O diretório `database/` precisa ser gravável pelo `www-data` (o SQLite cria
> arquivos de journal ao lado do `.sqlite`).

---

## 4. Bugs de código encontrados e corrigidos durante o deploy

### 4.1 `ForceHttps` quebrava download de arquivos binários (HTTP 500)

**Sintoma:** `GET /api/v1/configuracoes/empresa/logo` e
`GET /api/v1/equipments/{id}/photos/{photo}` retornavam 500 em produção.

**Causa:** `app/Http/Middleware/ForceHttps.php` chamava `$response->header(...)`,
método que existe em `Illuminate\Http\Response` mas **não** em
`Symfony\Component\HttpFoundation\BinaryFileResponse` (retornada por
`response()->file(...)`). O bug nunca aparecia em desenvolvimento porque o
middleware é ignorado em `local`/`testing`.

**Correção:** trocar por `$response->headers->set(...)`, disponível em qualquer
`SymfonyResponse`. Corrigido no repositório em 2026-07-04.

### 4.2 CORS ausente em produção

Descrito em 3.7 — exigiu `CORS_ALLOWED_ORIGINS` no `.env`.

---

## 5. Checklist de verificação pós-deploy

```bash
# API responde e redireciona corretamente
curl -sk -o /dev/null -w '%{http_code}\n' https://<IP>/            # 200
# login com credencial invalida atravessa toda a stack (nginx→fpm→laravel→mysql)
curl -sk -X POST https://<IP>/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"x@x.com","password":"senhaerrada123"}'             # AUTH_INVALID_CREDENTIALS
# CORS preflight do desktop
curl -sk -X OPTIONS https://<IP>/broadcasting/auth \
  -H 'Origin: https://<IP>:8443' -H 'Access-Control-Request-Method: POST' -D - -o /dev/null
  # deve conter Access-Control-Allow-Origin
# desktop no ar
curl -sk -o /dev/null -w '%{http_code}\n' https://<IP>:8443/login  # 200
# servicos
sudo supervisorctl status    # todos RUNNING
redis-cli -a '<senha>' ping  # PONG
```

Funcional (navegador): login no desktop → dashboard com contadores reais →
listagem de OS → fotos de equipamentos e logo carregando → console do navegador
sem erros de CORS/500.

## 6. Tabela de problemas × soluções (resumo executivo)

| # | Sintoma | Causa raiz | Solução |
|---|---|---|---|
| 1 | `apt` não encontra `php8.3-*` | Ubuntu 26.04 só empacota PHP 8.5 | usar `php8.5-*` (atende `^8.3`) |
| 2 | composer falha em `package:discover` | `bootstrap/cache` excluído do tar | `mkdir -p bootstrap/cache` |
| 3 | migrate falha: FK para `usuarios` | banco compartilhado com legado; dump não importado | importar dump do `sistema_hml` antes do migrate |
| 4 | import falha: `generated column ... not allowed` | dump MariaDB → MySQL 8 | remover colunas geradas do dump e recriar via ALTER depois |
| 5 | `config:cache` Permission denied | usuário de deploy fora do grupo `www-data` | `usermod -aG www-data administrador` |
| 6 | tudo redireciona para HTTPS sem certificado | middleware `ForceHttps` em produção | TLS autoassinado no Nginx |
| 7 | desktop: "Não foi possível conectar ao backend central" | cURL rejeita cert autoassinado | `update-ca-certificates` + restart FPM |
| 8 | logo/fotos retornam 500 | bug `ForceHttps` com `BinaryFileResponse` | `headers->set()` (corrigido no repo) |
| 9 | CORS bloqueia `/broadcasting/auth` | `CORS_ALLOWED_ORIGINS` ausente em produção | definir a variável com a origem do desktop |
| 10 | desktop: `could not find driver (sqlite)` | extensão não instalada | `apt install php8.5-sqlite3` |
| 11 | fotos/logo 404 após corrigir o 500 | dump não carrega arquivos físicos | copiar `storage/app` + uploads legados; `LEGACY_PUBLIC_PATH` |
| 12 | miniaturas de PDF retornam 503 | `poppler-utils` ausente ou feature desabilitada | instalar `poppler-utils`, validar `pdftocairo` como `www-data`, habilitar a flag e recriar o cache de configuração |

## 7. Pendências conhecidas (não configuradas neste deploy)

- **SMTP** — `MAIL_MAILER=log`; recuperação de senha não envia e-mail real;
- **S3/AWS** — storage 100% local;
- **Sentry** — sem rastreamento de erros externo;
- **frontends `chat` e `mobile`** — não publicados (somente backend + desktop);
- **backup automatizado** — dump/rotina de backup do MySQL do servidor novo.

## 8. Credenciais e segurança

- senhas de MySQL e Redis geradas aleatoriamente (24 chars) e gravadas **apenas**
  no `.env` do servidor (permissão `640`, grupo `www-data`);
- `.env` não versionado; nunca commitar;
- sudo continua exigindo senha (sem NOPASSWD);
- certificado autoassinado com validade de 10 anos — para acesso externo futuro,
  migrar para domínio + Let's Encrypt (ver `PRODUCTION_DEPLOYMENT_GUIDE.md`).

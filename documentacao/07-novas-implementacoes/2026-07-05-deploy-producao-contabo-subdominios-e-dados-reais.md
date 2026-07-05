# Deploy de producao na VPS Contabo com subdominios e dados reais do legado

**Data:** 2026-07-04/05
**Versoes:** 3.7.1 → 3.7.2
**Modulo:** infraestrutura + `backend` + `frontends/desktop`
**Ambientes:** VPS Contabo `161.97.93.120` (producao) e `192.168.1.100`/BANCADA-02 (dev)

## Contexto

Com o ambiente de desenvolvimento ja em Linux (v3.7.0), o objetivo passou a ser colocar
o novo ERP em producao real na VPS Contabo (Ubuntu 24.04) **mantendo os dados reais dos
clientes** do sistema legado (`sistema-hml`, CodeIgniter) que ja rodava la. O trabalho
teve tres frentes: (1) trazer os dados reais da VPS para o dev como ensaio seguro,
(2) instalar o ERP na VPS em paralelo ao legado, e (3) resolver os problemas de schema,
broadcasting e DNS que apareceram com dados/produzidos reais.

## Entrega

### 1. Copia dos dados reais VPS → dev (ensaio seguro)

- dump do banco `sistema_hml` de producao (110 MB, 144 tabelas) e dos uploads (59 MB)
  da VPS, **somente leitura**, importados no dev **substituindo** o snapshot antigo do
  XAMPP e **preservando** as 21 tabelas proprias do ERP (nenhuma colisao — o ERP usa
  `laravel_migrations`, nao a `migrations` do CI);
- colunas geradas de `os` (`data_abertura_efetiva`, `data_entrega_efetiva`) recriadas
  (a VPS/MySQL nao as tinha); dados reais confirmados (4 usuarios, 1314 clientes, 3611 OS).

### 2. ERP em producao na VPS, em paralelo ao legado

- ERP publicado em `/var/www/sistema-erp` apontando para o **mesmo** banco `sistema_hml`
  real da VPS; instalados Redis (com senha), Supervisor e extensoes PHP faltantes
  (a VPS ja tinha PHP 8.3, Nginx, MySQL 8, Node, Composer);
- migrations **aditivas** (criam so as tabelas do ERP; as que colidem com o legado sao
  puladas via `Schema::hasTable`) + colunas geradas de `os` recriadas antes do migrate;
- pools PHP-FPM dedicados (`erp-backend`, `erp-desktop`), Supervisor (2 workers + Reverb),
  cron do scheduler, backup diario — tudo sem tocar no legado, que segue no ar.

### 3. Topologia de subdominios (troca de portas por nomes)

- **Desktop:** `https://erp.jovemtech.eco.br` (443)
- **Backend/API:** `https://api-erp.jovemtech.eco.br` (443) — antes era `erp...:8443`
- **WebSocket (Reverb):** `wss://erp.jovemtech.eco.br/app/` (proxy 443 → 8090)
- **Legado (intocado):** `https://sistema.jovemtech.eco.br`
- certificados Let's Encrypt reais para os dois subdominios, com auto-renovacao testada;
- motivo da troca: a porta 8443 e' bloqueada em muitas redes restritivas, o que quebraria
  o broadcasting/auth e clientes diretos da API. Backend em 443 elimina o risco.

### 4. Correcoes de codigo (v3.7.1 e v3.7.2)

- **v3.7.1** — `OrderController::jsonFailure()` faltante causava 500 na busca de clientes
  (Select2 da Nova OS); adicionado o metodo no padrao dos demais controllers.
- **v3.7.2** — `broadcasting/auth` retornava **403** em producao porque `channels.php`
  era carregado via `loadRoutesFrom()`, **ignorado quando `route:cache` esta ativo** →
  canais nao registrados. Corrigido para `require base_path('routes/channels.php')` no
  `AppServiceProvider`, permitindo manter `route:cache` sem quebrar o tempo real.

### 5. Reconciliacao de schema (dados reais x schema do ERP)

- o ERP esperava colunas em tabelas legadas que existiam no XAMPP (adicionadas ad-hoc,
  fora de migration) mas **nao** no legado puro da VPS — ex.: `clientes.referencia`,
  `usuarios.remember_token_hash`, e mais 16 colunas em `clientes`, `financeiro`,
  `financeiro_movimentos_cartao`, `orcamento_aprovacoes`, `defeitos_relatados`;
- geradas ALTERs a partir das definicoes exatas do XAMPP e aplicadas (aditivas, nulaveis)
  na VPS **e** no dev (que ficou com o mesmo gap apos a copia dos dados).

## Problemas reais e solucoes (novos, alem do runbook LAN)

| Sintoma | Causa raiz | Solucao |
|---|---|---|
| dump MariaDB nao importa colunas geradas | XAMPP e' MariaDB, VPS/dev e' MySQL 8 | na VPS o dump ja e' MySQL 8 (limpo); colunas geradas recriadas via ALTER pos-import |
| `orcamentos`/`os`/`clientes` "colidem" no migrate | legado ja tem essas tabelas | migrations do ERP usam `Schema::hasTable` e pulam; ERP usa `laravel_migrations` |
| busca de cliente 500 (`Unknown column referencia`) | schema drift legado x ERP | ALTER aditivo das colunas faltantes (18) a partir do XAMPP |
| `broadcasting/auth` 403 | `channels.php` via `loadRoutesFrom` ignorado com `route:cache` | trocar por `require` no AppServiceProvider |
| login: "nao foi possivel conectar ao backend" | VPS parou de resolver `erp.jovemtech.eco.br` (cache DNS negativo) | `resolvectl flush-caches` + `/etc/hosts` `127.0.0.1 erp/api-erp` (resolucao interna robusta, imune a DNS externo) |
| worker/reverb em BACKOFF | `composer install` como root deixou `vendor/` sem leitura p/ www-data | `chown -R www-data:www-data /var/www/sistema-erp` |
| upload/fotos falham | (herdado do runbook) `client_max_body_size` + limites PHP | 25M no Nginx e nos pools |

## Licoes aprendidas

- **DNS interno da VPS:** um servidor que chama o proprio hostname publico depende de DNS
  externo; usar `/etc/hosts` apontando o proprio nome para `127.0.0.1` evita falhas de
  cache DNS negativo e hairpin NAT. Aplicado a `erp.` e `api-erp.` na VPS.
- **`route:cache` e broadcasting:** definicoes de canal (`Broadcast::channel`) nao fazem
  parte do cache de rotas; carregar `channels.php` com `require`, nunca `loadRoutesFrom`.
- **Schema como codigo:** colunas adicionadas ad-hoc no banco (fora de migration) viram
  divida — reaparecem como erro em producao quando o banco de destino nao as tem.
- **Versionamento x deploy:** `bump-version.sh` altera so o repo local; ao deployar uma
  correcao e' preciso enviar `VERSION`/`CHANGELOG` junto com o codigo (ou usar `git pull`).

## Validacao (executada contra producao)

- desktop `erp.` 443 → 200; backend `api-erp.` 443 → JSON de servico;
- login real atravessa a API (`AUTH_INVALID_CREDENTIALS`); server-to-server com TLS valido
  (sem `-k`, SSL_ERROR=nenhum); CORS cross-subdominio liberando `erp.`;
- `broadcasting/auth` → 200 com assinatura, **com `route:cache` ativo**;
- Supervisor RUNNING; legado `sistema.jovemtech.eco.br` 200 o tempo todo.

## Pendencias

- reprocessar telefones/nomes ja existentes no banco (padronizacao retroativa);
- SMTP, S3 e Sentry ainda nao configurados; frontends `chat`/`mobile` nao publicados;
- UFW inativo na VPS (ativar com `allow 22` antes do `enable`); publicar via GitHub para
  o deploy virar `git pull` (elimina o descasamento VERSION/codigo).

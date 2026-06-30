# Otimização de performance: backend e desktop

## Contexto

- data: `2026-06-30`
- ambiente-alvo: `Windows/XAMPP` (local) e `Ubuntu VPS` (produção)
- origem: auditoria de performance/segurança solicitada sobre todo o
  `sistema-erp/`, seguida de pedido explícito para corrigir os achados de
  performance.

## Entrega

### 1. Cache, sessão e fila deixam de ser `file`/`sync` no backend local

- `CACHE_STORE`, `SESSION_DRIVER` e `QUEUE_CONNECTION` passam de
  `file`/`sync` para `database` em `backend/.env` e `backend/.env.example`.
- Criadas as tabelas `cache`, `cache_locks` (via `php artisan cache:table`) e
  `sessions` (via `php artisan session:table`).
- Motivo: `file` isola cache/sessão por processo (quebra com mais de um
  worker) e `sync` executa jobs (`SendWhatsappMessageJob`) de forma
  bloqueante dentro da própria requisição HTTP.
- **Esta troca substitui uma decisão anterior** (`2026-06-25-fase-2-...md`)
  que mantinha `QUEUE_CONNECTION=sync` localmente de propósito, por
  praticidade. A partir de agora, jobs enfileirados só são processados se
  houver um worker rodando: `php artisan queue:work` (terminal separado, ou
  Task Scheduler no Windows, equivalente ao `infra/linux/supervisor-queue-worker.conf`
  já usado em produção).
- Produção já estava planejada para `redis` em `SESSION_DRIVER` e
  `QUEUE_CONNECTION` (`.env.production.example`, decisão de 2026-06-25) — não
  alterado aqui.

### 2. Bug corrigido: cache de produção não estava usando Redis de verdade

- `backend/.env.production.example` tinha `CACHE_DRIVER=redis` — nome de
  variável da versão antiga do Laravel. Esta versão (`laravel/framework ^13`)
  lê `CACHE_STORE` (`config/cache.php:18`). Como a variável não existia,
  `config('cache.default')` caía no padrão (`database`), ignorando a
  intenção original de usar Redis.
- Corrigido para `CACHE_STORE=redis`.
- **Não foi alterado nenhum `.env` real de produção** (não está neste
  checkout) — esta correção vale para o arquivo-modelo; é preciso conferir/
  corrigir o `.env` real do VPS na próxima janela de manutenção.

### 3. Dashboard: cache de 60s sobre o resultado agregado

- `DashboardSummaryService::build()` (`app/Services/Dashboard/DashboardSummaryService.php`)
  passou a envolver todo o cálculo (antes `build()`, agora `buildUncached()`)
  em `Cache::remember()`, chave por usuário + filtros, TTL de 60s.
- Sem isso, cada carregamento do dashboard disparava ~15 queries de
  agregação contra `os`/`clientes`/`equipamentos`. Com o cache, isso roda no
  máximo 1x por minuto por usuário.
- Validado: resultado idêntico (diff binário) entre a versão antiga e a nova
  rodando contra a base local (3.581 OS).

### 4. Índice real para os filtros de data do dashboard

- Nova migration `database/migrations/2026_06_30_120000_add_effective_dates_index_to_os_table.php`
  adiciona em `os` duas colunas **geradas e armazenadas** (`STORED`):
  - `data_abertura_efetiva` = `COALESCE(data_abertura, data_entrada, status_atualizado_em, updated_at, created_at)`
  - `data_entrega_efetiva` = `COALESCE(data_entrega, data_conclusao, status_atualizado_em, updated_at, created_at)`
  - cada uma com índice próprio (`idx_os_data_abertura_efetiva`,
    `idx_os_data_entrega_efetiva`).
- Por quê: o dashboard filtra/agrupa por `MONTH()`/`YEAR()` em cima de um
  `COALESCE` de 5 colunas — nenhum índice existente em `os` consegue ser
  usado nessas consultas (confirmado via `EXPLAIN`: `type=ALL`, sem `key`).
  Colunas geradas replicam o cálculo em tempo de escrita e podem ser
  indexadas normalmente.
- `DashboardSummaryService::OPEN_DATE_SQL`/`DELIVERY_DATE_SQL` passaram a
  apontar para essas colunas (em vez do `COALESCE` inline), e os filtros que
  antes eram `YEAR(...) = ?`/`MONTH(...) = ?` foram reescritos para range
  (`coluna >= inicio AND coluna < fim`, via novo helper `periodBounds()`) —
  `YEAR()`/`MONTH()` sobre uma coluna sempre força varredura, mesmo com
  índice; range usa o índice de fato (`EXPLAIN` confirma `type=range`).
- Medido na base local (3.581 OS): as 15 queries mais pesadas do dashboard
  caíram de ~62ms para ~18ms. O ganho real é de escala — em dezenas de
  milhares de linhas a diferença passa a ser de segundos, não de
  milissegundos.
- **Importante — tabela legada sem migration própria**: `os` (como
  `equipamentos`, `clientes` etc.) é compartilhada com o `sistema-hml` e não
  é criada por nenhuma migration deste repositório; só existe de fato no
  MySQL local/produção. A migration acima tem um guard
  (`if (! Schema::hasTable('os')) return;`) para não quebrar o bootstrap de
  teste (que recria `os` manualmente via
  `tests/Concerns/BuildsLegacyErpSchema.php`, com as mesmas colunas geradas
  espelhadas lá). Em ambiente real (local e VPS), a migration roda
  normalmente dentro do `php artisan migrate` de sempre — não exige nenhum
  passo manual de deploy além do já existente. É idempotente (testado rodar
  duas vezes seguidas sem erro).

### 5. `OsMargemService::recalcularEmLote()` deixa de ser N+1

- Antes: um loop chamando `calcularParaOs($id)` por OS (4 a 6 queries cada).
- Agora: busca todas as OS do lote de uma vez, calcula custo de peças e
  comissão em consultas agrupadas (`whereIn` + `groupBy`), e grava tudo com
  um único `OsMargem::upsert()`.
- `calcularParaOs()` (cálculo individual, usado após cada baixa de OS) não
  foi alterado.
- Validado: processado o lote completo (3.517 OS) e comparado resultado
  contra o cálculo individual antigo para uma amostra aleatória — idêntico.

### 6. Limites de segurança no módulo de estoque

- `EstoqueController::lowStock()` e `::movements()`
  (`app/Http/Controllers/Api/V1/EstoqueController.php`) ganharam um teto de
  500 registros (`->limit(500)`) — antes retornavam a lista inteira sem
  limite. Não é paginação completa (mudaria o contrato da resposta para o
  frontend) — é só um teto de segurança contra crescimento sem limite.

### 7. Desktop: cache de catálogo de status/técnicos

- `frontends/desktop/app/Http/Controllers/OrderController.php::index()`
  fazia 3 chamadas sequenciais ao backend a cada carregamento da listagem de
  OS. O catálogo de status e a lista de técnicos ativos são dados de
  referência (iguais para qualquer usuário com acesso) — passaram a ser
  cacheados por 60s via `Cache::remember`. Erros (sem permissão, API fora) não
  são cacheados, então um usuário sem acesso nunca "contamina" o cache para
  quem tem.

## Não incluído nesta entrega

- Busca de equipamentos (`EquipmentController::index()`, `LIKE` com
  `LOWER(COALESCE())` em 8 colunas + subqueries correlacionadas) continua sem
  índice. Diferente do item 4, a correção real exigiria trocar `LIKE` por
  busca fulltext (`MATCH ... AGAINST`), o que muda o comportamento da busca
  (tokenização por palavra, não mais substring livre) — não é uma otimização
  neutra, é uma mudança de produto. Medido em 8,5ms na base atual (3.575
  equipamentos): impacto hoje é imperceptível. Avaliar isso quando a base
  crescer ou se decidir investir em busca melhor.

## Validação

- `php artisan test` (backend): 140 passando, 9 falhas — todas
  pré-existentes e não relacionadas (bug de seed duplicado em
  `DashboardSummaryTest`, asserts `assertIdentical` float-vs-int em
  `FinanceiroMargemTest`/`FinanceiroReportTest`), confirmado comparando
  contra o código antes das mudanças desta entrega.
- `OrderFlowTest` (32 testes, grava/lê `os` de verdade): 100% passando —
  confirma que as colunas geradas funcionam igual em SQLite (teste) e
  MySQL/MariaDB (real).
- Resultado do dashboard comparado byte-a-byte (JSON) entre versão antiga e
  nova: idêntico.

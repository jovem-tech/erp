# Gargalos e travamentos sob operação intensa (2026-08-27)

**Versão:** v5.60.0.0
**Tipo:** desempenho e escalabilidade (MINOR)

## Por que agora

O sistema travava sob uso pesado. A auditoria mediu o ambiente real — MySQL
`sistema_hml`, PHP-FPM, nginx e Redis do servidor de desenvolvimento — e o
diagnóstico contraria a suspeita natural:

**A lentidão não vinha do volume de dados.** As tabelas operacionais são pequenas
(`os` = 3.645 linhas, `clientes` = 1.314). O travamento vinha da **arquitetura do
caminho de requisição**: o desktop é um BFF que fala HTTP com o backend, e esse
caminho tinha um teto efetivo de **12 requisições simultâneas** (`pm.max_children`
do pool `erp-desktop`) que se esgotava em segundos quando qualquer operação
demorava.

## O problema

### A cadeia de colapso

1. `POST /orders` gerava o PDF de abertura em dois formatos **e** fazia até duas
   tentativas de WhatsApp (inbox + fallback direto), cada uma com timeout de 20s
   no `IntegrationSettingsService` — **tudo dentro da requisição**. O próprio
   código documentava isso em `frontends/desktop/resources/views/layouts/app.blade.php`,
   onde a janela de navegação interna foi alargada de 10s para 60s justamente
   para tolerar esse fluxo.
2. O desktop desiste em 15s (`DESKTOP_API_TIMEOUT`), mas o `retryRequest` do
   `ApiClient` repetia **3 vezes** com `usleep(1s)` e `usleep(2s)` dentro do
   worker do PHP-FPM — e repetia também `POST`, `PUT`, `PATCH` e `DELETE`.
3. Com 12 workers no pool, uma dezena de aberturas de OS simultâneas saturava o
   sistema inteiro.
4. `SESSION_DRIVER=file` no desktop faz o Laravel serializar (`LOCK_EX`) as
   requisições **do mesmo usuário** — a requisição lenta congelava todas as
   outras abas e chamadas AJAX daquele operador. Esse é o sintoma que o usuário
   descreve como "travamento".

### Dois gargalos que escalavam mal

**Busca de OS.** A listagem fazia **53 predicados**
`LOWER(COALESCE(coluna,'')) LIKE '%termo%'` unidos por `OR`, espalhados por 7
tabelas. Nenhum índice atende esse formato (wildcard à esquerda + função sobre a
coluna), então cada busca varria a junção inteira **duas vezes** — página e
`COUNT` do paginador.

**`file_scan_runs`.** Crescia **11.232 linhas por dia** (uma a cada 7,7 segundos,
24h) sem nenhuma retenção. Havia chegado a 382.781 linhas e **373,6 MB** — a
maior tabela do banco, ocupando **35% do `innodb_buffer_pool_size` de 1 GB** e
expulsando do cache as páginas de `os` e `clientes`. Dessas, 186.474 eram
`catalog_legacy` com média de **0,0** arquivos processados.

## O que foi entregue

| Camada | Mudança |
|---|---|
| Backend / OS | PDF de abertura e avisos ao cliente saem da requisição para a fila `documents` |
| Backend / busca | 53 `LIKE` → um `LIKE` sobre `os.busca_texto` (coluna desnormalizada) |
| Backend / orçamentos | 15 agregações por listagem → um `GROUP BY` |
| Backend / arquivos | Retenção de `file_scan_runs` + descarte de execuções vazias |
| Backend / cobranças | N+1 no processamento de cobranças agendadas (até 400 consultas → 2) |
| Desktop / ApiClient | Retry só em leitura, 2 tentativas, backoff curto com jitter |
| Desktop / sessão | Correção de bug fatal na renovação de token |

### Números medidos

Busca de OS, no banco real com 3.645 OS (`SET profiling=1` + `SHOW PROFILES`):

| Cenário | Antes | Depois |
|---|---|---|
| Termo sem resultado (pior caso), página | 124ms | **14ms** |
| Termo sem resultado, `COUNT` do paginador | 120ms | **13ms** |
| **Total por busca** | **244ms** | **27ms** |
| Termo que casa ("notebook") | — | **2,4ms** |

O ganho é maior do que o fator 9 sugere, porque o custo antigo crescia
linearmente com o acervo: com 36k OS seriam ~2,4s por busca, com 100k ~6,7s — e
a busca dispara a cada digitação (com debounce), sendo que o `AbortController`
do navegador **não cancela a consulta no MySQL**.

### Jobs novos

Todos na fila `documents`, que o Supervisor já consome:

- `DeliverOrderOpeningDocumentJob` — gera o PDF de abertura e entrega ao cliente
- `NotifyOrderStatusChangeJob` — aviso de mudança de status
- `NotifyOrderClosureJob` / `NotifyOrderAdvanceJob` — encerramento e adiantamento

### O que deliberadamente **continuou** síncrono

A checagem de "cliente sem telefone" na criação de OS. É leitura de banco
(barata) cuja resposta o operador precisa ver ainda na tela, com o cliente na
frente dele e o cadastro aberto para corrigir. Só o que custa caro — gerar PDF e
falar com o gateway — foi para a fila.

Pelo mesmo motivo, o registro no histórico da OS continua sendo gravado de forma
síncrona quando o **enfileiramento** falha (Redis fora), para que a OS nunca
fique sem vestígio da tentativa de mensagem.

## Mudança visível ao operador

Ao criar OS com "enviar PDF ao cliente", a mensagem passou de
_"Documento enviado ao cliente."_ para
_"O PDF de abertura será enviado ao cliente em instantes."_

Isso **não é aviso de problema** — é o estado normal desde que a geração saiu da
requisição. O desktop só mostra alerta quando o enfileiramento em si falha.

## Três armadilhas que o trabalho encontrou

**O retry estava ao contrário.** `authenticatedRequest()` e irmãs convertiam
`ConnectionException` em `ApiRequestException` **antes** de o laço vê-la, então o
ramo que retentaria um soluço de rede era **código morto**. Funcionava só o retry
nocivo: repetir um 5xx de comando mutável, que o backend pode ter concluído antes
de falhar. Os dois lados foram corrigidos.

**Bug fatal na renovação de token.** `ApiClient::refreshToken()` chamava
`DesktopSession::storeToken()` e `storeExpiresAt()` — **nenhum dos dois existia**.
Todo usuário cujo token expirava no meio da sessão levava um `Error` fatal
(HTTP 500) exatamente no caminho de sucesso da renovação, em vez de continuar
trabalhando. Não é problema de desempenho, mas apareceu na mesma investigação.

**Duas otimizações mediram pior e foram descartadas.** Um ramo "ancorado"
(`LIKE 'termo%'`) para números de OS/CPF/telefone ficou **36ms contra 14ms**: com
um `OR` não-sargável ao lado, o MySQL abandona o índice e ainda paga os
predicados extras. E restringir o `View::composer('*')` aos layouts **quebra a
tela** — com `@extends`, o Blade renderiza a view filha antes do layout, então os
`@include` dentro das sections não enxergam as variáveis (a estrela de favorito
do dashboard sumia). Nesse caso a repetição foi resolvida onde o custo realmente
estava: memoizando `CompanyProfileService::branding()`, a única dessas chamadas
que fazia I/O a cada view renderizada.

## Rede de segurança da busca

`os.busca_texto` é mantida por `OrderSearchIndexService`, acionado nos eventos
`saved` de `Order`, `Client` e `Equipment` (o leque é pequeno: 2,81 OS por
cliente, 1,01 por equipamento).

Mas **o sistema legado compartilha este banco** e escreve em `os` direto, sem
passar pelo Eloquent. Por isso a busca tem um ramo de fallback para linhas com
`busca_texto IS NULL`: sem ele, a busca não ficaria lenta — ficaria **errada**,
devolvendo "nenhum resultado" em silêncio para uma OS que existe. O ramo só é
avaliado onde a coluna é nula, então após a reindexação não custa nada.

## Deploy

A migration `2026_08_27_000002_add_busca_texto_to_os` **faz o backfill sozinha**.
Aplicar com `--path` — o grant de `sistema_erp_chat` aborta o `artisan migrate`
completo:

```bash
php artisan migrate --path=database/migrations/2026_08_27_000002_add_busca_texto_to_os.php --force
```

Para reconstruir o índice depois (renomeação em massa de catálogo de
tipo/marca/modelo é o único caso que os listeners não alcançam):

```bash
php artisan os:reindexar-busca
```

Recuperar o espaço já acumulado em `file_scan_runs` (o agendador passa a fazer
isso diariamente às 02:40):

```bash
php artisan file-manager:purge-scan-runs --dry-run   # confere antes
php artisan file-manager:purge-scan-runs
```

### Migration que NÃO deve ser aplicada às cegas

`2026_08_27_000003_drop_redundant_os_indexes` remove 8 índices redundantes de
`os` (a tabela tinha `INDEX_LENGTH` de 2,6 MB **maior** que `DATA_LENGTH` de
1,5 MB, e cada `INSERT`/`UPDATE` mantinha 20 árvores B-tree).

A redundância é estrutural — regra do prefixo mais à esquerda —, não inferência
sobre tráfego. Ainda assim, **confirme o uso real antes de aplicar**:

```sql
SELECT index_name, count_star FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE object_schema='sistema_hml' AND object_name='os';
```

O usuário `erp_app` **não tem permissão de leitura em `performance_schema`** —
rode como root. O `down()` da migration recria todos os índices.

### Variáveis de ambiente novas

| Chave | Padrão | Efeito |
|---|---|---|
| `FILE_MANAGER_SCAN_RUN_RETENTION_DAYS` | 14 | Idade mínima para expurgar execução de varredura |
| `FILE_MANAGER_SCAN_RUN_PURGE_BATCH_SIZE` | 5000 | Teto de linhas por execução do expurgo |

### Fila

`documents` passou a receber PDF **e** WhatsApp. O Supervisor está com
`numprocs=2` e `--sleep=3`; avaliar 4 workers e `--sleep=1` conforme o volume.

## Validação

| Suíte | Antes | Depois |
|---|---|---|
| Backend | 854 passed, 0 failed | **866 passed, 0 failed** |
| Desktop | 412 passed, **5 failed** | **418 passed, 5 failed** |

As 5 falhas do desktop são pré-existentes, capturadas com `git stash` antes de
qualquer alteração (a suíte do desktop é instável — ver
`documentacao/04-governanca-ai/`). São elas: colisão de classes desktop/backend,
página de integrações, dois testes da listagem de OS e o calendário de fluxo de
caixa.

Testes novos que travam as regressões:

- `OrderFlowTest::test_order_creation_queues_delivery_without_blocking_on_external_calls`
  — usa `Http::preventStrayRequests()`, então **falha** se qualquer chamada HTTP
  externa voltar para dentro do `POST /orders`
- `OrderFlowTest::test_status_change_client_notification_is_queued_instead_of_sent_inline`
- `ApiClientRetryTest` (desktop) — 6 casos, incluindo a regressão do
  `refreshToken()` fatal

## Pendências conscientes

O runbook de infraestrutura **não foi aplicado** (escopo desta entrega era só
código). Continuam abertos, por ordem de impacto:

1. `SESSION_DRIVER`, `CACHE_STORE` e `QUEUE_CONNECTION` do desktop ainda em
   `file`/`file`/`sync` — o Redis já existe, com senha, e o backend já o usa
2. `pm.max_children = 12` no pool do desktop
3. Redis com `maxmemory-policy noeviction` em 1 GB: se o cache encher, o Redis
   **recusa escritas** e derruba sessão e fila juntas
4. `gzip on` no nginx mas `gzip_types` comentado — só `text/html` comprime; CSS,
   JS e todo o JSON da API trafegam sem compressão (grave na VPS de produção)
5. `fastcgi_read_timeout` ausente (padrão 60s) contra
   `request_terminate_timeout = 90` — o worker segue ocupado 30s depois de o
   cliente desistir
6. `opcache.validate_timestamps=On` em produção e `max_accelerated_files=10000`
   para 17.836 arquivos PHP dos dois apps no mesmo master FPM

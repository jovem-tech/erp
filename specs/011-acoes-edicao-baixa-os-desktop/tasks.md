# Tasks: Ações de OS no Desktop — Dropdown, Edição e Baixa (paridade completa com o legado)

## Phase 1: Setup e governanca

- [X] T001 Criar `specs/011-acoes-edicao-baixa-os-desktop/` (spec.md, plan.md, tasks.md)
- [X] T001a Reabrir o escopo da baixa (spec.md/plan.md/tasks.md) após o usuário pedir explicitamente a feature completa do legado, incluindo taxa de cartão e cobrança agendada

## Phase 2: Backend central — MVP (entregue antes da reabertura de escopo)

- [X] T002 Confirmado: `baixa_tecnica_em`, `baixa_tecnica_por`, `forma_pagamento`, `data_entrega` ja existiam em `backend/tests/Concerns/BuildsLegacyErpSchema.php::createOrdersTable()` (e no banco real) — nenhuma alteracao necessaria
- [X] T003 Criado `backend/app/Services/Orders/OrderClosureService.php` com `metadata(int $orderId, User $actor)` e `close(int $orderId, User $actor, array $payload)`, reaproveitando `OrderWorkflowService::updateStatus` (visibilidade de `canAccessOrder` ampliada para `public`), `FinanceiroService::registerMovement`/`create`/`movementSummary`
- [X] T004 Adicionados `closureMetadata`/`close` em `backend/app/Http/Controllers/Api/V1/OrderController.php`, `CloseOrderRequest` (FormRequest) e as rotas `GET/POST /api/v1/orders/{order}/closure` em `backend/routes/api.php`, exigindo `os:editar`
- [X] T005 Atualizado `backend/openapi.yaml` com `CloseOrderRequest`, `CloseOrder` (requestBody) e o path `/orders/{order}/closure` (GET + POST)

## Phase 3: Frontend desktop — MVP (entregue antes da reabertura de escopo)

- [X] T006 Adicionados `edit()`/`update()` em `frontends/desktop/app/Http/Controllers/OrderController.php`, `update()` em `frontends/desktop/app/Services/OrderService.php`, rotas `GET /os/{order}/editar` e `PUT|PATCH /os/{order}` em `frontends/desktop/routes/web.php`
- [X] T007 Criado `frontends/desktop/resources/views/orders/edit.blade.php` (mesmos campos de `create.blade.php`, pre-preenchidos, reaproveita `orders-create.js` e o modal de cliente rapido)
- [X] T008 Adicionados `closureShow()`/`closureStore()` em `OrderController.php`, `closureMetadata()`/`close()` em `OrderService.php`, rotas `GET/POST /os/{order}/baixa` em `web.php`
- [X] T009 Criado `frontends/desktop/resources/views/orders/closure.blade.php` (versão MVP de tela única — **substituída na Phase 6 pelo assistente de 3 etapas**)
- [X] T010 Atualizado `frontends/desktop/resources/views/orders/index.blade.php`: dropdown "Ações" (Detalhe sempre, Editar com gate `os:editar`, Baixa com gate `os:editar` + `estado_fluxo` fora de `encerrado`/`cancelado`)
- [X] T011 Adicionadas classes `os-actions-dropdown`/`-toggle`/`-menu` em `frontends/desktop/public/assets/css/desktop.css`, compartilhando a mesma regra CSS de `equipment-actions-*` (seletores combinados, sem duplicar declaracoes) inclusive no breakpoint mobile

## Phase 4: Testes — MVP (entregue antes da reabertura de escopo)

- [X] T012 Adicionados em `backend/tests/Feature/Api/V1/OrderFlowTest.php`: metadata, close com pagamento total, close sem pagamento, close com status invalido (422), close sem permissao (403), close com falha de notificacao mockada
- [X] T013 Adicionados em `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`: dropdown, edicao, baixa MVP. Bug real encontrado e corrigido: `OrderController@index` (desktop) tambem chama `UserService::paginate()` e `DesktopOrderStatusFlowService::index()`; dois testes sem mock para `/api/v1/users`/`/api/v1/knowledge/os-flow` deixavam essas chamadas escaparem para o backend de dev real, causando estouro de 1GB de memoria no Guzzle. Corrigido com mocks explicitos.

## Phase 5: Documentacao — MVP (entregue antes da reabertura de escopo)

- [X] T014 Atualizado `specs/004-os-mobile-flow/contracts/orders-api.md` com `equipamento_foto_id`, `GET/POST /orders/{id}/closure`
- [X] T015 Criada nota em `documentacao/07-novas-implementacoes/2026-06-27-acoes-edicao-baixa-os-desktop.md`
- [X] T016 Rodado `scaffold-release-note.php --version=3.2.1` e `sync-agent-docs.php`

## Phase 6: Backend — paridade completa (cartão, cobrança agendada, follow-up)

- [X] T018 Criados os Models `FinanceiroCartaoOperadora`, `FinanceiroCartaoBandeira`, `FinanceiroCartaoTaxa`, `FinanceiroMovimentoCartao`, `OsCobrancaAgendamento`, `CrmFollowup`, `OrderItem` (`backend/app/Models/`), todos verificados contra as colunas reais do banco via tinker (somente leitura) antes da escrita
- [X] T019 Criado `backend/app/Services/Financeiro/FinanceiroCartaoService.php` (`simulate`, `normalizeModalidade`, `findApplicableRate`, `buildActiveDataset`), porte fiel da fórmula e do algoritmo de seleção de taxa do legado, validado contra as taxas reais já cadastradas (Mercado Pago/Stone/Ton)
- [X] T020 Criada `backend/database/migrations/2026_06_29_000001_create_os_closure_module_tables.php` com `Schema::hasTable()` guard para as 6 tabelas do módulo (no-op em produção). Índices verificados via `SHOW INDEX` no banco real antes de escrever: corrigido um índice que havia sido escrito como `unique()` (`crm_followups.origem_evento`) quando a produção o tem como não-único; nomes de índice curtos (`cliente_id`, `os_id` etc.) trocados por nomes auto-gerados pelo Laravel (prefixados pela tabela) porque SQLite exige nomes de índice únicos no banco inteiro, diferente do MySQL
- [X] T021 Estendido `backend/app/Services/Orders/OrderClosureService.php`: `metadata()` agora retorna `custo_summary`, `retorno_padrao` e `cartao`; `close()` aceita `recebimentos[]` (múltiplos, com classificação e dados de cartão), simula taxa de cartão antes da transação, registra `financeiro_movimentos_cartao` e a despesa de taxa, aplica o status intermediário `entregue_pagamento_pendente` quando sobra saldo (exceto em "sem reparo"/"descartado"), agenda/cancela cobranças em `os_cobranca_agendamentos`, e cria follow-up via `createReturnFollowup()` quando `agendar_retorno`; novo método público `processPendingChargeNotifications()` para o comando agendado. A notificação (manual e agendada) usa `WhatsappMessagingService::sendSystemMessage()` (camada já existente do módulo de chat/inbox)
- [X] T022 Criado `backend/app/Console/Commands/ProcessPendingOsCollections.php` (`app:process-pending-os-collections`) e registrado em `backend/routes/console.php` (`->everyFifteenMinutes()`)
- [X] T023 Reescrito `backend/app/Http/Requests/Api/V1/CloseOrderRequest.php` para validar `recebimentos[]` (tipos apenas; a obrigatoriedade condicional de operadora/modalidade em pagamentos de cartão fica só em `FinanceiroCartaoService::simulate()`, fonte única dessa regra). Adicionado `invalid_card_payment` (422) em `OrderController::close()`, e `custo_summary`/`retorno_padrao`/`cartao` na resposta de `closureMetadata()`
- [X] T024 Removido o wrapper `IntegrationSettingsService::sendMessage()` (ficou órfão depois que a notificação passou a usar `WhatsappMessagingService` diretamente)
- [X] T025 Atualizado `backend/tests/Concerns/BuildsLegacyErpSchema.php`: 3 status novos em `seedOrderCatalog()` (`devolvido_sem_reparo`, `descartado`, `entregue_pagamento_pendente`, espelhando os atributos reais) e nova `createOrderItemsTable()` (`os_itens`, tabela puramente legada sem migration própria, adicionada ao pipeline de `rebuildLegacySchema()`)
- [X] T026 Atualizados 2 testes existentes em `OrderFlowTest.php` para o novo payload (`recebimentos[]` em vez de `valor_recebido`/`forma_pagamento`) e para o mock de `WhatsappMessagingService::sendSystemMessage` (em vez do wrapper removido)
- [X] T027 Adicionados 8 testes novos em `OrderFlowTest.php`: taxa de cartão + despesa registrada, recebimento de cartão sem taxa ativa (422, sem efeito colateral), saldo em aberto aplica status intermediário + 3 agendamentos, reabertura com pagamento total cancela agendamentos antigos, status "sem reparo" ignora saldo em aberto e não agenda cobrança, `agendar_retorno` cria follow-up com dedupe, comando processa cobrança vencida com sucesso, comando cancela agendamento de OS que saiu do status pendente
- [X] T028 Atualizado `backend/openapi.yaml`: novo schema `CloseOrderReceipt`, `CloseOrderRequest` reescrito para `recebimentos[]`/`agendar_retorno`/`retorno_data` (validado com `js-yaml` de outro projeto do mesmo ambiente, já que não há parser YAML disponível no próprio projeto)
- [X] T029 **Executado e confirmado**: `OrderFlowTest` 30/30 passando (115→153 assertions). Suite completa do backend sem regressão além das 2 falhas pré-existentes e não relacionadas já conhecidas antes desta sessão (`DashboardSummaryTest` x2 — `modulos.id=7` duplicado entre o seed da trait e um insert direto no próprio teste; `FinanceiroReportTest` x1 — bug de fuso horário/comparação de string em `whereBetween` contra coluna datetime em `cashFlowReport()`). Ambas fora do escopo desta feature; não corrigidas aqui.

## Phase 7: Desktop — assistente de 3 etapas (paridade completa)

- [X] T030 Reescrito `frontends/desktop/resources/views/orders/closure.blade.php` como assistente de 3 etapas (Encerramento → Financeiro → Confirmação): recebimentos dinâmicos (valor/classificação/forma de pagamento/data/observações + campos de cartão condicionais), atalhos "Receber saldo total" e "Adicionar adiantamento", cards de resumo (custo de peças/serviços, taxas/líquido/lucro estimados), toggle de notificação (condicionado a telefone), toggle de retorno pós-serviço, checklist visual e checkbox de confirmação obrigatório
- [X] T031 Criado `frontends/desktop/public/assets/js/orders-closure.js`: navegação de etapas com validação antes de avançar, add/remove de recebimentos com reindexação de `name`, exibição condicional dos campos de cartão, replicação em JS do algoritmo de seleção de taxa (`findApplicableRate`) para pré-visualização não autoritativa, reconstrução de recebimentos antigos em caso de erro de validação (`old()`)
- [X] T032 Atualizada a validação de `frontends/desktop/app/Http/Controllers/OrderController.php::closureStore()` para `recebimentos[]`/`agendar_retorno`/`retorno_data` (sem alteração em `OrderService::closureMetadata()`/`close()`, que já eram passthrough genérico)
- [X] T033 Adicionado teste novo em `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`: renderização do catálogo de cartão na tela e envio de `recebimentos[]`/`agendar_retorno`/`retorno_data` corretamente repassado ao backend (`Http::assertSent`)

## Phase 8: Documentacao e revisao final (paridade completa)

- [X] T034 Atualizados `specs/011-acoes-edicao-baixa-os-desktop/{spec.md,plan.md,tasks.md}` para o escopo completo (User Stories 4/5/6 novas, FRs de cartão/cobrança/follow-up, Assumptions revisadas)
- [ ] T035 Nota de implementação em `documentacao/07-novas-implementacoes/` e `scaffold-release-note.php`/`sync-agent-docs.php` para a expansão de escopo — **pendente**
- [ ] T036 Validação final: suite do desktop com o teste de baixa MVP + o teste novo de cartão/recebimentos — **execução em andamento no momento da escrita desta nota** (comando rodando em segundo plano demorou mais que o usual; sem indicação de falha real até agora, consistente com o atraso de notificação já documentado neste ambiente para `php artisan test`). Fluxo manual no navegador **não realizado** (sem driver de navegador neste ambiente).

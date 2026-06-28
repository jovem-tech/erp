# Ações de OS no desktop: dropdown, edição e baixa (paridade completa com o legado)

## Contexto

A listagem de Ordens de Serviço (`/os`) só tinha o botão "Detalhe" na
coluna "Ações". O pedido foi padronizar essa coluna no mesmo dropdown já
usado em `/equipamentos` e implementar as duas ações que faltavam:
edição completa da OS e baixa (encerramento). Ver
`specs/011-acoes-edicao-baixa-os-desktop/`.

A primeira entrega (2026-06-27) foi um MVP funcional: status final + data
de entrega + um lançamento financeiro + notificação WhatsApp manual,
deixando de fora deliberadamente o que o legado (`OsSettlementService`,
975 linhas) tinha de mais complexo: simulação de taxa de cartão,
cobrança agendada por WhatsApp em D+1/D+3/D+5 e follow-up de retorno via
CRM. Logo depois, o usuário pediu explicitamente a feature completa do
legado, incluindo esses três itens — esta nota documenta a entrega
ampliada.

## O que foi entregue

### Encerramento e múltiplos recebimentos

- `GET /api/v1/orders/{order}/closure` retorna o status atual, as opções
  de encerramento, o resumo financeiro, o resumo de custo de peças/
  serviços da OS (`custo_summary`), a data padrão de retorno
  (`retorno_padrao`, hoje + 180 dias) e o catálogo ativo de
  operadoras/bandeiras/taxas de cartão (`cartao`).
- `POST /api/v1/orders/{order}/closure` aceita uma lista de recebimentos
  (`recebimentos[]`, cada um com valor, classificação
  baixa/adiantamento/sinal, forma de pagamento, data e observações) em
  vez de um valor único. Cada recebimento é registrado como um movimento
  financeiro distinto via `FinanceiroService::registerMovement`.

### Simulação de taxa de cartão

- `FinanceiroCartaoService` (novo) porta a fórmula e o algoritmo de
  seleção de taxa do legado: `valor_taxa = valor_bruto * (taxa_percentual
  / 100) + taxa_fixa`, seleção por operadora + modalidade + intervalo de
  parcelas, priorizando taxa específica de bandeira sobre a genérica.
- A simulação roda **antes** de qualquer gravação; uma combinação
  operadora/bandeira/parcelas sem taxa ativa rejeita a baixa inteira
  (`422 ORDER_CLOSURE_CARD_PAYMENT_INVALID`) sem nenhum efeito colateral.
- Quando válida, grava o detalhe em `financeiro_movimentos_cartao` e cria
  uma despesa correspondente (`financeiro` tipo `pagar`, categoria "Taxa
  de cartão").

### Cobrança automática agendada

- Se sobrar saldo em aberto após os recebimentos (e o encerramento não
  for "sem reparo"/"descartado"), a OS recebe o status intermediário
  `entregue_pagamento_pendente` (status final desejado preservado em
  `status_final_pendente_pagamento`) e 3 cobranças são agendadas em
  `os_cobranca_agendamentos` (D+1/D+3/D+5 às 10h).
- Novo comando `app:process-pending-os-collections` (registrado no
  scheduler a cada 15 minutos) processa cobranças vencidas: cancela
  agendamentos de OS que já não têm mais saldo em aberto ou pendente,
  marca como erro quando o cliente não tem telefone, e tenta o envio nos
  demais casos.
- Reabrir a baixa da mesma OS cancela os agendamentos pendentes
  anteriores antes de decidir se cria novos.

### Follow-up de retorno (CRM)

- Quando o payload indica `agendar_retorno`, um registro é criado em
  `crm_followups`, deduplicado por OS + data prevista (`origem_evento`).
  Esta entrega **não** inclui uma tela de listagem/gestão desses
  follow-ups — só a criação pela baixa; a gestão fica para quando o
  módulo de CRM for implementado.

### Notificação por WhatsApp

- Tanto a notificação manual quanto a cobrança agendada passaram a usar
  `WhatsappMessagingService::sendSystemMessage()` — a mesma camada de
  mensageria já usada pelo módulo de chat/inbox — em vez de uma
  integração própria. Falha no envio não desfaz a baixa nem trava o
  comando agendado no registro com erro.

### Desktop: assistente de 3 etapas

- `closure.blade.php` foi reescrita como um assistente de 3 etapas
  (Encerramento → Financeiro → Confirmação) na mesma página (sem modal):
  recebimentos adicionados dinamicamente (com campos de cartão
  condicionais), atalhos "Receber saldo total" e "Adicionar
  adiantamento", cards de resumo com taxa/líquido/lucro estimados,
  toggle de retorno pós-serviço e checklist visual de apoio.
- `orders-closure.js` (novo) cuida da navegação entre etapas, dos
  recebimentos dinâmicos e de uma pré-visualização client-side (não
  autoritativa) da taxa de cartão, replicando o mesmo algoritmo de
  seleção de taxa do backend.

### Edição e dropdown de ações (sem alteração desde o MVP)

- Edição completa de OS no desktop (`/os/{id}/editar`), reaproveitando o
  endpoint `PATCH /api/v1/orders/{order}` que já existia.
- Coluna "Ações" da listagem de OS em dropdown
  (`os-actions-dropdown`/`-toggle`/`-menu`): Detalhe sempre disponível;
  Editar e Baixa apenas para quem tem permissão `os:editar`; Baixa some
  quando a OS já está com `estado_fluxo` em `encerrado` ou `cancelado`.

## Observações técnicas

- **Migration nova** (`2026_06_29_000001_create_os_closure_module_tables.php`):
  as 6 tabelas do módulo de cartão/cobrança/CRM já existem na produção
  compartilhada com dados reais; a migration usa `Schema::hasTable()`
  guard (no-op em produção) para que ambientes novos/CI/testes tenham o
  mesmo schema. Índices verificados via `SHOW INDEX` no banco real antes
  de escrever — um deles (`crm_followups.origem_evento`) havia sido
  rascunhado como `unique()` por engano; a produção o tem como
  não-único (a deduplicação é de aplicação, via `exists()`, não de
  banco). Nomes de índice curtos como `cliente_id`/`os_id` foram trocados
  por nomes auto-gerados pelo Laravel (prefixados pela tabela) porque o
  SQLite dos testes exige nomes de índice únicos no banco inteiro,
  diferente do MySQL de produção (que os escopa por tabela).
- `os_itens` (usada por `custo_summary`) é puramente legada, sem
  migration própria em nenhum ambiente; foi adicionada ao schema usado
  pelos testes (`BuildsLegacyErpSchema.php`), mesmo padrão de `os`/`clientes`.
- `IntegrationSettingsService::sendMessage()` foi adicionado e depois
  removido na mesma sessão: ficou órfão assim que a notificação passou a
  usar `WhatsappMessagingService` diretamente.
- `OrderWorkflowService::canAccessOrder()` passou de privado para
  público para ser reaproveitado por `OrderClosureService` sem duplicar
  a regra de escopo por técnico.
- Cobertura de testes: `backend/tests/Feature/Api/V1/OrderFlowTest.php`
  tem 30 testes de baixa (22 do MVP + 8 novos: taxa de cartão e despesa,
  recebimento de cartão sem taxa ativa, status intermediário com 3
  agendamentos, cancelamento de agendamentos ao reabrir com pagamento
  total, status "sem reparo" ignorando saldo aberto, follow-up com
  dedupe, e o comando agendado processando um envio com sucesso e uma
  cancelação). `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`
  ganhou um teste novo verificando a renderização do catálogo de cartão e
  o reenvio correto de `recebimentos[]`/`agendar_retorno`/`retorno_data`
  ao backend.
- Duas falhas pré-existentes e não relacionadas foram encontradas durante
  a verificação da suíte completa do backend (não corrigidas aqui, fora
  do escopo desta feature): `DashboardSummaryTest` (duplicidade de
  `modulos.id=7` entre o seed compartilhado e um insert direto no
  próprio teste) e `FinanceiroReportTest::test_fluxo_de_caixa_separa_realizados_de_previstos`
  (comparação de string em `whereBetween` contra uma coluna `datetime`
  com componente de hora, em `FinanceiroReportService::cashFlowReport()`).

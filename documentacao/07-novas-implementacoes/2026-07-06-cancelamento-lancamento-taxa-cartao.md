# Cancelamento de lançamento financeiro e taxa de cartão como despesa

## Contexto

- versao: `3.11.0.0`
- data: `2026-07-06`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- **Cancelar lançamento (backend + desktop):** novo endpoint `POST /api/v1/financeiro/{financeiro}/cancelar`
  (`FinanceiroController::cancel()` + `FinanceiroService::cancel()`) e rota espelho no desktop
  (`POST /financeiro/{financeiro}/cancelar`). Marca o título como `cancelado` e estorna
  (apaga) qualquer movimento de baixa já registrado — diferente de "Excluir" (hard delete),
  o registro é preservado para auditoria, só deixa de contar no fluxo de caixa e no DRE.
  Se o título já tiver uma despesa de "Taxa de cartão" vinculada (ver abaixo), ela também é
  cancelada em cascata (via `Financeiro.origem_id`), para não ficar órfã contando sozinha.
- **Botão "Cancelar"** no dropdown de Ações da tela de Lançamentos (`financeiro/index.blade.php`),
  visível para qualquer status diferente de "Cancelado" (o backend já bloqueia duplo cancelamento).
- **Taxa da operadora como despesa separada:** `FinanceiroService::registerCardFeeExpense()` —
  toda baixa em cartão com taxa configurada cria automaticamente uma despesa "Taxa de cartão"
  (tipo=pagar, grupo/subgrupo DRE "Despesas Operacionais/Taxas e impostos", já paga), com seu
  próprio `FinanceiroMovimento`, **datada no mesmo dia do pagamento** (não na data de repasse da
  operadora) — antes desta entrega a taxa nunca virava um lançamento real e o fluxo de caixa/DRE
  mostravam o valor bruto como se a assistência tivesse recebido 100% do valor.
- **DRE por competência:** `FinanceiroReportService::groupByCompetencia()` passou a excluir
  títulos com `status=cancelado`, que antes continuavam contando mesmo sem nunca terem sido pagos.
- **Correção de mensagens de validação cruas:** o frontend desktop não tinha nenhum arquivo de
  tradução de validação (`lang/pt_BR/validation.php`); qualquer regra sem mensagem customizada
  (ex.: `required_if` do campo operadora ao pagar em cartão) aparecia como a chave crua
  (`validation.required_if`) em vez de texto traduzido. Copiado o arquivo já usado no backend.

## Impactos

- Contrato da API: endpoint novo e aditivo (`POST /financeiro/{financeiro}/cancelar`), documentado
  em `backend/openapi.yaml`; nenhuma rota existente muda de contrato.
- Banco: nenhuma migration nova — reaproveita as colunas/tabelas já existentes
  (`financeiro.status=cancelado`, `financeiro.origem_tipo`/`origem_id`, `financeiro_movimentos`).
- Não altera a baixa de OS (`OrderClosureService`) — só o fluxo de "Lançamentos" avulsos. A baixa
  de OS tem uma criação de despesa de taxa própria e mais antiga, que não foi tocada nesta entrega
  (fica registrado como débito técnico conhecido: ela não seta `grupo_dre`/`subgrupo_dre` nem cria
  `FinanceiroMovimento` próprio, então essa taxa específica ainda não aparece nos relatórios).
- Segurança: rota nova protegida por `financeiro:editar` (mesma permissão de "Registrar baixa"),
  tanto na API central quanto no middleware do desktop.

## Validacao

- `backend/tests/Feature/Api/V1/FinanceiroTest.php`: cancelamento de título pendente, estorno de
  título parcial/pago com movimentos, bloqueio de cancelar duas vezes, taxa de cartão registrada
  como despesa separada no dia do pagamento (mesmo com repasse futuro).
- `backend/tests/Feature/Api/V1/FinanceiroReportTest.php`: DRE por competência ignora lançamento
  cancelado; cancelamento de lançamento pago estorna os valores do fluxo de caixa e do DRE de caixa.
- Suíte completa `FinanceiroTest` + `FinanceiroReportTest`: verde, sem regressão.
- Validação manual na tela (`/financeiro`): botão "Cancelar" aparece/some conforme o status,
  confirmação via modal, mensagem de erro traduzida ao faltar operadora numa baixa em cartão.

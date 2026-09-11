# Previsão de repasse e taxa de cartão: âncora no dia do pagamento, com dia útil

## Contexto

- versao: `5.82.1.0`
- data: `2026-09-10`
- ambiente-alvo: `Ubuntu VPS`
- reportado pelo Otávio a partir de um caso real em produção

## Bug

OS entregue e paga (reparado e pago) ao cliente no sábado 29/08/2026. A baixa só foi
digitada no sistema na segunda 31/08. Operadora com repasse em D+1.

| campo | esperado | produzido |
|---|---|---|
| entrega / pagamento ao cliente | 29/08 | 29/08 (ok) |
| baixa no sistema | 31/08 | 31/08 (ok) |
| repasse/recebimento previsto | 31/08 (29/08 + 1, domingo rolado p/ segunda) | **01/09** |
| taxa da operadora | 31/08 | **01/09** |

`FinanceiroCartaoService::simulate()` ancorava o prazo da operadora em `Carbon::now()`
— o dia em que a tela chamou a simulação — em vez do dia em que o cliente efetivamente
pagou. Uma baixa lançada 2 dias depois do pagamento (comum: equipamento sai da bancada
na sexta/sábado, o operador só formaliza no sistema na segunda seguinte) empurrava o
repasse previsto na mesma proporção. Também não existia nenhuma regra de dia útil: 30/08
(domingo) era aceito como data de repasse, o que nenhuma adquirente faz de verdade.

Reproduzido primeiro em teste (`OrderFlowTest`) antes de qualquer alteração de código,
confirmando os dois valores errados acima.

### Dois furos vizinhos, achados na mesma reprodução

- A despesa da taxa (`os_recebimento_cartao`) nascia **sem `data_competencia`**. Pelo
  fallback de `Financeiro::scopeCompetenciaEntre` (usa `data_vencimento` quando
  `data_competencia` é nulo), ela era reconhecida no mês do **repasse**, não no mês da
  **venda** — agosto ficava com a receita bruta da OS e sem o custo da maquininha, que só
  aparecia em setembro. Mesmo padrão que `FinanceiroService::registerCardFeeExpense()`
  (baixa avulsa em cartão) já fazia certo, só não estava espelhado em
  `OrderClosureService::registerCardFeeExpense()` (baixa de OS).
- `financeiro_movimentos_cartao.data_competencia` gravava **NULA** sempre que a tela de
  baixa de OS não mandava `data_pagamento` por recebimento (campo opcional na tela —
  normalmente só a data de entrega da OS é informada), enquanto o movimento financeiro
  em si ia datado corretamente pela data de entrega.

## Entrega

- **`FinanceiroCartaoService::simulate()`**: novo `resolveBaseDate()` lê a data do
  pagamento do payload (`data_base` / `data_pagamento` / `data_movimento`, nessa ordem),
  caindo para `now()` só quando nenhuma foi informada (a simulação solta da tela, antes
  de existir um movimento). Novo `creditDate()` soma o prazo em dias corridos a partir
  dessa data-base e rola para o próximo dia útil quando cair em sábado ou domingo — não
  existe repasse de adquirente no fim de semana. Feriados ficam de fora de propósito: o
  sistema não tem calendário de feriados, e `data_credito_efetivo` (a conciliação manual
  da conta, já existente) sobrepõe essa previsão em todos os relatórios quando o dinheiro
  cai de fato.
- **`POST /financeiro/cartoes/simular`**: aceita `data_pagamento` opcional no payload
  (contrato aditivo — nenhum campo existente mudou). Resposta ganha `data_base_repasse`
  (a data usada como âncora, para a tela poder exibir "repasse contado a partir de
  29/08", por exemplo).
- Os três caminhos que chamavam `simulate()` passaram a informar a data real do
  pagamento em vez de deixar cair no fallback de `now()`:
  - `OrderClosureService::simulateCardPayments()` (baixa e adiantamento/sinal de OS) —
    recebe a mesma `$dataReferencia` que `processReceipts()` já usa para datar o
    movimento;
  - `FinanceiroService::registerCardMovement()` (baixa avulsa de um lançamento em
    cartão) — usa `$dataMovimento`, que já existia;
  - `SalePaymentService::simulateCards()` (venda do PDV) — usa `data_pagamento` já
    resolvido por `SaleWorkflowService::normalizePayments()` (cai para a data da venda
    quando o pagamento não informa a própria data).
- `OrderClosureService::registerCardFeeExpense()` (taxa da baixa de OS) passa a gravar
  `data_competencia` = data do pagamento — mesma competência que a despesa da venda que a
  gerou, mesmo padrão que a baixa avulsa em cartão já usava.
- `OrderClosureService::processReceipts()` resolve a data do movimento uma única vez e
  repassa para `registerCardMovementMeta()`, que agora grava `data_competencia` sempre
  (nunca mais nula), em vez de depender do `data_pagamento` opcional do recebimento.

## Impactos

- Contrato da API: `POST /financeiro/cartoes/simular` ganha `data_pagamento` (request,
  opcional) e `data_base_repasse` (response) — aditivo, nada removido ou renomeado.
  `backend/openapi.yaml` atualizado.
- Banco: nenhuma migration. Só muda o valor calculado que já era gravado em
  `financeiro_movimentos_cartao.data_prevista_repasse/data_prevista_recebimento` e em
  `financeiro.data_vencimento/data_pagamento/data_competencia` da despesa de taxa.
- Lançamentos já baixados em produção **antes** desta correção mantêm as datas erradas
  gravadas (o fix não é retroativo) — só baixas novas, a partir do deploy, calculam
  certo. Não havia como corrigir os já lançados sem saber, um a um, se a operadora já
  havia efetivamente repassado naquele dia ou não.
- Telas do desktop que exibem `data_prevista_repasse`/`data_prevista_recebimento`
  (`financeiro/show.blade.php`, `financeiro/contas/index.blade.php`,
  `financeiro/relatorios/fluxo-caixa.blade.php`, `financeiro-cartoes.js`) não mudaram de
  código — passam a exibir a data já calculada certa, vinda do backend.

## Validação

- Reprodução do cenário real em teste (`OrderFlowTest`) **antes** de qualquer mudança de
  código, confirmando os dois valores errados (repasse e taxa em 01/09 em vez de 31/08).
- Testes de regressão novos, cobrindo a data-base e a rolagem de dia útil:
  - `FinanceiroCartaoTest::test_simulator_anchors_transfer_on_informed_payment_date_and_skips_weekend`
  - `OrderFlowTest::test_close_anchors_card_transfer_on_payment_date_and_next_business_day`
- Dois testes existentes de fluxo de caixa (`FinanceiroReportTest`) que calculavam a data
  de repasse com `now()->addDays(30)` foram travados no relógio (`Carbon::setTestNow`)
  para não dependerem do dia em que a suíte roda, e passaram a fixar a regra de dia útil
  (D+30 caindo em sábado rola para a segunda seguinte).
- Suíte completa do backend: 1278 passando. As 3 falhas remanescentes são de outra sessão
  trabalhando em paralelo no mesmo repositório (estoque/reservas, otimização de imagem de
  perfil, catálogo de arquivos legado) — confirmadas com `git stash` das minhas mudanças
  que a falha já existia sem elas.

## Fontes consultadas

- `backend/app/Services/Financeiro/FinanceiroCartaoService.php`
- `backend/app/Services/Orders/OrderClosureService.php`
- `backend/app/Services/Financeiro/FinanceiroService.php`
- `backend/app/Services/Sales/SalePaymentService.php`
- `backend/app/Http/Controllers/Api/V1/FinanceiroCartaoController.php`
- `backend/app/Models/Financeiro.php` (`scopeCompetenciaEntre`)
- `documentacao/07-novas-implementacoes/2026-07-06-fluxo-caixa-entrada-projetada-saldo-liquido.md`
- `documentacao/07-novas-implementacoes/2026-06-28-cartoes-taxas-desktop.md`

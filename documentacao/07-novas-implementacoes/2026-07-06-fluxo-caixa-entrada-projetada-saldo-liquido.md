# Fluxo de caixa: entrada projetada, saldo líquido e detalhamento diário

## Contexto

- versao: `3.12.0.0`
- data: `2026-07-06`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- **Coluna "Entrada projetada"** na lista do fluxo de caixa (`FinanceiroReportService::projectedCashInByDay()`):
  agrega entradas pelo dia em que o dinheiro **efetivamente cai na conta**, não pelo dia da venda/baixa
  — imediato (dinheiro/pix/boleto/transferência) no próprio `data_movimento`; cartão na data de
  crédito/repasse (`financeiro_movimentos_cartao.data_credito_efetivo` ou `data_prevista_recebimento`),
  que pode cair em outro mês. Valor bruto, de propósito — a taxa continua separada (ver v3.11.0.0).
- **Coluna "Saldo líquido em conta"** (`netCashDeltaByLandingDay()`/`netCashDeltaAcumulado()`): saldo
  acumulado de verdade em caixa — usa o valor **líquido** (`valor_liquido`, já descontada a taxa) no
  dia de pouso do dinheiro para vendas em cartão, e exclui explicitamente a despesa "Taxa de cartão"
  (via `origem_tipo`) para não descontar a taxa duas vezes (uma vez embutida no líquido, outra se
  contada como saída separada).
- **Bug pré-existente corrigido:** "Saldo inicial" (`netMovimentos(null, $inicio->subDay())`) só somava
  o dia imediatamente anterior ao período (`$end ??= $start` colapsava para um intervalo de 1 dia) em
  vez do histórico completo da conta. Novo método `netMovimentosAcumulado()` corrige isso; "Saldo
  realizado"/"Saldo final" passam a refletir o histórico real. Números existentes mudam (tendem a subir).
- **Modal de detalhamento por dia** (botão "Detalhes"): duas tabelas — "Pago/recebido neste dia"
  (mesma base de "Entradas"/"Saídas") e "Entradas de cartão previstas para cair hoje" (mesma base de
  "Entrada projetada"), com origem, cliente/fornecedor (`Financeiro::supplier()`, relação nova), forma
  de pagamento, valor e quando o dinheiro cai na conta.
- **Modal de detalhes do cartão** (botão na coluna Forma): operadora, bandeira, modalidade, parcelas,
  taxa percentual/fixa, valor da taxa, valor líquido, prazo de recebimento — um modal por
  `movimento_id`, deduplicado entre as duas tabelas do modal do dia.
- **Fix de modal empilhado (Bootstrap 5):** abrir o modal de detalhes do cartão de dentro do modal do
  dia (dois `.modal` simultâneos, padrão não suportado oficialmente pelo Bootstrap) fazia o modal
  interno remover `body.modal-open` ao fechar mesmo com o externo ainda aberto (perde o scroll-lock) e
  os dois nascerem com o mesmo z-index. Corrigido de forma genérica em `desktop.js`
  (`shown.bs.modal`/`hidden.bs.modal`), reutilizável por qualquer modal-em-modal futuro no sistema —
  confirmado com teste headless (jsdom + bootstrap.bundle.min.js real) antes e depois do fix.

## Impactos

- Contrato da API: `GET /financeiro/relatorios/fluxo-caixa` ganha campos aditivos em
  `linhas_diarias[]` (`entrada_projetada`, `saldo_liquido`, `detalhes.movimentos`,
  `detalhes.previstos_para_hoje`) e no nível raiz (`saldo_liquido_inicial`, `saldo_liquido_final`).
  Nenhum campo existente foi removido ou renomeado.
- Banco: nenhuma migration nova — só relações Eloquent novas (`Financeiro::supplier()`,
  `Financeiro::origemMovimento()`, `FinanceiroMovimento::cartao()`) sobre colunas já existentes.
- Payload do endpoint cresce (passa a incluir nomes de cliente/fornecedor por lançamento do mês, não
  só totais agregados) — aceitável no volume de uma assistência técnica pequena; se crescer, dá para
  extrair para um endpoint sob demanda sem quebrar o contrato atual.
- Modo Calendário do mesmo relatório não foi alterado (fora de escopo desta entrega).
- A correção do saldo inicial muda números já exibidos em produção (para refletir a realidade).

## Validacao

- `backend/tests/Feature/Api/V1/FinanceiroReportTest.php`: entrada projetada com pagamento imediato,
  com cartão no mesmo mês, cruzando para o mês seguinte, somando múltiplas vendas no mesmo dia de
  pouso, taxa de cartão não vazando na entrada projetada; detalhamento do dia separando cliente de
  fornecedor; saldo inicial somando todo o histórico; saldo líquido reconhecendo o valor líquido no
  dia do pouso (não no dia da venda) e acumulando corretamente vendas em cartão de períodos anteriores.
- Suíte completa `FinanceiroReportTest` + `FinanceiroTest`: 30 testes, verde, sem regressão.
- Teste headless isolado (jsdom + bootstrap.bundle.min.js real) reproduzindo e depois validando a
  correção do bug de modal empilhado, antes de alterar `desktop.js`.
- Validação manual na tela (`/financeiro/relatorios/fluxo-caixa`): coluna nova, modal do dia, submodal
  de cartão, valores conferidos contra um lançamento real pago em cartão.

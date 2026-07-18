# Data Model: Gestão de contas financeiras

## `financeiro_contas`

- `id`
- `nome` (único)
- `tipo`: `caixa`, `banco`, `adquirente`, `reserva`, `carteira_digital`, `outra`
- `instituicao` (opcional)
- `data_inicio_controle`
- `considera_disponivel`
- `ativo`
- `created_by`, `updated_by`
- timestamps

O saldo não é coluna. Ele é uma projeção das baixas atribuídas e dos movimentos patrimoniais.

## `financeiro_conta_movimentos`

- `id`, `conta_financeira_id`
- `transferencia_id` (opcional)
- `tipo`: `saldo_inicial`, `ajuste`, `transferencia`
- `natureza`: `entrada`, `saida`
- `status`: `realizado`, `cancelado`
- `data_movimento`, `valor`
- `descricao`, `documento_ref`
- `created_by`, `cancelado_por`, `cancelado_em`, `motivo_cancelamento`
- timestamps

## `financeiro_transferencias`

- `id`, `conta_origem_id`, `conta_destino_id`
- `data_transferencia`, `valor`, `descricao`, `documento_ref`
- `status`: `realizada`, `cancelada`
- `created_by`, `cancelado_por`, `cancelado_em`, `motivo_cancelamento`
- timestamps

Uma transferência tem exatamente dois movimentos patrimoniais: saída da origem e entrada no destino.

## `financeiro_conta_defaults`

- `forma_pagamento` (única)
- `conta_financeira_id`
- timestamps

## Alterações aditivas

- `financeiro_movimentos.conta_financeira_id` nullable e indexado.
- `financeiro_movimentos_cartao.credito_confirmado_por` nullable.
- `financeiro_movimentos_cartao.credito_confirmado_em` nullable.

## Fórmulas

`disponível = movimentos patrimoniais realizados + baixas imediatas + cartões com crédito efetivo`

`a receber = cartões sem crédito efetivo, pelo valor líquido`

`posição total = disponível + a receber`

`saldo final mensal = saldo antes do mês + entradas - saídas`

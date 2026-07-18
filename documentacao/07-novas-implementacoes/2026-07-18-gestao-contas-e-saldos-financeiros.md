# Gestão de contas e saldos financeiros

Data: 18/07/2026

Especificação: `specs/021-gestao-contas-financeiras/`

Módulo: Financeiro > Contas e Saldos

## Objetivo

Controlar quanto a assistência possui efetivamente em cada local acumulador — caixa físico, bancos, maquininhas, carteiras digitais e reservas — sem transformar saldo antigo, conciliação ou transferência interna em faturamento do mês.

A feature separa dois conceitos que antes eram levantados manualmente:

- **resultado**: faturamento, custos, despesas e lucro, consultados nos DREs;
- **patrimônio financeiro**: onde o dinheiro está e quanto está disponível, consultado em Contas e Saldos.

## Configuração inicial recomendada

Cadastre uma conta para cada lugar em que existe dinheiro ou valor disponível. Para o cenário da assistência:

| Conta | Tipo | Saldo inicial de exemplo | Disponível operacional | Forma padrão |
|---|---|---:|---|---|
| Caixa físico | Caixa físico | R$ 3.000,00 | Sim | Dinheiro |
| Conta Inter | Banco | R$ 1.900,00 | Sim | Pix |
| TOM | Adquirente / maquininha | R$ 3.000,00 | Sim, se o valor já estiver liberado | Cartão de crédito e débito |
| Reserva de lucro | Reserva | Valor real existente | Não | Nenhuma |

O saldo inicial deve ser o saldo real na data em que o controle começa. Ele é um movimento patrimonial e não aparece como receita, despesa ou lucro no DRE.

O sistema não tenta adivinhar a distribuição de baixas antigas. Essa decisão evita reclassificação incorreta do histórico. O ponto de partida é o saldo inicial conciliado de cada conta.

## Fluxo operacional

### Recebimentos e pagamentos

Toda baixa que impacta caixa passa a possuir uma `conta_financeira_id`:

- a forma de pagamento responde **como** o cliente pagou;
- a conta financeira responde **onde** o valor entrou ou saiu.

É possível definir uma conta padrão por forma. Exemplo: Pix seleciona Conta Inter e dinheiro seleciona Caixa físico. O operador ainda pode escolher outra conta na baixa.

Depois que existir ao menos uma conta ativa, uma baixa que impacta caixa exige uma conta explícita ou um padrão configurado. Antes da ativação do módulo, o comportamento legado permanece compatível.

### Cartão líquido

Um recebimento em cartão não entra imediatamente no saldo disponível:

1. a venda é registrada pelo valor bruto para quitar o título e compor o faturamento;
2. a taxa da operadora continua registrada no DRE como despesa;
3. em Contas e Saldos, o valor aparece em **Cartão a receber** já líquido da taxa;
4. quando o crédito aparecer no extrato da maquininha/conta, a gerente confirma a data efetiva;
5. somente então o valor líquido passa ao saldo disponível da conta.

Isso impede contar simultaneamente o valor bruto e a despesa da taxa na posição de tesouraria.

### Transferência entre contas

Use **Transferir** para mover dinheiro entre Inter, caixa, TOM e reserva. A operação cria uma saída na origem e uma entrada no destino dentro de uma única transação de banco.

Transferência interna:

- não cria lançamento em `financeiro`;
- não altera faturamento;
- não altera DRE;
- rejeita saldo insuficiente;
- pode ser cancelada com motivo, desde que o destino ainda possua saldo para o estorno.

No fechamento mensal, o lucro que será separado deve ser enviado à conta Reserva de lucro por transferência interna, nunca por uma nova receita ou despesa.

### Conciliação

Use **Conciliar** somente quando a contagem física ou o extrato externo for diferente do sistema. Todo ajuste exige natureza, valor, data e descrição.

O ajuste altera apenas o patrimônio. Ele não corrige faturamento nem substitui o cancelamento/correção de uma baixa lançada incorretamente.

## Leitura dos indicadores

- **Disponível operacional**: soma dos saldos de contas ativas marcadas como disponíveis.
- **Total em contas**: soma de todas as contas ativas, inclusive reservas.
- **Reservado**: saldos ativos que não fazem parte do caixa operacional.
- **Cartão a receber**: créditos de cartão ainda não confirmados, pelo valor líquido.
- **Posição total**: total em contas mais cartão líquido a receber.

Cada conta mostra saldo no início do mês, entradas, saídas e saldo final. O extrato unifica baixas financeiras realizadas, créditos de cartão, saldo inicial, ajustes e transferências, preservando a origem de cada linha.

## Modelo técnico

Tabelas novas:

- `financeiro_contas`;
- `financeiro_conta_defaults`;
- `financeiro_conta_movimentos`;
- `financeiro_transferencias`.

Alterações:

- `financeiro_movimentos.conta_financeira_id` vincula a baixa à conta;
- `financeiro_movimentos_cartao.data_credito_efetivo`, `credito_confirmado_por` e `credito_confirmado_em` controlam a confirmação do crédito.

Não foi criado um segundo livro-caixa para duplicar as baixas existentes. O saldo é calculado a partir de:

```text
saldo disponível = saldo inicial + ajustes + transferências
                  + recebimentos imediatos - pagamentos imediatos
                  + cartões líquidos com crédito efetivamente confirmado
```

Cartões sem confirmação integram apenas `cartao_a_receber`.

As consultas usam índices por conta/data/status. Transferências, cancelamentos e confirmações usam transações e bloqueio pessimista nas linhas críticas para reduzir risco de corrida e saldo negativo.

## API

Rotas principais:

- `GET /api/v1/financeiro/contas`;
- `POST /api/v1/financeiro/contas`;
- `PATCH /api/v1/financeiro/contas/{conta}`;
- `GET /api/v1/financeiro/contas/{conta}/extrato`;
- `POST /api/v1/financeiro/contas/{conta}/ajustes`;
- `POST /api/v1/financeiro/contas-transferencias`;
- `POST /api/v1/financeiro/contas-transferencias/{transferencia}/cancelar`;
- `POST /api/v1/financeiro/contas-cartoes/{cartao}/confirmar`.

O contrato completo está em `backend/openapi.yaml` e em `specs/021-gestao-contas-financeiras/contracts/api.md`.

## Segurança e integridade

- leitura exige `financeiro:visualizar`;
- criação, edição, conciliação, transferência e confirmação exigem `financeiro:editar`;
- validação de IDs é repetida no backend, sem confiar nos selects do desktop;
- contas inativas não recebem novas movimentações;
- datas anteriores ao início de controle da conta são rejeitadas;
- saídas patrimoniais e transferências não podem gerar saldo negativo;
- parâmetros de extrato são limitados a 366 dias e 100 itens por página;
- valores são gravados em colunas `DECIMAL`, e todas as mutações relevantes preservam autoria/data.

## Validação executada

Cobertura automatizada adicionada em `FinanceiroContaTest` e `OrderFlowTest`:

- saldos iniciais não criam receita;
- conta padrão e conta explícita nas baixas;
- bloqueio de baixa sem conta após ativação;
- cartão pendente e confirmação pelo valor líquido;
- transferência atômica e cancelamento;
- rejeição de saldo insuficiente;
- conciliação sem impacto em DRE;
- extrato e paginação;
- fechamento de OS usando a conta padrão.

## Limitações conhecidas e evolução

Esta entrega não importa automaticamente extratos do Banco Inter ou da TOM. A conciliação e a confirmação de cartão são manuais, porém centralizadas e auditáveis. Uma evolução futura pode adicionar integração bancária/adquirente com importação idempotente e fila de conciliação, sem mudar o modelo patrimonial desta feature.

## Ativação no ambiente LAN

Em 18/07/2026, a migration `2026_07_18_000001_create_financeiro_contas_tables` foi aplicada no servidor de desenvolvimento LAN como batch 19, após backup completo e validado do banco. As quatro tabelas patrimoniais e as colunas de vínculo/auditoria foram verificadas diretamente no schema.

Também foram reconstruídos autoload, assets Vite e caches de configuração, rotas e views. O smoke test HTTP confirmou a nova rota na API e no desktop. Nenhuma conta ou saldo ilustrativo foi criado automaticamente: a gerente deve cadastrar os saldos reais conciliados na data escolhida para início do controle.

# Estoque: fechamento da janela de corrupção e fim dos truncamentos (2026-08-27)

**Spec:** `specs/036-estoque-nucleo-razao/spec.md` (Fase 1a, correção)
**Tipo:** correção de entrega anterior (PATCH)

## O defeito que eu introduzi

A v5.58.0.0 relaxou a validação de quantidade de `integer` para `numeric`
"porque insumo se mede em fração" — **e não aplicou a migration que alargava as
colunas**. As quatro colunas continuaram `INT`.

MySQL, mesmo sob `STRICT_TRANS_TABLES`, **arredonda** decimal→int em vez de
recusar: `CAST(0.5 AS SIGNED)` = 1, `CAST(2.5 AS SIGNED)` = 3.

O resultado, medido no banco de desenvolvimento:

> Saída de 0,5 sobre saldo 3 → o PHP calcula 2,5 → o MySQL grava saldo **3**
> (inalterado) e movimentação **1**. O razão diz "saiu 1", o saldo diz "continua
> 3". Sem erro, sem aviso.

Antes da v5.58 a porta recusava 0,5 na validação. **O estado que eu deixei era
pior que o anterior.**

## Correção da migration antes de aplicar

Como ela nunca rodou em nenhum ambiente, foi emendada em vez de receber uma
corretiva. Tinha dois defeitos próprios:

| Defeito | Consequência |
|---|---|
| `MODIFY ... NOT NULL DEFAULT 0` fixo | tornaria `NOT NULL` três colunas hoje `NULL`-able |
| O mesmo `DEFAULT 0` | `pecas.estoque_minimo` tem `DEFAULT 1`; viraria 0, mudando o critério de estoque baixo pela porta dos fundos |

Agora ela **deriva** nulidade e default de `SHOW COLUMNS` e altera **só a
largura do tipo** — o único atributo que tem o direito de mudar. Verificado
depois de aplicar: `estoque_minimo` continua `NULL`-able com `DEFAULT 1.0000`.

Incluída na mesma migration: `orcamento_itens.quantidade` de `DECIMAL(10,2)`
para `DECIMAL(14,4)` — era a única tabela do fluxo que não comporta 3 casas
decimais, onde `0,250 kg` viraria `0,25`.

E corrigido um **espelho de teste que mentia**:
`BuildsLegacyErpSchema.php` declarava `orcamento_itens.quantidade` como
`decimal(12,3)` enquanto o banco real era `decimal(10,2)`. Os testes rodavam
contra um schema mais generoso que a produção — foi por isso que o truncamento
do PDF de orçamento nunca apareceu em teste.

## Os truncamentos: eram 11, não 8

| Onde | Efeito |
|---|---|
| **`BudgetPdfContextFactory:126`** | **o PDF que o cliente assina**: orçamento de 1,5 h imprimia "1", divergindo do total. Anterior à v5.58 e o pior da lista |
| `EstoqueController:616` | movimento de 0,5 aparecia como **0** na ficha da peça |
| `SaleWorkflowService:283` | saldo 1,25 chegava ao PDV como **1** — e o operador vendia a mais |
| `DashboardSummaryService:1134-1135` | card dizia "0 em estoque · mínimo 1" para 0,5 e 1,5 |
| `SaleStockService:161` | **o `int` estava no *return type* da closure**, não só no cast — trocar o cast sozinho não resolveria |
| `SaleStockService:177`, `SaleReturnService:499` | estorno e devolução de 0,5 creditavam 1 |
| `BudgetWorkflowService:374`, `SearchService:317` | saldo no seletor de peças e na busca global |
| `OrderPdfContextFactory:322` | **não é bug**: `os_itens.quantidade` é `int` de verdade. Mantido |

Removido também o comentário `// movimentacoes.quantidade é INT` de
`SaleReturnService:415`, que a migration tornou falso, e o PHPDoc
`quantidade: int` de `SaleStockService:204`.

**A regra de quantidade inteira em venda e devolução foi mantida de propósito.**
Sem o catálogo de unidades, ela é o único anteparo contra fração chegar ao razão
por aquela porta. Passa a ser dirigida pela unidade na próxima entrega.

## Testes

`EstoqueTruncamentoTest` — 4 casos: ficha da peça, busca do PDV, card de estoque
baixo e contexto do PDF de orçamento, todos provando `2,5` onde antes vinha `2`.

Duas asserções existentes precisaram mudar (`EstoqueFlowTest`,
`DashboardSummaryTest`): `assertJsonPath` compara com `===` e as respostas
passaram de `1` para `1.0`. Adaptadas com `assertEqualsWithDelta`, o mesmo
precedente que `FinanceiroMargemTest` já havia criado no repositório.

**Aceite duro mantido:** `SaleFlowTest` e `SaleReturnFlowTest` passam sem uma
linha alterada.

Backend: **854 passando, 0 falhas**. Desktop: **412 passando**, 5 pré-existentes.

## Prova em banco real

Transação revertida sobre `sistema_hml`, peça com saldo 3, saída de 0,5:

```
saldo final .... 2.5000   (antes da migration: 3, inalterado)
movimento ...... 0.5000   (antes da migration: 1)
```

## Próximo

Unidade de medida: catálogo fechado de 10 unidades (`UN CX PAR KIT` inteiras;
`M CM ML L G KG` fracionárias), validação dirigida pela unidade do item,
bloqueio de troca após a primeira movimentação. É o que dá sentido ao decimal —
e o que substitui a regra cega de quantidade inteira em venda e devolução.

# Fechando o ciclo com a OS: o fim do "R$ 0,00" (2026-08-27)

**Spec:** `specs/037-precificacao-integrada-ao-fluxo/spec.md` (Fase 5)
**Tipo:** correção de defeito + varredura de visibilidade (MINOR)

## O defeito

`OrderClosureService::buildCostSummary()` somava
`os_itens.preco_custo_referencia`. Medido no banco real: **2.306 linhas, ZERO
com custo preenchido**. O ERP novo nunca escreve nessa tabela e o legado parou
em 30/04/2026.

Resultado: a tela de baixa exibia **"Custo estimado de peças/serviços: R$ 0,00"
em toda OS** — exatamente no momento em que o dono decide se aquela OS deu
lucro.

## A correção

O custo passa a vir das **mesmas fontes que a margem usa**, para o encerramento
e o DRE nunca discordarem sobre a mesma OS:

| Componente | Fonte |
|---|---|
| Peças | saída de estoque valorizada — idêntico a `OsMargemService::custoPecasAplicadas()` |
| Serviços | `orcamento_itens.preco_custo_referencia` do orçamento vinculado, que a Fase 4 finalmente passou a preencher |

OS sem consumo registrado continua zerada — e isso é **verdade**, não defeito:
nenhuma peça saiu do estoque para ela. A diferença é que agora o zero significa
"não houve consumo", e não "o sistema não sabe olhar".

Esta é a prova mais forte de que as fases estão conectadas: o custo de serviço só
deixa de ser zero porque a Fase 4 preencheu a coluna.

## Varredura de visibilidade

Dois pontos ainda mostravam custo e margem para **qualquer um** com
`vendas:visualizar` — inclusive o balconista:

- `vendas/show.blade.php` — bloco de custo/margem no detalhe da venda
- `vendas/index.blade.php` — card de margem do período na listagem

Ambos passaram a exigir `financeiro:visualizar` ou `precificacao:visualizar`,
como o PDV, o orçamento e o cadastro já faziam.

**Isto é regressão de UX para quem hoje vê**, e está registrado como tal. Foi
feito porque é o mesmo dado que o dono decidiu proteger em todos os outros
lugares — manter aberto aqui tornaria a proteção decorativa: bastaria abrir a
listagem de vendas para ver a margem que o PDV esconde.

## Testes

`OrderClosureCustoSummaryTest` (2): custo de peça vem da saída de estoque e
deixa de ser zero; OS sem movimentação continua zerada.

`VendaTest` (desktop): o card de margem some para quem não tem permissão
financeira e volta para quem tem.

Backend: **885 passando, 0 falhas**. Desktop: **422 passando**, 5
pré-existentes. `OrderFlowTest` e `BudgetFlowTest` intocados.

## O que ainda mantém o custo em zero na prática

A correção faz o sistema **olhar no lugar certo**. Para o número aparecer, a OS
precisa ter saída de estoque registrada — e hoje **nenhuma tem**, porque a baixa
de peça na OS ainda é manual (`specs/036`, Fase 2 do roteiro de estoque).

O encerramento agora responde a pergunta certa; falta o estoque alimentá-lo.

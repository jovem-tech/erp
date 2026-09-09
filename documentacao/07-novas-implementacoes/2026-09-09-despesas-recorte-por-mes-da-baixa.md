# Despesas passam a listar também pelo mês da baixa, não só pelo vencimento

**Data:** 09/09/2026
**Versão:** `5.80.5.0`
**Status:** ativo no ambiente de desenvolvimento LAN; não publicado na VPS de produção

## O pedido

> "na pagina de despesas são listadas as despesas do mês. quero que se uma
> despesa pendente de outro mês, atrasada, paga no mês atual, seja listada no mês
> de pagamento mas continue sendo listada [no mês do vencimento]"

## O que estava errado

A tela **Despesas** (`/financeiro/despesas-fixas`) recortava o período
**exclusivamente por `financeiro.data_vencimento`** — tanto no filtro "Mês"
quanto na visão padrão ("mês corrente + atrasadas em aberto").

Consequência: uma despesa que venceu em agosto e só foi paga em setembro
**sumia da tela em setembro**, justamente no mês em que o dinheiro saiu do
caixa. Ela também não estava mais entre as "atrasadas", porque aquele ramo só
traz `status IN (pendente, parcial)`.

Isso não era descuido: era decisão registrada, com teste travando o
comportamento — *"Mês passado, já paga: NÃO deve aparecer (resolvida, é só
histórico)"*. O raciocínio era de **compromisso** (quando venceu); faltava o de
**caixa** (quando saiu o dinheiro). Quem acompanha despesa quer os dois.

## A regra nova

**Um título aparece no mês do vencimento E no mês da baixa.** Simétrica de
propósito: vale para a baixa depois do vencimento (atraso) e antes dele
(adiantamento).

| Decisão | Por quê |
|---|---|
| Aparece nos **dois** meses, não migra de um para o outro | O compromisso é do mês do vencimento; a saída de caixa é do mês da baixa. Apagar o primeiro esconderia o atraso |
| Vale no filtro "Mês" **e** na visão padrão | Escolher o mês explicitamente é justamente quando se quer fechar aquele mês |
| Totalizadores contam nos dois meses | O total do mês tem de bater com a lista que está logo abaixo dele |
| Regra **simétrica** (pega também baixa adiantada) | Os títulos 147/152/153 da base real vencem 25/09 e foram pagos 21/08 — o caso espelho já existe |
| Badge "Pago em atraso" / "Pago adiantado" | Sem ele a linha se confunde com uma despesa que venceu no próprio mês |
| Só a tela de Despesas, via flag | Lançamentos cobre também "a receber" e não foi pedido; a flag deixa a decisão reversível numa linha |

## Onde a mudança mora

Todo o recorte de período vive num lugar só: `Financeiro::scopeWithFilters()`.
A listagem (`FinanceiroService::list()`) e os totalizadores
(`FinanceiroService::totaisFixoVariavel()`) passam pelo mesmo scope — por isso
**os totais acompanharam sozinhos**, sem uma segunda alteração.

O scope ganhou a flag `incluir_baixas_do_periodo`. Ligada, os dois blocos de
período passam a ser *"venceu no período **OU** foi baixado no período"*:

- bloco `mes`: `orWhere` sobre `whereYear/whereMonth('data_pagamento')`;
- bloco `periodo_atual_e_atrasadas`: `orWhereBetween('data_pagamento', [início, fim do mês])`.

O desktop manda a flag **incondicionalmente** em `despesasFixas()` — na visão
padrão e com mês escolhido. `index()` (Lançamentos) não manda, e segue por
vencimento puro.

## Armadilhas

**O `orWhere` do mês precisa estar agrupado.** `tipo`, `status`,
`dre_fixo_mensal` e `cliente_id` já foram aplicados no mesmo builder antes do
bloco de período. Um `orWhere` solto vazaria por cima de todos eles — filtrar
`status=pendente` passaria a trazer título pago. O bloco `mes` foi envolvido num
`where(function ...)`; o de `periodo_atual_e_atrasadas` já era agrupado. Há
teste dedicado a isso (`..._nao_vaza_por_cima_dos_demais_filtros`).

**`data_pagamento` é NULL em pendente e cancelado** — derivada dos movimentos
por `syncFromMovements()`, volta a NULL quando a baixa é desfeita. Por isso o
ramo novo não precisa de guarda de status: só entra título efetivamente baixado.
E como é `OR` sobre a mesma linha (não `JOIN`), não há risco de linha duplicada.

**O invariante "a visão padrão nunca mostra mês futuro" ganhou uma exceção.**
Um título com vencimento futuro já baixado neste mês passa a aparecer — é
coerente com "o dinheiro saiu neste mês", e é exatamente o caso dos 147/152/153.
Está anotado no comentário do bloco.

**A mesma despesa soma no total de dois meses diferentes.** É o que foi pedido,
mas não é óbvio para quem olha a tela: por isso o label virou "Mês (vencimento
ou baixa)" e há uma linha de explicação logo abaixo dos totalizadores.

**O recibo de pagamento de fatura de cartão continua fora dos totais.** Exclusão
incondicional que já existia (`origem_tipo = fatura_cartao_credito`): as
despesas que ele resume já entram individualmente. Some-lo contaria duas vezes.

## O que muda na prática (base real, 09/09/2026)

Visão padrão de setembro: **nada muda** — 147/152/153 já apareciam pelo
vencimento (25/09).

Filtrando **agosto/2026**, entram três títulos pela baixa (21/08):

| | Sem a flag | Com a flag |
|---|---|---|
| Títulos listados | 16 | 19 (`+147, +152, +153`) |
| Total despesas fixas | R$ 1.735,90 | R$ 1.760,90 (`+25,00`, o 147) |
| Total despesas variáveis | R$ 318,20 | R$ 338,20 (`+20,00`, o 152) |

O 153 (R$ 45,00) aparece na lista mas não soma: é recibo de fatura de cartão.

Não existe hoje nenhuma **despesa** paga em atraso na base — os quatro casos de
baixa fora do mês do vencimento com atraso real (#3, #5, #9) são `tipo=receber`,
que não aparecem nesta tela. A regra passa a valer no próximo pagamento
atrasado, que é o cenário do pedido.

## Arquivos

**Backend:** `app/Models/Financeiro.php` (`scopeWithFilters`) ·
`tests/Feature/Api/V1/FinanceiroTest.php` (4 testes novos)

**Desktop:** `app/Http/Controllers/FinanceiroController.php` (`despesasFixas`) ·
`resources/views/financeiro/despesas-fixas.blade.php` (label + nota dos totais) ·
`resources/views/financeiro/_lancamentos_table.blade.php` (badge) ·
`tests/Feature/Desktop/FinanceiroTest.php` (1 teste novo + 2 estendidos)

Nenhuma migration, nenhuma rota nova, nenhum arquivo de código novo.

## Verificação

Backend: 1198 testes passando (a falha em `EstoqueFlowTest` é anterior e
independente — reproduz com o `Financeiro.php` do HEAD). Desktop: `FinanceiroTest`
38/38; as 9 falhas da suíte completa são idênticas com e sem esta mudança
(baseline tirado trocando os arquivos alterados pela versão do HEAD).

## Pendências

- **`financeiro.data_pagamento` não tem índice** (só `data_vencimento` tem). Com
  o volume atual não pesa; se a listagem ficar lenta, é o primeiro lugar a olhar.
  Criar o índice exige migration com o atrito conhecido do `sistema_erp_chat`
  (aplicar com `--path` e espelhar em `BuildsLegacyErpSchema`).
- **Lançamentos segue por vencimento puro.** Se um dia quiser o mesmo recorte de
  caixa lá — inclusive para "a receber" —, é acrescentar
  `'incluir_baixas_do_periodo' => '1'` em `FinanceiroController::index()` e
  ajustar o label do filtro. O backend já suporta.

# Entrada de estoque no lançamento financeiro (2026-08-28)

**Spec:** `specs/039-entrada-estoque-no-lancamento/spec.md`
**Tipo:** nova funcionalidade + migration aditiva (MINOR)

## O problema

Financeiro e Estoque eram duas ilhas. Quando a oficina comprava peça —
acessório, peça para cliente, peça sobressalente — o operador digitava a despesa
em `/financeiro/novo` e teria de ir a `/estoque` lançar a entrada à mão, noutra
tela, redigitando peça, quantidade e custo.

É o mesmo padrão que a `038` corrigiu do outro lado: **o que exige segunda tela
redigitando não acontece.** Lá o resultado foi CMV zerado em 2.187 OS; aqui era
o dinheiro sair registrado e o saldo de estoque ficar errado.

E não existia **nenhum** caminho de entrada que gerasse movimentação. O único
lugar que somava saldo era o CRUD de peça, gravando `quantidade_atual` direto —
os três "furos" documentados na `036`.

Detalhe que reforça: a categoria seed `Compra de peças` nasce com
`impacta_dre_padrao = false`. Comprar peça **não é** custo de DRE, é aquisição de
ativo; o custo vira CMV quando a peça sai. O lado da saída ficou pronto na `038`;
a entrada era a metade que faltava.

## O que foi entregue

**Uma seção "Entrada no estoque" dentro do próprio formulário de lançamento**,
com lista repetível de peças (peça, quantidade, custo unitário), busca por
código/nome, cadastro rápido de peça inline e sugestão de preço de venda.

| Decisão | Por quê |
|---|---|
| Movimentações criadas dentro do `DB::transaction` do `FinanceiroService::create()` | Título e estoque atômicos: nunca sobra um sem o outro |
| Chamada **antes** do ramo de parcelas | A compra aconteceu uma vez; o que se parcela é o pagamento. Depois, 3× geraria 3 entradas |
| `movimentacoes.financeiro_id` (coluna nova) | Quarto membro da família `os_id`/`venda_id`/`venda_item_id`. Referência em `motivo` (texto livre) não é rastreabilidade |
| `custo_unitario` adiantado da `036` | Com N itens, o custo por linha só existe naquele instante — `financeiro.valor` guarda só o total |
| Toda escrita via `EstoqueMovimentacaoService` | Motor único da `038`: lock ordenado, decremento atômico. Uma quarta porta tornaria o problema permanente |
| Soma dos itens > valor = **erro**; < valor = **aviso** | Comprar mais do que se pagou é digitação errada. Pagar mais é normal: frete, imposto, item que não é peça |
| Validação da soma no `withValidator()`, não no serviço | `resolveClassification()` troca `valor` pela 1ª parcela quando `parcelas > 1` — depois dele a comparação seria contra o número errado |
| `preco_custo` sobrescrito; `preco_venda` só se aplicado | O primeiro é fato novo da nota. O segundo é decisão comercial de alguém |
| Autorização dupla `financeiro:criar` + `estoque:editar` | Mexe no saldo. `editar` e não `criar`, como na `038` |

**Cancelar estorna o estoque** — decisão diferente da `038`, de propósito. Lá a
peça foi fisicamente aplicada num aparelho; aqui o caso que motiva o
cancelamento é o **equívoco** (lançamento errado, peça que nunca chegou), e
deixar o saldo inflado seria a mentira. Se a peça já saiu, o erro nomeia as
peças e exige `confirmar_estoque_insuficiente` — mesmo padrão do PDV e da `038`.

**`preco_custo` não volta no estorno.** Não existe histórico do valor anterior, e
adivinhar seria pior que não mexer. A mensagem de cancelamento diz isso em voz
alta.

**Editar itens é recusado com 422**, não ignorado em silêncio: ignorar faria o
operador acreditar que salvou. Diffar entradas gravadas exige o `estorno_de_id`
que ainda é da `036`.

## A armadilha que quase custou o dado

`estoque.quick.store` (cadastro rápido) cai em `EstoqueController::store()`, que
grava `quantidade_atual` **direto, sem gerar movimentação**. A quantidade
comprada entra pela movimentação que o lançamento gera ao salvar.

Se o modal também mandasse a quantidade, **o saldo contaria duas vezes** — em
silêncio, sem erro nenhum. O modal envia `quantidade_atual = 0` fixo, comentado
no Blade e no JS, e há teste travando isso.

Segunda armadilha, essa pega no navegador e não no teste: os selects `tipo` e
`categoria` viram Select2 pelo auto-init global, e Select2 dispara `change` só
via jQuery. `addEventListener('change')` puro passaria na suíte e **nunca**
dispararia na interação real. O bind é duplo.

Terceira: linhas em branco precisam sair **antes** do `validate()`. A tabela
sempre tem uma linha vazia esperando preenchimento, e o `min:1` do `peca_id`
reprovaria o lançamento inteiro por causa dela.

## Descoberta

Sem 5º item no menu "+Novo": **F5 é a tecla de recarregar do navegador**, e
`initQuickCreateShortcuts` engole a tecla sempre que o link existe — mapeá-la
sequestraria o reload para todo usuário com `financeiro:criar`. Além disso não é
documento novo, é um campo dentro de "Novo lançamento", que já é F4.

A porta é um botão **"Entrada por compra"** na tela de Estoque, apontando para
`/financeiro/novo?tipo=pagar&entrada_estoque=1` — onde o operador está quando
pensa em estoque.

## Arquivos

**Backend:** `2026_08_28_000001_add_financeiro_link_to_movimentacoes.php` ·
`EntradaPecaService` (novo) · `EstoqueMovimentacaoService` (2 campos) ·
`UpsertFinanceiroRequest` · `CancelFinanceiroRequest` · `FinanceiroService`
(create/cancel/detailContext) · `FinanceiroController` · `Movimentacao` ·
`BuildsLegacyErpSchema`

**Desktop:** `FinanceiroController` (searchParts, payload, permissões, cancel) ·
`FinanceiroService` · rota `financeiro.parts.search` · `financeiro/form.blade.php`
· partials `entrada-estoque`, `entrada-estoque-item-row`, `peca-quick-modal` ·
`financeiro/create.blade.php` · `financeiro/show.blade.php` ·
`estoque/index.blade.php` · `financeiro-entrada-estoque.js` (novo) ·
`financeiro-form.js` (expõe os helpers de máscara)

**Testes:** `FinanceiroEntradaEstoqueTest` no backend (16) e no desktop (11).
Os dois de rollback são os que provam a fronteira transacional: se alguém mover
a chamada para fora do `DB::transaction`, quebram na hora.

## Pendências

- Teste de concorrência no grupo `mysql` — `lockForUpdate()` é no-op em SQLite e
  a suíte padrão roda SQLite. Mesmo risco já registrado na `036` e na `038`.
- Quando a `036` Bloco B criar `pecas.custo_ultima_entrada`, a escrita de
  `preco_custo` do `EntradaPecaService` deve alimentá-la também.

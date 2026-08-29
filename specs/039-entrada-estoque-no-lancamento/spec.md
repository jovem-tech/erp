# Entrada de estoque no lançamento financeiro

> **Nota de numeração:** o roteiro original de estoque reservava a `039` para
> "reserva de peça ao aprovar orçamento" (ver `038/spec.md`, Fora de escopo).
> Essa entrega não começou, e a entrada de compra tem precedência: sem ela o
> saldo de estoque nasce errado, e reservar peça de um saldo errado não resolve
> nada. A reserva passa para a `040`.

## Problema

Financeiro e Estoque são duas ilhas. Quando a oficina compra peça — acessório,
peça para cliente, peça sobressalente — o operador digita a despesa em
`/financeiro/novo` e depois teria de ir a `/estoque` lançar a entrada à mão,
noutra tela, redigitando peça, quantidade e custo.

É o mesmo padrão que a `038` acabou de corrigir do outro lado: **o que exige
segunda tela redigitando não acontece.** Lá o resultado foi CMV zerado em 2.187
OS; aqui o resultado é o dinheiro sair registrado e o saldo de estoque ficar
errado.

E hoje não existe **nenhum** caminho de entrada que gere movimentação. O único
lugar que soma saldo é o CRUD de peça (`EstoqueController::store()`/`update()`/
`importCsv()`), que grava `quantidade_atual` direto — são os três "furos"
documentados na `036`. A tela genérica de movimentação (`tipo = entrada`) existe,
mas é desligada de fornecedor, de custo e do financeiro, e usa o
read-modify-write sem lock que a `038` já condenou.

Detalhe que reforça o argumento: a categoria seed `Compra de peças` nasce com
`impacta_dre_padrao = false`. Comprar peça **não é** custo de DRE — é aquisição
de ativo; o custo só vira CMV quando a peça sai. O lado da saída ficou pronto na
`038`. A entrada é a metade que faltava.

## Objetivo

Fazer a compra de peça ser **um formulário só**: o lançamento financeiro que
registra o gasto é o mesmo ato que dá entrada no estoque.

## Decisões

- **A movimentação JÁ É o registro da entrada** — mesma decisão da `038`. Não se
  cria tabela intermediária de compra; `movimentacoes` ganha `financeiro_id`,
  quarto membro da família `os_id`/`venda_id`/`venda_item_id` que já responde
  "qual documento gerou este movimento".
- **`financeiro_id`, não `compra_id`.** `compra_id` (planejado na `036`) aponta
  para um documento de nota que não existe. Um título a pagar não é uma nota de
  compra — quando Compras nascer, os dois vão coexistir na mesma linha, como
  `os_id` e `venda_id` já coexistem.
- **Nada de referência em `motivo`.** É texto livre sem índice, e a `036` está
  justamente depreciando esse campo em favor de `motivo_codigo`. Rastreabilidade
  que depende de `LIKE '%#123%'` (que casa `#1234` também) não é rastreabilidade.
- **`custo_unitario` sai adiantado da `036`.** Com N itens num lançamento, o
  custo por linha **só existe neste instante** — `financeiro.valor` guarda só o
  total. Não gravar agora é perder o dado para sempre.
- **Motor único.** Toda escrita de saldo passa por
  `EstoqueMovimentacaoService::registrarLote()`, que já suporta `entrada`. Uma
  quarta porta de escrita tornaria permanente o problema que a `038` fechou.
- **Uma entrada por compra, não por parcela.** A chamada fica antes do ramo de
  parcelamento do `FinanceiroService::create()`: comprar em 3x gera 3 títulos e
  **um** conjunto de movimentações.
- **Atomicidade real.** As movimentações nascem dentro da transação que já existe
  no `create()`. Nunca sobra entrada sem lançamento nem lançamento sem entrada.
- **Soma dos itens maior que o valor é erro; menor é aviso.** Comprar mais do que
  se pagou é digitação errada. Pagar mais do que a soma das peças é normal —
  frete, imposto, item que não é peça.
- **Cancelar o lançamento estorna o estoque.** Decisão do dono, e diferente da
  `038` de propósito: lá a peça foi fisicamente aplicada num aparelho; aqui o
  caso que motiva o cancelamento é o **equívoco** — lançamento de peça errado, ou
  peça lançada que nunca chegou. Se a peça já saiu, o estorno deixaria saldo
  negativo: pede confirmação explícita nomeando as peças, como o PDV e a `038`.
- **`preco_custo` é sobrescrito pelo custo da compra; `preco_venda` nunca é
  mexido sozinho.** O primeiro é fato novo trazido pela nota. O segundo é decisão
  comercial de alguém — a sugestão do simulador aparece com botão "Aplicar", e só
  vai ao servidor se clicada. Vale a "regra do sujo" do cadastro de peça (`037`).
- **Autorização dupla**: `financeiro:criar` **e** `estoque:editar`. Mesma escolha
  da `038` — `editar` e não `criar`, porque o que se faz é mexer no saldo.

## Escopo

`movimentacoes.financeiro_id` + `movimentacoes.custo_unitario` ·
`EntradaPecaService` (nome genérico: Compras vai reusar) · `itens_estoque[]` no
contrato de `POST /financeiro` · estorno no `cancel()` · bloqueio de `delete()`
com 409 · seção "Entrada no estoque" no formulário de lançamento, com linhas
repetíveis, busca de peça, cadastro rápido inline e sugestão de preço · entradas
geradas visíveis no detalhe do lançamento · botão "Entrada por compra" na tela de
Estoque.

## Fora de escopo

- **Editar itens de um lançamento salvo.** `PUT/PATCH` recusa `itens_estoque` com
  422 — não ignora em silêncio. Diffar entradas gravadas exige o estorno formal
  (`estorno_de_id`) que é da `036`.
- **Custo médio ponderado** — `036` Bloco B. O que se grava aqui é o último custo
  de compra digitado por um humano, não uma média. Quando
  `pecas.custo_ultima_entrada` existir, esta escrita deve alimentá-la também.
- **Módulo de Compras** (documento de nota fiscal, múltiplos títulos por nota).
- **5º item no menu "+Novo".** F5 é a tecla de recarregar do navegador, e
  `initQuickCreateShortcuts` engole a tecla sempre que o link existe — mapeá-la
  sequestraria o reload para todo usuário com `financeiro:criar`. Além disso não
  é documento novo, é um campo dentro de "Novo lançamento", que já é F4. A
  descoberta é resolvida pelo botão na tela de Estoque, que é onde o operador
  está quando pensa em estoque.

## Riscos

- **Contagem dupla no cadastro rápido.** `estoque.quick.store` cai em
  `EstoqueController::store()`, que grava `quantidade_atual` sem movimentação. Se
  o modal enviar a quantidade da compra, o saldo conta duas vezes — em silêncio,
  sem erro. O modal envia `quantidade_atual = 0` fixo, e isso está comentado no
  Blade e no JS porque é invisível para quem ler depois.
- **`lockForUpdate()` é no-op em SQLite** e a suíte padrão roda SQLite — mesmo
  risco registrado na `036` e na `038`. O teste de concorrência vive no grupo
  `mysql`.
- **Select2 não dispara `change` nativo.** O JS novo observa `financeiroTipo` e
  `financeiroCategoria`, que viram Select2 pelo auto-init global. Um
  `addEventListener('change')` puro passa no teste e nunca dispara no navegador;
  o bind é duplo (jQuery + nativo).
- **`preco_custo` não volta no estorno.** Não existe histórico do custo anterior,
  e adivinhar seria pior que não mexer. A mensagem de cancelamento diz isso.
- **Esta entrega empilha na `038`, que ainda não está commitada** (o
  `EstoqueMovimentacaoService` é arquivo novo não versionado). Se a `038` for
  revertida, o motor some junto.

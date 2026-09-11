# Reserva de peça de estoque vinculada ao orçamento (2026-09-10)

**Spec:** `specs/040-reserva-peca-orcamento/spec.md`
**Tipo:** funcionalidade nova (MINOR)

## O problema, medido

`orcamento_itens` aponta para `pecas` pelo par `tipo_item='peca'` +
`referencia_id` — **sem FK e sem nenhuma validação de saldo**. Dois orçamentos
podiam prometer a mesma peça única ao cliente, e ninguém descobria até o técnico
abrir a gaveta.

O seletor de peça piorava: um `<select>` com catálogo estático de **80 peças**,
que **recebia `quantidade_atual` do backend e o descartava no mapeamento** de
`create.blade.php`/`edit.blade.php`. Quem orçava não via estoque nenhum, e a
peça 81 não existia.

E um bug latente escondido atrás de um dos furos de escrita: em
`EstoqueController::update()`, a regra `'quantidade_atual' => ['nullable', ...]`
com `round((float) ($validated['quantidade_atual'] ?? 0), 4)` fazia **um PATCH
que apenas omitisse o campo zerar o saldo da peça em silêncio**. O formulário do
desktop sempre enviava, o que escondia isso de todo mundo.

## O que foi entregue

**A peça de um orçamento enviado ao cliente fica reservada** para aquele
orçamento e equipamento, some do disponível de todos os outros caminhos (PDV,
baixa de OS, outros orçamentos), e volta ao estoque quando o orçamento é
rejeitado, cancelado, vence, ou quando o operador solta na mão.

**A peça que falta é marcada na hora de orçar** — *Em estoque* / *Parcial* /
*A encomendar* — e some numa tela consolidada "Peças a comprar".

### Reconciliação, não eventos espalhados

O orçamento muda de estado em **oito** pontos diferentes, e
`BudgetWorkflowService::syncItems()` **apaga e reinsere todos os itens a cada
save** — o id de `orcamento_itens` não sobrevive a uma edição.

Espalhar `reservar()`/`liberar()` por esses oito pontos garantiria que um deles
ficasse para trás na próxima feature. Em vez disso,
`EstoqueReservaService::sincronizar()` calcula o estado **desejado** a partir de
`(status, itens)` e aplica o delta. É idempotente, e um ponto de transição novo
só precisa chamá-lo.

Por isso a reserva é chaveada por **`(orcamento_id, peca_id)`**, nunca por id de
item — o repositório já assumia isso em `cotacaoCongelada()`.

### Tabela própria, e o cache

`estoque_reservas` é a verdade: reserva tem ciclo de vida
(`ativa`/`consumida`/`liberada`), vencimento e dono, e `movimentacoes` é imutável
por design e registra fato consumado, não promessa.

`pecas.quantidade_reservada` é apenas o **cache** do somatório, para o disponível
ser lido sem JOIN no caminho quente. É reescrito por **soma absoluta dentro do
lock**, nunca por delta — deliberadamente um read-modify-write, seguro porque
roda sob o `lockForUpdate()` da mesma linha de `pecas`. Soma absoluta é
auto-reparável; delta acumularia deriva para sempre.

### O detalhe que decide se presta

`disponível = quantidade_atual − quantidade_reservada + reserva_do_próprio_orçamento`

Sem o último termo, a reserva bloquearia exatamente a baixa que ela existia para
proteger: o técnico abriria a OS do orçamento que reservou a peça e levaria "sem
saldo" por causa da própria promessa.

### Furos de escrita fechados

Dos quatro caminhos que gravavam saldo por fora do motor, os **dois críticos**:

- `update()` passa a **recusar** `quantidade_atual` com 422 — e não a ignorar em
  silêncio (mesmo critério da `039` com `itens_estoque`). Fecha de brinde o bug
  do PATCH que zerava o saldo.
- `storeMovement()` roteia `entrada`/`saida` pelo motor único, matando junto o
  read-modify-write sem lock e o truncamento em zero que a `038` já condenara.
  `ajuste` fica no controller (o motor só soma e subtrai) mas ganhou guarda:
  ajuste abaixo do reservado exige confirmação explícita.

`store()` e `importCsv()` ficam para a `036` Bloco B — peça recém-criada não tem
reserva, então o risco lá é saldo errado, não reserva furada.

### Telas

- Badge por item no detalhe do orçamento + alerta "N peças precisam ser
  adquiridas".
- Aviso de estoque na linha do item do formulário, recalculado quando a
  quantidade muda.
- **Busca remota paginada de peça** substitui o catálogo de 80, no mesmo padrão
  da entrada por compra da `039`, com o catálogo estático como fallback
  automático quando Select2/jQuery não estão disponíveis.
- `GET /estoque/a-comprar` e tela "Peças a comprar", com drill-down de quem
  segura cada peça e link direto para a entrada por compra.
- Colunas **Reservado** e **Disponível** na listagem de estoque.

## Testes

- `EstoqueReservaTest` (15): rascunho não reserva; envio reserva; falha de
  disparo não reserva; rejeitado/cancelado/vencido liberam; edição ajusta;
  duas linhas da mesma peça viram uma reserva; liberação manual sobrevive ao
  reenvio; consumo parcial deixa remanescente; disponível soma a reserva
  própria; `recalcular()` repara cache divergente; item órfão não quebra.
- `EstoqueFurosDeEscritaTest` (7): os dois furos, incluindo o PATCH que zerava.
- `OsAplicacaoPecaTest` (+3): reserva própria não bloqueia a própria baixa;
  reserva de terceiro bloqueia nomeando o ofensor; baixa parcial.
- `EstoqueTest` desktop (+5): busca remota, tela e permissões.

**Aceite duro cumprido:** `SaleFlowTest` e `SaleReturnFlowTest` passam **sem uma
linha alterada** — mesma régua que a `036` impôs. Funciona porque
`quantidade_reservada` nasce `0`.

Backend: **1282 passando**, 1 falha pré-existente (`EstoqueFlowTest`, payload
sem `estoque_subcategoria_id` desde a taxonomia de setembro — anterior a esta
entrega e confirmada no baseline).

## O que isto NÃO faz

- **Não prova o lock.** `lockForUpdate()` é no-op em SQLite e a suíte roda
  SQLite. O teste de concorrência no grupo `mysql` está pendente.
- **Não encomenda ao fornecedor.** A lista diz o que comprar e leva ao
  lançamento de entrada da `039`; módulo de Compras continua não existindo, e
  `pecas.fornecedor` segue varchar livre sem FK.
- **Não reserva retroativamente.** Orçamentos já enviados antes desta entrega só
  passam a reservar quando forem tocados (reenvio, edição ou decisão).

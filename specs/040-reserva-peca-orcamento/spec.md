# Reserva de peça de estoque vinculada ao orçamento

> **Nota de numeração:** o roteiro original de estoque previa esta entrega como
> `039`. A `039` acabou usada pela entrada de estoque no lançamento financeiro,
> que tinha precedência — sem ela o saldo nascia errado, e reservar peça de um
> saldo errado não resolve nada (ver `specs/039-entrada-estoque-no-lancamento/spec.md`,
> linhas 3-7: *"A reserva passa para a `040`"*).

## Problema

`orcamento_itens` referencia peça pelo par `tipo_item='peca'` + `referencia_id`,
**sem FK e sem nenhuma validação de saldo**. Dois orçamentos podiam prometer a
mesma peça única ao cliente e ninguém descobria até o técnico abrir a gaveta.

Pior: o seletor de peça do orçamento era um `<select>` alimentado por um catálogo
estático de 80 peças (`BudgetWorkflowService::formData()`) que **recebia
`quantidade_atual` do backend e o descartava no mapeamento** de
`create.blade.php` / `edit.blade.php`. O operador montava a proposta sem ver
estoque nenhum — e a peça 81 simplesmente não existia para quem orçava.

E havia quatro caminhos gravando `pecas.quantidade_atual` por fora do motor de
movimentação. Um deles, `EstoqueController::update()`, tinha a regra
`'quantidade_atual' => ['nullable', ...]` combinada com
`round((float) ($validated['quantidade_atual'] ?? 0), 4)`: **um PATCH que apenas
omitisse o campo zerava o saldo da peça em silêncio**. O formulário do desktop
sempre enviava, o que escondia o bug de todo mundo.

## Objetivo

Peça de orçamento enviado ao cliente fica reservada para aquele
orçamento/equipamento e some do disponível de todo mundo; a peça que falta
aparece marcada na hora de orçar e some numa lista consolidada de compra.

## Decisões

- **A reserva nasce no ENVIO, não no rascunho** (decisão do dono). Rascunho
  abandonado não pode segurar peça. `pendente_envio` fica de fora de propósito:
  é o estado de *falha* de disparo — o orçamento não chegou ao cliente, então
  não há promessa a honrar. `reenviar_orcamento` reserva: a negociação continua
  viva e soltar a peça no meio dela seria perdê-la.

- **Reconciliação, não eventos espalhados.** O orçamento muda de estado em oito
  pontos diferentes e `BudgetWorkflowService::syncItems()` apaga e reinsere todos
  os itens a cada save. Espalhar `reservar()`/`liberar()` por esses pontos garante
  que um deles fique para trás na próxima feature. `EstoqueReservaService::
  sincronizar()` calcula o estado desejado a partir de `(status, itens)` e aplica
  o delta — idempotente, e um ponto de transição novo só precisa chamá-lo.

- **Chaveada por `(orcamento_id, peca_id)`, nunca por `orcamento_item_id`.**
  `syncItems()` faz delete-all + insert, então o id do item não sobrevive a uma
  edição. O repositório já assumia isso em `cotacaoCongelada()`, que casa itens
  por `tipo_item:referencia_id`.

- **Tabela própria MAIS coluna denormalizada.** `estoque_reservas` é a verdade —
  reserva tem ciclo de vida (`ativa`/`consumida`/`liberada`), vencimento e dono,
  e `movimentacoes` é imutável por design (sem `updated_at`) e registra fato
  consumado, não promessa. `pecas.quantidade_reservada` é só o **cache** do
  somatório, para o disponível ser lido sem JOIN no PDV e nas listagens.

- **O cache é reescrito por soma absoluta dentro do lock, não por delta.** É
  deliberadamente um read-modify-write — o oposto da regra 3 do motor — e é
  seguro porque roda sob o `lockForUpdate()` da mesma linha de `pecas`. Soma
  absoluta é auto-reparável: qualquer deriva some na próxima sincronização.
  Delta acumularia o erro para sempre.

- **A reserva do próprio orçamento volta para o disponível.** É o detalhe que
  decide se a feature presta: sem ele, o técnico abriria a OS do orçamento que
  reservou a peça e levaria "sem saldo" por causa da própria reserva.

- **Reservar acima do saldo é permitido.** É exatamente isso que produz a
  marcação "A encomendar": a promessa fica registrada, e a compra fecha a conta.

- **Bloqueio real, com confirmação explícita para furar.** Mesma decisão do PDV
  e da `038`: recusar sem saída faria o operador contornar por fora do sistema,
  e aí some o registro inteiro, não só o saldo.

- **Liberação manual é respeitada pela reconciliação** (`liberacao_manual`).
  Se o reenvio do orçamento a desfizesse, o botão "liberar" seria mentira. Mudar
  a quantidade do item, porém, é intenção nova e a reserva volta.

- **Sem slug de permissão novo.** `permissoes` é tabela de produção e ação nova
  exige migration própria. Liberação manual usa `estoque:editar` — mesma escolha
  da `038` e da `039` ("`editar` e não `criar`, porque o que se faz é mexer no
  saldo").

## Escopo

`estoque_reservas` + `pecas.quantidade_reservada` · `EstoqueReservaService`
(`sincronizar`/`liberarManual`/`consumir`/`disponibilidade`/`recalcular`) ·
disponível passa a valer no motor único, no PDV e na baixa da OS · consumo da
reserva na baixa · fechamento dos dois furos críticos de escrita ·
badge Em estoque/Parcial/A encomendar no orçamento · busca remota paginada de
peça · tela e endpoint "Peças a comprar" · colunas Reservado/Disponível no
estoque.

## Fora de escopo

- **`EstoqueController::store()` e `importCsv()`** — os outros dois furos. Peça
  recém-criada ou importada não tem reserva, então o risco lá é saldo errado,
  não reserva furada. Ficam para a `036` Bloco B.
- **`tipo='ajuste'` pelo motor único.** O motor só soma e subtrai; ajuste fixa
  valor absoluto e pertence à contagem de inventário da `036`. Aqui ele apenas
  ganhou guarda contra deixar reserva sem lastro.
- **Custo médio ponderado** — `036` Bloco B.
- **Módulo de Compras / pedido ao fornecedor.** A porta de entrada continua
  sendo o lançamento financeiro da `039`; `pecas.fornecedor` ainda é varchar
  livre, sem FK.

## Riscos

- **`lockForUpdate()` é no-op em SQLite** e a suíte padrão roda SQLite — mesmo
  risco registrado na `036`, na `038` e na `039`. O teste de concorrência de
  reserva precisa viver no grupo `mysql`, e **ainda não foi escrito**.
- **Cast `integer` em `quantidade_reservada`** truncaria 0,5 em silêncio. O cast
  é `decimal:4`, mas é o erro mais provável de quem mexer depois.
- **O cache pode divergir** se alguém escrever em `estoque_reservas` por fora do
  serviço. `recalcular()` conserta, mas nada o chama sozinho hoje.

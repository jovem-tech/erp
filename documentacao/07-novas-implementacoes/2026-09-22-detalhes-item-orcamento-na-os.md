# Detalhes da peça e do serviço no detalhe da OS (2026-09-22)

**Tipo:** alteração em tela e payload existentes, sem migration nem rota nova
**Versão:** v6.1.2.0
**Complementa:** [Reserva de peça de estoque vinculada ao orçamento](2026-09-10-reserva-peca-orcamento.md)
(de onde vem o cálculo de saldo/reservado/disponível) e a visibilidade de custo
da specs/037 (quem vê custo em reais).

## O problema

A seção **Peças e serviços do orçamento**, no detalhe da OS (`/os/{id}`),
mostrava só tipo, descrição, quantidade, valor unitário e total. Para saber de
qual peça do estoque se tratava, quem era o fornecedor, se havia saldo na
gaveta ou quanto custou, o técnico tinha de sair da OS, abrir o orçamento e de
lá o cadastro da peça — três telas para responder "essa peça está aqui?".
Para serviço, nem isso: a linha não dizia qual item do catálogo era, nem o
tempo padrão previsto.

## O que muda

Cada linha da seção ganha o botão **Detalhes** (ícone de olho), que abre um
modal com a ficha do item. O modal é renderizado com o payload que a OS já
carrega — **sem requisição extra** ao abrir.

### Linha de peça → "Detalhes da peça"

| Bloco | Conteúdo |
|-------|----------|
| No orçamento | descrição, quantidade (com unidade), valor unitário, desconto e acréscimo (se houver), total, observações do item |
| No estoque | código, código do fabricante, nome no cadastro, classificação (Grupo › Categoria › Subcategoria), modelos compatíveis, **fornecedor**, localização, preço de venda de cadastro, observações da peça |
| Saldo | saldo em estoque, reservado para este orçamento, reservado para outros, disponível, estoque mínimo e **"Falta para esta OS"** (em vermelho) quando o disponível não cobre a quantidade orçada |
| Custo e margem | custo unitário gravado no orçamento, custo total, **custo atual no cadastro**, margem da linha (R$ e %) |

Chips no topo: código da peça, estado (**Em estoque / Parcial / A encomendar**,
mesmo cálculo do detalhe do orçamento), "Peça inativa no cadastro" e
**"Custo mudou desde o orçamento"** quando o custo do cadastro difere do que
foi gravado na linha. Rodapé: **Abrir no estoque** (quem edita estoque) ou
**Ver movimentações** (quem só visualiza).

### Linha de serviço → "Detalhes do serviço"

| Bloco | Conteúdo |
|-------|----------|
| No orçamento | igual ao da peça |
| No catálogo de serviços | nome e descrição do cadastro, tipo de equipamento, tempo padrão (h), valor de catálogo, tributação (item LC 116, código de tributação nacional, alíquota ISS) |
| Custo e margem | custo unitário/total do orçamento, **custo direto padrão do catálogo**, margem da linha |

Rodapé: **Abrir no catálogo** (quem edita serviços).

### Item sem ficha

Item digitado à mão no orçamento (sem `referencia_id`) mostra só o bloco "No
orçamento" e a frase *"Peça digitada à mão no orçamento, sem vínculo com o
cadastro de estoque"* (ou o equivalente para serviço). Referência a um
cadastro já excluído avisa que ele não existe mais.

## Quem vê o quê

A regra da specs/037 continua valendo: **a redação é feita no payload do
backend, nunca na view** — um `@if` no Blade esconderia o pixel e deixaria o
número no DOM.

| Dado | Precisa de |
|------|------------|
| Bloco "No orçamento" e o botão Detalhes | `os:visualizar` (já é o gate da tela) |
| Ficha da peça e Saldo | `estoque:visualizar` |
| Ficha do serviço | `servicos:visualizar` |
| Custo (orçamento e cadastro) e margem | `financeiro:visualizar` **ou** `precificacao:visualizar` (`VisibilidadeCusto::COMPLETO`) |

Sem a permissão, a chave simplesmente não vem no JSON (`peca`/`servico` = `null`;
`preco_custo_referencia`, `valor_margem`, `preco_custo`, `custo_direto_padrao`
ausentes) e o modal explica: *"A ficha do cadastro de estoque não está
disponível para o seu perfil."*

## Decisões

**Um modal por linha, em Blade, sem fetch.** Mesmo padrão do
`_checklist_detail_modal`: os dados já estão em `$order['orcamento']['itens']`,
o orçamento tem poucos itens e o botão usa `data-bs-toggle="modal"` puro. Evita
rota nova, JS novo e um estado de "carregando…" para dado que a página já tem.

**Saldo com a reserva do próprio orçamento somada de volta.** O backend usa o
mesmo `EstoqueReservaService::disponibilidade($ids, $orcamentoId)` da specs/040:
a peça que este orçamento reservou não pode aparecer como falta na OS dele.

**Custo do cadastro ao lado do custo gravado.** `preco_custo_referencia` é o
custo no momento do orçamento; `preco_custo` (peça) / `custo_direto_padrao`
(serviço) é o custo de hoje. Mostrar os dois — com o chip de divergência — é o
que permite perceber que a margem calculada envelheceu antes de fechar a OS.

**Saldo e Custo lado a lado.** Empilhados em largura total, o modal passava de
1,8 telas de rolagem a 768 px; em duas colunas ficou em 1,4. Quando só um dos
blocos existe, ele ocupa a largura toda (`desktop-grid-span-2`).

## Payload (backend)

`OrderWorkflowService::mapLinkedBudget()` passa a enviar, por item:

- `referencia_id` (antes ausente — a tela não sabia qual peça/serviço era);
- `peca` (só `tipo_item = peca`, com referência válida e `estoque:visualizar`):
  identificação, classificação, fornecedor, localização, unidade, `preco_venda`,
  `quantidade_atual`, `reservado_para_este`, `reservado_por_terceiros`,
  `quantidade_disponivel`, `falta`, `estado`, `estoque_minimo`, `ativo`,
  `status`, `observacoes` e, com custo visível, `preco_custo`;
- `servico` (só `tipo_item = servico`, com referência válida e
  `servicos:visualizar`): `nome`, `descricao`, `tipo_equipamento`, `unidade`,
  `valor`, `tempo_padrao_horas`, `item_lc116`, `codigo_tributacao_nacional`,
  `aliquota_iss`, `status`, `ativo` e, com custo visível, `custo_direto_padrao`;
- com custo visível: `preco_custo_referencia`, `valor_margem`, `percentual_margem`.

Peças e serviços são carregados em uma consulta cada (`loadBudgetParts`,
`loadBudgetServices`), indexados por id.

## Arquivos

- `backend/app/Services/Orders/OrderWorkflowService.php` — `mapLinkedBudget()`,
  `loadBudgetParts()`, `loadBudgetServices()`, `mapBudgetItemPart()`,
  `mapBudgetItemService()`.
- `frontends/desktop/resources/views/orders/show.blade.php` — coluna com o botão
  **Detalhes** e `@include('orders._item_detalhe_modal')` em `@push('modals')`.
- `frontends/desktop/resources/views/orders/_item_detalhe_modal.blade.php` — novo;
  um modal por item (`id="osItemDetalheModal-{item_id}"`).
- `backend/tests/Feature/Api/V1/OrderFlowTest.php` —
  `test_show_budget_items_carry_part_and_service_sheets_and_cost_only_with_permissions`.
- `frontends/desktop/tests/Feature/Desktop/OrderItemDetalheTest.php` — novo, 2 testes.

## Validação automatizada

Backend (`php artisan test --filter=test_show tests/Feature/Api/V1/OrderFlowTest.php`):
técnico só com `os:visualizar` recebe `referencia_id` mas `peca`/`servico = null`
e nenhuma chave de custo; após ganhar `estoque`, `servicos` e `financeiro`
(`visualizar`), recebe as fichas completas, o saldo já descontando a reserva de
terceiros (3 em estoque, 1 reservado por outro → 2 disponíveis, falta 0,
`em_estoque`), `preco_custo`, `custo_direto_padrao` e margem. Item digitado à mão
continua sem ficha, mas com custo. Suíte de regressão: `OrderFlowTest` +
`OsAplicacaoPecaTest` + `EstoqueReservaTest` = 122 testes verdes.

Desktop (`php artisan test tests/Feature/Desktop/OrderItemDetalheTest.php`):
botão e modal para peça **e** serviço; fornecedor, localização, classificação,
tempo padrão, LC 116, ISS, custo atual, chip de divergência e atalhos para
`estoque.edit`/`servicos.edit` renderizados; com payload redigido, nada de custo
nem ficha aparece e as mensagens de "não disponível para o seu perfil" /
"digitado à mão" são exibidas. Os 4 testes `orders_show` existentes seguem verdes.

Layout conferido em Chrome headless a 1366×768 (modal de peça: 1,44 telas de
rolagem; de serviço: 1,40).

## Como conferir na interface

1. Abrir uma OS com orçamento vinculado em `/os/{id}` e rolar até **Peças e
   serviços do orçamento**.
2. Clicar em **Detalhes** numa linha de peça: conferir fornecedor, localização e o
   bloco Saldo; com permissão financeira, o bloco **Custo e margem**.
3. Clicar em **Detalhes** numa linha de serviço: conferir nome do catálogo, tempo
   padrão e tributação.
4. Numa linha digitada à mão (ex.: item "teste"), o modal deve mostrar só o bloco
   "No orçamento" e a frase de item sem vínculo.
5. Com um usuário sem `financeiro`/`precificacao`, inspecionar o HTML do modal:
   nenhum valor de custo deve existir no DOM.

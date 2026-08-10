---
name: sistema-erp-os-fluxo-fechamento
description: Regra de negocio sobre quais status realmente encerram uma Ordem de Servico (OS) e por que so podem ser aplicados pelo fluxo de baixa. Use quando um agente de IA for mexer em status de OS, na tela de baixa/encerramento, em relatorios financeiros que leem dados de OS (DRE, fluxo de caixa), ou antes de adicionar qualquer novo caminho que altere o campo `os.status`.
---

# Sistema ERP — Fluxo de Fechamento da OS

## Regra central (nao negociavel sem decisao explicita do usuario)

Existem **5 status** (fora `cancelado`) que de fato encerram o atendimento de uma
OS — `OrderStatus::closureCodes()` (backend), coluna
`os_status.grupo_macro = 'encerrado'`:

| Codigo | Nome | Reparo entregue? | Gera receita? |
|---|---|---|---|
| `entregue_reparado_pago` | Entregue - Reparado e Pago | Sim | **Sim** — unico com `OrderStatus::REVENUE_CLOSURE_CODE` |
| `entregue_reparado_sem_custo` | Entregue - Reparado Sem Custo | Sim | Nao (R$0, sem lancamentos) |
| `entregue_reparado_garantia` | Entregue - Reparado em Garantia | Sim | Nao (R$0, sem lancamentos) |
| `devolvido_sem_reparo` | Devolvido Sem Reparo | Nao | Nao |
| `descartado` | Equipamento Descartado | Nao | Nao |

**Duas dimensoes independentes** (nao confundir):
- **Reparo entregue** (`OrderStatus::REPAIRED_DELIVERY_CODES` = os 3
  `entregue_reparado_*`): equipamento reparado e devolvido ao cliente. Contam
  como "entregue" nos indicadores OPERACIONAIS (card "Equipamento Entregue" do
  dashboard, grafico mensal de entregues) e geram os documentos de reparo
  (laudo + comprovante de entrega).
- **Gera receita** (`OrderStatus::REVENUE_CLOSURE_CODE` = so
  `entregue_reparado_pago`): unico que entra em faturamento/DRE/fluxo de
  caixa/margem/comissao. Sem custo e garantia sao reparo entregue mas R$0 —
  nunca entram em receita.

Esses 5 codigos **so podem ser aplicados por `OrderClosureService::close()`**
(o fluxo de baixa/encerramento da OS, tela `orders/closure.blade.php` no
desktop). Nenhum outro caminho — modal "Alterar status", edicao direta da OS,
chamada de API generica — pode setar `os.status` para um desses valores.

**Encerramentos SEM cobranca** (`OrderClosureService::NON_BILLED_CLOSURE_STATUSES`
= `devolvido_sem_reparo`, `descartado`, `entregue_reparado_sem_custo`,
`entregue_reparado_garantia`): `close()` ignora recebimentos, nao exige
pagamento e nao deixa saldo pendente/cobranca agendada. So
`entregue_reparado_pago` exige pagamento (guard `DELIVERED_STATUS`).

## Por que essa regra existe

A baixa nao e so uma troca de status: ela e a unica rotina que sabe fazer a
**reconciliacao correta** de tudo que depende do encerramento:

1. Cria/atualiza o titulo `Financeiro` (a receber) e registra os movimentos de
   pagamento informados — `devolvido_sem_reparo`/`descartado` **nao criam
   nenhum movimento** (ver `OrderClosureService::close()`, `$isNoRepairClosure`).
2. Calcula corretamente `status_final_pendente_pagamento` e decide se agenda
   cobrancas automaticas (D+1/D+3/D+5) — isso so faz sentido para uma OS que
   foi de fato entregue com saldo em aberto.
3. Seta `data_entrega`, `baixa_tecnica_em`, `baixa_tecnica_por` de forma
   consistente — varios relatorios usam `os.data_entrega` como a data de
   "realizacao" do atendimento.
4. Dispara a notificacao ao cliente (PDF consolidado da OS) no momento certo.

Se qualquer um desses 3 status for setado por fora da baixa (ex.: via modal de
"Alterar status" ou editando a OS diretamente), a OS fica com uma etiqueta de
"encerrada" sem nada disso ter acontecido — nenhum titulo financeiro, nenhuma
data de entrega, nenhuma cobranca — e os relatorios que dependem dessas colunas
ficam incoerentes.

## Onde a regra e' aplicada em codigo (2026-07-08)

- `App\Models\OrderStatus::closureCodes()` — fonte unica da verdade dos
  codigos de encerramento (query por `grupo_macro = 'encerrado'`). Nunca
  hardcode essas strings em outro lugar; sempre chame este metodo.
- `App\Models\OrderStatus::REVENUE_CLOSURE_CODE` — qual encerramento gera
  receita (`entregue_reparado_pago`). Usado por relatorios financeiros.
- `App\Models\OrderStatus::REPAIRED_DELIVERY_CODES` — os 3 encerramentos de
  reparo entregue (pago/sem custo/garantia). Usado pela contagem operacional
  de "entregue" (dashboard) e pela geracao de documentos de reparo.
- `OrderWorkflowService::updateStatus(..., bool $viaClosureFlow = false)` —
  rejeita com `result => 'closure_status_requires_baixa_flow'` se o destino
  estiver em `closureCodes()` e `$viaClosureFlow` nao for `true`. Tambem usa
  esse mesmo flag para pular a validacao do catalogo de transicoes (a baixa
  precisa poder encerrar a OS a partir de **qualquer** etapa aberta — ver
  `references/regra-fechamento-os.md` para o historico da decisao).
- `OrderWorkflowService::updateOrder()` — rejeita incondicionalmente (sem flag
  de excecao: a edicao generica da OS NUNCA deve encerrar o atendimento) se o
  payload tentar setar `status` para um dos 3 codigos.
- `OrderClosureService::close()` — o UNICO chamador autorizado de
  `updateStatus(..., viaClosureFlow: true)`.
- `FinanceiroReportService::dreReport()` (DRE por competencia) — reconhece
  receita de OS somente para `os.status = OrderStatus::REVENUE_CLOSURE_CODE`,
  nao para qualquer `status_final = true`.
- `OsMargemService::calcularParaOs()` / `recalcularEmLote()` — a tabela cache
  `os_margem` so contem OS com `status = OrderStatus::REVENUE_CLOSURE_CODE`
  (ver secao "Margem por OS" abaixo).

## Listagem operacional padrao (`status_scope=open`) so esconde os 3 closureCodes (2026-07-12)

Decisao explicita do usuario: a listagem de OS (`orders/index.blade.php`) e o
card "OS abertas" do dashboard usam `status_scope=open` por padrao quando
nenhum filtro explicito e informado. Esse escopo **so pode excluir uma OS
quando `os.status` literalmente esta em `OrderStatus::closureCodes()`** — os
mesmos 3 codigos da regra central, nada alem disso.

**Bug corrigido nesta data:** `applyOperationalStatusScope()`
(`OrderWorkflowService`) e `applyOperationalOpenScope()`
(`DashboardSummaryService`) tambem excluiam qualquer OS cujo
`os.status_final_pendente_pagamento` estivesse em `closureCodes()` — isso
escondia da listagem padrao qualquer OS "Entregue - Pendência Financeira"
(`entregue_pagamento_pendente`) que tivesse passado pela baixa real com saldo
em aberto (`OrderClosureService::close()` seta esse campo para o encerramento
que *vai* ser aplicado quando o saldo for quitado — ver `close()` e a secao
"Adiantamento/Sinal" abaixo). Essa OS **nao esta encerrada** — ainda ha
cobranca pendente — e sumia incorretamente da tela inicial da listagem.
Removida a clausula sobre `status_final_pendente_pagamento` nos dois lugares;
o escopo "aberta" agora depende apenas de `os.status`.

Regra de negocio explicita do usuario (2026-07-12): uma OS so e considerada
fechada com um dos 3 `closureCodes()`. Status que *parecem* encerrar o
atendimento mas nao sao um dos 3 (ex.: `entregue_pagamento_pendente`,
`irreparavel`, `reparo_recusado` — todos `grupo_macro='interrupcao'`)
continuam **abertos**, seja porque o equipamento ainda esta de posse da
assistencia, seja porque falta pagamento total.

**Achado durante a correcao**: o fixture de teste `seedOrderCatalog()`
(`tests/Concerns/BuildsLegacyErpSchema.php`) tinha `entregue_pagamento_pendente`
com `grupo_macro='encerrado'`, divergente do banco real (`'interrupcao'`).
Isso mascarava o bug nos testes — a exclusao acontecia via `closureCodes()`
diretamente (o status em si virava um "4º closure code" no catalogo de teste),
nao via `status_final_pendente_pagamento`. Corrigido o fixture para bater com
o banco real; teste renomeado para
`test_index_open_status_scope_excludes_only_the_three_closure_codes` e
ajustado para esperar a OS com pendencia financeira **visivel** no escopo
aberto.

### Onde a regra e aplicada na UI (os 3 status nao aparecem em dropdown fora da baixa)

- `OrderWorkflowService::mapNextStatusOptionsFromCatalog()` — filtra
  `grupo_macro = OrderStatus::CLOSURE_MACRO_GROUP` de `proximas_etapas`. Isso
  cobre o quick-status da listagem e o form "Atualizar status" da tela de
  detalhe (`orders/show.blade.php`), que ainda consomem `proximas_etapas`
  como lista fechada de opcoes.
- `frontends/desktop/resources/views/orders/_wizard.blade.php` e
  `orders-status-modal.js` (modal "Alterar status da OS",
  `_status_modal.blade.php`) — usam `status_disponiveis` (catalogo completo,
  nao `proximas_etapas`) e filtram `grupo_macro !== 'encerrado'` no proprio
  frontend. Ver secao "Modal 'Alterar status' mostra todos os status" abaixo
  para o porque do modal ter migrado de `proximas_etapas` pra
  `status_disponiveis` em 2026-08-09.
- `orders-map.js` (`createOsMapWidget`, usado pela pagina `/os/{id}/mapa` E
  pela aba "Mapa de status" do modal) — descarta `grupo_macro = 'encerrado'`
  ao montar `etapaByCode`, entao nenhum no de encerramento fica clicavel; eles
  recebem `.is-closure` e clicar neles abre o explicativo `explainBaixa()`,
  que so oferece ir para a tela de baixa. O filtro fica DENTRO do widget (nao
  so em quem chama) porque `status_disponiveis` e o catalogo COMPLETO e
  inclui os status de baixa — ver secao "Mapa de status dentro do modal".
- A tela de baixa (`orders/closure.blade.php`) e o UNICO lugar que exibe os 3
  status — via `OrderClosureService::closureOptions()`.

### Margem por OS (decisao tomada em 2026-07-08)

- `OsMargemService` (relatorio "Margem por OS") so considera OS que geraram
  receita real — `os.status = OrderStatus::REVENUE_CLOSURE_CODE`. Decisao do
  usuario: encerramentos sem receita (devolvido/descartado) sao **ignorados
  por completo** (nao entram no relatorio), nao registrados com receita 0.
  - `calcularParaOs()` so cria/atualiza registro para REVENUE_CLOSURE_CODE; se
    a OS nao for, remove qualquer registro stale existente e retorna vazio.
  - `recalcularEmLote()` filtra por REVENUE_CLOSURE_CODE e, no inicio, apaga
    qualquer registro de os_margem cujo os_id nao esteja mais em
    REVENUE_CLOSURE_CODE (mantem a invariante da tabela cache).
  - Limpeza unica aplicada em 2026-07-08: removidos 1.358 registros stale de
    os_margem (OS que nao eram entregue_reparado_pago, ~R$ 96.8k de receita fantasma).

## OS encerrada: mudança de status bloqueada + "Cancelar baixa" (2026-07-08)

Uma OS num dos 3 `closureCodes()` significa que **o equipamento não está mais de
posse da assistência**. Por isso, uma vez encerrada, a OS fica travada contra
mudança de status "facilitada" — só existem dois caminhos a partir daí:

1. **Cancelar a baixa** (`OrderClosureService::cancelClosure()`), reservado
   para quando a baixa foi dada **por engano**. Reverte o status para a etapa
   imediatamente anterior (lida do último `os_status_historico`) e **exclui
   por completo** (hard delete, não soft-cancel) tudo que a baixa criou:
   título a receber, movimentos, meta de cartão, despesa de taxa, `os_margem`,
   cobranças agendadas, follow-up de retorno. Fica só um registro de auditoria
   no `os_status_historico` ("Baixa cancelada..."). Re-baixar a mesma OS depois
   cria tudo limpo de novo.
2. **Abrir uma nova OS**, se o equipamento **realmente** foi entregue/descartado
   e depois **retornou** à assistência (ex.: o cliente trouxe de volta com o
   mesmo defeito, ou um novo defeito). Isso **não é** engano — não se cancela a
   baixa nesse caso, pois a baixa continua correta para o atendimento que ela
   representa. Reabrir/reverter a mesma OS misturaria dois atendimentos
   diferentes no mesmo registro.

### Onde o bloqueio e o cancelamento estão implementados

- `OrderWorkflowService::updateStatus()` — se o status atual da OS já está em
  `closureCodes()` e o destino é diferente (`statusChanged`), rejeita com
  `result => 'order_is_closed'`, a menos que venha via
  `viaClosureFlow: true` (usado só por `cancelClosure()`, nunca por `close()`
  — que já usa esse flag para a validação de transição, não para reabrir OS
  encerrada).
- `OrderWorkflowService::updateOrder()` — mesma rejeição incondicional
  (`order_is_closed`) para a edição genérica.
- `OrderClosureService::cancelClosure(int $orderId, User $actor, ?User $verifiedAdmin = null): array` —
  co-localizado com `close()`. Guards: OS existe, `canAccessOrder`, e
  `status ∈ closureCodes()` (senão `not_closed`); resolve o status anterior via
  `os_status_historico` (senão `cannot_resolve_previous_status` — nunca
  adivinha). Endpoint: `POST /api/v1/orders/{order}/closure/cancel` →
  `POST /os/{order}/baixa/cancelar` (desktop).
- UI: `orders/show.blade.php` mostra o botão "Cancelar baixa" (fora do gate
  `os,editar` — ver seção de gate de administrador abaixo) quando
  `$order['is_encerrada']` (campo de `mapDetail()`/`mapSummary()`); esconde
  "Alterar status" e o form "Atualizar status" da aba Informações vira texto
  explicativo. `_wizard.blade.php` (edição) esconde o gatilho/modal de status
  pela mesma flag. `orders/index.blade.php` mostra "Cancelar baixa" no dropdown
  Ações usando o mesmo campo `is_encerrada` (ver bug corrigido abaixo).

### Bug corrigido (2026-07-08): "Baixa" sumindo para Irreparável/Reparo Recusado

`OrderWorkflowService::mapSummary()` (usado pela listagem `orders/index.blade.php`)
não expunha `is_encerrada` — só `mapDetail()` tinha esse campo. A blade da listagem
então caía para `estado_fluxo === 'encerrado'` para decidir `$canCloseOrder`, mas
esse `estado_fluxo_padrao` também é usado por `irreparavel`/`reparo_recusado`
(que **não** são um dos 3 `closureCodes()` — grupo_macro `interrupcao`, não
`encerrado`). Resultado: o botão "Baixa" sumia incorretamente para essas duas
etapas, que continuam abertas e precisam poder ir para a baixa normalmente.
Corrigido adicionando `is_encerrada` (`grupo_macro='encerrado')` em
`mapSummary()` e reescrevendo `$canCloseOrder`/dropdown em `index.blade.php`
para usar esse campo em vez de `estado_fluxo`. Regra geral: qualquer decisão de
UI sobre "isso é um dos 3 status que encerram a OS" deve usar
`OrderStatus::closureCodes()`/`is_encerrada`, nunca `estado_fluxo` ou
`status_final` (ambos mais amplos, compartilhados por status que não encerram).

### Gate de administrador para "Cancelar baixa" (2026-07-08)

> Este é o caso de uso original do padrão genérico de **step-up authentication**
> documentado em `$sistema-erp-autenticacao-step-up` — consulte aquele skill
> antes de replicar este mecanismo em qualquer outra ação sensível do sistema
> (ex.: estorno de lançamento, exclusão de registro crítico).

Regra de negócio explícita do usuário: o botão "Cancelar baixa" é **visível para
qualquer usuário com acesso ao painel da OS** (gate de rota/permissão:
`os,visualizar`, tanto na listagem quanto no detalhe), mas a ação só se
concretiza mediante confirmação de **usuário e senha de um administrador**
(perfil `admin`) — não é preciso ser o usuário logado.

- `CancelOrderClosureRequest` (backend) exige `admin_email`/`admin_password`.
- `OrderController::cancelClosure()` autoriza a rota com `os:visualizar` (não
  `os:editar` — o gate real não é a permissão do usuário logado) e, antes de
  chamar o service, verifica: usuário com esse e-mail existe, `ativo=true`,
  `perfil === 'admin'` e `Hash::check($senha, $admin->senha)`. Só então chama
  `OrderClosureService::cancelClosure($order, $user, $admin)` — `$user` é quem
  clicou (autor do histórico), `$admin` é só para registrar quem autorizou na
  observação do `os_status_historico`.
- Rate limiting da verificação (mesmo padrão de `AuthController::login()`):
  chave `os-closure-cancel-admin-auth:{email}|{ip}`, 5 tentativas, bloqueio 60s.
- **Credenciais inválidas retornam HTTP 422, nunca 401.** O desktop
  (`ApiClient::parseResponse()`) trata **qualquer** 401 como "a sessão do
  usuário atual expirou" e força logout (`DesktopSession::forget()`). Como essa
  verificação é de um usuário **diferente** (o admin), reusar 401 aqui
  deslogaria por engano quem está clicando no botão, não quem errou a senha.
  Qualquer novo fluxo de "confirme a senha de outra pessoa" deve seguir esse
  mesmo cuidado (422/erro de validação, nunca 401).
- Senha de admin nunca é persistida em old-input/sessão: `dontFlash('admin_password')`
  em `bootstrap/app.php` (cobre `ValidationException` nativa) + o catch de
  `OrderController::closureCancel()` (desktop) não chama `withInput()` no
  caminho de erro + o handler de `ApiRequestException` exclui explicitamente
  `admin_password` do `except()` usado em `withInput()`.
- Arquivos novos: `CancelOrderClosureRequest.php`,
  `_cancel_closure_modal.blade.php`, `orders-cancel-closure-modal.js`.

## Adiantamento/Sinal sem fechar a OS (2026-07-09)

Decisão do usuário: a tela de baixa (`orders/closure.blade.php`) ganhou um
campo único **"Classificação"** (Baixa / Adiantamento / Sinal), posicionado na
aba Encerramento acima de "Encerrar como". Ele decide **qual caminho de
backend** a submissão do wizard vai seguir — não é só um rótulo:

- **Baixa** (padrão): fluxo de sempre, inalterado —
  `OrderClosureService::close()`, aplica um dos 3 `closureCodes()`.
- **Adiantamento / Sinal**: **novo método** `OrderClosureService::registerAdvance()`,
  que **nunca** aplica um dos 3 `closureCodes()`. Só registra recebimento(s)
  financeiro(s) contra o título da OS (reaproveita `processReceipts()` e
  `simulateCardPayments()`, os mesmos helpers privados usados por `close()` —
  não existe lógica financeira duplicada entre os dois caminhos).

Quando classificação é Adiantamento/Sinal, "Encerrar como" e "Data da entrega"
ficam escondidos e viram irrelevantes (não são enviados/validados como
obrigatórios). Em vez disso aparece um toggle **"Equipamento foi entregue?"**:

- Se **não marcado**: `registerAdvance()` não toca em `status`, `data_entrega`,
  `baixa_tecnica_em`/`_por` da OS — só lança o valor no financeiro. A OS
  continua exatamente na etapa em que estava.
- Se **marcado + data preenchida**: aplica `entregue_pagamento_pendente` via
  `updateStatus(..., viaClosureFlow: true)`. **Achado via tinker durante a
  implementação**: mesmo `entregue_pagamento_pendente` não sendo um dos 3
  `closureCodes()` (então as duas checagens de bloqueio de "OS encerrada" que
  `viaClosureFlow` pula nunca seriam acionadas aqui), `updateStatus()` também
  usa esse flag pra pular a validação do **catálogo de transições**
  (`allowedTransitionCodes()`) — sem ele, marcar "entregue" falhava com
  `invalid_transition` em qualquer status de origem que não tivesse essa
  transição cadastrada (ex.: `aguardando_autorizacao`), já que o equipamento
  precisa poder ser marcado como entregue a partir de **qualquer** etapa
  aberta, igual `close()`. Seta `data_entrega`, `baixa_tecnica_em`,
  `baixa_tecnica_por` (mesma semântica de handoff técnico de `close()`) e
  agenda cobranças pendentes — mas **propositalmente não seta**
  `status_final_pendente_pagamento`, porque nenhum código de fechamento real
  foi escolhido: a OS **continua aberta** (tem pendência financeira), só que
  com o equipamento já em posse do cliente. O fechamento de verdade só
  acontece depois, quando alguém rodar uma **Baixa** classificada de fato.
- Guarda: `registerAdvance()` recusa com `result => 'order_is_closed'` se a OS
  já estiver num dos 3 `closureCodes()` — mesma regra de "OS encerrada não
  aceita ação financeira/de status por fora do cancelamento de baixa" (ver
  seção acima).
- "Retorno pós-serviço" (etapa Confirmação) some quando a classificação não é
  Baixa — o atendimento não terminou, não faz sentido agendar retorno.
- Endpoint é o mesmo de sempre (`POST /orders/{order}/closure`); o roteamento
  `close()` vs. `registerAdvance()` acontece dentro do
  `Api/V1/OrderController` lendo `classificacao_baixa` do request validado.

**Bug real encontrado só depois do usuário testar na tela (não pego pelo Chrome
headless da primeira rodada)**: o listener `change` do select de Classificação
usava só `addEventListener` nativo. Como todo `select.form-select` vira Select2
automaticamente (`desktop.js`, `initSelect2()`), e o Select2 só dispara `change`
via `jQuery(el).trigger('change')` ao escolher uma opção pela sua UI — isso não
gera evento nativo —, o listener nunca disparava na prática, só quando o valor
era setado programaticamente (por isso o primeiro teste com Chrome headless via
`page.select()` passou, mascarando o bug: `page.select()` seta o valor
direto, sem passar pela UI real do Select2). Corrigido com o mesmo binding
paralelo via jQuery já usado para os campos de cartão do recebimento nesta
mesma tela. **Lição**: ao testar um `<select>` com Chrome headless neste
sistema, interagir com a UI real do Select2 (clicar no `.select2-selection` e
escolher a opção no `.select2-results__option`), não só `page.select()` — ver
comentário adicionado em `desktop.js::initSelect2()` para o aviso geral.

**Ao adicionar qualquer novo caminho de fechamento/registro financeiro da OS:**
nunca aplique um dos 3 `closureCodes()` fora de `OrderClosureService::close()`;
se o novo caminho só precisa registrar dinheiro sem fechar, siga o padrão de
`registerAdvance()` (reaproveitar `processReceipts()`/`simulateCardPayments()`,
nunca duplicar a lógica de título/movimento/cartão).

## Timeline de eventos da OS (`os_eventos`, 2026-07-09)

Os fluxos de baixa/cancelamento (e todo o resto do ciclo de vida da OS) agora
emitem eventos para a tabela append-only `os_eventos` via
`OrderEventService::record()` — mudança **puramente aditiva**: a semântica de
fechamento, os 3 `closureCodes()` e os writes em `os_status_historico`
continuam exatamente como documentado acima (o cancelamento de baixa segue
resolvendo o status anterior lendo `os_status_historico`, nunca `os_eventos`).
Regras: writer único (`record()`), falha de evento nunca quebra a ação
(try/catch + warning), nenhuma linha de `os_eventos` é atualizada/excluída pela
aplicação. Detalhes completos (schema, categorias, pontos de emissão, backfill
`os:backfill-eventos`): `documentacao/03-arquitetura-tecnica/eventos-os.md`.
Ao criar qualquer NOVO caminho que mexa em OS, emita o evento correspondente
pelo `OrderEventService` — nunca escreva direto na tabela.

## Modal "Alterar status" mostra todos os status (exceto baixa) por macrofase (2026-08-09)

Decisao de produto do usuario: a UI deixou de restringir a escolha do
tecnico as poucas etapas cadastradas em `os_status_transicoes` a partir do
status atual — na pratica o tecnico avanca varias etapas do atendimento
antes de mexer no sistema, entao uma maquina de estados rigida no backend
nao refletia o fluxo real (o técnico "avançava de cabeça" e só conseguia
registrar tudo de uma vez, no final).

- `_status_modal.blade.php` / `orders-status-modal.js`: passaram a consumir
  `status_disponiveis` (catalogo completo, igual `_wizard.blade.php` ja
  fazia) em vez de `proximas_etapas`, filtrando so `grupo_macro = 'encerrado'`
  (a regra central deste skill, **inalterada**) e agrupando o restante por
  `grupo_macro` (macrofase), na ordem em que aparecem em `status_disponiveis`
  (que ja vem ordenado por `ordem_fluxo`). `cancelado` continua com secao
  propria fixa "Cancelar atendimento", igual antes.
- `OrderWorkflowService::updateStatus()` — o bloco que rejeitava com
  `result => 'invalid_transition'` quando o destino nao estava cadastrado em
  `os_status_transicoes` para a origem atual foi **removido**. As duas
  checagens da regra central deste skill (`closure_status_requires_baixa_flow`
  e `order_is_closed`, ambas logo acima na mesma funcao) continuam
  **intactas** — a baixa continua so podendo ser aplicada por
  `OrderClosureService::close()`. `allowedTransitionCodes()` /
  `mapNextStatusOptions()` / `mapNextStatusOptionsFromCatalog()` nao foram
  removidos: `proximas_etapas` continua sendo calculado e volta na resposta,
  agora so como sugestao em destaque visual na grade (chip com borda
  realçada), nao mais como filtro do que pode ser salvo.
- `'invalid_transition'` / `ORDER_STATUS_TRANSITION_INVALID` foi removido do
  `match()` de `OrderController::updateStatus()` (era o unico emissor desse
  erro) — nenhum client deve mais esperar esse codigo.
- Teste que cobria a rejeicao (`test_patch_status_blocks_transition_not_allowed_by_catalog`)
  foi reescrito para `test_patch_status_allows_any_active_non_closure_destination_regardless_of_catalog`
  (`backend/tests/Feature/Api/V1/OrderFlowTest.php`), validando o novo
  comportamento permissivo.
- Aviso de "pulo de fase" (nao bloqueia, so confirma) fica inteiramente no
  frontend: `orders-status-modal.js::confirmPhaseSkipIfNeeded()` compara o
  indice da macrofase atual com o da selecionada (`phaseRankByGroup`,
  calculado a partir da ordem de chegada em `status_disponiveis`) e pede
  confirmacao via SweetAlert2 se a diferenca for maior que 1 fase. E so UX —
  o backend aceita a mudanca de qualquer forma, mesmo sem confirmar.
- **Escopo desta mudanca**: so o modal "Alterar status da OS" (incluido em
  `show.blade.php`, `index.blade.php`, `closure.blade.php`,
  `documents-center.blade.php`). O quick-status da listagem
  (`orders/index.blade.php`) e o form "Atualizar status" da aba Informacoes
  (`orders/show.blade.php`) continuam consumindo `proximas_etapas` sem
  mudanca visual — nao ficaram errados (o backend permissivo e um superset
  do que aceitavam antes), so nao foram migrados pra grade por macrofase
  nesta rodada.

**Ao tocar neste modal de novo:** nao reintroduza uma checagem de transicao
rigida no backend sem decisao explicita do usuario — foi removida de
proposito, com o usuario ciente do tradeoff (perde-se a trava automatica
contra pular etapa; fica so o aviso client-side). Qualquer novo bloqueio de
"para onde a OS pode ir" deve ser feito como aviso na UI (como o de pulo de
fase), nunca como 422 no backend, a menos que envolva os `closureCodes()`
(aí sim, a regra central deste skill continua valendo sem excecao).

## Aba "Status": fluxograma por macrofase (2026-08-10)

A listagem de chips agrupados virou um **fluxograma**: uma linha por
macrofase (faixa colorida com o nome da fase à esquerda) e as etapas daquela
fase fluindo para a direita, ligadas por setas — layout e paleta definidos
pelo usuario. Implementado em `orders-status-modal.js`
(`MACRO_PHASES`, `EXIT_PHASES`, `buildStep`, `buildPhaseRow`,
`renderStatusGrid`) + CSS em `_status_modal.blade.php` (`.os-flow*`).

- **A ordem das macrofases e declarada em `MACRO_PHASES`, NAO derivada de
  `os_status.ordem_fluxo`** — decisao explicita do usuario: Recepcao >
  Diagnostico > Orcamento > **Em espera** > Execucao > Qualidade > Concluido.
  No banco `interrupcao` (Em espera) tem `ordem_fluxo` 120-140, ou seja,
  cairia depois de Execucao/Qualidade. Ao adicionar uma macrofase nova ao
  catalogo, inclua-a em `MACRO_PHASES` (ou em `EXIT_PHASES`) — fases fora
  dessas listas ainda aparecem, mas caem no fim da tela.
- **Dentro de cada fase** a ordem continua sendo a de `ordem_fluxo` (ordem em
  que `status_disponiveis` chega do backend), sem override por status.
- `finalizado_sem_reparo` e `cancelado` sao **saidas do fluxo**: ficam num
  bloco separado por divisor, sob o titulo "Saida do fluxo", ambas em
  vermelho. Cancelado tem linha propria (e `grupo_macro` proprio no banco e
  o usuario o descreve como saida distinta), embora no fluxograma de
  referencia ele apareca na mesma faixa de "sem reparo".
- Paleta por fase (`--phase-color`/`--phase-text`, seletores
  `.os-flow-row[data-phase="..."]`): recepcao `#10739E`, diagnostico
  `#F2931E`, orcamento `#66B2FF`, interrupcao `#FFD400` (texto escuro),
  execucao `#999900`, qualidade `#9999FF`, concluido `#00994D`,
  finalizado_sem_reparo e cancelado `#CC0000`.
- Continua valendo tudo da secao anterior: status de `grupo_macro='encerrado'`
  nunca aparecem (so pela baixa), e qualquer etapa nao-baixa e clicavel.

## Mapa de status dentro do modal "Alterar status" (2026-08-09)

O modal virou `modal-fullscreen` e ganhou a aba **"Mapa de status"**, que
reaproveita o MESMO mapa interativo da pagina `/os/{id}/mapa` — nao existe
uma segunda implementacao do diagrama:

- `orders-map.js` foi refatorado de IIFE-de-pagina para uma **fabrica de
  widget**: `window.DesktopOsMap.create(rootEl, config)`. A pagina cheia
  continua auto-inicializando (`if (window.__DESKTOP_OS_MAP)` no fim do
  arquivo, root = `.os-map-frame`); o modal chama `create()` sob demanda.
- Elementos passaram a ser localizados por `data-os-map="..."` dentro do
  `root` (antes: `getElementById` global) — por isso a mesma partial pode
  existir duas vezes na pagina sem conflito. Excecao: `status-pill`, `banner`
  e `trail` seguem via `document.querySelector` porque na pagina cheia ficam
  FORA de `.os-map-frame` (e nao existem no modal — que nunca chama
  `refreshMap()`, ver `config.onMoved` abaixo).
- `config.initialView`: `'fit'` (modal — abre com o fluxo inteiro visivel,
  o ponto da aba e localizar a OS no mapa completo) vs. padrao (pagina cheia
  — centraliza na posicao atual, `scale` minimo 0.85).
- `config.onMoved`: no modal, mover a OS fecha o modal e recarrega a pagina
  (mesmo desfecho dos chips + "Salvar status"); sem esse callback o widget
  usa `refreshMap()` (comportamento da pagina cheia, que nao pode recarregar
  sob risco de sair do fullscreen).
- Clicabilidade acompanha a permissividade de 2026-08-09 (secao acima):
  **qualquer** status ativo nao-baixa e clicavel no mapa, nao so os de
  `proximas_etapas` — estes viram apenas destaque visual (`.is-destination`).
- **Pegadinha ja corrigida (nao reintroduzir):** `status_disponiveis` inclui
  os status de `grupo_macro='encerrado'`. Passar esse catalogo cru pro widget
  tornava os nos de baixa clicaveis (e o backend recusaria com 422
  `closure_status_requires_baixa_flow`). O descarte e feito dentro de
  `applyState()`, nao no chamador.
- **Ir para a baixa desloga o usuario (bug real, corrigido):** `explainBaixa()`
  navega por `window.location.href = config.closureUrl`. O guard de sessao do
  layout (`layouts/app.blade.php`) so reconhece navegacao interna por clique em
  `<a href>`, `submit` e F5 — navegacao PROGRAMATICA nao dispara nenhum desses,
  entao o `pagehide` gravava `erpDesktopClosedAt` ("navegador fechado") e a tela
  de baixa, ao carregar, disparava `POST /logout` sozinha e jogava o usuario no
  login. Corrigido expondo `window.erpMarkInternalNavigation` no layout e
  chamando-o ANTES de navegar. **Qualquer** `window.location.href = ` /
  `location.assign()` para pagina interna do desktop precisa desse hook —
  ainda existem outros pontos sem ele (`dashboard.js`,
  `configurations-integrations.js`), que continuam suscetiveis.
- Os 5 arquivos que incluem `_status_modal.blade.php` (`show`, `index`,
  `closure`, `documents-center`, `_wizard_scripts`) precisam carregar
  **`orders-map.js` antes de `orders-status-modal.js`** — sem ele
  `window.DesktopOsMap` nao existe e a aba fica com o SVG estatico, sem
  decoracao nem zoom/pan/clique (bug real que apareceu na primeira versao).
  Ambos os scripts usam cache-buster `?v=filemtime(...)`.

## Checklist ao tocar em status de OS ou relatorios financeiros

- [ ] Se adicionar um novo caminho que possa alterar `os.status` (novo
      endpoint, novo botao, import em lote), ele **nao pode** aceitar um dos
      `OrderStatus::closureCodes()` a menos que va atraves de
      `OrderClosureService::close()`.
- [ ] Se adicionar/editar um relatorio que soma `os.valor_final` ou
      `os.valor_total`, verificar se so deveria contar
      `OrderStatus::REVENUE_CLOSURE_CODE` (ou os codigos de receita
      aplicaveis), nao qualquer `status_final = true`.
- [ ] Rodar `references/regra-fechamento-os.md` → secao "Como validar" antes de
      dar como concluido.
- [ ] Se adicionar um novo caminho de mudanca de status, verificar se ele
      respeita o bloqueio de OS encerrada (`order_is_closed`) — nao deve ser
      possivel tirar uma OS de `closureCodes()` por fora de
      `OrderClosureService::cancelClosure()`.

## Workflow de decisao

- Tarefa envolve tela de baixa, modal de status, ou edicao de OS → ler este
  skill inteiro antes de codar.
- Tarefa envolve DRE, fluxo de caixa ou qualquer relatorio financeiro que lê
  dados de `os.*` → ler a secao "Onde a regra e aplicada" e conferir se o
  relatorio usa `OrderStatus::REVENUE_CLOSURE_CODE` corretamente.
- Combinar com `$sistema-erp-governanca` se a mudanca também tocar em
  arquitetura/contratos entre backend e frontends.

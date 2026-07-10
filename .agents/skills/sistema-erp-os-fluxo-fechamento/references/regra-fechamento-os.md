# Regra de Fechamento da OS — historico e racional completo

## Origem desta regra (2026-07-08)

Durante a implementacao/correcao da tela de baixa da OS (`orders/closure.blade.php`),
foram descobertos dois problemas reais decorrentes da ausencia desta regra:

### 1. Bug real no DRE por Competencia

`FinanceiroReportService::dreReport()` somava `os.valor_final` de **qualquer**
OS com `os_status.status_final = true` cujo `os.data_entrega` caisse no mes do
relatorio — sem checar se aquele encerramento gerou cobranca de fato.

Levantamento no banco de producao/dev no momento da correcao:

```
Ordens com status devolvido_sem_reparo/descartado/cancelado E data_entrega
preenchida (contaminavam o DRE por Competencia):
  total: 1.292 OS
  soma de valor_final contado indevidamente como receita: R$ 93.882,80
```

A maior parte veio de dados legados migrados (numero_os no padrao antigo,
datas de 2018-2020) — ou seja, o problema nao e' hipotetico, ja afetou
relatorios reais gerados antes da correcao. A correcao (`FinanceiroReportService`
passar a filtrar por `OrderStatus::REVENUE_CLOSURE_CODE`) so vale **daqui pra
frente**: relatorios de DRE ja gerados/exportados/usados para decisao antes
desta data continuam com os numeros antigos (inflados) — se algum foi usado
para decisao contabil, precisa ser re-executado manualmente.

### 2. Backdoor real: `PATCH /api/v1/orders/{id}` (edicao generica da OS)

`UpsertOrderRequest` (o form request da edicao de OS) validava `status` apenas
contra `OrderStatus::activeCodes()` — **qualquer** codigo ativo, sem excluir os
3 que encerram a OS — e tambem aceitava `data_entrega`, `baixa_tecnica_em`,
`baixa_tecnica_por` e todos os campos financeiros (`valor_mao_obra`,
`valor_pecas`, `valor_total`, `desconto`, `valor_final`) como campos livres.

Isso significa que, antes da correcao, uma unica chamada
`PATCH /api/v1/orders/{id}` com
`{"status": "entregue_reparado", "data_entrega": "2026-01-01", "valor_final": 500}`
fechava a OS "no papel" sem nenhuma reconciliacao financeira (nenhum titulo
`Financeiro` criado, nenhum movimento, nenhuma notificacao ao cliente) — e a
OS passava a contar como receita no DRE (item 1 acima).

**Verificado antes de corrigir**: nem o desktop (`OrderController::update()` so
valida/repassa `cliente_id`, `equipamento_id`, `relato_cliente`, `prioridade`,
`tecnico_id`, `data_previsao`, `observacoes_internas` — nunca `status` nem
campos financeiros) nem o mobile (`apiUpdateOrderStatus()` so chama o endpoint
de status, nunca o de edicao completa) usam esse caminho na pratica. O
backdoor era real na API mas nao estava sendo explorado por nenhum frontend
legitimo — seguro travar sem quebrar nada em uso.

### 3. Modal "Alterar status da OS"

O modal usa o mesmo `OrderWorkflowService::updateStatus()` da baixa, mas sem
nenhuma das rotinas de reconciliacao financeira. A tabela de transicoes
(`os_status_transicoes`) ja restringia parcialmente quais destinos apareciam
no dropdown do modal (ver `allowedTransitionCodes()`), mas nada impedia uma
chamada direta a API de setar um dos 3 status de fechamento por esse caminho.

## Decisao de negocio tomada (confirmada com o usuario em 2026-07-08)

A tela de baixa deve poder encerrar a OS **a partir de qualquer status aberto**
(nao so dos estagios avancados que a tabela de transicoes ja cobria — ex.:
"Reparo Concluido", "Irreparavel"). Por isso `OrderClosureService::close()`
chama `updateStatus(..., viaClosureFlow: true)`, que pula a validacao do
catalogo de transicoes **somente** para essa chamada especifica — o modal de
"Alterar status" e qualquer outro chamador continuam respeitando o catalogo de
transicoes normalmente.

## Como validar que a regra continua valendo

```bash
cd backend
php artisan tinker --execute="
// 1) updateStatus() fora da baixa deve rejeitar os 3 status de fechamento
\$svc = app(App\Services\Orders\OrderWorkflowService::class);
\$actor = App\Models\User::first();
\$order = App\Models\Order::where('status', '!=', 'entregue_reparado')->first();
\$r = \$svc->updateStatus(\$order->id, \$actor, 'entregue_reparado');
echo 'updateStatus sem viaClosureFlow: ' . \$r['result'] . ' (esperado: closure_status_requires_baixa_flow)' . PHP_EOL;

// 2) updateOrder() (edicao generica) deve rejeitar tambem
\$r2 = \$svc->updateOrder(\$order->id, \$actor, ['status' => 'devolvido_sem_reparo']);
echo 'updateOrder: ' . \$r2['result'] . ' (esperado: closure_status_requires_baixa_flow)' . PHP_EOL;

// 3) DRE deve contar só entregue_reparado
echo 'REVENUE_CLOSURE_CODE: ' . App\Models\OrderStatus::REVENUE_CLOSURE_CODE . PHP_EOL;

// 4) OS ja encerrada deve rejeitar QUALQUER troca de status (nao so p/ closureCodes)
\$closed = App\Models\Order::whereIn('status', App\Models\OrderStatus::closureCodes())->first();
if (\$closed) {
    \$r4 = \$svc->updateStatus(\$closed->id, \$actor, 'triagem');
    echo 'updateStatus em OS encerrada: ' . \$r4['result'] . ' (esperado: order_is_closed)' . PHP_EOL;
}
"
```

Se qualquer um desses testes nao bater com o esperado, a regra foi quebrada
por alguma mudanca recente — investigar `OrderWorkflowService::updateStatus()`/
`updateOrder()` e `FinanceiroReportService::dreReport()` antes de prosseguir.

## "Cancelar baixa" — bloqueio de mudança de status em OS encerrada (2026-07-08)

Decisão de negócio adicional confirmada com o usuário: uma OS num dos 3
`closureCodes()` significa que o equipamento não está mais na assistência —
mudar o status dela por engano (ex.: voltar pra "Triagem") indicaria
erroneamente que o equipamento está de volta. Duas decisões tomadas:

- **Escopo do bloqueio**: só os 3 `closureCodes()`. `entregue_pagamento_pendente`
  (grupo `interrupcao`) fica de fora — ainda precisa progredir para `entregue_reparado`
  quando o saldo for pago.
- **Reversão financeira do "Cancelar baixa"**: exclusão completa (hard delete),
  não soft-cancel. Título, movimentos, meta de cartão, despesa de taxa e
  `os_margem` da baixa são apagados — não ficam visíveis como "cancelados" em
  Lançamentos. A auditoria da ação fica só no `os_status_historico`.
- **Equipamento que retorna após baixa intencional → nova OS**, nunca reabrir/
  cancelar a baixa da mesma (ver seção correspondente no `SKILL.md`).

Testado via tinker (fechar com cartão real → cancelar → confirmar exclusão
total dos artefatos financeiros) e via Chrome headless (botão "Cancelar baixa"
end-to-end: clique → confirmação → POST → redirect → status revertido na tela).
Dados de teste sempre revertidos ao final.

## Bug corrigido: "Baixa" sumindo da listagem para Irreparável/Reparo Recusado (2026-07-08)

Relatado pelo usuário com print da listagem: a OS26070002 (status "Irreparável")
não mostrava mais o botão "Baixa" no dropdown de Ações. Causa raiz:
`OrderWorkflowService::mapSummary()` (fonte de dados da listagem) nunca expunha
`is_encerrada` — só `mapDetail()` (tela de detalhe) tinha esse campo, adicionado
numa correção anterior. A blade da listagem então caía para
`$order['estado_fluxo'] === 'encerrado'` para decidir `$canCloseOrder`, mas esse
`estado_fluxo_padrao` também é usado por `irreparavel`/`reparo_recusado` — que
**não** são um dos 3 `closureCodes()` (ficam no grupo_macro `interrupcao`).
Corrigido adicionando `is_encerrada` em `mapSummary()` e trocando a condição em
`orders/index.blade.php` para usar esse campo. Regra geral: nunca usar
`estado_fluxo`/`status_final` para decidir "isso é um dos 3 status que encerram
a OS" — usar sempre `OrderStatus::closureCodes()`/`is_encerrada`.

Verificado via Chrome headless (`test-canclose-dropdown.js`): OS26070002
(Irreparável) passou a mostrar "Baixa" e continuou sem mostrar "Cancelar baixa".

## Gate de administrador para "Cancelar baixa" (2026-07-08)

Requisito explícito do usuário: o botão "Cancelar baixa" deve ficar **visível
para qualquer usuário com acesso ao painel da OS** — tanto na listagem quanto
no detalhe — mas a ação só pode se concretizar mediante confirmação de
**usuário e senha de um administrador** (perfil `admin`), não necessariamente o
usuário logado.

Implementação:

- `CancelOrderClosureRequest` (novo form request) exige `admin_email` (email
  válido) e `admin_password` (string). `authorize()` só checa que existe um
  usuário autenticado — não é o gate de autorização real.
- `OrderController::cancelClosure()` — `$this->authorize('os:visualizar')` (não
  `os:editar`: o botão é visível a quem só visualiza a OS). Antes de chamar
  `OrderClosureService::cancelClosure()`, verifica: usuário com esse e-mail
  existe, `ativo=true`, `mb_strtolower(trim($admin->perfil)) === 'admin'` e
  `Hash::check($senha, $admin->senha)`. Rate limit
  `os-closure-cancel-admin-auth:{email}|{ip}` (5 tentativas / 60s, mesmo padrão
  de `AuthController::login()`). Falha → **HTTP 422** (nunca 401 — ver abaixo),
  code `ORDER_CLOSURE_CANCEL_ADMIN_AUTH_INVALID`. Sucesso → chama o service
  passando `$user` (quem clicou, autor do histórico) e `$admin` (só para constar
  na observação de quem autorizou).
- **Por que 422 e não 401**: `ApiClient::parseResponse()` (desktop) trata
  **qualquer** resposta 401 como "a sessão do usuário atual expirou" e força
  `DesktopSession::forget()` (logout automático). Essa verificação é de
  credenciais de um usuário **diferente** (o admin) — usar 401 aqui deslogaria
  por engano quem está clicando no botão ao simplesmente errar a senha do
  admin. Qualquer gate futuro de "confirme a senha de outra pessoa" precisa
  desse mesmo cuidado.
- Senha de admin nunca é persistida em old-input/sessão:
  `$exceptions->dontFlash('admin_password')` em `bootstrap/app.php` (cobre
  `ValidationException` nativa do `Request::validate()` no desktop) + o catch
  de `OrderController::closureCancel()` (desktop) não chama `withInput()` no
  caminho de erro + o handler de `ApiRequestException` exclui `admin_password`
  (junto com `password`) do `except()` usado em `withInput()`.
- `_cancel_closure_modal.blade.php` (partial compartilhado) +
  `orders-cancel-closure-modal.js` — usados tanto em `show.blade.php` quanto em
  `index.blade.php`, mesmo padrão de `window.__DESKTOP_*_MODAL` + `data-order-id`/
  `data-order-numero` via `event.relatedTarget` já usado por `_status_modal`.

Testado via Chrome headless (`test-cancelar-baixa-admin.js`, OS 3506): botão
presente, modal abre com número da OS certo, senha errada mostra erro inline
**sem** navegar e **sem** deslogar a sessão atual (`stillLoggedIn: true`), senha
correta cancela a baixa e reverte o status na tela. Rate limiter testado e
limpo (`os-closure-cancel-admin-auth:teste@rate.limit|127.0.0.1`). Dados de
teste revertidos ao final (histórico voltou a 3 entradas, financeiro/margem/
cobranças/followup em 0).

## Adiantamento/Sinal sem fechar a OS (2026-07-09)

Pedido original do usuário: o campo "Classificação" (Baixa/Adiantamento/Sinal)
da tela de baixa estava posicionado na aba errada (Financeiro, por linha de
recebimento) e não tinha nenhum efeito real — não importava o que fosse
escolhido, a OS sempre fechava com o status de "Encerrar como". Investigação
confirmou: campo puramente cosmético, escrito por `normalizeReceipts()` mas
nunca lido em nenhum lugar downstream (nem passado a `registerMovement`, nem
persistido em model algum) — seguro remover sem quebrar o fluxo existente.

Decisões do usuário, em sequência:

1. "Sua função é definir se a OS será encerrada ou será apenas lançados
   valores de sinal ou adiantamento na OS" — ou seja, vira uma decisão de
   **caminho de backend**, não um rótulo cosmético.
2. Confirmado via pergunta: escolher Sinal/Adiantamento mantém a OS **aberta**,
   só registra o valor; o campo passa a ser **um único por baixa** (não mais
   por linha de recebimento).
3. "Se for escolhido nas opções sinal ou adiantamento deve ser bloqueado o
   campo encerrar como e possibilitar prosseguir com o lançamento dos valores
   de abatimento da OS" — confirma que "Encerrar como"/"Data da entrega" saem
   de cena nesse modo.
4. Sobre "Data da entrega": usuário pediu para mantê-la escondida por padrão,
   mas adicionar um toggle "Equipamento foi entregue?" — se marcado, mostra o
   campo de data; se preenchido, o status da OS deve virar
   "Entregue - Pendência Financeira" (`entregue_pagamento_pendente`) e a OS
   **continua aberta** por ainda ter pendência financeira.
5. Sobre o toggle "Retorno pós-serviço": usuário escolheu escondê-lo no modo
   Sinal/Adiantamento (o atendimento não terminou, não faz sentido agendar
   retorno).

Implementação (detalhada na seção "Adiantamento/Sinal sem fechar a OS" do
`SKILL.md`): `OrderClosureService::registerAdvance()`, caminho paralelo a
`close()` que nunca aplica um dos 3 `closureCodes()`. Os dois métodos
compartilham as mesmas rotinas privadas extraídas (`processReceipts()`,
`simulateCardPayments()`) — nenhuma lógica financeira duplicada.
`entregue_pagamento_pendente` é seguro de aplicar fora de `close()` porque está
estruturalmente fora de `closureCodes()` (`grupo_macro='interrupcao'`),
confirmado por leitura de código antes da implementação — nenhuma das regras
de bloqueio de "OS encerrada" documentadas acima se aplica a ele.

**Bug real encontrado via tinker durante a implementação**: a primeira versão
de `registerAdvance()` chamava `updateStatus()` **sem** `viaClosureFlow: true`
(por achar que só era necessário para os 3 `closureCodes()`). Resultado:
marcar "equipamento entregue" falhava com `invalid_transition` em qualquer OS
cujo status de origem não tivesse `entregue_pagamento_pendente` cadastrado no
catálogo de transições (`os_status_transicoes`) — ex.: `aguardando_autorizacao`.
Além disso, como o `return $statusResult;` dentro do closure do `DB::transaction`
não lança exceção, a transação **commitava mesmo assim**: o título/movimento
financeiro ficava persistido apesar do resultado final ser de erro. Corrigido
passando `viaClosureFlow: true` (mesmo padrão de `close()` — o handoff técnico
precisa poder acontecer a partir de qualquer etapa aberta), o que faz
`statusResult` ser sempre `ok` na prática, alinhando o comportamento ao de
`close()`. Reproduzido e confirmado corrigido via tinker na OS de teste 2482
(ver abaixo).

Verificação (tinker, OS 2482 `aguardando_autorizacao`, revertido ao final):

1. `registerAdvance()` com `equipamento_entregue=false` → `status`/`data_entrega`
   da OS inalterados, 1 título `Financeiro` criado, 0 cobranças agendadas.
2. `registerAdvance()` com `equipamento_entregue=true` + `data_entrega` →
   `status='entregue_pagamento_pendente'`, `data_entrega`/`baixa_tecnica_em`/`_por`
   preenchidos, `status_final_pendente_pagamento` permanece `null`, 3 cobranças
   agendadas (D+1/D+3/D+5), `is_encerrada` continua `false`.
3. Guarda: `registerAdvance()` numa OS já em `closureCodes()` (OS 1,
   `devolvido_sem_reparo`) → `result: 'order_is_closed'`, zero efeitos colaterais
   (Financeiro/histórico/status inalterados).

Chrome headless (fluxo completo do wizard nos dois modos, OS 2482) e regressão
da Baixa normal na mesma OS — ambos verificados e revertidos (Baixa via
`cancelClosure()`, Adiantamento/Sinal via reversão manual dos artefatos
financeiros/histórico/status). Dados de teste sempre revertidos ao final.

**Segundo bug real, encontrado só quando o usuário testou manualmente**: o
select de Classificação vira Select2 automaticamente (todo `select.form-select`
vira, ver `desktop.js::initSelect2()`), e o Select2 só dispara `change` via
`jQuery(el).trigger('change')` — não gera evento nativo, então o
`addEventListener('change', ...)` original nunca disparava ao escolher pela UI
real. O Chrome headless da primeira rodada de verificação usou `page.select()`,
que seta o valor programaticamente e mascarou o bug (dispara evento nativo
direto, sem passar pela UI do Select2). Corrigido com bind paralelo via jQuery
(mesmo padrão de `receiptsList`/campos de cartão). Reproduzido e confirmado
corrigido clicando de verdade no dropdown Select2 renderizado
(`.select2-selection` → `.select2-results__option`), não via `page.select()`.
Comentário de aviso geral adicionado em `desktop.js::initSelect2()` para
qualquer novo listener de `change` em select — este é o segundo lugar dentro
de `orders-closure.js` a ser mordido por isso (o primeiro foram os campos de
cartão do recebimento).

## Arquivos que compoem esta regra

- `backend/app/Models/OrderStatus.php` — `closureCodes()`, `REVENUE_CLOSURE_CODE`,
  `CLOSURE_MACRO_GROUP`.
- `backend/app/Services/Orders/OrderWorkflowService.php` — `updateStatus()`
  (rejeita `order_is_closed` e `closure_status_requires_baixa_flow`),
  `updateOrder()`, `mapNextStatusOptionsFromCatalog()` (filtra closureCodes de
  `proximas_etapas`), `mapSummary()`/`mapDetail()` (`is_encerrada`).
- `backend/app/Services/Orders/OrderClosureService.php` — `close()`,
  `closureOptions()`, `cancelClosure(orderId, actor, ?verifiedAdmin)`,
  `registerAdvance(orderId, actor, payload)` (caminho paralelo, nunca aplica
  `closureCodes()`), `processReceipts()`/`simulateCardPayments()` (helpers
  privados compartilhados entre `close()` e `registerAdvance()`).
- `backend/app/Http/Requests/Api/V1/CloseOrderRequest.php` — `closureStatusCodes()`,
  `classificacao_baixa` (Baixa/Adiantamento/Sinal, decide quais campos são
  obrigatórios via `Rule::requiredIf()`).
- `backend/app/Http/Requests/Api/V1/CancelOrderClosureRequest.php` — valida
  `admin_email`/`admin_password` do gate de "Cancelar baixa".
- `backend/app/Http/Controllers/Api/V1/OrderController.php` — `update()`,
  `updateStatus()`, `close()`, `cancelClosure()` (tratamento dos results +
  verificação de admin com rate limiting).
- `backend/routes/api.php` — `POST orders/{order}/closure/cancel`.
- `backend/app/Services/Financeiro/FinanceiroReportService.php` — `dreReport()`.
- `backend/app/Services/Financeiro/OsMargemService.php` — `calcularParaOs()`,
  `recalcularEmLote()` (margem so para REVENUE_CLOSURE_CODE).
- `backend/bootstrap/app.php` — `$exceptions->dontFlash('admin_password')`.
- `frontends/desktop/app/Services/OrderService.php` —
  `cancelClosure($id, $adminEmail, $adminPassword)`.
- `frontends/desktop/app/Http/Controllers/OrderController.php` — `closureCancel()`
  (valida admin_email/admin_password, nunca usa `withInput()` no erro).
- `frontends/desktop/routes/web.php` — `POST /os/{order}/baixa/cancelar`
  (middleware `desktop.permission:os,visualizar`).
- `frontends/desktop/resources/views/orders/show.blade.php` — botão "Cancelar
  baixa" (fora do gate `os,editar`) / bloqueio do form "Atualizar status" via
  `$isEncerrada`.
- `frontends/desktop/resources/views/orders/_wizard.blade.php` — bloqueio do
  modal de status na edição via `$isEncerrada`.
- `frontends/desktop/resources/views/orders/index.blade.php` — `$canCloseOrder`
  via `is_encerrada` (não mais `estado_fluxo`) + item "Cancelar baixa" no
  dropdown Ações.
- `frontends/desktop/resources/views/orders/_cancel_closure_modal.blade.php` —
  modal compartilhado (listagem + detalhe) do gate de admin.
- `frontends/desktop/public/assets/js/orders-cancel-closure-modal.js` — submit
  do modal acima (fetch + CSRF, mostra erro inline em falha).
- `frontends/desktop/app/Http/Controllers/OrderController.php` — `closureStore()`
  (validação condicional por `classificacao_baixa`).
- `frontends/desktop/resources/views/orders/closure.blade.php` — select
  `classificacao_baixa`, bloco `#closureBaixaFields`/`#closureAdvanceFields`,
  toggle `equipamento_entregue`.
- `frontends/desktop/public/assets/js/orders-closure.js` — `isAdvanceClosure()`,
  `updateClosureModeVisibility()`, `updateDataEntregaVisibility()`,
  `updateReturnCardVisibility()`.

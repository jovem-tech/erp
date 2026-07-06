# Baixa de lancamento financeiro: valor total/parcial e forma de pagamento em cartao

## Contexto

- versao: `3.10.0.0`
- data: `2026-07-05`
- ambiente-alvo: `Ubuntu VPS` (reproduzido em `192.168.1.100`)
- area afetada: `Financeiro > Lancamentos > Registrar baixa` (desktop)
- referencia de UX/funcionalidade: mesma experiencia de recebimento em cartao ja usada na baixa de OS (`orders/closure.blade.php` + `orders-closure.js`)

## Entrega

- **Valor da baixa** ganhou dois atalhos:
  - `Valor total (R$ X,XX)`: preenche o campo com o saldo em aberto real do titulo (nao apenas o valor original — considera baixas parciais anteriores);
  - `Valor parcial`: limpa e foca o campo para digitacao manual (sem preencher nenhum valor automatico). Uma baixa parcial mantem o titulo com status `Parcial`, com valor pago e saldo pendente recalculados pelo backend.
- **Forma de pagamento** deixou de ser texto livre e virou um `<select>` com as mesmas opcoes da baixa de OS (`dinheiro`, `cartao_credito`, `cartao_debito`, `pix`, `boleto`, `transferencia`). Ao selecionar cartao, aparecem os campos `Operadora`, `Bandeira (opcional)`, `Modalidade` e `Parcelas`, com um texto de preview (`Taxa estimada: R$ ... · Liquido estimado: R$ ...`) calculado no cliente a partir do catalogo de taxas — mesma logica de `findApplicableRate`/`estimateFee` de `orders-closure.js`, extraida para `frontends/desktop/public/assets/js/financeiro-pay.js`.
- **Backend (`backend/`)**:
  - `FinanceiroController::index` (API) passa a anexar `valor_aberto` (calculado via `FinanceiroService::movementSummary`) em cada item de `/financeiro`;
  - `FinanceiroCatalogController::index` passa a expor `cartao` (`operadoras`/`bandeiras`/`taxas` ativos) via `FinanceiroCartaoService::buildActiveDataset()`;
  - `RegisterFinanceiroMovementRequest` aceita `operadora_id` (obrigatorio quando `forma_pagamento` e cartao), `bandeira_id`, `modalidade` e `parcelas`;
  - `FinanceiroService::registerMovement` passou a criar um registro em `FinanceiroMovimentoCartao` (taxa, valor liquido, prazo de recebimento) quando a baixa e em cartao, reaproveitando `FinanceiroCartaoService::simulate()` — o mesmo caminho usado por `OrderClosureService::registerCardMovementMeta`.
- **Desktop (`frontends/desktop/`)**:
  - `FinanceiroService::catalogo()` e `FinanceiroController::index`/`pay` repassam o dataset de cartao e aceitam os novos campos da baixa;
  - `financeiro/index.blade.php`: modal de baixa por lancamento ganhou os botoes de valor, o select de forma de pagamento e o bloco de campos de cartao (oculto por padrao via `d-none`, reaproveitando as classes utilitarias `desktop-grid`/`desktop-grid-two` ja globais no projeto — nao foi necessario CSS novo).

## Bug critico pre-existente encontrado e corrigido

Ao validar os botoes "Valor total"/"Valor parcial" no navegador real (192.168.1.100),
nenhum dos dois funcionava. Investigacao com um clone headless da pagina (Chrome +
Puppeteer, carregando os mesmos assets reais via HTTPS) mostrou a causa raiz:

- em `financeiro/index.blade.php`, o `<div class="modal fade" id="payModal{{ $id }}">`
  de cada lancamento era renderizado como filho direto de `<tbody>` (irmao do
  `<tr>`) — isso **ja existia no HEAD antes desta entrega**, nao foi introduzido agora;
- `<tbody>` so aceita `<tr>`/`<script>`/`<template>` como filho direto. Ao encontrar
  um `<div>` ali, o parser HTML do navegador aplica "foster parenting" (regra do
  WHATWG HTML parsing spec): o `<div>` e movido para antes da `<table>`, mas o
  `<form>` que ficava dentro dele e criado "vazio" — todo o conteudo que deveria
  estar dentro do form (inputs, selects, botoes) acaba fora dele, como irmaos soltos
  dentro de `.modal-content`;
- na pratica isso significa que o formulario de baixa **nunca teve seus campos
  reconhecidos como parte do `<form>`** — `new FormData(form)` so devolvia o
  `_token`. O botao `Confirmar baixa` (que ja existia antes desta entrega) muito
  provavelmente sempre submeteu um payload vazio, silenciosamente;
- confirmado com um repro minimo isolado (`<table><tbody><tr>...</tr><div><form><input></form></div></tbody></table>`)
  reproduzindo o mesmo esvaziamento fora do contexto da aplicacao.

**Correcao**: os modais de baixa deixaram de ser renderizados dentro do
`@foreach` que monta as `<tr>` da tabela. Agora existe um segundo `@foreach` (mesma
colecao `$lancamentos`), posicionado depois de `</table>`/`.table-responsive`,
que renderiza só os modais. Com isso o `<form>` fica com toda a hierarquia intacta
(confirmado via `form.querySelector(...)` e `new FormData(form)` retornando todos
os campos esperados).

## Impactos

- Nao ha migration nova: a baixa em cartao reaproveita a tabela `financeiro_movimentos_cartao`, ja criada em `2026_06_29_000001_create_os_closure_module_tables.php` para a baixa de OS.
- Contrato de `/financeiro` (lista) e `/financeiro/catalogo` mudou apenas de forma aditiva (campos novos, nenhum campo removido/renomeado) — nao quebra consumidores existentes.
- `/financeiro/{id}/baixar` aceita novos campos opcionais; sem eles, o comportamento anterior (baixa em dinheiro/pix/etc. sem captura de taxa) continua identico.
- Nao ha criacao automatica de despesa pela taxa de cartao (diferente da baixa de OS, que registra a taxa como custo operacional da OS) — aqui o `FinanceiroMovimentoCartao` fica apenas registrado para fins de auditoria/relatorio; se o negocio tambem quiser lancar a taxa como despesa nesse fluxo, e' uma decisao a parte.

## Validacao

- `node --check public/assets/js/financeiro-pay.js` sem erros de sintaxe;
- `php -l` em todos os arquivos PHP alterados (backend e desktop);
- `php artisan view:cache` (desktop) compilou `financeiro/index.blade.php` sem erros;
- renderizacao direta da view via `tinker` com dados simulados (dois lancamentos, um `pendente` e um `parcial`) confirmando: formulario de baixa presente, botao "Valor total" com o saldo correto por linha (nao o valor cheio do titulo), bloco de campos de cartao presente, catalogo de operadoras/bandeiras carregado, script `financeiro-pay.js` incluido;
- teste de ponta a ponta do `FinanceiroService::registerMovement` (backend) dentro de uma transacao com rollback: baixa parcial em cartao de debito (Mercado Pago) — resultado: `status` do titulo vira `parcial`, `valor_aberto` reduz corretamente, `FinanceiroMovimentoCartao` criado com `taxa_percentual`/`valor_taxa`/`valor_liquido` batendo com a taxa real cadastrada;
- corrigido tambem um bug pre-existente em `scripts/php/scaffold-release-note.php`: a insercao no topo de `historico-de-versoes.md` comparava com o titulo acentuado (`# Histórico de versões`) enquanto o arquivo real usa `# Historico de versoes` (sem acentos), entao a automacao sempre inseria a entrada no final do arquivo em vez do topo — corrigido para detectar a primeira linha do arquivo dinamicamente;
- apos a correcao do bug de foster parenting, repeti a validacao no navegador real (Chrome headless com os assets servidos por `https://192.168.1.100`, incluindo `jquery`, `bootstrap`, `select2` e `desktop.js` reais): `caminhoAteInput` do campo `valor_movimento` passou a incluir `FORM` na cadeia de ancestrais (antes, o `<form>` nao aparecia); clique em "Valor total" preenche `20.00`; clique em "Valor parcial" limpa o campo e move o foco para ele; selecionar "Cartão de crédito" exibe os campos de cartao e o preview calcula a taxa corretamente (ex.: débito Mercado Pago 1,99% sobre R$ 20,00 → taxa R$ 0,40, líquido R$ 19,60); `new FormData(form)` no submit real passou a incluir todos os campos (`valor_movimento`, `forma_pagamento`, `operadora_id`, `bandeira_id`, `modalidade`, `parcelas`, `observacoes`, `_token`), antes so o `_token` era enviado.

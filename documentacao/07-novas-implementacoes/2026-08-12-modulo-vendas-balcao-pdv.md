# Módulo de Vendas (balcão/PDV)

**Data:** 2026-08-12
**Spec:** `specs/027-vendas-balcao-pdv/spec.md`
**Tipo:** módulo novo (MINOR)

## Problema

A assistência vendia película, carregador, cabo, capa, celular e monitor no
balcão sem ter onde registrar: o único lugar do sistema com estrutura de itens
era o Orçamento, que existe para alimentar uma OS — e a OS exige `cliente_id` e
`equipamento_id` NOT NULL.

Na prática essas vendas ficavam fora do sistema ou entravam como lançamento
avulso no Financeiro, sem itens, sem baixa de estoque, sem margem e sem
comprovante. Efeito colateral: `movimentacoes` tinha **zero linhas** desde
sempre, e por isso `os_margem.custo_pecas` era sempre 0.

## O que foi entregue

Módulo `vendas` completo: PDV, baixa de estoque, título e baixas no Financeiro
(inclusive cartão com taxa e parcelas), cupom 80 mm e cancelamento com estorno.

### Banco

| Migration | Conteúdo |
|---|---|
| `2026_08_12_000001_create_vendas_module_tables.php` | `vendas`, `venda_itens`, `venda_pagamentos` |
| `2026_08_12_000002_add_sales_support_to_legacy_tables.php` | `movimentacoes.venda_id`/`venda_item_id`, `financeiro.venda_id`, `pecas.codigo_barras`/`unidade` + campos fiscais |
| `2026_08_12_000003_seed_vendas_module.php` | módulo RBAC `vendas` + categoria e subgrupo DRE "Venda de balcão" |
| `2026_08_12_000004_seed_venda_receipt_template.php` | template PDF `venda_comprovante` (80 mm) |

`php artisan migrate` continua quebrado por um erro de grant no banco de chat —
aplicar uma a uma com `--path`, nesta ordem.

### Decisões que valem registro

- **Venda concluída é imutável.** Sem rascunho no servidor: o carrinho vive no
  navegador e vai num único POST atômico. Correção é cancelar e revender. Isso
  elimina máquina de estados, reserva de estoque e locks longos.
- **`financeiro.venda_id` em vez de `origem_tipo`/`origem_id`.** Apesar do nome
  genérico, `origem_id` é um `belongsTo(FinanceiroMovimento)` com eager-load
  incondicional em `FinanceiroService::list()`: gravar o id da venda ali
  carregaria um movimento alheio de mesmo id, em silêncio.
- **ENUM de `financeiro.forma_pagamento` não foi tocado.** O título da venda
  nasce sem forma (em pagamento misto não existe "a" forma) e
  `syncFromMovements()` resolve o status. As formas vão para
  `venda_pagamentos.forma_pagamento` e `financeiro_movimentos.forma_pagamento`,
  ambos VARCHAR.
- **Consumidor final exige `avulso => true`.** `resolveClassification()` recusa
  título a receber sem cliente e sem OS. Coberto por teste.
- **Taxa de cartão é gravada pelo `FinanceiroService`**, porque o
  `SalePaymentService` repassa `operadora_id`. Gravar a meta também no serviço
  de vendas duplicaria a despesa no DRE — há asserção de contagem no teste.
- **Saldo insuficiente não bloqueia.** O sistema avisa, o operador confirma
  (`confirmar_estoque_insuficiente`), a venda é marcada com
  `estoque_divergente` e o saldo pode ficar negativo (`pecas.quantidade_atual`
  é `int` com sinal). Decisão consciente: o cadastro de estoque tem 9 peças e
  nunca foi movimentado; bloquear travaria o balcão por defeito de cadastro.
- **Venda fiada é permitida.** Soma dos pagamentos menor que o total deixa o
  título `pendente`/`parcial`, cobrável como qualquer outro.
- **Baixa de estoque com decremento atômico e lock ordenado por id.**
  `EstoqueController::storeMovement()` não foi reutilizado de propósito: ele faz
  read-modify-write em PHP, que é corrida sob concorrência.

### Backend

```
app/Models/{Sale,SaleItem,SalePayment}.php
app/Services/Sales/{SaleWorkflowService,SaleStockService,SalePaymentService,SaleReceiptService,InsufficientStockException}.php
app/Support/CommercialAdjustment.php
app/Http/Controllers/Api/V1/SaleController.php
app/Http/Requests/Api/V1/{StoreSaleRequest,CancelSaleRequest}.php
app/Services/Pdf/Contexts/SalePdfContextFactory.php
```

Alterados: `FinanceiroService` (ramo `venda_id` em `resolveOriginTrail()` +
eager-load de `sale`), `Financeiro` (relação `sale()`), `PdfTemplateRegistry`
(tipo `venda_comprovante`), `PdfDefaultTemplates` (`vendaComprovante()` e
`cabecalhoSemOs()`), `EstoqueController` (origem "Venda" nas movimentações +
campos fiscais).

### Rotas da API (`/api/v1`, `auth:sanctum`)

```
GET   vendas/form-data            vendas:criar
GET   vendas/itens/buscar         vendas:criar
GET   vendas/clientes             vendas:criar
GET   vendas/resumo               vendas:visualizar
GET   vendas                      vendas:visualizar
POST  vendas                      vendas:criar
GET   vendas/{venda}              vendas:visualizar
POST  vendas/{venda}/cancelar     vendas:excluir
GET   vendas/{venda}/comprovante  vendas:visualizar
```

Sem PUT/PATCH/DELETE. Códigos de erro próprios:
`VENDA_ESTOQUE_INSUFICIENTE` (422, com a lista de itens em falta),
`VENDA_IDEMPOTENCY_CONFLICT` (409), `VENDA_INVALIDA`,
`VENDA_ADMIN_AUTH_REQUIRED`, `VENDA_COMPROVANTE_INDISPONIVEL`.

### Desktop

Menu em Atendimento: "Vendas" e "Nova venda (PDV)". Telas: listagem com filtros
e cards de total/ticket/margem, PDV em duas colunas, detalhe e ajuda.
`public/assets/js/pagamentos-cartao.js` é novo e compartilhável — extraído de
`orders-closure.js`, que **ainda usa a cópia própria**: migrá-lo é refactor
separado, porque a baixa de OS não tem cobertura automatizada de JS.

### Permissões

O slug `vendas` já existia em `RbacAuthorizationService::DEFAULT_MODULES` e
havia uma linha órfã `modulos` id=15 sem código. A migration adota essa linha
(`updateOrInsert` por slug) e reposiciona `ordem_menu` de 95 para 15.

Herança aplicada: `os:visualizar` → `vendas:visualizar`; `os:criar`/`os:editar`
→ `vendas:criar`/`vendas:editar`; `financeiro:editar` → `vendas:excluir`.
Resultado no ambiente de dev: **35 vínculos** criados.

## Testes

- `backend/tests/Feature/Api/V1/SaleFlowTest.php` — 9 cenários, 67 asserções:
  venda com peça e serviço, consumidor final, pagamento misto com cartão
  (contagem de `financeiro_movimentos_cartao` para pegar duplicação), venda
  fiada, estoque insuficiente (bloqueio e confirmação), idempotência,
  cancelamento com estorno, 403 sem permissão, totais do período.
- `frontends/desktop/tests/Feature/Desktop/VendaTest.php` — 4 cenários.
- Suíte completa: **zero regressões** nos dois apps (as falhas remanescentes já
  existiam antes desta entrega).

Testes ajustados por consequência do módulo novo:
`FinanceiroTest` (12 → 13 categorias) e `PdfTemplateEngineControllerTest`
(7 → 8 tipos documentais).

## Validação no ambiente de desenvolvimento

Smoke ponta a ponta contra `sistema_hml`, com dados removidos ao final: 21
verificações, todas verdes — numeração, totais, custo/margem, baixa de estoque,
título quitado com DRE resolvido, troco, trilha de origem, cupom 80 mm gerado
pelo motor real, idempotência (replay não debita duas vezes) e cancelamento com
estorno preservando saída e entrada no histórico.

**Achado do ambiente real:** existem contas financeiras ativas mas sem padrão
para `dinheiro`, e `FinanceiroContaService::resolveAccountId()` exige conta
explícita nesse caso. O PDV passou a receber `contas_padrao` no `form-data` e
pré-seleciona a conta configurada; sem padrão, o select fica obrigatório e cobra
a escolha antes do envio, em vez de falhar no backend com o carrinho montado.

## Fora de escopo (fases seguintes)

Controle de caixa (abertura/fechamento/sangria/suprimento), devolução e troca
parcial, edição de venda, emissão fiscal (NFC-e), impressão térmica direta
ESC/POS, comissão de vendedor, parcelamento real de título, custo médio,
lote/série, multi-depósito e multi-filial.

Os campos fiscais de `pecas` (`ncm`, `cest`, `cfop_venda`, `origem_mercadoria`,
`cst_icms`, `csosn`, `unidade_tributavel`) já existem, nullable, numa aba
recolhida do cadastro — preparam a emissão futura sem exigir ALTER depois.

## Checklist de ativação

1. Aplicar as 4 migrations com `--path`, na ordem.
2. `config:cache`, `route:cache` e `view:clear` nos **dois** apps.
3. **Logout/login obrigatório**: o desktop guarda as permissões na sessão; sem
   relogar o menu Vendas não aparece e parece bug.
4. Conferir Financeiro > Contas: sem conta padrão para dinheiro, o operador
   precisa escolher a conta em toda venda em espécie.
5. Cadastrar os produtos e fazer a carga inicial de saldo — sem isso o estoque
   e a margem não têm valor. É o principal risco de o módulo "não pegar".

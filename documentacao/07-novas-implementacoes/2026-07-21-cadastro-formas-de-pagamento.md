# Cadastro de formas de pagamento

**Data:** 21/07/2026
**Versão:** `5.5.0.0`
**Status:** migration aplicada e módulo ativo no ambiente de desenvolvimento LAN; não publicado na VPS de produção

## Objetivo

As formas de pagamento eram uma **lista fixa no código** (`Financeiro::FORMAS_PAGAMENTO`
e `Financeiro::formaPagamentoOptions()`), repetida ainda como `<option>` fixo nos
Blades. Acrescentar "Cheque", "Vale" ou qualquer forma nova exigia alterar código em
vários lugares. Agora existe um cadastro gerenciável em **Lançamentos → Mais ações →
Configurações Financeiras → aba "Formas de Pagamento"**, e ele alimenta o sistema
inteiro.

## Restrição do banco legado (decisão de projeto)

A coluna-resumo `financeiro.forma_pagamento` é um **ENUM fixo** no banco real
`sistema_hml`, compartilhado com o sistema legado que roda em paralelo. As colunas de
detalhe (`financeiro_movimentos.forma_pagamento`, `financeiro_conta_defaults.forma_pagamento`)
são `varchar` e aceitam qualquer valor.

Optou-se por **não alterar o schema legado**. O catálogo carrega a flag `resumo_enum`,
que marca quais códigos a coluna-resumo aceita:

- **Formas de sistema** (as 6 originais): `resumo_enum = true` — funcionam em todo lugar.
- **Formas personalizadas**: `resumo_enum = false` — funcionam na baixa/recebimento, nos
  movimentos e nas formas padrão de conta (colunas varchar). Na coluna-resumo do título
  elas ficam nulas, exatamente como já acontecia com pagamentos "múltiplos" — sem perda
  de informação, porque o detalhe de cada baixa sempre fica em `financeiro_movimentos`.

Por isso o seletor do **formulário de lançamento** lista só as formas compatíveis com o
resumo, com o aviso "Formas personalizadas ficam disponíveis na hora da baixa"; já a
**baixa de OS** lista o catálogo inteiro.

## Arquitetura

- Migration aditiva `2026_07_21_000001_create_financeiro_formas_pagamento_table.php`:
  cria `financeiro_formas_pagamento` e semeia as 6 formas atuais. O seed só insere o que
  falta, então nunca sobrescreve um rótulo renomeado pelo usuário.
- Colunas: `codigo` (slug único e imutável — é o valor gravado nos lançamentos), `nome`
  (rótulo editável), `is_cartao`, `sistema`, `resumo_enum`, `ordem_exibicao`, `ativo`.
- `App\Models\FinanceiroFormaPagamento` expõe `validCodes()` (colunas varchar),
  `summaryCodes()` (coluna-resumo ENUM), `options()` e `isCardCode()`, com cache por
  request invalidado a cada escrita. `Financeiro::FORMAS_PAGAMENTO` continua no código
  apenas como semente/fallback (se a tabela ainda não existir, nada quebra).
- Consumidores refatorados: `UpsertFinanceiroRequest` (usa `summaryCodes()`),
  `CloseOrderRequest` e `UpsertFinanceiroContaRequest` (usam `validCodes()`),
  `FinanceiroService`, `FinanceiroContaService` e `OrderClosureService::isCardPayment()`.
- API CRUD em `FinanceiroCatalogController` (`/financeiro/formas-pagamento`), com o
  catálogo incluído no payload de `catalogo`. `OrderController::closureMetadata()` passou
  a devolver `formas_pagamento` para a tela de baixa.

## Regras de proteção

- Formas de **sistema** não podem ser excluídas (só desativadas) e têm código e tipo
  cartão imutáveis — toda a lógica de operadora/bandeira/parcelas/taxas depende deles.
- Forma personalizada **já usada** em lançamentos não pode ser excluída; a mensagem
  orienta a desativá-la.
- O código nunca muda depois de criado, porque registros históricos apontam para ele.

## Cartão

A marcação "É cartão" define quem dispara operadora/bandeira/parcelas e cálculo de taxa.
O JS da baixa (`orders-closure.js`) deixou de adivinhar pelo prefixo `cartao` do código e
passou a receber `cardPaymentCodes` do catálogo — assim uma forma personalizada marcada
como cartão também pede os campos corretos, em vez de o backend exigir operadora numa
tela que nunca perguntou.

## Validação

8 testes novos em `tests/Feature/Api/V1/FinanceiroFormaPagamentoTest.php` cobrindo:
catálogo semeado, criação com código gerado do nome, proteção das formas de sistema,
bloqueio de exclusão de forma em uso, exclusão de forma sem uso, separação entre
`validCodes()`/`summaryCodes()`, detecção de cartão pelo cadastro e desativação.
Suítes Financeiro e OrderFlow rodadas antes e depois: nenhuma regressão.

## Pendências conhecidas

Telas secundárias que ainda listam formas de pagamento de forma fixa (filtros e rótulos):
`financeiro/index.blade.php`, `financeiro/show.blade.php`,
`financeiro/relatorios/fluxo-caixa.blade.php` e `orders/show.blade.php`. Elas continuam
funcionando para as 6 formas de sistema; adaptá-las ao catálogo é um ajuste pendente.

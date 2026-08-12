# Devolução e troca de venda

**Data:** 2026-08-14
**Spec:** `specs/029-devolucao-troca/spec.md`
**Tipo:** módulo novo (MINOR)
**Depende de:** `specs/027-vendas-balcao-pdv`, `specs/028-caixa-sessoes`

## Problema

O único jeito de desfazer uma venda era **cancelá-la inteira**. Cliente que
comprou três películas e queria devolver uma não tinha caminho: o operador
cancelava tudo e refazia, queimando o número da venda original e sujando o
histórico. Troca não existia de forma nenhuma.

## O que foi entregue

Devolução **parcial** por item, com o dinheiro voltando pela mesma forma em que
entrou, o produto voltando à prateleira e o caixa do dia refletindo a saída.
Troca é a composição de uma devolução com uma venda nova.

### Banco

| Migration | Conteúdo |
|---|---|
| `2026_08_14_000001_create_venda_devolucoes_tables.php` | `venda_devolucoes`, `venda_devolucao_itens`, `venda_devolucao_pagamentos`, `vendas.total_devolvido`, `caixa_sessoes.total_devolucoes_dinheiro` |
| `2026_08_14_000002_seed_devolucao_categoria.php` | categoria e subgrupo DRE "Devolução de venda" |
| `2026_08_14_000003_seed_devolucao_receipt_template.php` | template PDF `venda_devolucao` (80 mm) |

Sem módulo RBAC novo: devolver é ação de `vendas`.

### As decisões que valem registro

**A taxa de cartão é registrada "não fazendo nada".** Este é o ponto mais
contraintuitivo da entrega. A operadora não devolve a taxa, e a pergunta natural
é "onde lanço essa perda?". A resposta: ela **já está lançada**. A despesa nasce
na venda (`FinanceiroService::registerCardFeeExpense`). O cancelamento total a
cancela junto, porque a venda deixou de existir; a devolução **não pode fazer
isso**, porque a venda aconteceu e a taxa foi cobrada de verdade. Registrar a
perda no DRE significa, portanto, **não reverter** o que já existe — e há teste
asserindo que nenhuma despesa de taxa é cancelada numa devolução. O valor fica
guardado em `valor_taxa_nao_estornada` só para exibição.

**A receita original não é estornada.** Apagá-la reescreveria o DRE de um mês
possivelmente já fechado. A devolução é evento próprio, com data própria, e vira
um título a pagar sob "Despesas Operacionais" → "Devolução de venda". Infla
receita e despesa em paralelo, fecha certo no resultado e deixa o volume de
devoluções visível.

**Venda fiada não devolve dinheiro que o cliente nunca pagou.** Numa venda de
R$ 100 com apenas R$ 30 pagos, devolver tudo gera crédito de 100, reembolso de
**30** e abatimento de **70** na dívida em aberto. Sem esse teto, o sistema
devolveria dinheiro que nunca entrou — bug que custa caro e é silencioso.

**O reembolso é proporcional ao que foi pago, não ao preço de lista.** Numa
venda de subtotal 100 com desconto geral de 10 (total 90), devolver um item de
50 devolve **45**.

**O dinheiro sai da gaveta de hoje.** Venda de terça devolvida na quinta sai do
caixa de quinta. `CaixaSessionService::sessionTotals()` passou a subtrair as
devoluções em dinheiro do turno, senão o operador fecharia com falta
inexplicada. Caixa fechado abre automaticamente, como na venda.

**Saldo devolvível controlado.** Não dá para devolver 3 de um item com 2
vendidos, nem devolver duas vezes a mesma unidade — e a validação é refeita
dentro da transação, para duas devoluções simultâneas não passarem do total.

### Backend

```
app/Models/{SaleReturn,SaleReturnItem,SaleReturnPayment}.php
app/Services/Sales/{SaleReturnService,SaleReturnReceiptService}.php
app/Http/Controllers/Api/V1/SaleReturnController.php
app/Http/Requests/Api/V1/StoreSaleReturnRequest.php
app/Services/Pdf/Contexts/SaleReturnPdfContextFactory.php
```

Alterados: `SaleStockService` (`creditForReturn()` para devolução parcial),
`CaixaSessionService` (devoluções no cálculo do esperado), `Sale`
(`total_devolvido`, relação `returns()`), `PdfTemplateRegistry` e
`PdfDefaultTemplates` (tipo `venda_devolucao`).

### Rotas da API (`/api/v1`, `auth:sanctum`)

```
GET   devolucoes                        vendas:visualizar
GET   devolucoes/{devolucao}            vendas:visualizar
GET   devolucoes/{devolucao}/comprovante vendas:visualizar
GET   vendas/{venda}/devolvivel         vendas:visualizar
POST  vendas/{venda}/devolucoes         vendas:criar
```

Códigos próprios: `DEVOLUCAO_INVALIDA`, `DEVOLUCAO_IDEMPOTENCY_CONFLICT`,
`DEVOLUCAO_TROCA_INVALIDA`, `DEVOLUCAO_ADMIN_AUTH_*`,
`DEVOLUCAO_COMPROVANTE_INDISPONIVEL`.

### Desktop

Botão "Devolver" no detalhe da venda leva à tela de devolução, que mostra o
saldo por item, o reembolso unitário já proporcional e — item a item — se aquilo
volta ou não ao estoque. Listagem de devoluções no menu (a consulta é
frequente), detalhe com o comparativo e a taxa perdida em destaque, e ajuda.

Linhas com quantidade zero são descartadas no controller antes de chamar a API:
no formulário elas são só campo não preenchido.

## Testes

- `backend/tests/Feature/Api/V1/SaleReturnFlowTest.php` — 10 cenários, 69
  asserções: devolução parcial com reembolso proporcional, saldo devolvível
  impedindo excesso, rateio entre formas de pagamento, taxa de cartão não
  revertida, venda fiada com abatimento, serviço sem retorno de estoque,
  devolução em dinheiro na conferência do caixa, venda antiga exigindo admin,
  troca vinculada, venda cancelada recusada, 403 sem permissão.
- `frontends/desktop/tests/Feature/Desktop/DevolucaoTest.php` — 6 cenários.
- Suíte completa: **zero regressões** nos dois apps.

Testes ajustados por consequência: `FinanceiroTest` (13 → 14 categorias) e
`PdfTemplateEngineControllerTest` (9 → 10 tipos documentais).

## Validação no ambiente de desenvolvimento

Smoke ponta a ponta contra `sistema_hml`, com dados removidos ao final: **21
verificações, todas verdes** — reembolso proporcional (45 e não 50), retorno de
uma unidade ao estoque com rastro da venda, título a pagar com DRE resolvido,
receita original preservada, devolução descontada do esperado do caixa
(100 + 90 − 45 = 145), saldo devolvível caindo de 2 para 1, comprovante 80 mm
gerado pelo motor real, recusa ao exceder o saldo, e o caso fiado
(crédito 100 / reembolso 30 / abatido 70) com a dívida caindo de 100 para 30.

## Fora de escopo

Cancelar uma devolução já registrada, vale-troca (crédito do cliente), estorno
automático na maquininha, devolução de venda cancelada e relatórios de devolução
por motivo — estes últimos cabem na fase de relatórios.

## Checklist de ativação

1. Aplicar as 3 migrations com `--path`, na ordem.
2. `config:cache`, `route:cache` e `view:clear` nos **dois** apps.
3. **Logout/login** para o menu Devoluções aparecer.
4. Combinar a política de devolução com a equipe: o prazo livre é de **7 dias**
   (`SaleReturn::PRAZO_LIVRE_DIAS`); acima disso o sistema pede credencial de
   administrador e grava quem autorizou.
5. Lembrar o balcão de que **estorno em cartão não é automático**: o sistema
   registra a saída, mas processar na maquininha continua manual.

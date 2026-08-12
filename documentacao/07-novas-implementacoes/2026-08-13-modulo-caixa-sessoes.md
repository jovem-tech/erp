# Módulo de Caixa (turnos, sangria e conferência)

**Data:** 2026-08-13
**Spec:** `specs/028-caixa-sessoes/spec.md`
**Tipo:** módulo novo (MINOR)
**Depende de:** `specs/027-vendas-balcao-pdv`

## Problema

O módulo de vendas registra corretamente cada venda, mas ninguém conseguia
responder a pergunta do fim do dia: **o que está na gaveta bate com o que o
sistema diz?**

Não havia registro de quem abriu o caixa, com quanto de troco, o que saiu para o
cofre nem quanto foi contado no fechamento. Erro de troco ou desvio só apareceria
semanas depois na conciliação bancária, sem dono e sem data.

Sintoma concreto: **não existia conta financeira do tipo `caixa`**. Por isso toda
venda em dinheiro obrigava o operador a escolher a conta manualmente.

## O que foi entregue

Turno de caixa com dono: abertura declarada, sangria e suprimento rastreáveis,
contagem cega no fechamento, diferença apurada e relatório em 80 mm. E a venda em
dinheiro deixou de pedir conta.

### Banco

| Migration | Conteúdo |
|---|---|
| `2026_08_13_000001_create_caixa_module_tables.php` | `caixa_sessoes`, `caixa_movimentos`, `vendas.caixa_sessao_id` |
| `2026_08_13_000002_seed_caixa_module.php` | módulo RBAC `caixa` (só isso — ver decisão abaixo) |
| `2026_08_13_000003_seed_caixa_report_template.php` | template PDF `caixa_fechamento` (80 mm) |

### A decisão mais importante desta entrega

**A migration NÃO cria a conta de caixa.** Enquanto nenhuma conta financeira
ativa existe, `FinanceiroContaService::resolveAccountId()` devolve `null` e o
sistema opera sem rastreio de conta. **A primeira conta cadastrada liga o modo
"conta obrigatória" para todo o sistema** — baixa de OS, venda, lançamento
avulso.

A primeira versão desta entrega criava a conta na migration. O resultado foi
medido: **25 testes quebrados**, incluindo todo o fechamento de OS. Criar a conta
numa migration ligaria esse modo silenciosamente em instalações que sequer vão
usar caixa.

A conta passou a nascer na **primeira abertura explícita de caixa**
(`CaixaSessionService::resolveOrCreateCashAccount`), que é uma decisão visível do
usuário, feita numa tela que avisa o que vai acontecer. Efeito colateral
documentado e coberto por teste: a partir desse ponto, formas de pagamento sem
conta padrão configurada passam a exigir conta explícita.

### Outras decisões

- **Abertura automática.** Venda em dinheiro com caixa fechado abre o turno
  sozinha, herdando como troco inicial o valor contado no fechamento anterior.
  Bloquear seria o controle mais rígido, mas travaria o balcão por esquecimento —
  e vai esquecer. O turno fica marcado como automático e o valor é corrigível.
- **Conferência cega.** O `valor_esperado` **não é devolvido pela API nem
  exibido** enquanto o turno está aberto. Mostrar antes da contagem
  transformaria o fechamento em "digitar o número que o sistema quer".
  `valor_esperado`/`diferenca` são `prohibited` no request de fechamento.
- **A sessão reconcilia a conta.** Abertura declarada, suprimento, sangria sem
  destino e a diferença do fechamento viram ajustes em
  `financeiro_conta_movimentos`. Sem isso, "Contas e Saldos" mostraria a gaveta
  errada e a sangria não encontraria saldo para transferir.
- **Sangria com destino é transferência de verdade**, via
  `FinanceiroContaService::createTransfer()` — com lock nas duas contas,
  validação de saldo e de data.
- **Troco não entra na conta separadamente.** Numa venda de R$ 50 paga com R$
  100, a gaveta ganha 100 e devolve 50: o líquido é o próprio `valor` do
  pagamento.
- **Venda cancelada sai do esperado** automaticamente — o filtro de totais
  considera só vendas concluídas.
- **Cartão e Pix ficam fora da conferência**: não passam pela gaveta.

### Backend

```
app/Models/{CaixaSessao,CaixaMovimento}.php
app/Services/Caixa/{CaixaSessionService,CaixaReportService}.php
app/Http/Controllers/Api/V1/CaixaController.php
app/Http/Requests/Api/V1/{OpenCaixaRequest,StoreCaixaMovimentoRequest,CloseCaixaRequest}.php
app/Services/Pdf/Contexts/CaixaPdfContextFactory.php
```

Alterados: `RbacAuthorizationService` (slug `caixa` em `DEFAULT_MODULES`),
`FinanceiroContaService` (`balanceOf()` público), `SaleWorkflowService` e
`SalePaymentService` (vínculo com o turno e conta de dinheiro resolvida),
`PdfTemplateRegistry` e `PdfDefaultTemplates` (tipo `caixa_fechamento`).

### Rotas da API (`/api/v1`, `auth:sanctum`)

```
GET    caixa/atual                caixa:visualizar
GET    caixa                      caixa:visualizar
POST   caixa/abrir                caixa:criar
GET    caixa/{sessao}             caixa:visualizar
POST   caixa/{sessao}/movimentos  caixa:editar
PATCH  caixa/{sessao}/abertura    caixa:editar
POST   caixa/{sessao}/fechar      caixa:editar
POST   caixa/{sessao}/reabrir     caixa:excluir  (+ credencial de admin)
GET    caixa/{sessao}/relatorio   caixa:visualizar
```

Códigos próprios: `CAIXA_ABERTURA_INVALIDA`, `CAIXA_MOVIMENTO_INVALIDO`,
`CAIXA_FECHAMENTO_INVALIDO`, `CAIXA_REABERTURA_INVALIDA`,
`CAIXA_SESSAO_NAO_ENCONTRADA`, `CAIXA_ADMIN_AUTH_*`, `CAIXA_RELATORIO_INDISPONIVEL`.

### Permissões

Herança: `vendas:visualizar` → `caixa:visualizar`; `vendas:criar` →
`caixa:criar` + `caixa:editar`; `financeiro:editar` → `caixa:excluir`.
No ambiente de dev: **21 vínculos** criados.

### Desktop

Menu em Atendimento, junto do PDV. Telas: turno atual (com modais de abertura,
sangria, suprimento, correção de abertura e fechamento), histórico com filtro
"só com diferença", detalhe com o comparativo da conferência, e ajuda.

## Testes

- `backend/tests/Feature/Api/V1/CaixaFlowTest.php` — 11 cenários, 73 asserções:
  abertura declarada, recusa de segundo turno, caixa aberto não revela o
  esperado, abertura automática, Pix não abre caixa, consequência da adoção
  (forma sem padrão exige conta), sangria com transferência e limite de saldo,
  fechamento com esperado/diferença e congelamento dos totais, venda cancelada
  fora do esperado, reabertura com admin, correção da abertura, 403 sem permissão.
- `frontends/desktop/tests/Feature/Desktop/CaixaTest.php` — 5 cenários,
  incluindo a asserção de que a tela do turno aberto **não** exibe o esperado.
- Suíte completa: **zero regressões** nos dois apps.

Testes ajustados por consequência: `PdfGenerationServiceTest` (novo tipo no loop
de geração) e `PdfTemplateEngineControllerTest` (8 → 9 tipos documentais).

## Validação no ambiente de desenvolvimento

Smoke ponta a ponta contra `sistema_hml`, com dados removidos ao final: **17
verificações, todas verdes** — criação da conta na primeira abertura, padrão de
dinheiro configurado, saldo reconciliado, venda vinculada ao turno com conta
resolvida sozinha, sangria virando transferência, esperado calculado
(200 + 90 − 100 + 40 = 230), venda cancelada saindo do esperado, fechamento com
diferença de −5, saldo ajustado para o valor contado, relatório 80 mm gerado pelo
motor real, e abertura automática herdando os R$ 195 do fechamento anterior.

Confirmado após as migrations: **nenhuma conta de caixa criada** e nenhum padrão
de dinheiro alterado — o comportamento das instalações existentes ficou intacto
até a primeira abertura.

## Fora de escopo

Múltiplos caixas simultâneos na UI (o modelo suporta, a tela assume um),
conferência por denominação de cédula, troca de turno sem fechar, sangria
automática por limite na gaveta e conciliação de cartão.

## Checklist de ativação

1. Aplicar as 3 migrations com `--path`, na ordem.
2. `config:cache`, `route:cache` e `view:clear` nos **dois** apps.
3. **Logout/login obrigatório** para o menu Caixa aparecer.
4. **Abrir o caixa pela primeira vez** contando o dinheiro que já está na gaveta.
   É essa abertura que cria a conta "Caixa da loja" — e a partir dela o sistema
   passa a exigir conta financeira em toda baixa. Confira em Financeiro > Contas
   se as demais formas (Pix, cartões, transferência) têm conta padrão
   configurada, senão elas passarão a pedir escolha manual.

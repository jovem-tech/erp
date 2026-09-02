# Abrir OS a partir de um orçamento aprovado do cliente (mobile) (2026-09-02)

**Spec:** `specs/004-os-mobile-flow/spec.md`
**Tipo:** nova funcionalidade (MINOR)

## O problema

No app mobile, a única forma de vincular um orçamento à OS em criação era o
campo de busca livre "Vincular orçamento avulso" na etapa **Atendimento** — o
técnico precisava lembrar que o orçamento existia, saber o número (ou o nome
que o cliente usou) e digitá-lo, já quase no fim do formulário. Para o caso
mais comum do balcão — o cliente aprovou o orçamento e voltou com o aparelho —
não havia caminho nenhum partindo do cliente.

Pior: mesmo quando o técnico acertava o vínculo, a OS nascia em **Triagem**,
como qualquer outra. Um serviço já autorizado voltava para a fila de
diagnóstico/orçamento que o cliente já tinha respondido.

## O que passou a existir

**Etapa Cliente (mobile).** Ao selecionar um cliente cadastrado, o app consulta
os orçamentos aprovados dele que ainda não geraram OS e lista cada um com
número, equipamento e total. Um toque vincula o orçamento à OS em criação e o
card passa a dizer que a OS será aberta em **Aguardando Reparo**. Sem
orçamento aprovado, nada aparece — a etapa continua igual ao que era.

**Etapa Equipamento (mobile).** Quando o orçamento vinculado é de um
equipamento já cadastrado, esse equipamento vem selecionado sozinho: o backend
exige que a OS use exatamente o equipamento do orçamento, então deixar a
escolha aberta só produziria um 422 no fim do formulário. Se o técnico trocar
para outro equipamento, um aviso explica o conflito e aponta as duas saídas
(voltar ao equipamento do orçamento ou desvincular o orçamento na etapa
Cliente).

**Revisão.** O card "Extras" passa a mostrar `Status inicial da OS`
= "Aguardando Reparo (orçamento aprovado)" — o técnico confere antes de salvar,
em vez de descobrir o status depois de criada.

## Backend

`GET /orcamentos/vinculaveis-os` ganhou dois filtros:

- `cliente_id` — restringe aos orçamentos de um cliente cadastrado;
- `somente_aprovados=1` — restringe aos que o cliente autorizou (`aprovado` e
  `pendente_abertura_os`).

Cada item da lista passou a trazer `cliente_id` e `equipamento_id` (nulos em
orçamento avulso ou sem equipamento), que é o que permite ao app já selecionar
o equipamento certo. Permissões inalteradas: `os:criar` + `orcamentos:converter_os`.

**Status inicial da OS** (`OrderWorkflowService::pendingOrderStatusForBudgetLink()`):
orçamento aprovado agora abre a OS em `aguardando_reparo` em vez de cair no
status padrão de abertura. Os demais estados continuam como estavam
(`aguardando_orcamento` ou `aguardando_autorizacao`). O mapeamento passa a ser
o mesmo em ambos os sentidos do fluxo — é exatamente o alvo que
`BudgetOrderSyncService::targetOrderStatus()` já usava quando o orçamento é
aprovado **depois** da OS existir; era só a abertura que destoava.

A regra vale para qualquer canal que abra OS com `orcamento_id` (mobile e
desktop), e o `status` enviado na requisição continua sendo ignorado quando há
orçamento vinculado — a autorização do cliente é que manda.

## Ajuste no desktop, por consequência

A Nova OS do desktop anunciava "Triagem ao salvar" e "A OS entra em triagem no
backend central" mesmo com orçamento vinculado — já estava desatualizado para
os orçamentos ainda não aprovados (que abriam em `aguardando_orcamento` /
`aguardando_autorizacao`) e passaria a estar errado justamente no caminho mais
usado, `/os/criar?orcamento_id=`. O rótulo e a nota agora acompanham o status
do orçamento vinculado.

## Verificação

- Backend: `1048 testes` (suíte completa), incluindo dois novos em
  `BudgetAvulsoFlowTest` — o filtro por cliente + aprovados (e a exclusão de
  orçamento de outro cliente e de orçamento ainda não aprovado) e a abertura de
  OS aprovada nascendo em `aguardando_reparo`/`em_execucao` com o orçamento
  virando `convertido`.
- Mobile: `229 testes` (suíte completa), com casos novos para a listagem e o
  vínculo na etapa Cliente, o aviso de "Aguardando Reparo", a ausência da
  consulta sem permissão de conversão, a seleção automática do equipamento do
  orçamento, o aviso de equipamento divergente e a linha de status inicial na
  Revisão. `tsc --noEmit` e `eslint` limpos.
- `backend/openapi.yaml` atualizado (novos parâmetros, novos campos do
  `LinkableBudgetSummary` e a regra de status inicial em `UpsertOrderRequest`).

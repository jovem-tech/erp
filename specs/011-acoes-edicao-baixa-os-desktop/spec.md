# Feature Specification: Ações de OS no Desktop — Dropdown, Edição e Baixa (paridade completa com o legado)

**Feature Branch**: `011-acoes-edicao-baixa-os-desktop`

**Created**: 2026-06-27

**Updated**: 2026-06-28 — escopo da baixa ampliado de MVP para paridade completa com o legado, a pedido do usuário.

**Status**: Approved

**Input**: User description: "Em /os na coluna ações, quero que as ações sejam apresentadas semelhante ao de /equipamentos. Neste menu de ações terá os botões de detalhes da OS, editar e baixa." Escopo de Baixa inicialmente aprovado como MVP funcional (status final + data de entrega + um recebimento + notificação WhatsApp manual). Após a entrega do MVP, o usuário pediu explicitamente a feature completa do legado: "Quero a feature completa do legado, incluindo taxa de cartao e cobranca agendada" — revertendo a decisão anterior de deixar simulação de taxa de cartão, cobrança automática agendada e follow-up de retorno via CRM fora de escopo.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Abrir o menu de ações padronizado na listagem de OS (Priority: P1)

Como atendente ou técnico, quero abrir um menu "Ações" na listagem de OS
igual ao que já existe na listagem de equipamentos, para acessar Detalhe,
Editar e Baixa sem poluir a tabela com vários botões soltos.

**Why this priority**: é a mudança visível imediata pedida; sem ela, as
outras duas ações não têm onde aparecer na listagem.

**Independent Test**: abrir `/os`, clicar no botão "Ações" de uma linha e
confirmar que o menu abre com os mesmos estilos/comportamento do menu de
`/equipamentos` (dropdown Bootstrap, mesma aparência visual).

**Acceptance Scenarios**:

1. **Given** uma OS qualquer na listagem, **When** clico em "Ações",
   **Then** vejo um menu com pelo menos "Detalhe".
2. **Given** um usuário sem permissão `os:editar`, **When** abre o menu de
   ações, **Then** não vê "Editar" nem "Baixa", só "Detalhe".
3. **Given** uma OS já encerrada (`estado_fluxo` em `encerrado` ou
   `cancelado`), **When** abro o menu de ações de um usuário com
   `os:editar`, **Then** vejo "Editar" mas não vejo "Baixa".

---

### User Story 2 - Editar uma OS existente (Priority: P1)

Como atendente ou técnico com permissão de edição, quero alterar dados de
uma OS já criada (relato, prioridade, técnico responsável, previsão,
observações internas, cliente/equipamento) sem precisar recriar a OS.

**Why this priority**: hoje não existe nenhuma forma de corrigir dados de
uma OS após a criação no desktop; é uma lacuna operacional básica.

**Independent Test**: abrir o menu de ações de uma OS, clicar em "Editar",
alterar a prioridade e o relato, salvar, e confirmar que o detalhe da OS
reflete os novos valores.

**Acceptance Scenarios**:

1. **Given** uma OS existente, **When** acesso `/os/{id}/editar`, **Then**
   vejo o mesmo formulário usado na criação, pré-preenchido com os dados
   atuais.
2. **Given** um formulário de edição preenchido, **When** salvo, **Then**
   sou redirecionado para o detalhe da OS com os dados atualizados e uma
   mensagem de sucesso.
3. **Given** um equipamento que não pertence ao cliente selecionado,
   **When** tento salvar, **Then** recebo erro de validação sem perder os
   dados já digitados.

---

### User Story 3 - Encerrar uma OS com baixa financeira em múltiplas etapas (Priority: P1)

Como atendente ou técnico com permissão de edição, quero encerrar uma OS
(marcar como entregue/devolvida sem reparo/descartada) percorrendo um
assistente de 3 etapas (Encerramento → Financeiro → Confirmação), registrar
a data de entrega e um ou mais recebimentos, e opcionalmente avisar o
cliente por WhatsApp.

**Why this priority**: é a ação operacional final de toda OS; sem ela, o
encerramento continua dependendo do legado.

**Independent Test**: abrir a baixa de uma OS aberta, escolher
"Equipamento entregue" na etapa 1, adicionar um recebimento de "receber
saldo total" na etapa 2, confirmar na etapa 3, e ver a OS aparecer na
listagem com status final, saldo atualizado e sem a opção "Baixa"
disponível novamente.

**Acceptance Scenarios**:

1. **Given** uma OS sem título financeiro vinculado, **When** abro a
   baixa, **Then** vejo o valor final da OS como valor a receber na etapa
   Financeiro.
2. **Given** uma OS com saldo em aberto, **When** escolho "receber saldo
   total" e confirmo, **Then** o saldo da OS fica zerado na listagem.
3. **Given** que marquei "notificar cliente por WhatsApp" mas o envio
   falha (integração fora do ar), **When** confirmo a baixa, **Then** a
   baixa é concluída normalmente e recebo um aviso de que a notificação
   não foi enviada — a falha de notificação não pode bloquear o
   encerramento.
4. **Given** uma OS sem telefone de cliente cadastrado, **When** abro a
   baixa, **Then** não vejo a opção de notificar por WhatsApp.
5. **Given** uma OS já encerrada, **When** tento acessar a URL de baixa
   diretamente, **Then** o backend aceita reabrir a baixa (para registrar
   o saldo restante) em vez de bloquear — mesmo comportamento do legado.
6. **Given** que informo múltiplos recebimentos (ex.: um sinal em dinheiro
   e o restante no pix) na etapa Financeiro, **When** confirmo, **Then**
   cada recebimento é registrado como um movimento financeiro distinto.

---

### User Story 4 - Receber pagamento em cartão com cálculo automático de taxa (Priority: P2)

Como atendente, quero informar que um recebimento da baixa foi em cartão
de crédito ou débito (operadora, bandeira, parcelas) e ver a taxa da
operadora calculada automaticamente, para saber o valor líquido que
efetivamente entrará no caixa.

**Why this priority**: é a principal lacuna financeira do MVP — sem ela, a
baixa subestima o custo real de cada venda no cartão; mas a baixa básica
(US3) já funciona sem isso, por isso é P2.

**Independent Test**: na etapa Financeiro, adicionar um recebimento,
marcar forma de pagamento "Cartão de crédito", escolher operadora e
parcelas, e ver a taxa estimada e o valor líquido aparecerem antes de
confirmar; após confirmar, ver uma despesa de "Taxa de cartão" criada no
módulo Financeiro.

**Acceptance Scenarios**:

1. **Given** uma operadora com taxa cadastrada para crédito em 1x,
   **When** registro um recebimento em cartão de crédito 1x dessa
   operadora, **Then** a baixa calcula `valor_taxa = valor_bruto *
   (taxa_percentual / 100) + taxa_fixa` e registra essa taxa como uma
   despesa (`financeiro` tipo `pagar`, categoria "Taxa de cartão").
2. **Given** uma combinação de operadora/modalidade/parcelas sem taxa
   ativa cadastrada, **When** tento confirmar a baixa com esse
   recebimento, **Then** recebo um erro de validação específico e nenhuma
   alteração é feita na OS.
3. **Given** um recebimento em débito, **When** preencho o formulário,
   **Then** o número de parcelas é ignorado e forçado para 1.

---

### User Story 5 - Cobrança automática agendada quando sobra saldo em aberto (Priority: P2)

Como gestor, quero que, quando uma baixa é confirmada com saldo ainda em
aberto (e a OS não for um caso "sem reparo"/"descartada"), o sistema
agende cobranças automáticas por WhatsApp em D+1, D+3 e D+5 após a
entrega, para reduzir inadimplência sem trabalho manual de cobrança.

**Why this priority**: automatiza um processo hoje manual, mas depende da
baixa básica (US3) já funcionando; por isso é P2.

**Independent Test**: confirmar uma baixa com saldo parcialmente pago,
verificar que a OS fica com status intermediário "Entregue - Pendência
Financeira" e que 3 agendamentos de cobrança foram criados; rodar o
comando `app:process-pending-os-collections` com um agendamento vencido e
confirmar que ele tenta notificar o cliente e atualiza o status do
agendamento.

**Acceptance Scenarios**:

1. **Given** uma baixa confirmada com saldo em aberto e encerramento que
   não é "sem reparo"/"descartado", **When** a transação é concluída,
   **Then** a OS fica com status `entregue_pagamento_pendente` (não o
   status final escolhido) e 3 registros de cobrança são agendados
   (D+1/D+3/D+5 às 10h).
2. **Given** uma OS "devolvida sem reparo" ou "descartada" com saldo em
   aberto, **When** a baixa é confirmada, **Then** o status final
   escolhido é aplicado diretamente, sem cobrança agendada.
3. **Given** agendamentos de cobrança pendentes de uma OS, **When** a
   mesma OS é encerrada novamente com o saldo total pago, **Then** os
   agendamentos pendentes anteriores são cancelados.
4. **Given** um agendamento de cobrança vencido cuja OS já não está mais
   com saldo em aberto, **When** o comando agendado processa esse
   registro, **Then** ele é cancelado sem tentar notificar o cliente.

---

### User Story 6 - Agendar retorno pós-serviço (Priority: P3)

Como atendente, quero poder marcar, na confirmação da baixa, que esta OS
deve gerar um retorno futuro de pós-venda (ex.: revisão em 180 dias), para
que a equipe de relacionamento saiba quando recontatar o cliente.

**Why this priority**: é um benefício de relacionamento, não bloqueia a
operação financeira da baixa; por isso é P3.

**Independent Test**: na etapa Confirmação, marcar "Agendar retorno
pós-serviço" com uma data, confirmar a baixa, e verificar que um registro
de follow-up foi criado vinculado à OS e ao cliente.

**Acceptance Scenarios**:

1. **Given** o toggle "Agendar retorno" marcado com uma data, **When** a
   baixa é confirmada, **Then** um follow-up é criado com status
   pendente, vinculado à OS e ao cliente.
2. **Given** que já existe um follow-up criado para a mesma OS e mesma
   data, **When** tento criar outro idêntico (ex.: reenviando o mesmo
   formulário), **Then** o sistema não duplica o registro.

## Edge Cases

- Status de encerramento inválido ou inativo enviado no payload → rejeitar
  com erro de validação, sem alterar a OS.
- Confirmar baixa sem informar nenhum valor de recebimento → permitido
  (a OS é encerrada com saldo em aberto, igual ao comportamento já
  existente de `entregue_pagamento_pendente` no legado).
- Usuário tenta editar ou dar baixa em OS de outro técnico quando o
  próprio usuário é técnico → respeitar a mesma regra de escopo já
  existente (`OrderWorkflowService::canAccessOrder`).
- Falha de conexão com a API durante a edição ou a baixa → manter os
  dados já digitados na tela e mostrar mensagem de erro, sem redirecionar.
- Recebimento em cartão sem operadora informada, ou com combinação sem
  taxa ativa → rejeitar a baixa inteira com erro específico, sem registrar
  nenhum movimento financeiro (a simulação ocorre antes de qualquer
  gravação).
- Cliente sem telefone cadastrado quando uma cobrança agendada vence →
  marcar o agendamento como erro (não cancelado), preservando o registro
  para diagnóstico.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: O desktop MUST substituir o botão único "Detalhe" da coluna
  "Ações" de `/os` por um menu dropdown, no mesmo padrão visual e
  estrutural já usado em `/equipamentos` (`dropdown-toggle` + `dropdown-menu`).
- **FR-002**: O menu de ações MUST exibir "Editar" apenas para usuários
  com permissão `os:editar`, e "Baixa" apenas para usuários com permissão
  `os:editar` **e** quando a OS não estiver com `estado_fluxo` em
  `encerrado` ou `cancelado`.
- **FR-003**: O sistema MUST expor edição completa de OS no desktop
  (`GET /os/{id}/editar`, `PATCH /os/{id}`), reaproveitando o endpoint já
  existente `PATCH /api/v1/orders/{order}` e os mesmos campos do
  formulário de criação.
- **FR-004**: O backend MUST expor `GET /api/v1/orders/{order}/closure`
  retornando o status atual, as opções de encerramento (status com
  `status_final=1` ativos no catálogo), o resumo financeiro, o telefone do
  cliente, o resumo de custo de peças/serviços da OS (`custo_summary`), a
  data padrão de retorno (`retorno_padrao`) e o catálogo ativo de
  operadoras/bandeiras/taxas de cartão (`cartao`).
- **FR-005**: O backend MUST expor `POST /api/v1/orders/{order}/closure`
  aceitando uma lista de recebimentos (`recebimentos[]`, cada um com
  valor, classificação, forma de pagamento, data e, quando for cartão,
  operadora/bandeira/modalidade/parcelas) e que, em uma única transação:
  valida o status de encerramento contra o catálogo; registra cada
  recebimento reaproveitando `FinanceiroService::registerMovement`;
  atualiza status/estado_fluxo/histórico da OS reaproveitando
  `OrderWorkflowService::updateStatus`; grava `data_entrega`,
  `baixa_tecnica_em` e `baixa_tecnica_por`.
- **FR-006**: Quando um recebimento tiver forma de pagamento em cartão, o
  backend MUST simular a taxa da operadora (`FinanceiroCartaoService::simulate`)
  **antes** de gravar qualquer dado, rejeitando a baixa inteira sem
  efeitos colaterais se a combinação operadora/bandeira/parcelas não tiver
  taxa ativa; quando a simulação for válida, MUST registrar o detalhe em
  `financeiro_movimentos_cartao` e criar uma despesa correspondente
  (`financeiro` tipo `pagar`, categoria "Taxa de cartão").
- **FR-007**: Se, após registrar os recebimentos, sobrar saldo em aberto e
  o encerramento não for "sem reparo"/"descartado", o backend MUST aplicar
  o status intermediário `entregue_pagamento_pendente` (preservando o
  status final desejado em `status_final_pendente_pagamento`) e agendar 3
  cobranças por WhatsApp (D+1/D+3/D+5 às 10h) em
  `os_cobranca_agendamentos`, cancelando agendamentos pendentes anteriores
  da mesma OS antes de criar os novos.
- **FR-008**: O backend MUST expor um comando agendado
  (`app:process-pending-os-collections`, registrado no scheduler) que
  processa cobranças vencidas: cancela agendamentos cuja OS já não esteja
  mais com saldo em aberto ou pendente, marca como erro quando o cliente
  não tem telefone, e tenta o envio por WhatsApp nos demais casos,
  atualizando o status do agendamento conforme o resultado.
- **FR-009**: Quando o payload da baixa indicar `agendar_retorno`, o
  backend MUST criar um registro de follow-up (`crm_followups`) vinculado
  à OS e ao cliente, deduplicado por OS + data prevista (reenviar o mesmo
  pedido não cria um segundo registro).
- **FR-010**: Quando o payload da baixa indicar notificação ao cliente, o
  backend MUST tentar o envio por WhatsApp reaproveitando
  `WhatsappMessagingService::sendSystemMessage`; uma falha nesse envio
  MUST ser registrada em log e retornada como aviso, sem desfazer a baixa
  já confirmada.
- **FR-011**: O desktop MUST apresentar a baixa como um assistente de 3
  etapas (Encerramento, Financeiro, Confirmação) em uma única página (sem
  modal/iframe), com atalhos para adicionar um recebimento com o saldo
  total em aberto e para adicionar um recebimento classificado como
  adiantamento, e com pré-visualização client-side da taxa de cartão
  estimada por recebimento.
- **FR-012**: Esta entrega MUST NOT incluir uma tela de listagem/gestão de
  `crm_followups` — a baixa apenas cria o registro; a gestão desses
  follow-ups fica para quando o módulo de CRM for implementado.
- **FR-013**: A documentação técnica (contrato da API, nota de
  implementação, `openapi.yaml`) MUST ser atualizada junto da entrega.

### Key Entities

- **Baixa de OS**: ação que encerra uma OS, combinando mudança de status
  final (ou intermediário, quando sobra saldo), registro de entrega,
  um ou mais recebimentos (com simulação de taxa de cartão quando
  aplicável), notificação opcional ao cliente e agendamento opcional de
  retorno pós-serviço.
- **Opções de encerramento**: subconjunto do catálogo de status de OS
  (`os_status`) com `status_final=1` e `ativo=1`.
- **Recebimento**: cada lançamento informado na etapa Financeiro da
  baixa — valor, classificação (baixa/adiantamento/sinal), forma de
  pagamento e, quando cartão, os dados de simulação de taxa.
- **Cobrança agendada**: registro em `os_cobranca_agendamentos` que
  representa uma tentativa futura de cobrança por WhatsApp (D+1/D+3/D+5)
  de uma OS com saldo em aberto.
- **Follow-up de CRM**: registro em `crm_followups` que representa um
  retorno futuro de pós-venda vinculado a uma OS e a um cliente.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Um usuário autorizado consegue editar e dar baixa em uma OS
  inteiramente pelo desktop, sem acessar o legado.
- **SC-002**: Uma OS encerrada com saldo zerado não oferece mais a opção
  "Baixa" no menu de ações.
- **SC-003**: Uma falha de envio de WhatsApp durante a baixa não impede o
  encerramento da OS.
- **SC-004**: A baixa sem nenhum recebimento informado encerra a OS com
  saldo em aberto, sem erro.
- **SC-005**: Um recebimento em cartão sempre tem sua taxa calculada e
  registrada como despesa antes da baixa ser considerada concluída.
- **SC-006**: Uma baixa com saldo parcial sempre resulta em exatamente 3
  cobranças agendadas (D+1/D+3/D+5), nunca mais nem menos, e reabrir a
  mesma OS com o saldo total pago cancela os agendamentos pendentes.

## Assumptions

- Esta entrega não constrói uma tela de CRM para listar/gerenciar
  `crm_followups` (FR-012) — esse módulo não existe hoje no sistema-erp;
  a baixa cria o registro corretamente, a gestão fica para uma entrega
  futura do módulo de CRM.
- As tabelas de cartão/cobrança/follow-up (`financeiro_cartao_operadoras`,
  `financeiro_cartao_bandeiras`, `financeiro_cartao_taxas`,
  `financeiro_movimentos_cartao`, `os_cobranca_agendamentos`,
  `crm_followups`) e `os_itens` já existem no banco de produção
  compartilhado (origem legada); esta entrega formaliza essas tabelas como
  uma migration com `Schema::hasTable()` guard (no-op em produção) para
  que ambientes novos/CI/testes tenham o mesmo schema, e adiciona
  `os_itens` ao schema usado pelos testes (`BuildsLegacyErpSchema.php`)
  por ser uma tabela puramente legada sem migration própria.
- A frequência do comando `app:process-pending-os-collections` no
  scheduler (a cada 15 minutos) é uma escolha de implementação — o legado
  não documentava uma cadência fixa para o processamento de cobranças
  vencidas.
- Os 3 status finais usados como opções de encerramento já existem e
  estão ativos no catálogo `os_status` (`entregue_reparado`,
  `devolvido_sem_reparo`, `descartado`), assim como o status intermediário
  `entregue_pagamento_pendente`; esta entrega não cria nem modifica esse
  catálogo, apenas consulta os status com `status_final=1` e usa o
  intermediário já existente.

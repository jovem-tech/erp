# Feature Specification: Orcamentos - Revisao antes de salvar e envio para aprovacao

**Feature Branch**: `019-aprovacao-orcamento-desktop`

**Created**: 2026-07-03

**Status**: Draft

**Input**: User description: "ao clicar em salvar orçamento , deve ser exibido um modal com todas as informações do orçamento e se existe alguma pendencia... haverá um botão para enviar o pdf do orçamento para aprovação do cliente ou apenas gerar o orçamento sem enviar..."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Revisar o orçamento antes de salvar (Priority: P1)

Como operador comercial, quero revisar o orçamento em um modal antes da persistência final, para confirmar os dados, enxergar pendências e decidir se salvo apenas o registro ou se já disparo a proposta ao cliente.

**Why this priority**: evita disparo incorreto e torna o fechamento do orçamento auditável e mais seguro.

**Independent Test**: abrir `/orcamentos/novo`, preencher cliente, itens e valores, clicar em `Salvar orçamento` e verificar que o modal mostra resumo, itens e pendências antes de qualquer persistência final.

**Acceptance Scenarios**:

1. **Given** um orçamento preenchido, **When** clico em `Salvar orçamento`, **Then** o sistema MUST abrir um modal com dados do cliente, equipamento/OS, itens, resumo financeiro e pendências detectadas.
2. **Given** que há pendências para envio ao cliente, **When** o modal é exibido, **Then** o sistema MUST deixar explícito quais são as pendências e manter disponível a ação de salvar sem enviar.

---

### User Story 2 - Salvar e enviar PDF para aprovação (Priority: P1)

Como operador comercial, quero salvar o orçamento e enviar o PDF para aprovação do cliente no mesmo fluxo, para reduzir cliques e manter o processo parecido com o legado homologado.

**Why this priority**: o valor operacional da feature está em fechar e disparar a proposta sem salto de contexto.

**Independent Test**: preencher orçamento com telefone válido, clicar em `Salvar orçamento`, escolher `Salvar e enviar para aprovação` e confirmar que o orçamento é persistido, o PDF é gerado, o envio é rastreado e o status comercial avança para aguardando resposta.

**Acceptance Scenarios**:

1. **Given** um orçamento válido com telefone apto para WhatsApp, **When** escolho `Salvar e enviar para aprovação`, **Then** o sistema MUST salvar o orçamento, gerar o PDF, enviar a proposta ao cliente e registrar o envio em `orcamento_envios`.
2. **Given** que o envio para aprovação falha por indisponibilidade de canal ou telefone inválido, **When** o orçamento já foi salvo, **Then** o sistema MUST preservar o orçamento salvo e informar a falha sem perder os dados.

---

### User Story 3 - Cliente aprova ou rejeita a proposta recebida (Priority: P1)

Como cliente, quero abrir um link da proposta e aprovar ou rejeitar o orçamento, para responder sem depender de contato manual da equipe.

**Why this priority**: o envio para aprovação só é completo quando existe um destino operacional para a decisão do cliente.

**Independent Test**: abrir o link público gerado para o orçamento enviado, aprovar ou rejeitar a proposta e verificar atualização do status, histórico e rastreabilidade.

**Acceptance Scenarios**:

1. **Given** um orçamento enviado com token válido, **When** o cliente abre o link público, **Then** o sistema MUST mostrar um resumo comercial legível com itens, valores e ações de aprovar/rejeitar.
2. **Given** que o cliente aprova a proposta, **When** confirma a decisão, **Then** o sistema MUST registrar a aprovação em `orcamento_aprovacoes`, atualizar o status do orçamento e refletir a aprovação na OS vinculada quando existir.
3. **Given** que o cliente rejeita a proposta, **When** informa o motivo e confirma, **Then** o sistema MUST registrar a rejeição com rastreabilidade e atualizar o status do orçamento.

### Edge Cases

- o operador tenta enviar para aprovação sem telefone válido no orçamento;
- o orçamento possui itens, mas total final zerado;
- o token público expira antes da resposta do cliente;
- o cliente tenta reutilizar um link já respondido;
- o envio do PDF falha, mas o disparo de texto com link ainda poderia acontecer em fases futuras;
- o orçamento é editado depois de enviado e precisa abrir nova rodada comercial em etapa posterior.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: O desktop MUST interceptar a ação `Salvar orçamento` para abrir um modal de revisão antes do submit final.
- **FR-002**: O modal MUST mostrar resumo de cliente, equipamento/OS, itens do orçamento, resumo financeiro e uma lista clara de pendências detectadas.
- **FR-003**: O modal MUST oferecer as ações `Salvar sem enviar` e `Salvar e enviar para aprovação`.
- **FR-004**: O backend central MUST expor um endpoint autenticado para disparar o envio comercial do orçamento já salvo.
- **FR-005**: O backend central MUST gerar um token público seguro para o orçamento enviado e reutilizá-lo enquanto a rodada comercial permanecer válida.
- **FR-006**: O backend central MUST gerar um PDF do orçamento para o envio comercial.
- **FR-007**: O backend central MUST registrar cada tentativa de envio em `orcamento_envios`, incluindo canal, destino, status e erro quando houver.
- **FR-008**: O backend central MUST expor uma página pública de consulta da proposta por token, com ações de aprovar e rejeitar.
- **FR-009**: Ao aprovar ou rejeitar, o backend central MUST registrar rastreabilidade em `orcamento_aprovacoes` e `orcamento_status_historico`.
- **FR-010**: Quando houver OS vinculada, a decisão do cliente MUST refletir os campos operacionais mínimos da ordem (`orcamento_aprovado`, `data_aprovacao` e afins quando aplicável).
- **FR-011**: A entrega MUST atualizar testes, `backend/openapi.yaml`, documentação versionada e a trilha em `specs/`.

### Key Entities

- **Resumo pré-envio do orçamento**: projeção visual do formulário usada para revisão final antes do submit.
- **Envio comercial do orçamento**: disparo auditável do PDF com link de decisão do cliente.
- **Decisão pública do cliente**: aprovação ou rejeição associada a um token público do orçamento.
- **Rastreabilidade comercial**: conjunto de histórico, envio e aprovação que documenta o ciclo da proposta.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: O operador consegue revisar e salvar um orçamento sem sair da própria tela de criação/edição.
- **SC-002**: O operador consegue salvar e disparar a proposta ao cliente no mesmo fluxo, com rastreabilidade persistida.
- **SC-003**: O cliente consegue aprovar ou rejeitar a proposta por link público válido.
- **SC-004**: Falhas de envio não causam perda do orçamento salvo nem deixam o sistema em estado ambíguo.

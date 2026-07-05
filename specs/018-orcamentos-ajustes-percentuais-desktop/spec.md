# Feature Specification: Orcamentos - Ajustes Monetarios ou Percentuais

**Feature Branch**: `018-orcamentos-ajustes-percentuais-desktop`

**Created**: 2026-07-03

**Status**: Approved

**Input**: User description: "vamos permitir que o desconto e o acressimo seja em percentual ou monetario"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Ajustar item por valor ou percentual (Priority: P1)

Como usuario comercial, quero definir desconto e acrescimo de cada item em `R$` ou `%`, para montar o orcamento com a mesma logica de negociacao que uso no atendimento.

**Why this priority**: o item e a unidade basica do orcamento; sem essa flexibilidade o resumo financeiro continua limitado.

**Independent Test**: abrir `/orcamentos/novo`, alternar o modo de desconto/acrescimo de um item entre `R$` e `%`, editar os valores e confirmar que o total da linha e atualizado automaticamente.

**Acceptance Scenarios**:

1. **Given** um item com quantidade e valor unitario preenchidos, **When** escolho `percentual` e digito `10`, **Then** o total da linha aplica 10% sobre a base do item.
2. **Given** um item salvo com ajuste percentual, **When** abro a edicao do orcamento, **Then** o formulario restaura o modo `%` e o percentual informado.
3. **Given** um item legado salvo apenas com valor absoluto, **When** abro a edicao, **Then** o formulario assume modo `R$` sem perda de compatibilidade.

---

### User Story 2 - Ajustar resumo financeiro por valor ou percentual (Priority: P1)

Como usuario comercial, quero definir desconto geral e acrescimo geral em `R$` ou `%`, para aplicar uma negociacao global sobre o subtotal calculado dos itens.

**Why this priority**: o resumo financeiro consolida a negociacao final entregue ao cliente.

**Independent Test**: preencher itens, alternar o modo do desconto geral e do acrescimo geral entre `R$` e `%`, e confirmar que o total final responde automaticamente.

**Acceptance Scenarios**:

1. **Given** um subtotal calculado pelos itens, **When** defino `desconto geral` como `10%`, **Then** o total final aplica o percentual sobre o subtotal atual.
2. **Given** que altero quantidade, valor unitario, desconto ou acrescimo de um item, **When** o subtotal muda, **Then** os ajustes percentuais globais sao recalculados sem recarregar a pagina.

### Edge Cases

- o usuario alterna de `%` para `R$` apos ja ter digitado um valor;
- o percentual vem com virgula decimal (`12,5`);
- o desconto percentual e zero;
- o orcamento e salvo por clientes antigos que nao enviam os novos campos de modo/percentual;
- a edicao de orcamentos antigos sem metadados novos continua funcionando.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: O backend central MUST aceitar `desconto_tipo`, `desconto_percentual`, `acrescimo_tipo` e `acrescimo_percentual` no orcamento e em cada item.
- **FR-002**: O backend central MUST continuar aceitando payloads antigos que enviam apenas `desconto` e `acrescimo` monetarios.
- **FR-003**: O backend central MUST persistir o modo e o percentual informado, alem do valor absoluto efetivamente aplicado.
- **FR-004**: O backend central MUST recalcular os valores absolutos a partir do percentual quando o modo informado for `percentual`.
- **FR-005**: O desktop MUST permitir alternar visualmente entre `R$` e `%` para desconto e acrescimo dos itens e do resumo financeiro.
- **FR-006**: O desktop MUST recalcular linha, subtotal e total final automaticamente ao mudar valores ou modos.
- **FR-007**: A edicao de orcamentos MUST restaurar corretamente o modo e o valor percentual salvos.
- **FR-008**: `backend/openapi.yaml`, testes e documentacao MUST ser atualizados junto com a entrega.

### Key Entities

- **Ajuste do item**: combinacao de modo (`valor` ou `percentual`), valor percentual opcional e valor monetario absoluto persistido para desconto/acrescimo da linha.
- **Ajuste global do orcamento**: combinacao de modo (`valor` ou `percentual`), valor percentual opcional e valor monetario absoluto persistido para resumo financeiro.
- **Resumo financeiro**: subtotal vindo da soma dos itens, combinado com desconto/acrescimo globais para formar o total final.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: O usuario consegue salvar orcamentos novos e editados usando ajuste monetario ou percentual em itens e no resumo.
- **SC-002**: O backend preserva o modo escolhido e o percentual informado para reabrir o orcamento em edicao sem ambiguidade.
- **SC-003**: Payloads legados continuam validos sem exigir mudanca imediata em outros clientes da API.
- **SC-004**: O total mostrado no desktop permanece consistente com o recalculo do backend central.

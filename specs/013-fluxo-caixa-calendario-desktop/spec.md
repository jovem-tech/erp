# Feature Specification: Fluxo de caixa no desktop com lista e calendário

**Feature Branch**: `013-fluxo-caixa-calendario-desktop`

**Created**: 2026-06-28

**Status**: Approved

**Input**: User description: "quero modificar o modo de visualização do fluxo de caixa. quero adicionar alem dos lançamentos em lista , quero visualizar os lançamentos em um calendario"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Alternar entre lista e calendário do fluxo de caixa (Priority: P1)

Como usuário financeiro autorizado, quero alternar entre a lista diária e um calendário mensal no relatório de fluxo de caixa, para comparar os lançamentos por dia sem perder a leitura operacional do relatório.

**Why this priority**: a visualização em calendário é o valor principal pedido na mudança e precisa coexistir com a lista já existente.

**Independent Test**: abrir `/financeiro/relatorios/fluxo-caixa`, alternar entre `Lista` e `Calendário` e confirmar que as duas visualizações usam o mesmo período selecionado.

**Acceptance Scenarios**:

1. **Given** que o usuário tem permissão de financeiro, **When** abre o relatório em `/financeiro/relatorios/fluxo-caixa`, **Then** vê a visualização em lista como comportamento padrão.
2. **Given** que o usuário seleciona a visualização de calendário, **When** a página é recarregada com o mesmo mês, **Then** o período e a visualização permanecem consistentes.
3. **Given** que o usuário troca de mês em qualquer uma das visualizações, **When** confirma a atualização, **Then** o relatório preserva a visualização escolhida.

---

### User Story 2 - Ler os lançamentos por dia no calendário (Priority: P1)

Como usuário financeiro, quero ver os lançamentos do mês distribuídos em um calendário, para identificar rapidamente dias com entradas, saídas e saldo acumulado.

**Why this priority**: sem a leitura diária no calendário, a nova visualização não entrega valor além de uma simples troca de layout.

**Independent Test**: abrir o relatório no modo calendário e confirmar que cada dia útil do mês mostra os totais diários provenientes do mesmo payload usado pela lista.

**Acceptance Scenarios**:

1. **Given** um mês com movimentos, **When** o calendário é exibido, **Then** cada dia do mês exibe os valores diários de entradas, saídas e saldo.
2. **Given** dias fora do mês corrente no grid, **When** o calendário é renderizado, **Then** eles aparecem visualmente atenuados para não competir com os dias válidos.

### Edge Cases

- O usuário alterna a visualização sem informar mês.
- O relatório não tem movimentos no período.
- O mês selecionado começa em uma segunda-feira ou termina em um domingo, alterando a quantidade de semanas do grid.
- O navegador é pequeno e precisa permitir rolagem horizontal do calendário sem quebrar o layout.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: O desktop MUST expor o relatório `/financeiro/relatorios/fluxo-caixa` com duas visualizações: lista e calendário.
- **FR-002**: A visualização padrão MUST continuar sendo a lista diária já existente.
- **FR-003**: A visualização em calendário MUST reutilizar o mesmo payload de relatório, sem acesso direto ao banco de dados pelo frontend.
- **FR-004**: O calendário MUST mostrar os dias do mês selecionado em grade mensal, com distinção visual para dias fora do período e para dias com movimento.
- **FR-005**: O calendário MUST exibir, por dia, entradas, saídas e saldo realizado acumulado.
- **FR-006**: A troca de visualização e a troca de mês MUST preservar o contexto atual via query string.
- **FR-007**: A implementação MUST manter o padrão visual do desktop, sem romper o shell, a navegação ou a responsividade básica.
- **FR-008**: A documentação do desktop e o histórico de versão MUST ser atualizados junto da entrega.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Um usuário autorizado consegue alternar entre lista e calendário no mesmo relatório sem perder o mês selecionado.
- **SC-002**: O calendário exibe os dias do mês e os totais diários sem depender de novo contrato de API.
- **SC-003**: O comportamento continua funcionando em telas menores com overflow controlado.
- **SC-004**: A documentação do desktop e o histórico de versões registram a nova visualização.

## Assumptions

- O backend central já fornece os totais diários necessários para a lista.
- Não haverá alteração de schema nem de contrato da API central para esta entrega.
- A nova visualização será entregue apenas no desktop Laravel/Blade.

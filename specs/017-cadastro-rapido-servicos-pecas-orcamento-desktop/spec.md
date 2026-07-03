# Feature Specification: Cadastro rápido de serviços e peças no orçamento desktop

**Feature Branch**: `017-cadastro-rapido-servicos-pecas-orcamento-desktop`

**Status**: Draft

**Input**: User request: "caso a peça ou serviço não estiver cadastro, adicione um botão que abre um modal para cadastrar a peça ou serviço sem precisar sair do fluxo de cadastro da os"

## User Scenarios & Testing

### User Story 1 - Cadastrar serviço ou peça sem sair do orçamento

Como atendente, quero abrir um modal de cadastro rápido a partir da linha do item do orçamento, para criar o serviço ou a peça sem abandonar o fluxo da OS.

**Independent Test**: abrir `/orcamentos/novo`, clicar no botão `Cadastrar` de uma linha e confirmar que o modal abre com o tipo correto e salva o item via JSON.

**Acceptance Scenarios**:

1. **Given** uma linha de item do orçamento, **When** o usuário clica em `Cadastrar`, **Then** o modal rápido abre com o tipo de cadastro relevante.
2. **Given** que o cadastro rápido foi salvo com sucesso, **When** a resposta chega do backend, **Then** o novo item é aplicado à linha atual e o select de referência é atualizado.
3. **Given** validação inválida ou erro do backend, **When** o usuário tenta salvar, **Then** a mensagem é exibida no próprio modal e o fluxo do orçamento continua intacto.

### User Story 2 - Respeitar permissões e fonte de verdade

Como responsável técnico, quero que o cadastro rápido continue respeitando RBAC e o backend central como fonte de verdade, para não criar atalho inseguro no desktop.

**Independent Test**: acessar o orçamento sem permissão de criação de serviços ou estoque e confirmar que o botão não aparece; quando a rota for chamada diretamente, o backend responde 403.

**Acceptance Scenarios**:

1. **Given** ausência de permissão para criar itens de catálogo, **When** a tela renderiza, **Then** o botão rápido não é exibido.
2. **Given** uma chamada direta ao endpoint rápido sem permissão, **When** o backend responde, **Then** a operação é bloqueada com 403.
3. **Given** a criação ocorreu com sucesso, **When** o item é persistido, **Then** ele passa a existir no catálogo consumido pelo orçamento.

## Requirements

- **FR-001**: A linha de item do orçamento MUST exibir um botão `Cadastrar` quando houver permissão para criar serviços ou peças.
- **FR-002**: O clique no botão MUST abrir um modal com o tipo de cadastro apropriado e com campos mínimos para serviço ou peça.
- **FR-003**: O submit do modal MUST chamar um endpoint JSON do desktop e aplicar o item criado à linha atual sem recarregar a página.
- **FR-004**: O backend central MUST continuar sendo a fonte de verdade para o cadastro de serviços e peças.
- **FR-005**: A implementação MUST preservar validação, tratamento de erro e compatibilidade com Select2 no orçamento.

## Edge Cases

- O item pode ser cadastrado com a linha sem referência ainda selecionada.
- O usuário pode trocar o tipo no modal, desde que tenha permissão para o tipo escolhido.
- A linha pode ter sido removida enquanto o modal estava aberto; nesse caso o catálogo é atualizado, mas a aplicação visual deve falhar de forma segura.
- Erros de validação devem permanecer no modal, sem perder o rascunho do orçamento.

## Success Criteria

- O atendente não precisa sair do orçamento para registrar o serviço ou a peça faltante.
- O novo item fica selecionado na linha atual imediatamente após o cadastro.
- O fluxo continua seguro, auditável e compatível com o backend central.

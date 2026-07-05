# Feature Specification: Lançamentos avulsos com histórico financeiro do cliente

**Feature Branch**: `develop`

**Created**: 2026-07-05

**Status**: Implemented

**Input**: permitir pagamentos e recebimentos simples sem ordem de serviço, opcionalmente vinculados a cliente, sem misturar esse fluxo com o financeiro da OS.

## User Scenarios & Testing

### User Story 1 - Registrar lançamento avulso sem OS (Priority: P1)

Como usuário autorizado do financeiro, quero registrar um pagamento ou recebimento simples sem OS para representar pequenas operações que não justificam uma ordem de serviço.

**Acceptance Scenarios**:

1. **Given** um lançamento marcado como avulso, **When** ele não possui OS nem cliente, **Then** o backend salva o título e ele continua impactando DRE e fluxo de caixa conforme sua categoria.
2. **Given** um lançamento não avulso a receber sem OS e sem cliente, **When** o usuário tenta salvar, **Then** o backend rejeita a operação.
3. **Given** um lançamento avulso, **When** também é informado `os_id`, **Then** o backend rejeita a combinação.

### User Story 2 - Vincular recebimento avulso ao cliente (Priority: P1)

Como usuário autorizado, quero associar um recebimento avulso ao cliente para consultar depois todos os títulos recebíveis relacionados a ele.

**Acceptance Scenarios**:

1. **Given** um avulso sem OS com `cliente_id`, **When** ele é salvo, **Then** o título fica disponível no histórico financeiro do cliente.
2. **Given** um usuário com `financeiro:visualizar`, **When** abre o cliente, **Then** vê uma seção paginada com os recebíveis associados e um atalho para a listagem filtrada.
3. **Given** um usuário sem `financeiro:visualizar`, **When** abre o cliente, **Then** a seção financeira não é renderizada e nenhuma consulta financeira é feita pelo desktop.

### User Story 3 - Preservar o financeiro da OS (Priority: P1)

Como operador de OS, quero que títulos originados pela OS continuem sendo não avulsos para preservar a rastreabilidade do fechamento e das taxas.

**Acceptance Scenarios**:

1. **Given** o encerramento financeiro de uma OS, **When** o título ou a taxa de cartão é criado, **Then** `avulso=false` é persistido.
2. **Given** um título com movimentos, **When** alguém tenta alterar `avulso`, **Then** o backend rejeita a mudança.
3. **Given** uma OS e um cliente divergente, **When** o título é salvo, **Then** o backend rejeita a associação inconsistente.

## Requirements

- **FR-001**: O banco MUST persistir `financeiro.avulso` como booleano, com default `false`.
- **FR-002**: A API MUST aceitar `avulso` no create e update.
- **FR-003**: `avulso=true` MUST ser incompatível com `os_id`.
- **FR-004**: A alteração de `avulso` MUST ser bloqueada após qualquer movimento financeiro.
- **FR-005**: A listagem financeira MUST aceitar `cliente_id` e continuar paginada.
- **FR-006**: O histórico no cliente MUST consultar apenas a API central e respeitar `financeiro:visualizar`.
- **FR-007**: O desktop MUST limpar e desabilitar OS enquanto o switch de avulso estiver ativo.
- **FR-008**: DRE e fluxo de caixa MUST continuar considerando avulsos segundo os flags financeiros existentes.
- **FR-009**: O contrato OpenAPI e a documentação da entrega MUST refletir o novo campo e filtro.

## Security and Performance

- O backend é a autoridade final; a restrição avulso/OS não depende do JavaScript.
- O cliente de uma OS é validado contra a própria OS para evitar vínculo incorreto e exposição cruzada de histórico.
- O histórico é protegido por RBAC no desktop e novamente na API.
- A consulta usa paginação, eager loading já existente e índice composto por cliente, tipo e vencimento.

## Success Criteria

- Os cenários de avulso puro, avulso com cliente, bloqueio com OS e imutabilidade após movimentos possuem testes automatizados.
- O histórico do cliente aparece somente com permissão financeira.
- A regressão do fechamento da OS permanece verde.

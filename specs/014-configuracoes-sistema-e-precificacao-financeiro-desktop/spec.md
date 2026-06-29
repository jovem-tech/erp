# Feature Specification: Configuracoes do Sistema e Precificacao no desktop

**Feature Branch**: `014-configuracoes-sistema-e-precificacao-financeiro-desktop`

**Created**: 2026-06-29

**Status**: Draft

**Input**: User description: "na pagina de integracoes, deixe apenas as integracoes. crie um novo menu no side bar para configuracoes do sistema, onde abrigara os menus aparencia, dados da empresa, secao e seguranca. precificacao deve ser implementado nas finanças (faça o mesmo modulo de /sistema-hml)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manter a pagina de integracoes focada apenas em integracoes (Priority: P1)

Como usuario administrativo, quero que a pagina de Integracoes mostre somente os canais e testes de integracao, para reduzir ruido visual e evitar confusao com configuracoes que pertencem a outro grupo.

**Why this priority**: a separacao limpa da pagina atual e a mudanca mais imediata para a navegacao ficar mais previsivel.

**Independent Test**: abrir `/configuracoes/integracoes` e confirmar que a tela nao exibe abas ou blocos de Aparencia, Dados da Empresa, Sessao e Seguranca, ou Precificacao.

**Acceptance Scenarios**:

1. **Given** que o usuario tem permissao de configuracoes, **When** abre a pagina de integracoes, **Then** ve apenas os blocos de integracoes.
2. **Given** que o usuario revisita a pagina, **When** carrega a rota de integracoes, **Then** a ordenacao e os atalhos continuam consistentes.

---

### User Story 2 - Acessar configuracoes do sistema em um novo menu lateral (Priority: P1)

Como usuario administrativo, quero um menu separado para Configuracoes do Sistema, para encontrar Aparencia, Dados da Empresa, Sessao e Seguranca sem misturar com Integracoes.

**Why this priority**: a separacao da navegacao reduz friccao e deixa o painel de configuracoes mais compreensivel.

**Independent Test**: abrir o sidebar e verificar que existe um item proprio para Configuracoes do Sistema, com a pagina dedicada exibindo as areas de configuracao do sistema.

**Acceptance Scenarios**:

1. **Given** que o usuario tem permissao de configuracoes, **When** abre o sidebar, **Then** ve um item dedicado a Configuracoes do Sistema.
2. **Given** que o usuario entra na pagina do sistema, **When** navega entre os blocos, **Then** encontra Aparencia, Dados da Empresa, Sessao e Seguranca agrupados na mesma area.

---

### User Story 3 - Operar a precificacao dentro do Financeiro (Priority: P1)

Como usuario financeiro autorizado, quero acessar e operar a precificacao dentro do menu Financeiro, seguindo a logica do modulo legado, para manter a regra de formacao de preco centralizada.

**Why this priority**: precificacao impacta receita e margem; ela precisa ficar na area financeira e nao perdida em configuracoes gerais.

**Independent Test**: abrir a nova rota de precificacao no Financeiro e confirmar que a tela carrega o catalogo, as regras e o simulador sem depender de acesso direto ao banco pelo frontend.

**Acceptance Scenarios**:

1. **Given** que o usuario tem permissao de financeiro, **When** abre a precificacao, **Then** ve os parametros e o simulador do modulo.
2. **Given** que o usuario altera regras e salva, **When** recarrega a pagina, **Then** os valores persistidos continuam disponiveis via backend central.
3. **Given** que o usuario acessa o modulo em um ambiente Linux/VPS, **When** executa o fluxo, **Then** o comportamento permanece consistente com o ambiente local.

### Edge Cases

- O usuario abre Integracoes esperando ver configuracoes gerais, e a pagina precisa deixar claro que so trata de integracoes.
- O usuario sem permissao para configuracoes do sistema ou precificacao deve nao ver os itens no sidebar.
- A base pode ainda nao ter as tabelas de precificacao do legado; o modulo precisa sinalizar a ausencia sem quebrar a pagina.
- O usuario pode acessar a precificacao sem preencher valores; o formulario precisa usar defaults seguros.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A rota `/configuracoes/integracoes` MUST exibir somente o painel de integracoes.
- **FR-002**: O desktop MUST expor uma pagina separada para Configuracoes do Sistema, com as areas Aparencia, Dados da Empresa, Sessao e Seguranca.
- **FR-003**: O sidebar MUST conter um menu proprio para Configuracoes do Sistema e outro para Precificacao dentro de Financeiro, respeitando as permissoes existentes.
- **FR-004**: O modulo de Precificacao MUST viver dentro de Financeiro e MUST seguir a estrutura funcional do legado `/sistema-hml`, incluindo configuracao principal e simulador.
- **FR-005**: O frontend MUST consumir a precificacao via backend central, sem acesso direto ao banco.
- **FR-006**: O backend MUST expor os contratos necessarios para carregar e persistir configuracoes de precificacao, categorias e simulacao.
- **FR-007**: O modulo MUST manter o comportamento seguro em ambientes Windows/XAMPP e Ubuntu VPS, sem caminhos ou suposicoes especificas de plataforma.
- **FR-008**: A documentacao de arquitetura, a nota de implementacao e o contexto vivo MUST ser atualizados ao final.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A pagina de Integracoes nao exibe mais abas de configuracoes gerais nem blocos de precificacao.
- **SC-002**: O sidebar exibe Configuracoes do Sistema em um menu proprio e Precificacao dentro do Financeiro.
- **SC-003**: O usuario consegue abrir a precificacao e ver configuracoes carregadas do backend central.
- **SC-004**: A entrega deixa rastros consistentes em specs, documentacao e versionamento.

## Assumptions

- A entrega inicial pode reutilizar a base de configuracoes ja existente e ampliar as tabelas de precificacao do legado conforme necessario.
- O modulo de precificacao sera entregue primeiro no desktop Laravel/Blade, mantendo o backend central como fonte unica de verdade.
- As areas de Aparencia, Dados da Empresa, Sessao e Seguranca podem começar como estrutura dedicada no desktop e evoluir em seguida sem voltar para a pagina de Integracoes.

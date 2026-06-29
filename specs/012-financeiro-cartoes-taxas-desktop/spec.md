# Feature Specification: Financeiro - Cartões e Taxas no Desktop

**Feature Branch**: `012-financeiro-cartoes-taxas-desktop`

**Created**: 2026-06-28

**Status**: Approved

**Input**: User description: "quero que sistema-erp/frontends/desktop tenha este modulo de cartão e taxas com a mesma feature de /sistema-hml/financeiro/cartoes seja integralmente passado com implantações de melhorias e correções de possiveis erros"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Gerir catálogos de cartões e taxas (Priority: P1)

Como usuário financeiro autorizado, quero acessar a tela de cartões e taxas com abas separadas para operadoras, bandeiras, taxas por parcela e taxas online, para manter o catálogo operacional do ERP sem recorrer ao legado.

**Why this priority**: esse é o núcleo funcional da tela; sem os catálogos, o simulador e os demais fluxos ficam incompletos.

**Independent Test**: acessar `/financeiro/cartoes`, alternar entre as abas e confirmar que cada seção carrega os catálogos e permite cadastro/edição/desativação conforme permissão.

**Acceptance Scenarios**:

1. **Given** que o usuário tem permissão de financeiro, **When** abre `/financeiro/cartoes`, **Then** vê os cards-resumo e as abas do módulo com a navegação fiel ao legado.
2. **Given** que o usuário tem permissão de edição, **When** preenche um formulário de operadora, bandeira, taxa por parcela ou taxa online, **Then** o backend central recebe o payload e a tabela atualiza sem acesso direto ao banco.
3. **Given** que o registro foi editado a partir da tabela, **When** aciono "Editar", **Then** o formulário é preenchido com os dados da linha e permanece pronto para salvar no mesmo contexto.

---

### User Story 2 - Simular recebimento líquido no cartão (Priority: P1)

Como atendente ou financeiro, quero simular um recebimento em cartão com valor bruto, operadora, bandeira, modalidade e parcelas, para enxergar taxa e valor líquido antes de concluir uma cobrança.

**Why this priority**: a simulação é a principal leitura operacional da tela e reduz erro financeiro em campo.

**Independent Test**: preencher o formulário do simulador, enviar e confirmar que os cards de resultado mostram taxa total, valor líquido, percentual aplicado e previsão de recebimento.

**Acceptance Scenarios**:

1. **Given** uma combinação válida de operadora/bandeira/modalidade/parcelas, **When** envio o simulador, **Then** o desktop retorna a resposta do backend e exibe os números calculados.
2. **Given** uma combinação sem taxa ativa, **When** envio a simulação, **Then** recebo mensagem de erro amigável sem quebrar a página.

---

### User Story 3 - Operar taxas online por gateway (Priority: P2)

Como gestor, quero configurar taxas online por gateway e modalidade, para embutir o custo de pagamento digital no fluxo financeiro do ERP.

**Why this priority**: esse bloco completa a gestão financeira da tela, mas depende da navegação principal e da simulação.

**Independent Test**: alternar para a aba de taxas online, editar um gateway, filtrar por provedor e confirmar que os cards e tabelas refletem a configuração salva.

**Acceptance Scenarios**:

1. **Given** um gateway configurado, **When** salvo uma taxa online com provider e modalidade válidos, **Then** o registro é persistido via API central.
2. **Given** que a modalidade não existe no catálogo do provider, **When** tento salvar, **Then** o backend rejeita a combinação sem efeitos colaterais.

### Edge Cases

- O usuário tenta abrir a página sem permissão de financeiro.
- O backend retorna `401`, `403`, `422` ou `500` durante o carregamento dos catálogos.
- O Select2 precisa ser reconfigurado após trocar de aba ou limpar formulário.
- O simulador recebe valor bruto inválido ou zero.
- A lista de taxas online volta vazia.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: O desktop MUST expor `/financeiro/cartoes` e `/financeiro/cartoes/ajuda` com shell, navbar e sidebar do canal desktop.
- **FR-002**: A página MUST reproduzir a organização funcional do legado com abas para operadoras, bandeiras, taxas por parcela, simulador e taxas online.
- **FR-003**: Todo select visível do módulo MUST usar Select2 com tema Bootstrap 5, helper compartilhado e comportamento consistente em tabs e modais.
- **FR-004**: O frontend MUST consumir o endpoint agregado de cartões e taxas do backend central para montar os catálogos e os cards-resumo.
- **FR-005**: O simulador MUST postar para o backend central e exibir taxa total, valor líquido, percentual aplicado e previsão de recebimento.
- **FR-006**: Os formulários MUST permitir cadastro, edição e desativação dos catálogos sem tocar no banco local.
- **FR-007**: O módulo MUST falhar com segurança em `401`, `403` e `422`, preservando a sessão e retornando feedback claro ao usuário.
- **FR-008**: O help local MUST explicar o papel de cada aba e o fluxo operacional sem depender do legado.
- **FR-009**: Nenhum fluxo deste módulo MUST criar acesso direto ao banco de dados.
- **FR-010**: A documentação e a versão do sistema MUST ser atualizadas junto da entrega.

### Key Entities

- **Operadora de maquininha**: catálogo de adquirentes/operadoras com prazo padrão e estado ativo.
- **Bandeira**: catálogo de bandeiras usado na composição da taxa por parcela.
- **Taxa por parcela**: regra que cruza operadora, bandeira, modalidade e faixa de parcelas.
- **Taxa online**: regra de gateway para Pix, boleto, crédito e débito.
- **Simulador**: cálculo operacional de valor bruto, taxa e valor líquido antes da cobrança.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Um usuário autorizado consegue abrir, navegar e operar o módulo de cartões e taxas no desktop sem acessar o legado.
- **SC-002**: O simulador retorna os dados calculados para combinações válidas em uma chamada única.
- **SC-003**: Todo `select` visível da página renderiza com Select2.
- **SC-004**: A documentação do desktop e o contrato da API central ficam sincronizados com o novo módulo.

## Assumptions

- O backend central continua sendo a fonte única de dados e regras.
- Não haverá mudança de schema ou acesso direto ao banco neste canal.
- O módulo precisa manter o padrão Select2-first do desktop.
- A implementação já existente é a referência visual e funcional imediata do legado.

# Feature Specification: Modelo Ideal da Assistência Técnica no Desktop

**Feature Branch**: `016-modelo-assistencia-tecnica-desktop`

**Created**: 2026-06-29

**Status**: Approved

**Input**: User description: "FAÇA COM BASE EM MODELOS DE NEGOCIOS PARA ASSISTENCIA TECNICA QUE FUNCIONA , UM FLUXO QUE EVITA GARGALOS DE PROCRASTINAÇÃO E QUEBRA DA FILA"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Visualizar um modelo operacional de assistência técnica que reduza gargalos (Priority: P1)

Como gestor da assistência, quero abrir um modelo visual de fluxo ideal, para enxergar a sequência certa de atendimento, diagnóstico, orçamento, execução e entrega sem deixar a OS parada em fila.

**Why this priority**: se o fluxo principal não estiver claro, a operação tende a criar gargalos, retrabalho e procrastinação invisível.

**Independent Test**: abrir a página do modelo e confirmar que o diagrama apresenta triagem, garantia, diagnóstico técnico, orçamento, execução, qualidade, entrega e pós-venda.

**Acceptance Scenarios**:

1. **Given** que o usuário tem permissão de conhecimento, **When** acessa o modelo, **Then** vê um fluxo visual com leitura imediata.
2. **Given** que a OS precisa de garantia, **When** o fluxo é analisado, **Then** o caminho prioritário fica destacado e não entra na fila comercial.
3. **Given** que a OS não tem reparo, **When** o fluxo é interpretado, **Then** o encerramento controlado fica explícito.

---

### User Story 2 - Evitar quebra de fila com regras operacionais claras (Priority: P1)

Como coordenador, quero ver regras práticas de fila, SLA, WIP e escalonamento, para impedir que a equipe deixe ordens sem dono, sem prazo ou sem próxima ação.

**Why this priority**: a fila quebra quando cada caso parece importante, mas nenhum tem dono, prazo e prioridade objetiva.

**Independent Test**: validar que o modelo explicita fila única, prioridade por aging, limite de WIP e escalonamento automático de pendências.

**Acceptance Scenarios**:

1. **Given** uma OS aguardando retorno, **When** o modelo é seguido, **Then** existe prazo de revisão e responsável visível.
2. **Given** um técnico com muitas OS abertas, **When** a operação segue o modelo, **Then** o WIP limita a sobrecarga.
3. **Given** um orçamento pendente de resposta, **When** o SLA expira, **Then** a pendência sobe de nível e não fica silenciosa.

---

### User Story 3 - Representar ramos especiais sem esconder a exceção (Priority: P2)

Como líder de operação, quero visualizar garantia, aguardando peça, pagamento pendente e cancelamento como saídas controladas, para não misturar exceções com o fluxo principal.

**Why this priority**: a operação fica mais saudável quando a exceção é tratada como ramo visível e não como atraso informal.

**Independent Test**: confirmar que o modelo mostra ramos especiais com explicação clara de entrada, saída e impacto na fila.

**Acceptance Scenarios**:

1. **Given** cobertura de garantia, **When** a OS entra no fluxo, **Then** o caminho prioritário é separado do orçamento comum.
2. **Given** falta de peça, **When** o caso segue para espera, **Then** a pendência fica marcada como bloqueio visível.
3. **Given** pagamento em aberto após entrega, **When** o fluxo termina, **Then** a cobrança vira ramo terminal controlado e não trava a produção.

---

### User Story 4 - Manter a experiência simples e consistente no desktop (Priority: P2)

Como usuário do desktop, quero um layout limpo, visual e consistente com o restante do ERP, para entender o modelo sem aprender uma interface nova.

**Why this priority**: o valor do modelo cai se o visual for confuso ou fugir do padrão do sistema.

**Independent Test**: abrir a página e confirmar a presença do shell padrão, do bloco visual principal e das seções de apoio com leitura rápida.

**Acceptance Scenarios**:

1. **Given** a página carregada, **When** o usuário lê o conteúdo, **Then** o fluxo visual domina a tela.
2. **Given** viewport reduzida, **When** a página é vista no desktop responsivo, **Then** o conteúdo continua legível.
3. **Given** navegação pelo menu, **When** o item é exibido, **Then** a página fica acessível pela gestão de conhecimento.

## Edge Cases

- Garantia não deve entrar em fila comercial comum.
- Pagamento pendente não deve bloquear a execução interna concluída.
- Aguardando peça precisa de data de revisão e responsável.
- Cancelamento precisa preservar o motivo para análise de perda.
- Orçamento sem resposta não pode ficar sem escalonamento.
- WIP excessivo deve ser visível como risco operacional.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: O desktop MUST renderizar um modelo visual de assistência técnica com diagrama central em linguagem de negócios.
- **FR-002**: O fluxo MUST cobrir recepção, triagem, garantia, diagnóstico técnico, orçamento, execução, qualidade, entrega e pós-venda.
- **FR-003**: O modelo MUST destacar regras de fila, SLA, prioridade, WIP e escalonamento.
- **FR-004**: O modelo MUST expor ramos especiais para garantia, peça pendente, cancelamento e pagamento pendente.
- **FR-005**: A tela MUST ser acessível pela gestão de conhecimento e seguir o shell visual do desktop.
- **FR-006**: A documentação e o versionamento MUST ser atualizados ao final da entrega.

### Key Entities

- **Fila única**: fila operacional com prioridade por urgência e envelhecimento.
- **WIP**: limite de ordens simultâneas por técnico para evitar multitarefa excessiva.
- **Ramo especial**: saída controlada do fluxo principal para garantia, cancelamento, peça ou pagamento.

### Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: O usuário identifica o caminho principal da assistência em menos de 30 segundos.
- **SC-002**: A operação passa a enxergar regras objetivas para não deixar OS sem dono, prazo ou próxima ação.
- **SC-003**: Os ramos especiais ficam visíveis sem misturar exceção com produção normal.
- **SC-004**: A entrega permanece consistente com o padrão visual e de segurança do desktop.

## Assumptions

- A página é informativa e não altera contratos da API central.
- O valor principal está na clareza operacional e na padronização da fila.
- O modelo pode evoluir depois para virar playbook de operação ou referência de treinamento.

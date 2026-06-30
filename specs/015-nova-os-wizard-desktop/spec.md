# Feature Specification: Nova OS em modo wizard no desktop

**Feature Branch**: `015-nova-os-wizard-desktop`

**Created**: 2026-06-29

**Status**: Draft

**Input**: User description: "quero que quando uma ordem de serviço em `sistema-erp/frontends/desktop` for gerada o processo de registro siga o mesmo padrão de `/sistema-hml/os/nova`: 1 coluna com foto e resumo da OS, das informações forem indo sendo preenchidas e outra coluna com o formulario dividido em abas: cliente, equipamento, defeito, dados operacionais e fotos. faça a implementação semelhante a `/sistema-hml/os/nova`, sugira melhorias e aponte possiveis inconsistencias antes da implementação."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Registrar uma OS em fluxo guiado e visualmente estável (Priority: P1)

Como atendente, quero abrir `/os/criar` e ver a criação da OS em duas colunas, com uma área de resumo à esquerda e o formulário dividido em abas à direita, para ter uma sensação de estabilidade parecida com o legado sem perder a arquitetura nova.

**Why this priority**: a primeira experiência de criação de OS é um ponto crítico de percepção de velocidade e organização do sistema.

**Independent Test**: abrir `/os/criar` com permissões de OS e confirmar que a tela exibe um resumo lateral e abas para `Cliente`, `Equipamento`, `Defeito`, `Dados Operacionais` e `Fotos`.

**Acceptance Scenarios**:

1. **Given** que o usuário tem permissão para criar OS, **When** abre `/os/criar`, **Then** vê o wizard em duas colunas.
2. **Given** que o usuário navega entre as abas, **When** troca de etapa, **Then** o conteúdo muda sem quebrar o restante do formulário.
3. **Given** que a tela está carregada, **When** o usuário preenche os campos, **Then** o resumo lateral reflete os dados em tempo quase real.

---

### User Story 2 - Ver cliente, equipamento e foto de contexto no resumo lateral (Priority: P1)

Como atendente, quero que o resumo lateral mostre cliente, equipamento, foto principal do equipamento e os estados da OS em construção, para reconhecer rapidamente o atendimento sem depender de abrir outra tela.

**Why this priority**: o resumo lateral é o principal ganho de usabilidade frente ao formulário linear atual.

**Independent Test**: selecionar cliente e equipamento e confirmar que o painel lateral exibe nome, contato principal, resumo técnico e foto principal do equipamento quando houver.

**Acceptance Scenarios**:

1. **Given** um equipamento com foto principal, **When** ele é selecionado, **Then** o painel lateral mostra a imagem autenticada.
2. **Given** um cliente com telefone, **When** ele é selecionado, **Then** o resumo exibe o contato e o atalho de WhatsApp quando aplicável.
3. **Given** um equipamento sem foto, **When** ele é selecionado, **Then** o painel mantém um placeholder seguro sem quebrar o layout.

---

### User Story 3 - Registrar fotos da OS já na criação (Priority: P1)

Como atendente, quero adicionar fotos de entrada na própria criação da OS, com limite e validação, para não perder contexto visual do atendimento inicial.

**Why this priority**: a aba de fotos do legado faz parte do fluxo de recepção e ajuda a manter a paridade funcional.

**Independent Test**: anexar até 4 fotos ao criar a OS e confirmar que o backend central persiste os arquivos e devolve os registros vinculados.

**Acceptance Scenarios**:

1. **Given** até 4 imagens válidas, **When** a OS é criada, **Then** as fotos são salvas em armazenamento privado e vinculadas à OS.
2. **Given** mais de 4 imagens ou arquivo inválido, **When** o usuário tenta enviar, **Then** a validação bloqueia a submissão.
3. **Given** fotos removidas antes do envio, **When** o formulário é submetido, **Then** somente os arquivos mantidos são enviados.

---

### User Story 4 - Manter o fluxo seguro e compatível com backend central (Priority: P1)

Como responsável técnico, quero que a nova criação de OS continue respeitando RBAC, storage privado e contratos da API central, para não introduzir atalho inseguro no desktop.

**Why this priority**: o ganho visual não pode comprometer o modelo de segurança e a separação de responsabilidades do sistema.

**Independent Test**: submeter a OS com permissões válidas e sem permissões suficientes, e confirmar que o acesso é bloqueado corretamente pelo backend ou middleware.

**Acceptance Scenarios**:

1. **Given** usuário sem `os:criar`, **When** acessa `/os/criar`, **Then** é redirecionado conforme a política de permissão do desktop.
2. **Given** submissão válida, **When** a OS é salva, **Then** o desktop chama apenas a API central, sem acesso direto ao banco.
3. **Given** ambiente Linux/VPS, **When** o fluxo roda, **Then** caminhos e storage seguem compatíveis com filesystem case-sensitive.

---

### User Story 5 - Melhorar a experiência sem perder clareza (Priority: P2)

Como time de produto, queremos um fluxo com sugestões e estados claros, para reduzir erro de preenchimento e aumentar a sensação de rapidez.

**Why this priority**: são melhorias importantes, mas não impedem a entrega principal do wizard.

**Independent Test**: verificar mensagens, placeholders, progresso visual e atalhos de apoio sem introduzir ruído ou comportamento inconsistente.

**Acceptance Scenarios**:

1. **Given** campos obrigatórios vazios, **When** o usuário tenta enviar, **Then** a interface aponta o problema com clareza.
2. **Given** campos opcionais vazios, **When** a OS é enviada, **Then** o fluxo continua sem exigir preenchimento desnecessário.
3. **Given** o usuário troca de aba, **When** volta para uma etapa anterior, **Then** os dados digitados permanecem preservados.

## Edge Cases

- Cliente selecionado fora da primeira página de resultados deve continuar aparecendo no resumo e no select.
- Equipamento sem foto principal deve cair para placeholder sem travar a tela.
- Fotos maiores que 2MB ou em formato inválido devem ser bloqueadas antes do envio.
- Navegação entre abas não deve perder o conteúdo digitado.
- Se o backend ficar indisponível, o formulário não deve corromper o rascunho local do usuário.
- A criação deve seguir sendo útil mesmo sem preencher campos opcionais.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A rota `/os/criar` MUST renderizar um layout em duas colunas com painel de resumo à esquerda e formulário em abas à direita.
- **FR-002**: O formulário MUST conter as abas `Cliente`, `Equipamento`, `Defeito`, `Dados Operacionais` e `Fotos`.
- **FR-003**: O resumo lateral MUST atualizar cliente, equipamento, prioridade, status, previsão, relato e contagem de fotos conforme os campos são preenchidos.
- **FR-004**: O equipamento selecionado MUST mostrar foto principal autenticada quando houver, ou placeholder seguro quando não houver.
- **FR-005**: O desktop MUST permitir anexar fotos de entrada na criação da OS, com validação de tipo, tamanho e limite máximo.
- **FR-006**: O backend central MUST persistir as fotos vinculadas à OS em storage privado e expô-las somente por rota autenticada.
- **FR-007**: A criação de OS MUST continuar usando somente o backend central como fonte de verdade, sem acesso direto ao banco pelo desktop.
- **FR-008**: A documentação, contrato da API e nota de implementação MUST ser atualizados ao final da entrega.

### Key Entities

- **Wizard de criação de OS**: tela desktop em duas colunas que guia o preenchimento da nova ordem de serviço.
- **Resumo lateral da OS**: painel visual com foto, cliente, equipamento e indicadores de progresso.
- **Fotos de entrada**: arquivos anexados durante a criação, servidos posteriormente via rota autenticada.
- **Dados operacionais**: técnico, prioridade e datas iniciais da OS.

### Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: O usuário percebe a criação de OS como uma sequência guiada, não como um formulário linear pesado.
- **SC-002**: O resumo lateral reflete cliente/equipamento/fotos sem recarregar a página.
- **SC-003**: Fotos anexadas na criação ficam disponíveis no detalhe da OS.
- **SC-004**: A implementação mantém RBAC, storage privado e compatibilidade com Ubuntu VPS.

## Assumptions

- O backend central já possui a maior parte dos campos da OS; esta entrega acrescenta o suporte de criação guiada e o envio de fotos.
- As fotos de criação entram como fotos de recepção/entrada da OS.
- O fluxo de edição de OS pode permanecer como etapa separada, sem copiar todo o wizard de criação nesta mesma entrega.
- Melhorias mais avançadas como crop automático, captura por câmera e autosave local podem ser tratadas em iteração futura se não couberem na primeira entrega.

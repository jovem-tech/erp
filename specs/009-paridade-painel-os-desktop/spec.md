# Feature Specification: Paridade Operacional do Painel de Ordens de Serviço no Desktop

**Feature Branch**: `009-paridade-painel-os-desktop`

**Created**: 2026-06-26

**Status**: Approved

**Input**: User description: "Extrair o melhor do painel de Ordens de Serviço do sistema-hml (foto, cliente com WhatsApp, equipamento resumido, datas/prazo coloridas, status + orçamento, valor total/recebido/saldo) para o painel de Ordens de Serviço do sistema-erp, eliminando o que não funciona no legado (N+1 de consultas, lógica de formatação espalhada, modais com iframe), potencializando o que já funciona no sistema-erp (API central, paginação Eloquent, partials de UI reutilizáveis) e implementando as melhorias possíveis (filtros adicionais, breakdown financeiro via o módulo Financeiro já implementado)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Identificar cliente e equipamento sem abrir o detalhe (Priority: P1)

Como atendente ou técnico, quero ver a foto do equipamento, o nome do cliente
com um link direto para WhatsApp e um resumo curto do equipamento já na
listagem, para reconhecer rapidamente qual atendimento é qual sem precisar
abrir cada OS.

**Why this priority**: é a informação mais usada para triagem visual rápida;
sem ela, o operador precisa abrir cada OS individualmente, como acontece
hoje no sistema-erp.

**Independent Test**: abrir `/orders`, confirmar que cada linha mostra
miniatura do equipamento (ou placeholder), nome do cliente com botão de
WhatsApp (quando há telefone) e um resumo curto do equipamento (tipo + marca
+ modelo), com o texto técnico completo disponível via tooltip.

**Acceptance Scenarios**:

1. **Given** uma OS cujo equipamento tem foto principal cadastrada, **When**
   a listagem é carregada, **Then** a miniatura da foto aparece na primeira
   coluna, servida por endpoint autenticado (sem URL pública direta).
2. **Given** uma OS cujo cliente tem telefone cadastrado, **When** a
   listagem é carregada, **Then** existe um link clicável para
   `https://wa.me/55...` ao lado do nome do cliente.
3. **Given** uma OS cujo cliente não tem telefone cadastrado, **When** a
   listagem é carregada, **Then** a célula mostra o nome do cliente sem
   quebrar nem exibir link inválido.
4. **Given** um equipamento com tipo/marca/modelo cadastrados, **When** a
   listagem é carregada, **Then** o resumo exibido é curto (ex.: "Notebook
   Dell Inspiron 15"), com o `resumo_tecnico` completo disponível em
   `title=""`.

---

### User Story 2 - Identificar prazo e atraso por cor sem abrir o detalhe (Priority: P1)

Como gestor da assistência, quero ver as datas de entrada, prazo, conclusão
e entrega de cada OS com indicação visual de atraso, para priorizar o
atendimento das OS mais críticas sem precisar abrir cada uma.

**Why this priority**: hoje a listagem só mostra "Previsão" sem nenhuma
indicação de atraso; o gestor não consegue priorizar a fila sem abrir OS
uma a uma.

**Independent Test**: abrir `/orders` com OS em diferentes situações de
prazo (atrasada, vencendo hoje, no prazo, concluída no prazo, concluída com
atraso, sem previsão) e confirmar que a cor/rótulo de cada uma reflete a
situação real.

**Acceptance Scenarios**:

1. **Given** uma OS aberta com `data_previsao` no passado, **When** a
   listagem é carregada, **Then** a célula de prazo mostra estado "atrasado"
   com a contagem de dias.
2. **Given** uma OS aberta com `data_previsao` igual a hoje, **When** a
   listagem é carregada, **Then** a célula mostra estado "vence hoje".
3. **Given** uma OS concluída (`data_conclusao`/`data_entrega` preenchida)
   antes ou na data de `data_previsao`, **When** a listagem é carregada,
   **Then** a célula mostra estado "concluída no prazo".
4. **Given** uma OS sem `data_previsao`, **When** a listagem é carregada,
   **Then** a célula mostra "sem previsão" sem tentar calcular atraso.

---

### User Story 3 - Ver status da OS junto do status do orçamento vinculado (Priority: P1)

Como atendente, quero ver o status do orçamento mais recente vinculado a
uma OS junto do status da própria OS, para saber se ela está parada
esperando aprovação sem precisar abrir o módulo de orçamentos.

**Why this priority**: o módulo de Orçamentos já existe e já guarda
`os_id`; sem esse cruzamento na listagem, o atendente continua tendo que
checar dois lugares separados.

**Independent Test**: abrir `/orders` com uma OS que tem orçamento vinculado
em status "aguardando_resposta" e outra sem nenhum orçamento vinculado, e
confirmar que a primeira mostra o badge de orçamento com a cor certa e a
segunda mostra um indicador neutro ("sem orçamento").

**Acceptance Scenarios**:

1. **Given** uma OS com mais de um orçamento vinculado, **When** a listagem
   é carregada, **Then** o badge exibido reflete o orçamento mais recente
   (`created_at` mais alto).
2. **Given** uma OS sem orçamento vinculado, **When** a listagem é
   carregada, **Then** a célula mostra "Sem orçamento" sem erro.

---

### User Story 4 - Ver Total/Recebido/Saldo da OS sem abrir o financeiro (Priority: P1)

Como atendente ou responsável financeiro, quero ver o valor total da OS, o
quanto já foi recebido e o saldo em aberto diretamente na listagem, para
identificar rapidamente quais OS têm cobrança pendente.

**Why this priority**: é a informação financeira mais usada do legado; o
módulo `Financeiro`/`FinanceiroMovimento` já existe no backend e já guarda
`os_id`, então a paridade fica completa nesta entrega.

**Independent Test**: abrir `/orders` com uma OS que tem título financeiro
`tipo=receber` com baixa parcial, outra com baixa total e outra sem nenhum
título financeiro vinculado, e confirmar que os três casos mostram os
valores corretos (ou o estado neutro "sem cobrança").

**Acceptance Scenarios**:

1. **Given** uma OS com título financeiro `receber` sem nenhuma baixa,
   **When** a listagem é carregada, **Then** "Recebido" mostra R$ 0,00 e
   "Saldo" mostra o valor total do título.
2. **Given** uma OS com título financeiro `receber` parcialmente baixado,
   **When** a listagem é carregada, **Then** "Recebido" e "Saldo" refletem
   a soma dos `financeiro_movimentos` daquele título.
3. **Given** uma OS sem título financeiro vinculado, **When** a listagem é
   carregada, **Then** a célula mostra apenas o valor total da OS
   (`valor_final`) com indicação neutra de "sem cobrança" no lugar de
   Recebido/Saldo.

---

### User Story 5 - Filtrar a fila operacional sem digitar códigos (Priority: P2)

Como gestor, quero filtrar a listagem por técnico responsável, macrofase do
status, intervalo de datas de abertura e intervalo de valor, para montar
filas de trabalho sem precisar saber de cor os códigos internos de status.

**Why this priority**: melhora a operação diária, mas a listagem já é
utilizável sem isso (busca textual e filtro de status já existem).

**Independent Test**: aplicar cada filtro novo isoladamente (técnico,
macrofase, intervalo de datas, intervalo de valor) e confirmar que o
resultado retorna apenas OS compatíveis, mantendo os filtros já existentes
(`search`, `status`, `client_id`, `equipment_id`) funcionando juntos.

**Acceptance Scenarios**:

1. **Given** um técnico com OS atribuídas, **When** filtro o select de
   técnico, **Then** só vejo OS atribuídas a ele.
2. **Given** um intervalo de datas de abertura, **When** aplico o filtro,
   **Then** só vejo OS com `data_abertura` dentro do intervalo.
3. **Given** um intervalo de valor, **When** aplico o filtro, **Then** só
   vejo OS com `valor_final` dentro do intervalo.

---

### User Story 6 - Usar o painel em tablet de bancada e celular (Priority: P2)

Como atendente de balcão, quero usar a listagem de OS em um tablet ou
celular durante o atendimento presencial sem quebra visual, para não
depender de um desktop fixo.

**Why this priority**: o painel ganha muitas colunas novas nesta entrega;
sem cuidado de responsividade, a tela fica inutilizável em telas pequenas.

**Independent Test**: abrir `/orders` nos breakpoints já usados pelo projeto
(1280px, 992px, 768px, 430px, 390px, 360px, 320px) e confirmar ausência de
overflow horizontal e legibilidade de cada célula.

**Acceptance Scenarios**:

1. **Given** a tela em até 768px, **When** a listagem é carregada, **Then**
   cada OS aparece como um card empilhado (`table-stack` já usado em outras
   listagens do projeto), sem cabeçalho de tabela.
2. **Given** a tela em até 992px, **When** a listagem é carregada, **Then**
   os filtros avançados (técnico, macrofase, datas, valores) começam
   recolhidos dentro de um bloco "Filtros avançados", expansível sem
   recarregar a página.

---

## Edge Cases

- OS sem foto de equipamento cadastrada → mostrar placeholder visual, sem
  quebrar o layout da célula.
- OS sem orçamento vinculado → célula mostra "Sem orçamento".
- OS sem título financeiro `receber` vinculado → célula mostra "Sem
  cobrança", exibindo apenas o valor total da OS.
- OS sem técnico atribuído → não pode quebrar o filtro de técnico nem a
  listagem para perfis administrativos.
- Cliente sem telefone cadastrado → sem link de WhatsApp, sem erro.
- Equipamento sem tipo/marca/modelo (só `resumo_tecnico` legado) → cair para
  o resumo truncado existente.
- `data_previsao` nula → célula de prazo mostra "sem previsão", sem cálculo
  de atraso.
- Mais de um orçamento ou mais de um título financeiro `receber` vinculado à
  mesma OS → considerar sempre o mais recente por `created_at`.
- Usuário com perfil técnico → continua restrito às OS atribuídas a ele
  (regra já existente em `OrderWorkflowService::canAccessOrder`); os novos
  campos não podem expor dados de OS de outros técnicos.
- Viewport reduzido (320px-768px) → tabela em modo card e filtros avançados
  recolhidos por padrão, sem overflow horizontal.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: O backend MUST expor no summary de `GET /api/v1/orders`: foto
  principal do equipamento (URL autenticada via rota já existente de fotos
  de equipamento), telefone do cliente, resumo curto do equipamento,
  `data_entrada`, `data_conclusao`, `data_entrega`, um objeto de prazo
  calculado (estado + dias), status/cor/número do orçamento mais recente
  vinculado e o breakdown financeiro (`valor_final`, `valor_mao_obra`,
  `valor_pecas`, `desconto`, `valor_recebido`, `saldo`).
- **FR-002**: O backend MUST resolver orçamento e financeiro vinculados à
  página atual em consultas agregadas por lote (no máximo poucas queries
  adicionais por página), nunca uma consulta por OS exibida.
- **FR-003**: O backend MUST aceitar os filtros `technician_id` (já
  aceito, só documentar), `grupo_macro`, `data_abertura_de`,
  `data_abertura_ate`, `valor_min` e `valor_max` em
  `GET /api/v1/orders`.
- **FR-004**: O frontend desktop MUST exibir as novas colunas (Foto,
  Cliente+WhatsApp, Equipamento curto, Datas coloridas, Status+Orçamento,
  Valor) reaproveitando os partials existentes (`status-pill`,
  `pagination`, `empty-state`).
- **FR-005**: O frontend desktop MUST exibir o link de WhatsApp apenas
  quando o telefone do cliente estiver preenchido, sem gerar link inválido
  quando ausente.
- **FR-006**: O frontend desktop MUST agrupar os filtros novos (técnico,
  macrofase, datas, valores) em um bloco colapsável "Filtros avançados",
  expandido por padrão em telas `>=992px` e recolhido por padrão abaixo
  disso.
- **FR-007**: Todo `<select>` novo no frontend desktop MUST seguir o padrão
  `Select2` já configurado globalmente (classe `form-select`, sem
  `data-native-select="true"`), conforme a constituição do projeto.
- **FR-008**: A listagem MUST permanecer utilizável sem quebra visual ou
  overflow horizontal nos breakpoints já adotados pelo projeto (`1280px`,
  `992px`, `768px`, `430px`, `390px`, `360px`, `320px`).
- **FR-009**: O backend MUST continuar restringindo usuários com perfil
  técnico à visualização das OS atribuídas a eles (regra já existente),
  aplicada também aos novos campos.
- **FR-010**: A documentação técnica (contrato da API, nota de
  implementação em `documentacao/07-novas-implementacoes/`,
  `backend/openapi.yaml`) MUST ser atualizada junto da entrega.

### Key Entities

- **Resumo de OS na listagem**: representação enxuta de uma OS para a
  tabela de `/orders`, combinando dados de `os`, `clientes`, `equipamentos`
  (com tipo/marca/modelo), foto principal do equipamento, orçamento mais
  recente vinculado e título financeiro mais recente vinculado.
- **Foto principal do equipamento**: registro já existente em
  `equipamentos_fotos` (`is_principal=1`), servido por endpoint autenticado
  já existente do módulo de equipamentos.
- **Orçamento vinculado**: registro mais recente da tabela `orcamentos`
  (`os_id`) para a OS exibida, com status e cor já definidos em
  `Budget::statusOptions()`.
- **Título financeiro vinculado**: registro mais recente da tabela
  `financeiro` (`os_id`, `tipo=receber`) para a OS exibida, com os
  `financeiro_movimentos` agregados para compor valor recebido e saldo.
- **Prazo calculado**: valor derivado (não persistido) a partir de
  `data_previsao`, `data_conclusao`, `data_entrega` e a data atual,
  classificado em estados (atrasado, vence hoje, crítico, no prazo,
  concluído no prazo, concluído com atraso, sem previsão).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Um usuário consegue identificar cliente, equipamento e
  situação de atraso de uma OS sem abrir o detalhe.
- **SC-002**: O número de consultas ao banco para montar uma página da
  listagem não cresce proporcionalmente à quantidade de OS exibidas
  (verificado por teste automatizado que conta queries).
- **SC-003**: Um usuário consegue filtrar por técnico, macrofase, intervalo
  de datas e intervalo de valor sem digitar nenhum código manualmente.
- **SC-004**: A tela permanece utilizável em viewport reduzido (até 320px)
  sem quebra visual ou overflow horizontal.
- **SC-005**: Uma OS sem orçamento e sem título financeiro vinculado
  continua sendo exibida normalmente, sem erro, com indicação neutra.

## Assumptions

- O módulo `Financeiro`/`FinanceiroMovimento` (migrations de
  2026-06-27/2026-06-28) já está implementado e será aplicado ao banco
  antes desta feature ser validada.
- O módulo de Orçamentos (`Budget`, vinculado via `os_id`) já está
  implementado e em uso.
- A foto principal do equipamento reaproveita a relação e a rota já
  existentes do módulo de cadastro de equipamentos (`specs/008-cadastro-equipamentos-desktop`).
- A criação automática de título financeiro a partir do fechamento/aprovação
  de uma OS (equivalente ao `OsSettlementService` do legado) fica fora do
  escopo desta entrega — aqui só se lê o que já existir.
- O texto de "relato do cliente" e ações em massa do legado ficam fora do
  escopo desta entrega.
- Não há, nesta fase, modais com iframe para criar/editar/ver fotos a partir
  da listagem — a navegação para a página de detalhe (`/orders/{id}`)
  continua sendo o padrão de interação.

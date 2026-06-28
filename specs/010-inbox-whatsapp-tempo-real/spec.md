# Feature Specification: Central de Atendimento — Inbox de WhatsApp em tempo real

**Feature Branch**: `010-inbox-whatsapp-tempo-real`

**Created**: 2026-06-27

**Status**: Approved

**Input**: Primeira fase do roadmap de reconstrução do núcleo do Chatwoot como módulo
nativo do sistema-erp (ver plano arquitetural completo em
`C:\Users\jovem\.claude\plans\robust-herding-cookie.md`). Esta fase entrega o núcleo
navegável mínimo: Account/Inbox/Contact/ContactInbox/Conversation/Message, canal WhatsApp
via Evolution API, tempo real via Reverb/Echo, e a nova app `frontends/chat` instalável
como PWA.

## Scope Update - v1 minimal UX (2026-06-27)

Este escopo aprovado foi ampliado no mesmo ciclo para cobrir a experiencia minima de uso da
central como um "WhatsApp Web/mobile" do ERP, sem entrar ainda nas camadas avancadas de
gestao (assignee, labels, notes, macros, SLA, automacoes e integracoes extras).

Novos pilares desta etapa:

- o banco `sistema_hml` passa a ser a fonte oficial de clientes para o chat, por conexao
  dedicada e explicita;
- a central continua usando `sistema_erp_chat` para contatos, conversas, mensagens e
  anexos, mas agora com suporte real a midia inbound e outbound;
- toda mensagem nova enviada pelo ERP ao WhatsApp deve entrar na mesma timeline do chat como
  `outgoing/system`;
- o `frontends/chat` passa de inbox inicial para UX mobile-first de lista, thread e perfil,
  com navegacao usavel em smartphone de verdade;
- a timeline unificada vale apenas para mensagens novas apos a implantacao, sem backfill.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Atender uma conversa de WhatsApp em tempo real (Priority: P1)

Como atendente, quero ver mensagens de WhatsApp chegando em tempo real numa tela dedicada
do sistema-erp e responder por ela, para não precisar alternar para outro sistema durante
o atendimento.

**Why this priority**: é a razão de existir desta fase — sem isso, não há produto.

**Independent Test**: enviar uma mensagem real para o número WhatsApp configurado na
Evolution API e confirmar que ela aparece na tela de conversas sem reload manual; responder
pela tela e confirmar que o cliente recebe a resposta no WhatsApp.

**Acceptance Scenarios**:

1. **Given** um número de telefone que nunca conversou antes, **When** ele envia uma
   mensagem de WhatsApp, **Then** um novo Contact, ContactInbox e Conversation são criados,
   e a mensagem aparece na lista de conversas do atendente em tempo real.
2. **Given** uma conversa já aberta e não resolvida com um contato, **When** esse mesmo
   contato envia outra mensagem, **Then** a mensagem entra na conversa existente, sem criar
   uma segunda conversa para o mesmo atendimento.
3. **Given** uma conversa aberta na tela do atendente, **When** o atendente digita e envia
   uma resposta, **Then** a mensagem aparece imediatamente na thread (otimista) e chega ao
   WhatsApp do cliente via Evolution API.
4. **Given** uma mensagem enviada pelo atendente, **When** a Evolution API confirma o envio
   ou retorna erro, **Then** o status da mensagem na tela reflete isso (enviada/falha).

---

### User Story 2 - Identificar automaticamente o cliente do ERP na conversa (Priority: P1)

Como atendente, quero que a conversa já mostre o cadastro do cliente do ERP quando o
telefone coincidir, para não precisar buscar manualmente quem está falando.

**Why this priority**: sem isso a central de atendimento é um chat genérico, desconectado
do propósito de assistência técnica do sistema-erp.

**Independent Test**: receber uma mensagem de um telefone que já existe em
`sistema_erp.clientes` e confirmar que o painel de contato mostra nome/dados do cliente,
sem busca manual.

**Acceptance Scenarios**:

1. **Given** um telefone que já existe em `clientes.telefone1` (ou `telefone2`/`telefone_contato`),
   **When** uma conversa nova é criada para esse telefone, **Then** o `Contact` é vinculado
   ao `cliente_id` correspondente e o painel exibe os dados do cliente.
2. **Given** um telefone que não existe em nenhum cadastro de cliente, **When** uma conversa
   nova é criada, **Then** o `Contact` é criado sem vínculo, sem erro, e o painel mostra
   apenas o telefone/nome de WhatsApp disponível.

---

### User Story 3 - Instalar a Central de Atendimento como app (Priority: P2)

Como atendente, quero instalar a central de atendimento como um app no computador ou
celular, para abri-la rapidamente como se fosse um aplicativo nativo, sem digitar URL nem
manter aba de navegador aberta entre outras.

**Why this priority**: requisito explícito do projeto; eleva a percepção de "ferramenta de
trabalho" em vez de "página web", mas não bloqueia o valor central (atendimento em tempo
real) se ainda não estiver pronta no primeiro corte.

**Independent Test**: abrir `frontends/chat` em Chrome/Edge (desktop ou Android), confirmar
o prompt/opção "Instalar app", instalar, e confirmar que abre em janela própria (modo
`standalone`, sem barra de endereço), com ícone e nome corretos.

**Acceptance Scenarios**:

1. **Given** o app `frontends/chat` publicado via HTTPS, **When** o atendente abre no
   Chrome/Edge/Android, **Then** o navegador oferece a opção de instalação.
2. **Given** o app instalado, **When** o atendente o abre pelo ícone, **Then** ele abre em
   janela própria, sem chrome de navegador, com a lista de conversas carregada.
3. **Given** o app instalado sem conexão momentânea, **When** o atendente o abre, **Then**
   o app-shell (lista/thread já carregadas) continua visível, em vez de tela em branco.

---

### User Story 4 - Não perder o fio da conversa ao trocar de tela ou reconectar (Priority: P2)

Como atendente, quero que a conversa continue completa e atualizada mesmo se eu recarregar
a página ou minha conexão cair e voltar, para não perder mensagens nem duplicar respostas.

**Why this priority**: confiabilidade básica esperada de qualquer ferramenta de atendimento;
sem isso o tempo real da US1 não é confiável no uso real do dia a dia.

**Independent Test**: abrir uma conversa, desconectar a rede por alguns segundos, reconectar,
e confirmar que mensagens recebidas durante a queda aparecem sem duplicar e sem precisar de
reload manual.

**Acceptance Scenarios**:

1. **Given** uma conversa aberta, **When** a conexão WebSocket cai e reconecta, **Then** o
   cliente re-sincroniza via REST as mensagens que chegaram durante a queda.
2. **Given** o atendente recarrega a página numa conversa, **When** a página carrega de
   novo, **Then** o histórico completo da conversa aparece na ordem correta, sem duplicar
   mensagens já existentes.

---

### User Story 5 - Iniciar conversa pelo ERP ou por telefone livre (Priority: P1)

Como atendente, quero iniciar uma conversa buscando um cliente do ERP ou digitando um
telefone livre, para usar a central como canal ativo de atendimento e nao apenas como inbox
reativo.

**Why this priority**: sem isso, a central nao atende o fluxo real do time, que precisa
acionar clientes do ERP a partir do proprio sistema.

**Independent Test**: buscar um cliente do `sistema_hml` e iniciar conversa com mensagem
inicial; repetir o mesmo fluxo com telefone livre e confirmar que o numero reaproveita a
mesma conversa quando usado novamente.

**Acceptance Scenarios**:

1. **Given** um cliente encontrado pela busca do chat, **When** o atendente inicia uma
   conversa por `client_id`, **Then** a conversa usa o telefone selecionado do cliente e o
   painel mostra o contexto desse cadastro.
2. **Given** um telefone livre digitado manualmente, **When** o atendente cria uma
   conversa, **Then** o sistema normaliza o numero, localiza ou cria o contato e reaproveita
   a conversa existente do mesmo telefone quando houver.
3. **Given** uma mensagem inicial com texto e/ou anexos, **When** a conversa e criada,
   **Then** a thread ja nasce com a primeira mensagem registrada na timeline.

---

### User Story 6 - Operar a central no smartphone sem perder contexto (Priority: P1)

Como atendente, quero navegar da lista para a conversa e para o perfil do contato usando o
celular, para tratar a central como um app de atendimento real, sem a UX quebrar em telas
pequenas.

**Why this priority**: o uso mobile eh parte explicita da proposta desta fase e da
analogia com WhatsApp mobile.

**Independent Test**: abrir a central num smartphone, entrar numa conversa e acessar o
perfil do contato sem perder a navegacao de volta para a lista.

**Acceptance Scenarios**:

1. **Given** a central aberta em viewport pequena, **When** o atendente toca numa conversa,
   **Then** a lista cede lugar para a thread e existe navegacao clara de volta.
2. **Given** uma thread aberta no smartphone, **When** o atendente abre o perfil do
   contato, **Then** o contexto essencial do cliente aparece sem impedir o retorno rapido a
   lista ou a conversa.
3. **Given** a mesma aplicacao em desktop ou tablet, **When** ha espaco suficiente,
   **Then** a experiencia evolui para split view sobre a mesma base de componentes.

---

### User Story 7 - Ver mensagens automáticas do ERP na mesma timeline (Priority: P1)

Como atendente, quero ver na conversa os disparos automáticos do ERP enviados por WhatsApp,
para ter o contexto completo do relacionamento com o cliente no mesmo lugar.

**Why this priority**: sem timeline unica, o atendente continua cego para comunicacoes
automaticas importantes do proprio ERP.

**Independent Test**: disparar um envio automatico do ERP para WhatsApp e confirmar que a
mensagem aparece em tempo real na conversa correspondente como mensagem do sistema.

**Acceptance Scenarios**:

1. **Given** um fluxo automatico do ERP que envia WhatsApp, **When** o envio ocorre,
   **Then** a mensagem entra no provider, localiza ou cria a conversa e fica registrada como
   `outgoing/system` na timeline do chat.
2. **Given** que nao exista conversa aberta para o telefone, **When** o ERP dispara a
   mensagem, **Then** o sistema cria contato e conversa antes de persistir a mensagem.
3. **Given** que a implantacao desta etapa ja ocorreu, **When** mensagens novas sao
   disparadas, **Then** apenas esses eventos novos entram na timeline, sem importar
   retrospectivamente historico antigo.

---

### Edge Cases

- Webhook da Evolution API chega em formato de "self-check" (validação de conectividade,
  já usada hoje pela tela de Configurações) — MUST ser reconhecido e respondido sem criar
  Contact/Conversation/Message falsos.
- Webhook chega duplicado (reentrega do provider) — MUST não duplicar a `Message`
  (idempotência por `source_id`).
- Telefone recebido em formato inválido ou sem DDI — MUST ser normalizado de forma
  consistente (mesma regra sempre) ou rejeitado com log, nunca criar contato com telefone
  ambíguo.
- Mídia (imagem/áudio/documento) que falha no download do provider — MUST registrar a
  mensagem mesmo assim (com indicador de falha de mídia), sem quebrar a conversa inteira.
- Atendente sem internet momentânea com o app instalado — MUST mostrar o app-shell já
  carregado em vez de tela em branco (sem exigir funcionar 100% offline).
- Dois atendentes abrindo a mesma conversa ao mesmo tempo — MUST ambos verem as mensagens
  em tempo real; concorrência de atribuição/assignee fica fora do escopo desta fase.
- Evolution API fora do ar no momento do envio — MUST marcar a mensagem como falha de envio
  de forma visível, sem travar a tela.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: O sistema MUST aceitar webhooks da Evolution API em `POST /webhooks/whatsapp`
  (endpoint já existente) e, quando o payload não for um self-check, processá-lo como
  mensagem entrante.
- **FR-002**: O sistema MUST normalizar o telefone do remetente para um formato único
  (E.164) antes de usá-lo como identificador de contato/canal.
- **FR-003**: O sistema MUST localizar ou criar `Contact`, `ContactInbox` e `Conversation`
  para cada mensagem entrante, reaproveitando a conversa não resolvida mais recente do mesmo
  `ContactInbox` quando existir.
- **FR-004**: O sistema MUST vincular automaticamente o `Contact` ao cadastro de `Client` do
  ERP quando o telefone coincidir com um cadastro existente.
- **FR-005**: O sistema MUST transmitir em tempo real (WebSocket) o evento de nova mensagem
  para os atendentes autenticados conectados à conta.
- **FR-006**: O sistema MUST permitir que um atendente autenticado envie uma mensagem de
  texto numa conversa existente.
- **FR-007**: O sistema MUST entregar a mensagem enviada pelo atendente ao cliente via
  Evolution API, reaproveitando a integração de envio já existente
  (`IntegrationSettingsService`).
- **FR-008**: O sistema MUST refletir o status de entrega da mensagem enviada
  (enviada/falha) com base na resposta do provider.
- **FR-009**: O sistema MUST autenticar a conexão de tempo real (Reverb) usando o mesmo
  Bearer token Sanctum já emitido para o atendente, sem exigir mecanismo de login separado.
- **FR-010**: O sistema MUST re-sincronizar via REST as mensagens de uma conversa após uma
  reconexão de WebSocket, sem duplicar mensagens já exibidas.
- **FR-011**: O sistema MUST armazenar mídia recebida em storage privado, acessível somente
  por endpoint autenticado.
- **FR-012**: A aplicação `frontends/chat` MUST ser instalável como PWA (manifest válido,
  ícones, service worker registrado), abrindo em modo `standalone` após instalada.
- **FR-013**: O sistema MUST continuar respondendo corretamente ao fluxo de self-check de
  webhook já usado pela tela de Configurações de integrações (`selfCheckInbound`/
  `directWebhookSelfCheck`), sem regressão.
- **FR-014**: O sistema MUST manter todo texto visível ao atendente em pt-BR (rótulos de
  status de conversa, mensagens de erro), mesmo quando o valor técnico subjacente estiver em
  inglês (ex.: `status=open` exibido como "Aberta").
- **FR-015**: O sistema MUST expor uma busca de clientes específica do chat, somente leitura,
  baseada no banco `sistema_hml`, sem depender do módulo amplo de clientes.
- **FR-016**: O sistema MUST permitir iniciar conversa por `client_id` ou por telefone livre,
  com mensagem inicial opcional contendo texto, anexos ou ambos.
- **FR-017**: O sistema MUST modelar anexos de chat em estrutura própria e servi-los apenas
  por endpoint autenticado.
- **FR-018**: O sistema MUST aceitar e persistir mensagens inbound de imagem, áudio, vídeo e
  documento, inclusive quando não houver texto associado.
- **FR-019**: O sistema MUST unificar os novos disparos de WhatsApp do ERP na timeline do
  chat, registrando-os como mensagens `outgoing/system`.
- **FR-020**: O sistema MUST exibir `unread_count`, preview da última mensagem e resumo do
  cliente ERP nos payloads públicos de conversa.
- **FR-021**: O `frontends/chat` MUST oferecer navegação mobile-first em uma coluna para
  smartphone e melhoria progressiva para split view em telas maiores.
- **FR-022**: O sistema MUST registrar placeholder de falha quando a mídia inbound não puder
  ser baixada, preservando trilha de auditoria.
- **FR-023**: O sistema MUST manter fora desta fase a UI de assignee, labels, notes, macros,
  snooze, relatórios, SLA e automações.

### Key Entities

- **Account** (`contas_atendimento`): conta/tenant da central de atendimento; nesta fase,
  uma única linha.
- **Inbox** (`caixas_entrada`): canal de comunicação configurado; nesta fase, uma caixa de
  entrada do tipo WhatsApp.
- **Contact** (`contatos`): pessoa que conversa pelo canal; pode ou não estar vinculada a um
  `Client` do ERP.
- **ContactInbox** (`contatos_caixas_entrada`): identidade do contato dentro de um inbox
  específico (telefone normalizado, para WhatsApp).
- **Conversation** (`conversas`): atendimento em andamento ou encerrado entre um
  ContactInbox e a equipe.
- **Message** (`mensagens`): cada mensagem trocada (entrante ou enviada), com status de
  entrega.
- **Channel::Whatsapp** (`canais_whatsapp`): configuração do canal WhatsApp (delega
  credenciais ao `IntegrationSettingsService` já existente).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Mensagem real enviada para o número WhatsApp configurado aparece na tela do
  atendente sem reload manual, em poucos segundos.
- **SC-002**: Resposta enviada pelo atendente pela tela chega de fato ao WhatsApp do
  cliente.
- **SC-003**: Nenhuma conversa ou contato duplicado é criado para o mesmo telefone em
  mensagens consecutivas de uma mesma sessão de atendimento.
- **SC-004**: O self-check de webhook já usado pela tela de Configurações continua
  retornando sucesso após esta entrega.
- **SC-005**: O app `frontends/chat` passa o critério de instalabilidade do navegador
  (Chrome/Edge: prompt ou opção "Instalar app" disponível) e abre em modo `standalone` uma
  vez instalado.
- **SC-006**: Reconectar o WebSocket após uma queda de rede traz as mensagens perdidas sem
  duplicar as já exibidas.

## Assumptions

- A instância da Evolution API já está configurada e conectada (setup feito hoje pela tela
  de Configurações existente) — esta fase não cobre o provisionamento inicial da instância.
- Existe um único número/instância WhatsApp ativo nesta fase (sem múltiplos inboxes
  simultâneos).
- `contas_atendimento` terá uma única linha nesta fase (decisão já fechada no plano
  arquitetural).
- Notificação push com o app fechado (Web Push/VAPID) **não** entra nesta fase — fica como
  fast-follow imediato após `010`, já que é um mecanismo adicional ao Reverb (que exige o
  app aberto/conectado) e tem requisitos próprios (permissão do navegador, tabela de
  inscrições por dispositivo).
- `sistema_hml` é tratado como fonte oficial de clientes nesta etapa, por conexão dedicada.
- O vínculo manual posterior de contatos desconhecidos a clientes fica fora deste corte; o
  vínculo segue automático por telefone ou por criação via `client_id`.
- A timeline única vale apenas para mensagens novas após a implantação desta etapa; não há
  backfill histórico.
- Atribuição de conversa a um atendente específico, times, automações e respostas rápidas
  ficam para as fases seguintes do roadmap (`011-respostas-rapidas-automacao-times` em
  diante) — nesta fase qualquer atendente autenticado pode ver e responder qualquer
  conversa.
- Esta fase não inclui tela de configuração nova para o canal WhatsApp — reaproveita a tela
  de integrações já existente, que já gerencia URL/apikey/instância da Evolution API.

# Central de Atendimento v1: WhatsApp Web/mobile do ERP

## Contexto

- versao: `3.3.0`
- data: `2026-06-27`
- ambiente-alvo: `Ubuntu VPS`

Evolucao da feature `specs/010-inbox-whatsapp-tempo-real/` para o primeiro corte de
experiencia operacional real da Central de Atendimento. O objetivo desta etapa foi fazer o
`frontends/chat` se comportar como um "WhatsApp Web/mobile" do ERP: lista de conversas,
thread, perfil, busca, envio e recebimento em tempo real, inicio de conversa por cliente ou
telefone livre e timeline unica para mensagens novas do WhatsApp do ERP.

Nesta fase, a fonte oficial de clientes para o chat passa a ser o banco legado
`sistema_hml`, acessado por conexao dedicada e explicita. O banco `sistema_erp_chat`
continua sendo a origem de verdade de contatos, conversas, mensagens e anexos do modulo.

## Entrega

- **Conexao dedicada de leitura para `sistema_hml`**:
  - `backend/config/database.php` ganhou a conexao `sistema_hml`;
  - `backend/.env.example` recebeu as chaves `SISTEMA_HML_DB_*`;
  - `App\Models\Legacy\LegacyClient` e `App\Services\Chat\ChatClientLookupService`
    passaram a isolar a busca leve por nome/documento/telefone e o vinculo automatico por
    telefone.
- **Timeline do chat mantida no banco `chat`, agora com suporte real a anexos**:
  - `mensagens` ganhou `content_type`;
  - nova tabela `mensagem_anexos`;
  - `App\Services\Chat\MessageAttachmentService` salva anexos em storage privado,
    preserva metadata do provider em `content_attributes` e registra placeholder de falha
    quando a midia inbound nao puder ser baixada;
  - mensagens sem texto continuam validas quando houver apenas midia.
- **Webhook inbound da Evolution ampliado para texto e midia**:
  - `IncomingMessageService` agora trata texto, imagem, audio, video e documento;
  - mensagens inbound com legenda vazia nao sao descartadas;
  - se o download da midia falhar, a mensagem ainda entra na timeline com status de falha
    do anexo para auditoria e reprocesso.
- **Servico unificado de WhatsApp do ERP**:
  - `App\Services\Channels\Whatsapp\WhatsappMessagingService` virou a camada unica de envio;
  - o driver de saida passou a enviar texto e midia pela Evolution;
  - status da mensagem e metadata de provider sao atualizados no registro do chat;
  - disparos automáticos do ERP passaram a registrar mensagem `outgoing/system` na mesma
    conversa do telefone correspondente, inclusive criando contato/conversa quando preciso.
- **Fluxos do ERP ja migrados para a timeline unica**:
  - notificacao de encerramento de OS e cobrancas pendentes agora usam o servico unificado;
  - toda mensagem nova enviada por esses fluxos aparece em tempo real para o atendente na
    central.
- **API publica da Central de Atendimento evoluida**:
  - `GET /api/v1/conversas` agora inclui `unread_count`, `last_message`, resumo do cliente e
    preview enriquecido;
  - `POST /api/v1/conversas` aceita abrir conversa por `client_id` ou telefone livre, com
    mensagem inicial opcional e anexos;
  - `POST /api/v1/conversas/{id}/mensagens` passou para `multipart/form-data`, aceitando
    texto, anexos ou ambos;
  - `GET /api/v1/chat/clientes/search` expoe busca minima de clientes do `sistema_hml` para
    o chat, protegida pelo modulo `atendimento_whatsapp` sem exigir o modulo amplo de
    clientes;
  - `GET /api/v1/chat/anexos/{attachment}` serve download/stream autenticado de anexos
    privados;
  - `backend/openapi.yaml` foi atualizado para refletir o contrato real.
- **`frontends/chat` reaproveitado com UX mobile-first**:
  - mesma app existente, sem criar um frontend paralelo;
  - smartphone: navegacao em uma coluna (`lista -> conversa -> perfil`);
  - desktop/tablet: melhora progressiva para split view;
  - lista de conversas com busca, filtros `Todas` e `Nao lidas`, preview, badge de nao
    lidas, status da ultima saida, avatar/nome/telefone e contexto do cliente;
  - inicio de conversa por busca de cliente do ERP ou telefone livre;
  - thread com mensagens inbound, respostas humanas, mensagens automaticas do ERP e anexos
    de imagem, audio, video e documento;
  - composer mobile-first com texto, upload, preview antes do envio e estado de envio/erro;
  - card/perfil de contato com nome, telefones, documento, cidade e vinculo ao cliente;
  - tempo real e PWA preservados, agora com navegacao coerente em smartphone.

## Regras desta fase

- A timeline unica vale apenas para mensagens novas apos a implantacao desta etapa; nao ha
  importacao retroativa de historico antigo.
- Conversas inbound de numeros sem cliente vinculado continuam aparecendo normalmente, com o
  contexto disponivel do WhatsApp.
- O vinculo manual posterior de um contato desconhecido a um cliente fica fora desta fase;
  o vinculo segue automatico por telefone ou por criacao via `client_id`.
- Nao ha interface nesta entrega para assignee, labels, notes, snooze, macros, SLA,
  automacoes ou integracoes extras.
- O fluxo operacional desta etapa fica deliberadamente centrado em lista, unread e timeline.

## Impactos tecnicos

- `frontends/chat` continua responsivo para desktop, mas a shell agora eh mobile-first.
- Midia do chat permanece sempre em storage privado e sai apenas por endpoint autenticado.
- O modulo ganhou dependencia operacional explicita de uma conexao dedicada para
  `sistema_hml`, sem se apoiar na conexao default do backend.
- O contrato de conversa/mensagem ficou mais rico e exigiu atualizacao do `openapi.yaml`.
- Nao houve backfill historico; a convergencia da timeline vale para eventos novos.

## Validacao

- `php artisan test --filter="(ConversationFlowTest|WhatsappWebhookTest)"` no `backend`:
  23 testes passando, cobrindo busca de cliente, abertura por cliente/telefone, texto,
  midia inbound, falha de download inbound, envio com anexos, reaproveitamento de conversa e
  download autenticado de anexo.
- `npm run build` em `frontends/chat`: build de producao sem erros.
- `npm test` em `frontends/chat`: 10 testes Vitest passando.
- O runtime `Cannot find module './331.js'` foi reproduzido contra um `.next` antigo e
  sumiu apos limpeza do cache gerado e rebuild limpo; o `frontends/chat/package.json`
  agora executa `npm run clean` automaticamente antes de `dev` e `build` para reduzir a
  chance de artefato stale voltar a quebrar o app.
- O 500 em `GET /api/v1/conversas` vinha de uma base local sem a tabela
  `mensagem_anexos`; a migration `2026_06_27_000002_add_chat_message_media_support`
  foi aplicada em `sistema_erp_chat`, e a listagem passou a carregar normalmente.
- O webhook de WhatsApp passou a aceitar os formatos mais comuns de autenticacao de
  provider (`X-Webhook-Token`, `X-Api-Token`, `X-Api-Key`, `apikey` e `Authorization:
  Bearer`) e o inbound agora interpreta `fromMe` textual (`"false"`, `"true"`) sem
  descartar mensagens validas por cast incorreto.
- Login, realtime e PWA existentes permaneceram ativos no app do chat.
- Ainda nao houve validacao visual em navegador real neste ambiente; recomenda-se abrir
  `http://localhost:3002` com backend + Reverb para confirmar a navegacao mobile, o fluxo de
  anexos e o realtime fim a fim.

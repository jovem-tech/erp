# Tasks: Central de Atendimento — Inbox de WhatsApp em tempo real

## Phase 1: Setup e governança

- [X] T001 Criar `specs/010-inbox-whatsapp-tempo-real/` (spec.md, plan.md, tasks.md) e atualizar `.specify/feature.json`
- [X] T002 Criar `sistema-erp/NOTICE.md` (atribuição MIT do Chatwoot)
- [X] T003 Atualizar `shared/version.php` para a nova versão funcional (3.3.0)

## Phase 2: Banco de dados e modelos (backend)

- [X] T004 Configurar conexão `chat` em `backend/config/database.php` (driver-condicional `mysql`/`sqlite` para testes) e novas chaves `CHAT_DB_*` em `.env`/`.env.example`
- [X] T005 Migration `backend/database/migrations/chat/2026_06_27_000001_create_chat_core_tables.php`: `contas_atendimento` (com `proximo_display_id`), `caixas_entrada`, `contatos`, `contatos_caixas_entrada`, `conversas` (com `lida_em`), `mensagens`, `canais_whatsapp` — guard `Schema::hasTable()`, índices nomeados. **Ajuste em relação ao plano original**: sem FK cross-database de verdade (`unsignedBigInteger` + índice para `cliente_id`), pois o restante do projeto não usa constraint de FK entre tabelas — mantém o mesmo padrão já estabelecido.
- [X] T006 Models `backend/app/Models/Chat/{Account,Inbox,Contact,ContactInbox,Conversation,Message}.php` e `Chat/Channel/Whatsapp.php`, todos com `protected $connection = 'chat';`. **Achado importante**: `Contact::client()` precisou ser escrita sem o helper `belongsTo()` padrão — `Model::newRelatedInstance()` herda a conexão do pai quando o relacionado não declara `$connection` própria, o que faria `Client` ser consultado (errado) na conexão `chat`. Corrigido construindo a relação manualmente com a conexão default explícita.
- [X] T007 `backend/app/Contracts/ChannelDriverInterface.php` + `backend/app/Support/Channels/ChannelRegistry.php` + `backend/config/channels.php`

## Phase 3: Canal WhatsApp (entrada e saída)

- [X] T008 `backend/app/Services/Channels/Whatsapp/PhoneNumberNormalizationService.php` (normalização E.164)
- [X] T009 `backend/app/Services/Channels/Whatsapp/IncomingMessageService.php`: findOrCreateContact (com vínculo a `Client` do ERP por telefone, comparando os últimos 9 dígitos) → findOrCreateContactInbox → findOrCreateConversation (reaproveita não-resolvida) → cria Message → dispara `MessageCreated`. Ignora mensagens de grupo/broadcast e `fromMe`.
- [X] T010 Estendido `backend/app/Http/Controllers/Webhooks/WhatsAppWebhookController.php`: quando `self_check` for falso, repassa payload ao `IncomingMessageService` (com try/catch para nunca quebrar o ack); preserva validação de token e resposta de self-check existentes.
- [X] T011 `backend/app/Services/Channels/Whatsapp/WhatsappChannelDriver.php` implementando `ChannelDriverInterface::sendMessage()`, reaproveitando `IntegrationSettingsService::sendDirectMessage()` (método novo, fino, adicionado ao serviço existente) para o envio real.
- [X] T012 `backend/app/Jobs/SendWhatsappMessageJob.php` + `POST /api/v1/conversas/{conversa}/mensagens` em `MessageController`, com status enviada/falha conforme retorno do provider.

## Phase 4: Tempo real (Reverb)

- [X] T013 Adicionado `laravel/reverb` ao `composer.json` (via `composer require`), rodado `php artisan reverb:install`, configurado `.env`/`.env.example` (`BROADCAST_CONNECTION=reverb`, chaves Reverb, porta 8090 para não colidir com `frontends/desktop`:8080).
- [X] T014 Events `backend/app/Events/{MessageCreated,MessageUpdated,ConversationStatusChanged}.php`
- [X] T015 `backend/routes/channels.php` com autorização via Bearer/Sanctum + `backend/app/Services/Chat/ConversationAccessService.php`. **Achado importante**: `bootstrap/app.php` tinha `channels: routes/channels.php` no `withRouting()`, que registra `Broadcast::routes()` com middleware `web` padrão *depois* do boot dos providers, sobrescrevendo a customização para `auth:sanctum`. Corrigido removendo esse parâmetro de `withRouting()` e registrando manualmente em `AppServiceProvider::boot()`.
- [X] T016 Templates de infraestrutura de produção: `infra/linux/supervisor-reverb.conf` (systemd/supervisor) e bloco `location /app/` de proxy WebSocket adicionado a `infra/linux/nginx-site.conf`.

## Phase 5: API de conversas (backend)

- [X] T017 `backend/app/Http/Controllers/Api/V1/Chat/ConversationController.php` (listar com indicador de não lida calculado em lote/sem N+1, detalhe que marca como lida) e `MessageController.php` (enviar), estendendo `BaseApiController`.
- [X] T018 Rotas registradas em `backend/routes/api.php` (`api/v1/conversas/*`, grupo `auth:sanctum`) e `backend/openapi.yaml` atualizado (tag "Central de Atendimento", schemas `ConversationSummary`/`ConversationDetail`/`ChatMessage`).

## Phase 6: Frontend `frontends/chat` (app nova)

- [X] T019 Scaffold do projeto Next.js 15 + TypeScript em `frontends/chat/` (porta 3002, independente de `frontends/mobile`), `package.json`, `tsconfig.json`, `.env`/`.env.example`.
- [X] T020 `src/lib/api.ts` + `src/lib/session.ts` (réplica do padrão de `frontends/mobile`) + `src/app/login/page.tsx` + `session-provider.tsx`/`auth-guard.tsx`/`logout-button.tsx`.
- [X] T021 `src/lib/echo.ts` (cliente Echo/Reverb com `authorizer` Bearer customizado) + hooks `useConversationChannel`/`useAccountConversationsChannel` em `src/hooks/use-chat-channels.ts`.
- [X] T022 Layout de 3 colunas: `conversas/layout.tsx`, `conversas/page.tsx`, `conversas/[id]/page.tsx` + componentes `conversation-list.tsx`, `conversation-list-item.tsx`, `message-bubble.tsx`, `message-composer.tsx`, `contact-panel.tsx`.
- [X] T023 PWA: **ajuste em relação ao plano original** — em vez de `@ducanh2912/next-pwa`, replicado o padrão hand-rolled já usado (e já comprovadamente funcional) em `frontends/mobile`: `manifest.ts`, `icon.tsx`/`apple-icon.tsx`, `public/sw.js` próprio, `pwa-register.tsx`, `pwa-install-button.tsx`. Sem dependência nova.

## Phase 7: Testes

- [X] T024 `backend/tests/Feature/Chat/WhatsappWebhookTest.php` (6 testes): self-check, payload real cria Contact/ContactInbox/Conversation/Message, payload duplicado não duplica, segunda mensagem do mesmo contato entra na mesma conversa, grupo ignorado, `fromMe` ignorado.
- [X] T025 `backend/tests/Feature/Chat/ConversationFlowTest.php` (6 testes): listar, detalhe marca como lida, vínculo com `Client` por telefone, enviar mensagem, validação de mensagem vazia, autenticação obrigatória. **Achado importante**: `RefreshDatabase` só migra/transaciona `config('database.default')` por padrão — a conexão `chat` precisou ser adicionada em `protected array $connectionsToTransact` na `Tests\TestCase` **base** (não nas classes individuais), porque o flag estático que controla a migração única é global para toda a suíte.
- [X] T026 Testes Vitest em `frontends/chat/tests/` (10 testes: `session.test.ts` + `message-bubble.test.tsx`).

## Phase 8: Documentação e revisão final

- [X] T027 Nota em `documentacao/07-novas-implementacoes/2026-06-27-inbox-whatsapp-tempo-real.md`.
- [X] T028 Rodado `php scripts/php/scaffold-release-note.php` e `php scripts/php/sync-agent-docs.php`. Reproduzido o bug de encoding já conhecido (entrada inserida no fim do `historico-de-versoes.md` em vez do topo) — corrigido manualmente, igual da vez anterior.
- [~] T029 Validação manual: **feito** — fluxo completo via `tinker`/`curl` (webhook simulado → Contact/ContactInbox/Conversation/Message → API lista/detalha/envia → `/broadcasting/auth` autoriza com Bearer e rejeita sem token) e validacoes automatizadas (`php artisan test --filter="(ConversationFlowTest|WhatsappWebhookTest)"`: 23 testes passando; `npm run build` e `npm test` no `frontends/chat` sem erro). **Não feito** — instalação visual do PWA em navegador real e verificação do layout/tempo real end-to-end pela UI, por falta de driver de navegador neste ambiente. Recomenda-se ao usuário abrir `http://localhost:3002` com backend+Reverb rodando para validar visualmente antes de considerar a Fase 1 100% encerrada.

## Phase 9: Evolução v1 mobile-first e timeline unificada

- [X] T030 Adicionar conexão dedicada `sistema_hml` ao backend (`backend/config/database.php`, `.env.example`) e criar resolução de clientes do chat sem depender da conexão default.
- [X] T031 Estender o domínio do chat para anexos reais (`mensagem_anexos`, `content_type`, storage privado, placeholder de falha) e suportar mídia inbound/outbound via Evolution.
- [X] T032 Criar busca de clientes específica do chat (`GET /api/v1/chat/clientes/search`) e permitir início de conversa por `client_id` ou telefone livre com mensagem inicial opcional.
- [X] T033 Evoluir `GET /api/v1/conversas` e `GET /api/v1/conversas/{id}` para incluir `unread_count`, preview da última mensagem, resumo do cliente ERP, tipo da mensagem e resumo de anexos.
- [X] T034 Evoluir `POST /api/v1/conversas/{id}/mensagens` para `multipart/form-data`, aceitando texto, anexos ou ambos, e publicar rota autenticada para download/stream de anexos.
- [X] T035 Unificar o envio de WhatsApp do ERP com `WhatsappMessagingService`, registrando mensagens `outgoing/system` na timeline do chat e migrando os fluxos de OS já existentes no repositório.
- [X] T036 Reaproveitar o `frontends/chat` com UX mobile-first de lista, thread e perfil, incluindo busca, filtros mínimos, composer com upload/preview e renderização de imagem, áudio, vídeo e documento.
- [X] T037 Atualizar `backend/openapi.yaml`, `specs/010-inbox-whatsapp-tempo-real/` e a nota `documentacao/07-novas-implementacoes/2026-06-27-inbox-whatsapp-tempo-real.md` para refletir o corte v1 mínimo.

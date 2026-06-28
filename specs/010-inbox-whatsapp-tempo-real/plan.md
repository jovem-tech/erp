# Implementation Plan: Central de Atendimento — Inbox de WhatsApp em tempo real

**Branch**: `010-inbox-whatsapp-tempo-real` | **Date**: 2026-06-27 | **Spec**: [spec.md](./spec.md)

**Origem**: porta do MIT core do Chatwoot (Account/Inbox/Contact/ContactInbox/Conversation/
Message, fluxo de mensagem entrante via `Whatsapp::IncomingMessageBaseService`), com o
canal WhatsApp adaptado para Evolution API (provider não suportado oficialmente pelo
Chatwoot) e tempo real reconstruído com Laravel Reverb (em vez de ActionCable). Ver plano
arquitetural completo em `C:\Users\jovem\.claude\plans\robust-herding-cookie.md`.

## Summary

Construir o núcleo navegável mínimo da central de atendimento nativa do sistema-erp: banco
de dados próprio (`chat`), entidades Account/Inbox/Contact/ContactInbox/Conversation/Message,
canal WhatsApp entrante (webhook Evolution API → conversa) e saída (resposta do atendente →
Evolution API), tempo real via Laravel Reverb + Laravel Echo, e a nova app Next.js
`frontends/chat` com layout de 3 colunas, instalável como PWA.

## Addendum - v1 minimal UX do ERP

No mesmo ciclo, o plano foi estendido para cobrir a experiencia minima operacional da
central como um "WhatsApp Web/mobile" do ERP. A implementacao final deste corte passa a
incluir:

- conexao dedicada de leitura para `sistema_hml`, usada exclusivamente pela resolucao de
  clientes do chat;
- busca leve de clientes por nome/documento/telefone e inicio de conversa por `client_id`
  ou telefone livre;
- suporte real a midia inbound e outbound, com modelagem propria de anexos em
  `mensagem_anexos`, storage privado e placeholder de falha quando a midia inbound nao puder
  ser baixada;
- servico unico de envio de WhatsApp do ERP, reaproveitado pela central e pelos fluxos
  automaticos do sistema, com persistencia de `outgoing/system` na mesma timeline do chat;
- payloads publicos enriquecidos de conversa/mensagem/anexo no `backend/openapi.yaml`;
- UX mobile-first no `frontends/chat`, com navegacao em uma coluna no smartphone
  (`lista -> conversa -> perfil`) e melhoria progressiva para split view em telas maiores.

## Technical Context

**Language/Version**:
- backend: PHP 8+ com Laravel 13 (mesmo backend central do sistema-erp)
- frontend novo: Next.js 15 + TypeScript (`frontends/chat`, app independente)

**Primary Dependencies**:
- `laravel/reverb` (novo — servidor WebSocket)
- `laravel-echo` + `pusher-js` (novo — cliente WebSocket no Next.js, driver compatível Reverb)
- Reaproveitados sem mudança: `App\Services\Integrations\IntegrationSettingsService`
  (credenciais e envio Evolution API), `App\Support\ApiResponse`,
  `App\Http\Controllers\Api\V1\BaseApiController`

**Storage**:
- Banco **novo e separado**, mesma instância MySQL: conexão Laravel `chat` (`DB_DATABASE`
  própria, ex. `sistema_erp_chat`), ao lado do banco `sistema_erp` existente. FK entre bancos
  (`conversas.cliente_id` → `sistema_erp.clientes.id`) suportada nativamente pelo InnoDB por
  estarem no mesmo servidor.
- Banco legado de consulta somente leitura: conexão Laravel `sistema_hml`, dedicada à busca
  de clientes para o chat.
- Credenciais do canal WhatsApp **não duplicadas**: lidas da tabela `Configuration`
  já existente no banco `sistema_erp` via `IntegrationSettingsService` (achado de Phase 0,
  ver abaixo) — `canais_whatsapp` no banco `chat` guarda só a referência ao inbox, não a
  apikey/instância da Evolution.

**Testing**:
- `php artisan test` no backend (`backend/tests/Feature/Chat/` — pasta nova, paralela a
  `tests/Feature/Api/V1/`)
- Testes novos no `frontends/chat` (Vitest, mesmo padrão de `frontends/mobile`)

**Target Platform**:
- Windows + XAMPP em desenvolvimento
- VPS Linux em produção (processo Reverb via systemd, proxy WebSocket via Nginx)

**Project Type**:
- backend central Laravel (API + webhook + broadcasting), banco próprio
- frontend novo, independente, instalável como PWA

**Performance Goals**:
- mensagem entrante aparece na UI do atendente em poucos segundos (broadcast assíncrono,
  sem polling)
- envio de mensagem não bloqueia a resposta HTTP do atendente (despachado via Job)

**Constraints**:
- sem Redis nesta fase (deduplicação de mensagem via `unique` constraint, não lock
  distribuído)
- sem nova tabela de configuração de canal duplicando credenciais já existentes
- `frontends/chat` não compartilha código em runtime com `frontends/mobile` (apps
  independentes, mesmo padrão de estilo)
- token Bearer/Sanctum como único mecanismo de autenticação, inclusive para o WebSocket
  (sem cookie de sessão)

**Scale/Scope**:
- 1 conta (`contas_atendimento`), 1 inbox WhatsApp, qualquer atendente autenticado pode
  ver/responder qualquer conversa (sem atribuição/times nesta fase)
- não inclui: respostas rápidas, automação de regras, canais adicionais (e-mail/web widget),
  relatórios, SLA, assistente de IA — fases seguintes do roadmap
- timeline única apenas para mensagens novas após a implantação, sem backfill histórico
- sem vínculo manual posterior de contato desconhecido a cliente nesta fase

## Constitution Check

- **Documentação sincronizada**: `backend/openapi.yaml` ganha as rotas novas de
  `/api/v1/conversas/*`; nota de implementação em `documentacao/07-novas-implementacoes/`.
- **pt-BR e UTF-8**: rótulos de status de conversa (`open`→"Aberta" etc.), mensagens de erro
  e toda a UI de `frontends/chat` em pt-BR.
- **Backend central como fonte única**: toda regra de negócio (normalização de telefone,
  vínculo com Client, envio via Evolution) vive no `backend/`; `frontends/chat` só consome
  API e WebSocket.
- **Frontends por canal com responsabilidade clara**: `frontends/chat` é um canal novo,
  Next.js via API (consistente com o princípio 4 da constituição, que já prevê "frontend/ ou
  frontends/mobile podem usar outro stack, mas sempre via API").
- **Segurança e compatibilidade de ambiente**: token Bearer nunca em log; mídia em storage
  privado; compatível Windows/XAMPP (dev) e VPS Linux (produção, processo Reverb via
  systemd).
- **Licenciamento (decisão do plano arquitetural)**: este módulo porta lógica/estrutura do
  Chatwoot (MIT) — `sistema-erp/NOTICE.md` deve creditar a origem antes do merge desta fase.

## Project Structure

### Documentation (this feature)

```text
specs/010-inbox-whatsapp-tempo-real/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code (repository root)

```text
backend/
├── config/database.php                            # + conexão 'chat'
├── config/channels.php                             # novo — registry de Channel
├── config/broadcasting.php                         # novo (via install:broadcasting)
├── database/migrations/chat/                       # novo — migrations da conexão chat
│   └── 2026_06_27_000001_create_chat_core_tables.php
├── app/Models/Chat/
│   ├── Account.php
│   ├── Inbox.php
│   ├── Contact.php
│   ├── ContactInbox.php
│   ├── Conversation.php
│   ├── Message.php
│   └── Channel/Whatsapp.php
├── app/Contracts/ChannelDriverInterface.php        # novo
├── app/Support/Channels/ChannelRegistry.php        # novo
├── app/Services/Channels/Whatsapp/
│   ├── WhatsappChannelDriver.php                   # delega para IntegrationSettingsService::sendEvolutionText
│   ├── IncomingMessageService.php                  # porta de incoming_message_base_service.rb
│   └── PhoneNumberNormalizationService.php         # porta de phone_number_normalization_service.rb
├── app/Services/Chat/ConversationAccessService.php # regra de acesso central (canais privados)
├── app/Events/
│   ├── MessageCreated.php
│   ├── MessageUpdated.php
│   └── ConversationStatusChanged.php
├── app/Jobs/SendWhatsappMessageJob.php
├── app/Http/Controllers/Webhooks/WhatsAppWebhookController.php  # ESTENDER (já existe), não recriar
├── app/Http/Controllers/Api/V1/Chat/
│   ├── ConversationController.php
│   └── MessageController.php
├── routes/api.php                                  # + grupo api/v1/conversas
├── routes/channels.php                             # novo — auth de canais privados (Bearer)
├── openapi.yaml                                    # + contrato de /api/v1/conversas
└── tests/Feature/Chat/
    ├── WhatsappWebhookTest.php
    └── ConversationFlowTest.php

frontends/chat/                                      # app Next.js nova e independente
├── package.json
├── tsconfig.json
├── next.config.ts                                  # + next-pwa
├── public/
│   ├── manifest.webmanifest
│   └── icons/ (192.png, 512.png, maskable.png)
├── .env.example
├── src/app/
│   ├── layout.tsx
│   ├── page.tsx
│   ├── login/page.tsx
│   └── conversas/[id]/page.tsx
├── src/components/conversas/
│   ├── conversation-list.tsx
│   ├── conversation-list-item.tsx
│   ├── conversation-thread.tsx
│   ├── message-bubble.tsx
│   ├── message-composer.tsx
│   └── contact-panel.tsx
├── src/lib/
│   ├── api.ts
│   ├── session.ts
│   ├── conversations.ts
│   ├── echo.ts
│   └── types.ts
└── tests/

sistema-erp/
└── NOTICE.md                                        # novo — atribuição MIT do Chatwoot

shared/
└── version.php                                      # nova versão funcional
```

**Structure Decision**: o backend ganha um sub-domínio novo (`app/Models/Chat/`,
`app/Services/Channels/`) sem tocar nos módulos existentes (Orders/Clients/Financeiro);
`frontends/chat` nasce como app irmã de `desktop`/`mobile`, não como rota dentro deles.

## Phase 0 - Research Decisions

- **Envio Evolution API já existe — não reimplementar**: `IntegrationSettingsService::sendEvolutionText()`
  (`backend/app/Services/Integrations/IntegrationSettingsService.php:731`) já chama
  `POST {evolution_url}/message/sendText/{instance}` com o header `apikey`. O novo
  `WhatsappChannelDriver::sendMessage()` MUST injetar `IntegrationSettingsService` e
  reaproveitar essa chamada (adicionando um método público fino se o privado atual não for
  suficiente), em vez de duplicar a integração HTTP com a Evolution API. Isso reduz
  significativamente o risco da Fase 1 face ao plano arquitetural original (que assumia
  construir o driver Evolution do zero).
- **Webhook entrante já existe — estender, não recriar**: `WhatsAppWebhookController`
  (`backend/app/Http/Controllers/Webhooks/WhatsAppWebhookController.php`), registrado em
  `POST /webhooks/whatsapp` (`routes/web.php:10`), hoje só valida o token
  (`IntegrationSettingsService::webhookToken()`) e loga o payload. Esta fase MUST estender o
  `__invoke()` para, quando `self_check` for falso, repassar o payload ao novo
  `IncomingMessageService`, preservando o comportamento atual de validação de token e a
  resposta ao self-check (usado hoje pela tela de Configurações via
  `selfCheckInbound()`/`directWebhookSelfCheck()` — não pode regredir).
- **Credenciais do canal não são duplicadas**: a Evolution API já tem URL/apikey/instância
  geridos pela tela de Configurações existente, persistidos via `Configuration`
  (key/value) no banco `sistema_erp`. O novo `canais_whatsapp` (banco `chat`) guarda só o
  vínculo Inbox↔tipo de canal, não credenciais — `WhatsappChannelDriver` lê do
  `IntegrationSettingsService` em tempo de execução. Evita dois lugares de configuração
  para a mesma instância Evolution.
- **`display_id` sequencial por conta (MySQL sem sequence nativa)**: adicionar coluna
  `proximo_display_id` (inteiro) em `contas_atendimento`, incrementada dentro da mesma
  transação que cria a `Conversation`, usando `lockForUpdate()` na linha da conta. Simples,
  sem tabela auxiliar, seguro para o volume real esperado (uma assistência técnica, não um
  SaaS multi-tenant de alto tráfego).
- **Processo Reverb em produção**: novo serviço systemd dedicado (`reverb.service`,
  `php artisan reverb:start`), porta própria (ex. `8090`, não conflita com PHP-FPM nem com o
  MySQL); Nginx faz proxy reverso para essa porta num path dedicado (`/app` ou subdomínio),
  com `proxy_set_header Upgrade`/`Connection "upgrade"`. Sem Redis nesta fase (Reverb roda
  como processo único, sem necessidade de scale horizontal).
- **Auth do canal de broadcasting com Bearer (não cookie)**: `routes/channels.php` MUST ser
  carregado dentro do grupo `auth:sanctum` (mesmo padrão de `routes/api.php`), não pelo
  `BroadcastServiceProvider` padrão que assume guard `web`. O cliente Echo no
  `frontends/chat` usa um `authorizer` customizado que injeta `Authorization: Bearer
  <token>` lido de `lib/session.ts` em vez do default baseado em cookie.
- **`frontends/005-pwa-mobile-session` não é reaproveitável para instalabilidade**:
  confirmado lendo `specs/005-pwa-mobile-session/` — "PWA" ali significa sessão Bearer
  persistente (token, CSP, auto-restore), não instalabilidade real; não existe
  `manifest.webmanifest` nem service worker em `frontends/mobile` hoje. `frontends/chat` é o
  primeiro PWA instalável de fato do projeto — sem padrão a herdar nesse ponto específico,
  ainda que o padrão de sessão Bearer (`lib/session.ts`, `lib/api.ts`, CSP em
  `next.config.ts`) seja, sim, reaproveitável como referência de estilo.
- **Web Push (notificação com app fechado) fica fora desta fase**: documentado como
  Assumption em `spec.md` — é um mecanismo adicional ao Reverb, com requisitos próprios
  (VAPID, tabela de inscrições, permissão do navegador); entra como fast-follow imediato
  após esta fase, não bloqueia a entrega do núcleo de atendimento em tempo real.

# 2026-06-28 - Endurecimento de seguranca do chat e das integracoes

## Objetivo

Corrigir a superficie de ataque identificada na auditoria do modulo de integracoes e da
Central de Atendimento sem alterar o banco de dados.

## Correcoes aplicadas

- O webhook `POST /webhooks/whatsapp` agora falha fechado quando o token inbound nao esta
  configurado e tambem recebeu `throttle` para reduzir abuso por repeticao.
- O webhook inbound continua respondendo `ack` para self-check e eventos validos, mas nao
  aceita mais operacao sem segredo compartilhado.
- O download de midia inbound do chat passou a aceitar apenas origens ja confiadas pela
  configuracao ativa do provider (Evolution/Menuia/gateways com credencial preenchida).
- O download de midia inbound agora respeita limite maximo de tamanho via
  `CHAT_INBOUND_ATTACHMENT_MAX_BYTES` (padrao: `25 MiB`).
- A leitura de configuracoes de integracao nao devolve mais segredos em claro para o
  frontend. Os campos sensiveis chegam vazios e o estado fica exposto em `secret_status`.
- Tokens internos do gateway deixaram de ser serializados no payload de leitura das
  integracoes.
- A assinatura de conectividade usada no resumo deixou de expor credenciais concatenadas e
  passou a usar fingerprint hash.
- O health check publico reduziu o payload para o minimo operacional (`status`, `service`
  e `database`), removendo ambiente e versoes de framework.
- O contexto de acesso do chat passou a falhar fechado quando existem multiplas
  `contas_atendimento` sem contexto explicito. Nesta situacao, e obrigatorio configurar:
  - `CHAT_ALLOWED_ACCOUNT_IDS`
  - `CHAT_DEFAULT_ACCOUNT_ID` quando houver mais de uma conta autorizada
- O frontend mobile passou a forcar `postcss >= 8.5.15` via override de `pnpm` para
  eliminar o advisory `GHSA-qx2v-qp2m-jg93`.

## Impacto operacional

- Ambientes que ainda nao definiram `whatsapp_webhook_token` vao rejeitar o webhook inbound
  ate a credencial ser preenchida no painel de integracoes.
- Ambientes com multiplas contas de atendimento precisam declarar explicitamente as contas
  permitidas por ambiente antes de liberar acesso do chat.
- A troca de um segredo salvo no painel de integracoes continua suportada; deixar o campo
  sensivel em branco preserva o valor atual no backend.

## Validacao esperada

- `php artisan test --filter=WhatsappWebhookTest`
- `php artisan test --filter=ConversationFlowTest`
- `php artisan test --filter=ConfigurationIntegrationsTest`
- `php artisan test --filter=AuthFlowTest`
- `pnpm audit --json` em `frontends/mobile`

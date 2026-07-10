# Notificações do Sino (desktop) — emissores, prazos e tempo real

> Atualizado em 2026-07-10. Tabela: `mobile_notifications` (backend). Canal de
> escrita único: `MobileInboxChannel` (via `User::notify(new MobileNotification(...))`).

## O que gera notificação

| Evento | kind | Destinatários |
|---|---|---|
| OS criada | `order.created` | autor + técnico da OS |
| Status da OS atualizado (inclui baixa/entrega, sync de orçamento) | `order.status.updated` | autor + técnico |
| OS editada | `order.updated` | autor + técnico |
| Orçamento criado | `orcamento.created` | autor + técnico da OS vinculada |
| Orçamento enviado ao cliente | `orcamento.sent` | autor + responsável + criador + técnico |
| Orçamento aprovado pelo cliente (link público) | `orcamento.approved` | responsável + criador + técnico |
| Orçamento recusado pelo cliente | `orcamento.rejected` | responsável + criador + técnico |
| Adiantamento/Sinal recebido na OS | `os.advance_received` | autor + técnico |
| Prazo de reparo termina hoje | `order.deadline.today` | técnico + admins ativos |
| Prazo de reparo venceu ontem | `order.deadline.overdue` | técnico + admins ativos |

Despacho para múltiplos usuários: `NotificationDispatchService::toUsers()`
(deduplica ids e filtra inativos). A baixa "de verdade" da OS não tem evento
próprio — já é coberta pela notificação de mudança de status.

Tipos legados (`message.inbound`, `orcamento.public_status_changed`,
`order.client_notification.sent`) eram do sistema antigo; as 222 linhas foram
removidas do banco em 2026-07-10 e nenhum código atual as emite.

## Prazos (comando agendado)

`php artisan app:notify-order-deadlines` — agendado de hora em hora
(`routes/console.php`). Considera apenas OS abertas (fora de
`OrderStatus::closureCodes()` e não canceladas):

- `os.data_previsao` = hoje → "Prazo da OS termina hoje";
- `os.data_previsao` = ontem → "Prazo da OS vencido".

Dedupe: um aviso por OS/tipo/dia (checa `mobile_notifications` por
`tipo_evento` + `rota_destino` + data de criação), então re-execuções no mesmo
dia não duplicam.

## Tempo real (sem reload)

Cadeia: `MobileInboxChannel` grava a linha e emite
`App\Events\NotificationCreated` (ShouldBroadcast + **ShouldDispatchAfterCommit**
— nunca anuncia notificação que sofreu rollback) → fila redis → worker →
Reverb → canal privado `notifications.{userId}` (autorização em
`routes/channels.php`: só o próprio usuário).

No desktop: `layouts/app.blade.php` injeta `window.__DESKTOP_REALTIME`
(config Reverb + userId + URL do proxy de auth) em toda página autenticada;
`desktop.js::initNotificationRealtime()` assina o canal e, ao receber
`notification.created`:

1. incrementa o badge do sino (contagem exata, sem "9+");
2. insere o item no topo da lista do dropdown (se o resumo já foi carregado);
3. mostra o toast padrão do sistema (SweetAlert2, canto superior direito).

O proxy `/broadcasting/auth` do desktop **não tem gate de módulo** de
propósito: a autorização é POR CANAL no backend (`orders` exige
`os:visualizar`; `notifications.{id}` exige ser o próprio usuário).

## Interações do dropdown

- Clicar num item passa por `/notificacoes/{id}/abrir` (marca como lida →
  redireciona ao destino).
- "Marcar todas" (`POST /notificacoes/lidas`) e "Limpar lidas"
  (`POST /notificacoes/limpar-lidas`, remove definitivamente as já lidas,
  permanecendo na mesma página).
- Resumo do sino tem cache de 30s por usuário no desktop
  (`NotificationService::summary`), invalidado ao abrir/marcar/limpar.

## Armadilha conhecida (consertada em 2026-07-10)

O sino ficou ~2 semanas sem funcionar porque `desktop.js` lia
`dataset.notificationSummaryUrl` enquanto o atributo era
`data-desktop-notification-summary-url` (dataset correto:
`desktopNotificationSummaryUrl`) — a URL vinha vazia e o código desistia sem
erro. Ao criar novos `data-*`, conferir a conversão camelCase do dataset.
O `normalize()` do desktop também lia campos inexistentes do contrato
(`criada_em`/`tipo`/`dados`/`icone` vs. `created_at`/`tipo_evento`/`payload`).

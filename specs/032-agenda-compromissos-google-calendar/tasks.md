# Tarefas — Agenda de compromissos com sincronização Google

## Bloco A — Agenda interna  ✅

- [x] Migration `agenda_compromissos` com UNIQUE `(origem_tipo, origem_id)`
- [x] Migration de seed do módulo RBAC `agenda` + slug `ver_todos`
- [x] `agenda` e `ver_todos` em `RbacAuthorizationService`
- [x] Model `AgendaCompromisso` (escopos `pendentes`, `noPeriodo`, `visiveisPara`)
- [x] `AgendaService` (CRUD, resumo, regra de autoridade sobre item gerido)
- [x] `AgendaController` + rotas `/api/v1/agenda`
- [x] Desktop: `AgendaService`, `AgendaController`, rotas, sidebar
- [x] `App\Support\CalendarGrid` extraído e reaproveitado pelo Fluxo de Caixa
- [x] Views: grade mensal, lista, modal de formulário, modal de detalhe
- [x] CSS (claro e escuro) e `agenda.js`
- [x] Card de agenda no dashboard

## Bloco B — Fontes automáticas  ✅

- [x] `AgendaSource`, `AgendaSourceItem`, `AgendaSourceRegistry`
- [x] Tag `agenda.sources` no `AppServiceProvider`
- [x] `ContasPagarSource` / `ContasReceberSource`
- [x] `RetornoPosServicoSource` — dá tela ao `crm_followups` que ninguém lia
- [x] `PrazoOsSource` reaproveitando `DEADLINE_FREEZE_CODES`
- [x] `CobrancaOsSource`
- [x] `AgendaSourceReconciler` + `agenda:sincronizar-origens` no scheduler

## Bloco C — Sincronização Google  ✅

- [x] `GoogleCalendarSettingsService` com segredos cifrados
- [x] `GoogleCalendarClient` (REST via `Http`)
- [x] `GoogleCalendarConnectionService` (escopo `calendar.app.created`, `state` único)
- [x] `GoogleCalendarPushService` + `PushAgendaCompromissoToGoogleJob`
- [x] `GoogleCalendarPullService` com anti-loop por etag e resync em `410`
- [x] `agenda:sincronizar-google` no scheduler (5 min)
- [x] `AgendaGoogleController` + rotas (callback fora de `auth:sanctum`)
- [x] Sub-aba "Google Agenda" em Integrações, com saída para refresh token manual

## Bloco D — Visões dia, semana, mês e ano  ✅

- [x] `CalendarGrid::week()`, `::days()`, `::year()`, `dayLabel()`, `weekLabel()`
- [x] `App\Support\AgendaTimeGrid` — posicionamento por hora e clusters de sobreposição
- [x] Cursor único `data` (Y-m-d), com `mes` aceito por compatibilidade
- [x] Navegação na unidade da visão (dia/semana/mês/ano)
- [x] `_grade_horaria.blade.php` compartilhada por Dia e Semana
- [x] Faixa de dia inteiro fixa no topo, separada da coluna de horas
- [x] `_ano.blade.php` com mapa de densidade em cinco níveis
- [x] `_evento_chip.blade.php` — estado do compromisso decidido num lugar só
- [x] Rolagem inicial no primeiro compromisso do período
- [x] CSS claro e escuro; camadas `sticky` com ordem explícita

## Testes  ✅

- [x] `AgendaApiTest` — 10 testes (CRUD, RBAC, autoridade, resumo)
- [x] `AgendaSourceReconcilerTest` — 9 testes (4 transições, idempotência, janela)
- [x] `GoogleCalendarSyncTest` — 13 testes (push, pull, anti-loop, 410, cifra)
- [x] `Desktop\AgendaTest` — 13 testes (sidebar, permissão, as cinco visões, navegação)
- [x] `Unit\AgendaTimeGridTest` — 10 testes (posicionamento e sobreposição)

## Documentação e entrega

- [x] `backend/openapi.yaml`
- [x] `documentacao/07-novas-implementacoes/2026-08-22-modulo-agenda-google-calendar.md`
- [ ] `./scripts/bump-version.sh --tier=minor`

## Pendente do usuário (externo)

- [ ] Google Cloud Console: ativar Calendar API, criar credencial OAuth Web,
      registrar o redirect URI de produção
- [ ] Conectar em Configurações → Integrações → Google Agenda (em produção)

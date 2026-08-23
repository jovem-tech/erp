# Plano — Agenda de compromissos com sincronização Google

Guardrail do `AGENTS.md`: o backend é a fonte única de verdade; o desktop
consome apenas a API central.

```
                    ┌──────────────── backend ────────────────────────────────┐
Financeiro ─┐       │                                                         │
CrmFollowup ├──> AgendaSourceRegistry ──> agenda_compromissos <──> Google      │
Order       │       (reconciliação)             ▲              Calendar API    │
Cobranças  ─┘                                   │                    ▲         │
                    │                      API v1 /agenda            │         │
                    └───────────────────────────┼────────────────────┼─────────┘
                                                │                    │
                             desktop AgendaService          📱 alarme no celular
                                                │
                                   Sidebar > Agenda
```

## Backend

### Banco

`agenda_compromissos` (migration `2026_08_22_000004`). Colunas que carregam
decisão:

| Coluna | Papel |
|---|---|
| `origem_tipo` + `origem_id` | ponteiro para o registro de origem. **UNIQUE** — é o que torna a reconciliação idempotente. |
| `tipo` | `manual` ou a chave da fonte. String, não ENUM: módulo novo não deve exigir `ALTER TABLE`. |
| `google_etag` + `google_sync_hash` | as duas travas anti-loop. |
| `google_sync_estado` | `pendente`/`sincronizado`/`erro`/`desligado`. `desligado` é o que permite a agenda funcionar sem Google e subir tudo ao conectar. |
| `lembrete_minutos` | vira `reminders.overrides`; é o que faz o celular tocar. |

`2026_08_22_000005` semeia o módulo RBAC `agenda` com
`visualizar/criar/editar/excluir` + o slug próprio `ver_todos`. Concede
visualizar/criar/editar a todo grupo que já tem `dashboard:visualizar`;
`ver_todos` e `excluir` só à administração.

### Motor de fontes (ponto de extensão)

```
App\Services\Agenda\Sources\
  AgendaSource          interface: key(), label(), icon(), collect(de, ate)
  AgendaSourceItem      DTO: o retrato de uma obrigação
  AgendaSourceRegistry  catálogo, populado por tag no AppServiceProvider
```

**Para ligar um módulo novo à agenda:** implemente `AgendaSource` e acrescente a
classe à tag `agenda.sources` no `AppServiceProvider`. Nada no motor muda.

Fontes iniciais: `ContasPagarSource`, `ContasReceberSource` (ambas sobre
`ContasVencimentoSource`), `RetornoPosServicoSource`, `PrazoOsSource`,
`CobrancaOsSource`.

`PrazoOsSource` reaproveita `OrderStatus::DEADLINE_FREEZE_CODES` — a mesma
definição de "OS ainda tem prazo a cumprir" usada por
`app:notify-order-deadlines`. Duplicar a lista faria sino e agenda divergirem no
dia em que um status novo fosse acrescentado.

### Reconciliação

`AgendaSourceReconciler` + `agenda:sincronizar-origens` (scheduler, 15 min,
janela −30/+180 dias). Converge, não importa:

| Situação | Ação |
|---|---|
| fonte tem, agenda não | cria |
| fonte tem, agenda tem | atualiza o que mudou |
| fonte diz resolvido | conclui (a conta foi paga) |
| fonte não reporta mais, dentro da janela | cancela |
| obrigação volta a valer | **reabre** — nunca insere de novo (a UNIQUE impede) |
| `tipo = manual` | nunca é tocado |

Fora da janela nada é cancelado: ausência ali significa "não foi consultado",
não "não existe mais".

### Google

`App\Services\Agenda\Google\`:

- `GoogleCalendarSettingsService` — credenciais e estado em `configuracoes`;
  `client_secret` e `refresh_token` cifrados com `Crypt` (APP_KEY).
- `GoogleCalendarClient` — REST direto pela facade `Http`. Sem `google/apiclient`:
  são seis endpoints, e o SDK traz um grafo grande de dependências com
  restrições próprias que passariam a limitar as atualizações do Laravel aqui.
- `GoogleCalendarConnectionService` — consentimento (`state` de uso único, TTL
  15 min) e criação do calendário dedicado.
- `GoogleCalendarPushService` / `GoogleCalendarPullService`.
- `AgendaEventPayload` — tradução e cálculo do hash de conteúdo.

**Anti-loop, duas travas:**

1. Antes de empurrar, compara o hash do conteúdo local com `google_sync_hash`.
   Igual → não empurra.
2. Ao puxar, se o `etag` recebido é o que gravamos no nosso último push, o
   evento é eco nosso → ignora.

Só entra no hash o que de fato viaja para o Google, então mudar um campo interno
(`concluido_por`) não dispara push.

**Conflito:** item manual, last-write-wins por `updated`. Item gerido, o ERP
reafirma a verdade empurrando de volta — arrastar o card de um vencimento no
celular não muda a data em que a conta vence.

**`410 Gone`** no `syncToken` não é falha: é o sinal para refazer a carga
completa (janela de 90 dias).

### API

`/api/v1/agenda` (CRUD, resumo, concluir, reabrir) e `/api/v1/agenda/google/*`
(status, credenciais, conectar, callback, conectar-manual, desconectar,
sincronizar). O **callback fica fora de `auth:sanctum`**: quem chega nele é o
navegador redirecionado pelo Google, sem o Bearer do desktop — o que autentica a
chamada é o `state` de uso único.

## Desktop

- `DesktopNavigation`: item **Agenda** na seção Visão Geral, logo abaixo de
  Dashboard.
- `AgendaService` (via `ApiClient`) + `AgendaController` + views em
  `resources/views/agenda/`.
- **`App\Support\CalendarGrid`** — grade mensal extraída de
  `FinanceiroReportController::buildCalendar()` e agora compartilhada pelas duas
  telas. Sem biblioteca JS de calendário: mantém o padrão server-rendered do
  sistema.
- Card "Agenda" no dashboard, exibido só quando há algo a resolver.
- Sub-aba **Google Agenda** em Configurações → Integrações.

## Pré-requisito externo

Google Cloud Console: ativar a Calendar API, criar credencial OAuth "Aplicativo
da Web" e registrar o redirect URI.

⚠️ **O Google recusa IP privado como redirect URI.** `https://192.168.1.100:8443`
não pode ser cadastrado. Conecte pela produção
(`https://api-erp.jovemtech.eco.br/api/v1/agenda/google/callback`) ou use o campo
"colar refresh token" da tela de integrações.

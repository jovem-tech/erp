# Módulo Agenda com sincronização Google (2026-08-22)

Spec: `specs/032-agenda-compromissos-google-calendar/`

## O que mudou

O sistema passou a ter uma **Agenda** — tela na sidebar, logo abaixo do
Dashboard — que concentra compromissos, obrigações e lembretes, e a espelha num
calendário dedicado do Google para que o alarme chegue ao celular.

Antes, cada obrigação vivia num canto diferente do sistema. Uma delas não vivia
em canto nenhum: o **retorno pós-serviço** marcado na baixa da OS era gravado em
`crm_followups` por `OrderClosureService::createReturnFollowup()` e **nenhuma
tela do sistema lia essa tabela**. O compromisso era assumido com o cliente e
esquecido por construção.

## Isolamento do Google — o ponto que mais importa

O ERP cria um **calendário secundário próprio** chamado "Agenda ERP" dentro da
conta conectada e pede o escopo
`https://www.googleapis.com/auth/calendar.app.created`, que autoriza o app a ver
e editar **apenas calendários criados por ele mesmo**.

Os calendários pessoais da conta são inacessíveis ao sistema — não por disciplina
do nosso código, mas por regra do próprio Google. E a via de volta continua
aberta: um evento criado no celular *dentro do calendário "Agenda ERP"* é
importado para o sistema em até 5 minutos.

## As cinco visões

A tela abre em **Mês** e alterna entre cinco modos, preservando o dia que se
está olhando e os filtros ativos:

| Visão | O que mostra |
|---|---|
| **Dia** | Grade de 24 horas de um dia, com faixa de dia inteiro no topo |
| **Semana** | Os sete dias (segunda a domingo) lado a lado, mesma grade horária |
| **Mês** | Grade mensal com os compromissos listados em cada dia |
| **Ano** | Doze mini-meses; a intensidade da cor indica os dias mais carregados |
| **Lista** | Tudo do período em ordem cronológica, agrupado por data |

A navegação anda na unidade da visão corrente: em Dia o botão avança um dia, em
Semana sete, em Mês um mês, em Ano um ano.

### Cursor único de navegação

Todas as visões compartilham o parâmetro `data` (`Y-m-d`). É o que permite ir de
Semana para Mês sem perder a data em foco. O parâmetro antigo `mes` (`Y-m`)
continua aceito para não quebrar link salvo.

### Grade horária (Dia e Semana)

Uma partial só (`_grade_horaria.blade.php`) serve as duas visões — a diferença
entre elas é o número de colunas. Duplicar o arquivo faria as duas divergirem no
primeiro ajuste de altura de hora.

**Compromissos de dia inteiro não entram na coluna de horas.** Vencimentos e
prazos de OS vão para a faixa fixa do topo, como no Google Agenda: eles valem o
dia todo, e colocá-los numa linha de horário mentiria sobre o dado.

**Sobreposição** (`App\Support\AgendaTimeGrid`): compromissos que se cruzam no
tempo dividem a largura da coluna. O cálculo é por **cluster**, não par a par —
A(9–10), B(9:30–11) e C(10:30–12) se encadeiam sem que A e C se toquem, mas os
três precisam da mesma largura, senão C ficaria por cima de B. A e C, que não se
sobrepõem, reusam a mesma coluna.

Detalhes que o uso real exigiu:

- bloco de até 45 min deita hora e título na mesma linha; empilhados, o título
  ficaria sob o `overflow` e o compromisso viraria só um horário;
- evento sem fim assume uma hora; abaixo de 30 min o bloco recebe altura mínima
  legível;
- evento que atravessa a meia-noite é cortado no fim do dia — sem isso, um que
  fecha 01:00 do dia seguinte teria altura negativa;
- a grade rola dentro da própria caixa e **abre no primeiro compromisso do
  período** (ou às 07:00 quando não há nenhum), em vez de na meia-noite;
- cabeçalho de dias, faixa de dia inteiro e coluna de horas são `sticky`, com
  ordem de camada explícita — sem ela o rótulo de hora vaza sobre a faixa no
  canto superior esquerdo durante a rolagem.

### Visão de ano

Não cabe título de compromisso num mini-mês, então cada dia mostra **densidade**
em cinco níveis. A escala é relativa ao pico do próprio ano: um ano de pico 3 e
outro de pico 30 não podem pintar o mesmo tom para "1 compromisso". Clicar num
mês abre a visão de mês; clicar num dia abre a visão de dia.

## Fontes automáticas

| Fonte | Lê | Chave |
|---|---|---|
| Contas a pagar | `financeiro` (tipo=pagar) por `data_vencimento` | `conta_pagar` |
| Contas a receber | `financeiro` (tipo=receber) | `conta_receber` |

> **Obrigação é saldo, não é lançamento.** As duas fontes ignoram título de
> valor zero e tratam como resolvido o que não tem mais saldo em aberto — ver
> "Título sem saldo não é compromisso" abaixo.
| Retorno pós-serviço | `crm_followups` | `retorno_pos_servico` |
| Prazo de reparo | `os.data_previsao`, excluindo `OrderStatus::DEADLINE_FREEZE_CODES` | `prazo_os` |
| Cobrança automática | `os_cobranca_agendamentos` | `cobranca_os` |

### Como ligar um módulo novo à agenda

1. Crie uma classe em `backend/app/Services/Agenda/Sources/` que implemente
   `AgendaSource` (`key`, `label`, `icon`, `collect`).
2. Acrescente a classe à tag `agenda.sources` no `AppServiceProvider`.

Nada mais. O motor, a tela, os filtros e o sync passam a tratá-la sozinhos.

Uma fonte **não escreve** na agenda: ela só descreve o que existe na janela
pedida. Criar, atualizar, concluir e cancelar são decisões do
`AgendaSourceReconciler`, que compara o retorno da fonte com o que já está
gravado. É por isso que o comando pode rodar de 15 em 15 minutos, duas vezes em
paralelo ou depois de meses parado, sempre com o mesmo resultado.

## Quando a obrigação chega na agenda

Há dois caminhos, e os dois existem por um motivo:

**Varredura periódica** (`agenda:sincronizar-origens`, de 15 em 15 minutos) —
mantém a agenda coerente com o resto do sistema: pega o que foi criado por fora,
conclui o que foi resolvido, cancela o que deixou de valer. Horizonte de −60 a
+400 dias.

**Reflexo imediato** (`AgendaSourceReconciler::reconcileForDate()`) — chamado
pelo módulo que acabou de criar a obrigação. A janela acompanha a data pedida,
então funciona **em qualquer horizonte**, inclusive além dos 400 dias.

Hoje quem usa é a baixa da OS, ao agendar o retorno pós-serviço. Para ligar
outro módulo ao caminho imediato basta uma linha depois de gravar o registro:

```php
$this->agendaReconciler->reconcileForDate('conta_pagar', $vencimento);
```

`reconcileForDate()` nunca lança: a agenda é um espelho, e uma falha nela não
pode derrubar a operação que criou a obrigação.

### Duas armadilhas que já custaram caro

**O horizonte da varredura era 180 dias — exatamente o padrão de retorno
pós-serviço** (`OrderClosureService::RETURN_FOLLOWUP_DEFAULT_DAYS`). O padrão da
tela caía na borda, e um dia além dela a obrigação existia em `crm_followups` e
simplesmente não aparecia na agenda até o tempo passar. Daí o horizonte de 400
dias, casado com o teto de janela da listagem.

**O horizonte estava escrito em dois lugares** — na constante do reconciliador e
no valor padrão da opção `--dias-a-frente` do comando. Ampliar a constante não
mudou nada, porque a execução agendada continuava mandando 180. As opções do
comando agora nascem vazias e servem só para sobrescrever pontualmente.

### Título sem saldo não é compromisso

A baixa da OS grava um lançamento de cobrança **mesmo quando não sobra nada a
receber** — garantia, sem custo, devolvido sem reparo. São títulos de R$ 0,00
com status `pendente`. Na base real eram **13 de 30** contas a receber na
agenda: linhas de "Receber: Cobrança da OS …" para algo que ninguém jamais
precisaria fazer. Um dia chegou a exibir 12 itens, quase todos ruído.

Duas regras corrigem isso, e valem para pagar e receber:

- **valor ≤ 0 não entra na coleta.** Não é obrigação: não há o que pagar nem o
  que cobrar. Como a fonte deixa de reportá-lo, o reconciliador cancela sozinho
  os que já tinham sido criados — sem código novo.
- **resolvido é saldo ≤ 0**, não apenas `status = pago`. Cobre o título
  liquidado por movimentos cujo status não acompanhou: ele parava de exigir ação
  e continuava na agenda.

> A agenda expôs um defeito que não era dela: encerramento sem cobrança criava
> título a receber no valor da OS. Ver
> [2026-08-23-cobranca-indevida-em-encerramento-sem-cobranca.md](2026-08-23-cobranca-indevida-em-encerramento-sem-cobranca.md).

A descrição passou a mostrar **o que falta**, não o valor de face: "R$ 586,00"
num título com R$ 100,00 já recebidos levava a cobrar o valor errado. E a
prioridade acompanha o saldo — um título de R$ 5.000 com R$ 50 em aberto não
merece destaque.

## Autoridade sobre o dado

Compromisso gerado por uma fonte (`gerido: true`) tem título e data pertencentes
ao módulo de origem:

- edição de título/data pela API é **ignorada** (observação, prioridade e
  lembrete continuam editáveis);
- exclusão é **bloqueada** (`422 AGENDA_DELETE_BLOCKED`) — o item voltaria na
  reconciliação seguinte, porque a obrigação continua existindo;
- alteração feita no celular é **desfeita**: o ERP reafirma a própria verdade
  empurrando de volta. Arrastar o card de um vencimento no telefone não muda a
  data em que a conta vence.

## Anti-loop do sync bidirecional

Todo push nosso gera um `updated` no Google. Sem tratamento, o pull seguinte
leria esse eco como edição do usuário e reescreveria o item, o que dispararia
outro push — indefinidamente. Duas travas cortam isso:

1. antes de empurrar, o hash do conteúdo local é comparado com
   `google_sync_hash`; iguais, não empurra;
2. ao puxar, se o `etag` recebido é o que gravamos no último push, o evento é eco
   nosso e é ignorado.

Só entra no hash o que de fato viaja para o Google, então mudar um campo interno
(ex.: `concluido_por`) não dispara sincronização.

`410 Gone` no `syncToken` não é falha: é o sinal de que a próxima leitura precisa
ser completa (janela de 90 dias).

## RBAC

Módulo `agenda`, com `visualizar`, `criar`, `editar`, `excluir` e o slug próprio
**`ver_todos`**.

Sem `ver_todos`, o usuário enxerga os próprios compromissos **e os que ainda não
têm responsável** — uma obrigação que ninguém assumiu não pode ficar invisível
para todo mundo. A migration concede visualizar/criar/editar a todo grupo que já
tem `dashboard:visualizar`.

## Agendamentos (scheduler)

| Comando | Frequência | Papel |
|---|---|---|
| `agenda:sincronizar-origens` | 15 min | reconcilia as fontes automáticas |
| `agenda:sincronizar-google` | 5 min | push do pendente + pull incremental |

Ambos saem cedo sozinhos quando a tabela não existe ou o Google não está
conectado.

## Configuração no Google Cloud Console

1. Ative a **Google Calendar API** no projeto.
2. Crie uma credencial OAuth do tipo **Aplicativo da Web**.
3. Registre o redirect URI mostrado na tela de integrações
   (`https://api-erp.jovemtech.eco.br/api/v1/agenda/google/callback` em produção).
4. Em Configurações → Integrações → **Google Agenda**, salve Client ID e Secret e
   clique em "Conectar com o Google".

### Qual conta está vinculada

Conectado, o painel mostra **"Vinculado à conta \<e-mail\>"** logo abaixo do
título da sub-aba, e a tela da Agenda repete o e-mail no card do Google. É a
primeira dúvida de quem abre a tela — ainda mais com várias contas Google no
mesmo navegador —, e é o celular dessa conta que vai tocar.

O e-mail vem do escopo `userinfo.email`, pedido junto do consentimento. Se a
captura falhar no momento de conectar (rede, ou consentimento sem esse escopo),
`GoogleCalendarConnectionService::resolveAccountEmail()` busca de novo na
primeira leitura de status e grava. Sem essa recuperação, a tela mostraria "—"
para sempre e a única saída seria desconectar e refazer todo o consentimento só
para descobrir de qual conta se tratava.

Duas garantias: a busca nunca derruba a tela, e **nunca grava vazio por cima de
um e-mail já conhecido** — uma falha pontual apagaria a informação boa.

> ⚠️ **O Google recusa IP privado como redirect URI.** O ambiente de bancada
> (`https://192.168.1.100:8443`) **não pode** ser cadastrado no Cloud Console.
> Conecte pela produção, ou obtenha o refresh token por fora e use o campo
> "colar refresh token" da própria tela (`/agenda/google/conectar-manual`).

### Por que "Conectar" é um link, e não um botão de formulário

O desktop declara `form-action 'self'` (ver
`frontends/desktop/app/Http/Middleware/SecurityHeaders.php`). O Chrome aplica
essa diretiva **também ao redirecionamento que um envio de formulário segue** —
e não só à URL de destino do `<form>`. Como a rota de conexão termina num 302
para `accounts.google.com`, o POST era bloqueado antes de sair do navegador:

```
Sending form data to '.../agenda-google/conectar' violates the following
Content Security Policy directive: "form-action 'self'". The request has been blocked.
```

A rota é `GET` e o controle na tela é um `<a>`: navegação por link não passa por
`form-action`. As demais ações (salvar credenciais, desconectar, sincronizar)
seguem POST, porque redirecionam de volta para a própria origem.

O link abre em **aba nova** (`target="_blank"`). Levar a aba do painel para fora
do domínio dispararia o `pagehide` que o guard de sessão lê como "navegador
fechado", e o usuário voltaria deslogado.

A aba de retorno **não se fecha sozinha**: `window.close()` só funciona em janela
que o próprio script abriu, e esta nasce de um link. A página pede o fechamento
em vez de prometer o que o navegador recusaria.

As credenciais do Google Agenda são **separadas** das `portal_google_*` já
existentes (Portal do Cliente): outro consentimento, outro escopo, outro ciclo de
vida. Podem vir do mesmo projeto no Cloud Console; não são a mesma configuração.

O `client_secret` e o `refresh_token` ficam cifrados em repouso com o `APP_KEY`.

## Arquivos principais

**Backend**
- `database/migrations/2026_08_22_000004_create_agenda_module_tables.php`
- `database/migrations/2026_08_22_000005_seed_agenda_module.php`
- `app/Models/AgendaCompromisso.php`
- `app/Services/Agenda/AgendaService.php`, `AgendaSourceReconciler.php`
- `app/Services/Agenda/Sources/` (interface, DTO, registry e 5 fontes)
- `app/Services/Agenda/Google/` (settings, client, connection, push, pull, payload)
- `app/Jobs/Agenda/`, `app/Console/Commands/Agenda/`
- `app/Http/Controllers/Api/V1/AgendaController.php`, `AgendaGoogleController.php`

**Desktop**
- `app/Support/CalendarGrid.php` — grades de mês, semana, dia e ano; a mensal foi
  extraída do relatório de Fluxo de Caixa e é compartilhada pelas duas telas
- `app/Support/AgendaTimeGrid.php` — posicionamento por hora e divisão de largura
  entre compromissos sobrepostos
- `app/Services/AgendaService.php`, `app/Http/Controllers/AgendaController.php`
- `resources/views/agenda/` — `_grade_horaria` (dia e semana), `_calendario`
  (mês), `_ano`, `_lista`, `_evento_chip` (único ponto onde as classes de estado
  do compromisso são decididas)
- `public/assets/js/agenda.js`, blocos "Agenda" em `desktop.css` e `themes/dark.css`

## Testes

| Arquivo | Testes | Cobre |
|---|---|---|
| `backend/tests/Feature/Agenda/AgendaApiTest.php` | 10 | CRUD, RBAC, `ver_todos`, autoridade, resumo |
| `backend/tests/Feature/Agenda/AgendaSourceReconcilerTest.php` | 9 | 4 transições, idempotência, reabertura, janela |
| `backend/tests/Feature/Agenda/GoogleCalendarSyncTest.php` | 13 | push, pull, anti-loop, 410, dia inteiro, cifra |
| `frontends/desktop/tests/Feature/Desktop/AgendaTest.php` | 13 | sidebar e ordem, permissão, as cinco visões, navegação por unidade, cursor inválido |
| `frontends/desktop/tests/Unit/AgendaTimeGridTest.php` | 10 | posicionamento, sobreposição transitiva, altura mínima, virada do dia |

## Fora de escopo

Agenda no frontend mobile; convites e participantes; recorrência criada pelo ERP
(recorrência criada no Google é importada expandida em instâncias).

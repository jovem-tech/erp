# Agenda de compromissos com sincronização Google

## Problema

O sistema **gerava** obrigações e não tinha **onde mostrá-las**. Cada uma morava
num canto diferente, e uma delas simplesmente desaparecia:

| Obrigação | Onde nascia | Onde aparecia |
|---|---|---|
| Retorno pós-serviço | `OrderClosureService::createReturnFollowup()` grava em `crm_followups` | **Em lugar nenhum.** Nenhuma tela do sistema lia essa tabela. |
| Vencimento de conta a pagar/receber | `financeiro.data_vencimento` | Só na listagem do Financeiro, misturado a tudo |
| Prazo de reparo da OS | `os.data_previsao` | Sino (`app:notify-order-deadlines`), some ao ser lido |
| Cobrança automática | `os_cobranca_agendamentos.enviar_em` | Nada visível |

O caso do retorno pós-serviço era o mais grave: o operador marcava o toggle na
tela de baixa da OS, o sistema gravava a linha, e ninguém nunca mais via aquilo.
Um compromisso assumido com o cliente que o sistema esquecia por construção.

Faltava também o outro lado: nenhum desses avisos saía do computador. Quem
fechava o navegador não era lembrado de nada.

## Objetivo

Uma tela **Agenda**, logo abaixo do Dashboard, que concentra compromissos,
obrigações e lembretes — automáticos e manuais — e espelha tudo num calendário
dedicado do Google, para que o alarme chegue ao celular.

## Decisões

1. **Conta Google única da empresa**, não por usuário.
2. **Sincronização bidirecional, mas isolada.** O ERP nunca lê os calendários
   pessoais de quem conectou a conta.
3. **Compromisso pessoal e atribuível**: cada item tem um responsável.
4. **Fontes automáticas extensíveis**: um módulo novo entra na agenda sem que o
   motor mude.

### Como o isolamento é garantido

O ERP cria um **calendário secundário próprio** ("Agenda ERP") dentro da conta e
pede o escopo OAuth `https://www.googleapis.com/auth/calendar.app.created`, que
autoriza o app a ver e editar **apenas calendários criados por ele mesmo**.

Não é disciplina de código: é o próprio Google que barra o acesso ao resto. E
ainda assim o caminho de volta continua aberto — um evento criado no celular
*dentro do calendário "Agenda ERP"* é importado para o sistema.

## Requisitos

### Agenda

- Cinco visões — **dia, semana, mês, ano e lista** — com filtro por tipo e por
  situação, no espírito do Google Agenda.
- Dia e semana em grade de 24 horas, com os compromissos de dia inteiro numa
  faixa separada no topo e sobreposições dividindo a largura da coluna.
- Ano como mapa de densidade: onde o ano aperta, sem precisar abrir mês a mês.
- Navegação anda na unidade da visão corrente e preserva a data em foco ao
  trocar de visão.
- Barra de estado: atrasados, hoje, próximos 7 dias.
- Compromisso manual: criar, editar, concluir, reabrir, excluir.
- Lembrete em minutos, que vira alarme no celular via Google.
- Deep-link para a OS e para o cliente de origem.

### Autoridade sobre o dado

- Compromisso gerado por uma fonte (`gerido: true`) **não** aceita edição de
  título nem de data — quem manda é o registro de origem. Observação, prioridade
  e lembrete continuam editáveis.
- Compromisso gerido **não pode ser excluído**: voltaria na reconciliação
  seguinte, porque a obrigação continua existindo.

### Visibilidade

- Sem `agenda:ver_todos`, o usuário vê os próprios compromissos e os que ainda
  não têm responsável (uma obrigação que ninguém assumiu não pode ficar
  invisível para todos).
- Com `agenda:ver_todos`, vê e opera a agenda de qualquer responsável.

### Sincronização

- Push ERP → Google a cada escrita, por job idempotente.
- Pull Google → ERP a cada 5 minutos, incremental por `syncToken`, restrito ao
  calendário dedicado.
- **Anti-loop obrigatório**: todo push nosso gera um `updated` no Google; sem
  tratamento, o pull seguinte leria esse eco como edição do usuário e
  reescreveria o item, gerando outro push, indefinidamente.
- Conflito em item manual: vence a edição mais recente.
- Conflito em item gerido: o ERP é autoritativo e reafirma a própria verdade.

## Fora de escopo

- Agenda no frontend mobile (`frontends/mobile`).
- Convites e participantes de evento.
- Recorrência criada pelo ERP (recorrência criada no Google é importada expandida
  em instâncias).

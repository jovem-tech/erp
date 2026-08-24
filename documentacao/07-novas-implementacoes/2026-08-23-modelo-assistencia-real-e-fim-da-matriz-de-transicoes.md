# Modelo da Assistência passa a refletir o sistema; matriz de transições sai da tela

**Data:** 23/08/2026
**Versão:** `5.49.1.0`
**Status:** ativo no ambiente de desenvolvimento LAN; não publicado na VPS de produção

## Objetivo

As duas telas da seção *Processos e Modelos* que descrevem o fluxo de OS descreviam
um sistema que não existe.

**`/conhecimento/modelo-assistencia-tecnica`** afirmava, em texto fixo, coisas que
nunca foram implementadas:

| Afirmação da página | Realidade verificada |
| --- | --- |
| SLA de 15 min na triagem, 30 min no diagnóstico, 24 h no orçamento | `os_status` não tem coluna de prazo; nada mede ou escalona |
| "WIP técnico: 3" | a string `WIP` aparecia em **um** arquivo do repositório: o próprio controller |
| "Prioridade por aging", "Escalonamento automático" | não implementados |
| Trilha com "Qualidade" e "Pós-venda" | não existem no catálogo de status |
| "Diagnóstico", "Orçamento", "Autorização", "Reparo", "Execução" | nomes reais são *Diagnóstico Técnico*, *Aguardando Orçamento*, *Aguardando Autorização*, *Aguardando Reparo*, *Em Execução do Serviço* |

**`/conhecimento/fluxo-os`** era o oposto: 100% fiel ao banco, mas montada em torno de
uma máquina de estados que o próprio backend abandonou. A
[decisão de produto de 09/08/2026](../../backend/app/Services/Orders/OrderWorkflowService.php)
(comentário em `OrderWorkflowService::changeStatus`) já registrava o motivo: *"o técnico
avança várias etapas do atendimento antes de mexer no sistema, então uma máquina de
estados rígida não reflete o fluxo real"*. Desde então `os_status_transicoes` não trava
nada — só alimenta `proximas_etapas` como sugestão em destaque na UI.

## O que mudou

### 1. Novo `App\Support\OrderStatusMacroGroups` (desktop)

Rótulo, descrição, cor e estado de fluxo das macrofases (`os_status.grupo_macro`)
saíram de métodos privados de `OrderStatusFlowController` para uma classe compartilhada.
As duas telas leem o mesmo catálogo e precisavam chamar as fases pelo mesmo nome.
Aproveitado para corrigir `flowStateLabel()`: `pronto` e `cancelado` existem no banco e
faltavam no `match`, caindo no `humanizeSlug()` por acidente.

### 2. `Modelo da Assistência Técnica` — atualizado

Todo número e todo nome de etapa agora **saem do catálogo vivo**; o texto fixo se limita
a comportamento que existe no código.

- **Indicadores**: em vez de SLA/WIP inventados, quatro contagens reais — status ativos,
  macrofases, status de pausa e saídas finais.
- **Raias**: uma por macrofase real, com os status ativos de cada uma, o
  `estado_fluxo_padrao` que gravam na OS e a marcação de pausa/final. Saíram os campos
  `timebox`, `owner`, `entry`, `exit` e `risk` das raias — o catálogo não tem esses dados.
- **Princípios** (6): reescritos para descrever só o que o sistema aplica — status
  sugerido mas nunca imposto, encerramento exclusivo da baixa, pausa como estado
  declarado, saída classificada em quatro grupos, garantia com via própria e evento
  gravado a cada mudança de status.
- **Tabela de regras**: passou a listar o que o código garante (status + estado de fluxo
  gravados juntos, pausa visível, baixa fecha a OS, evento por mudança, prazo na OS via
  `data_previsao`). O que depende de disciplina do time saiu.
- **Bloco "Caso feliz"**: continua resolvendo os status pelo `codigo` real. Removidos os
  13 `timebox` fixos que ele também carregava, mais duas frases que citavam SLA e WIP.

O título perdeu o "Ideal": a página descreve o que o sistema faz, não o que se gostaria.

### 3. `Fluxo de Trabalho OS` → `Status de OS` — máquina de estados removida

Removidos da tela o diagrama "Leitura operacional do fluxo" e a "Matriz operacional de
transições", junto com a rota `knowledge.os-flow.transitions.update`, o método
`OrderStatusFlowController::updateTransitions()` e o proxy
`DesktopOrderStatusFlowService::updateTransitions()`. O controller caiu de 588 para 238
linhas; a view, de 632 para 359.

**Mantido o cadastro de status** — é o único lugar do sistema que cria, renomeia ou
desativa os status de OS, e eles alimentam o módulo inteiro. A tela virou exatamente
isso: o catálogo agrupado por macrofase. Renomeada para *Status de OS* e movida de
*Processos e Modelos* para *Administração*, ao lado de Configurações do Sistema, porque
edita comportamento de produção e não é material de leitura.

O módulo RBAC continua `conhecimento` de propósito: trocar para `configuracoes` tiraria
o acesso de quem usa a tela hoje.

## O que NÃO mudou (e por quê)

- **A API central continua expondo `PATCH /knowledge/os-flow/transitions`** e
  `os_status_transicoes` segue alimentando `proximas_etapas` nas 7 telas de OS que
  mostram etapas sugeridas. Só o editor saiu do desktop. Efeito colateral aceito e
  conhecido: as sugestões ficam congeladas no que está gravado hoje, já que não há mais
  tela para editá-las. Encerrar a feature de ponta a ponta (backend + telas de OS) foi
  avaliado e ficou de fora desta entrega.
- **URL e nomes de rota** seguem `/conhecimento/fluxo-os` e `knowledge.os-flow.*`.
  Renomear exigiria tocar navegação, view, redirects do controller e testes, com risco de
  `Route [x] not defined` em produção, sem ganho para o usuário.

## Achados registrados, fora do escopo desta entrega

- **`os_status.gera_evento_crm` é gravado mas nunca lido.** Aparece só no cast do model e
  na validação/persistência da API — nenhum consumidor gera evento de CRM a partir dele.
  A tela de cadastro continua oferecendo o switch.
- **`os.tecnico_id` está preenchido em 69 de 3.645 OS (1,9%).** Por isso "uma OS, um dono"
  não entrou como princípio do modelo: é capacidade do sistema, não prática do time.

## Testes

`frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`:

- `test_knowledge_os_status_index_renders_catalog_without_flow_diagram_or_matrix`
  (renomeado): afirma o catálogo agrupado, a edição de status ainda disponível, e a
  ausência do diagrama, da matriz, dos super-grupos e da rota de gravação.
- `test_knowledge_assistance_model_index_renders_visual_workflow_and_queue_rules`:
  passou a exigir os indicadores derivados e a **ausência** de `WIP`, `15 min`, `30 min`,
  `24 h`, `aging`, `Escalonamento` e `Pós-venda`.

Suíte desktop: 355 passando. As 18 falhas restantes são as mesmas pré-existentes já
registradas em `2026-08-23-sidebar-enxuta-atalhos-clientes-equipamentos.md` (tabela
`user_preferences` ausente no SQLite de teste e `ClassIsolationTest`).

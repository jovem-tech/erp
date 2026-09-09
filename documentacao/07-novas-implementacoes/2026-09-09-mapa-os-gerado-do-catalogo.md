# Mapa da OS passa a ser gerado do catálogo vivo, com cronologia real

**Data:** 09/09/2026
**Versão:** `5.80.0.0` – `5.80.2.0`
**Status:** ativo no ambiente de desenvolvimento LAN; não publicado na VPS de produção

## Objetivo

Duas queixas do usuário, com a mesma raiz:

> "o sistema permite criar e alterar os status atuais, mas o mapa da OS não sofre
> alterações com as mudanças" — "o andamento atual da OS não segue o mesmo
> compasso e cronologia do mapa".

A tela **Status de OS** (`/conhecimento/fluxo-os`) é o único lugar que cria, renomeia,
reordena e desativa status de OS, e nada disso chegava ao **Mapa da OS** (`/os/{id}/mapa`).

## O que estava errado

### 1. O mapa era um artefato congelado, não um desenho do catálogo

`orders/_flow_map_svg.blade.php` era um SVG estático de 43 KB gerado por
`scripts/python/diagrama_fluxo_os_organizado.py --embed`, com:

- `LANES` — 10 raias com `x/y/w/h` e títulos inventados ("G1 · RECEPÇÃO", "G2 ·
  INTERRUPÇÃO"), que não existem em lugar nenhum do banco;
- `CARDS` — 27 status com coordenadas e rótulos quebrados em linhas **à mão**
  (`"lines": ["Reparo", "concluído"]`);
- `EDGES` — 73 polilinhas ortogonais roteadas manualmente, com corredores `x/y`
  escolhidos um a um;
- `REAL_TRANSITIONS` — as 95 transições do banco **transcritas**, e uma
  `verify_against_catalog()` que **recusava a geração** se o banco divergisse.

Consequência: criar um status → sem card; renomear → rótulo velho; reordenar → nada;
desativar → continuava desenhado. E regenerar não era automático: exigia um
desenvolvedor roteando coordenadas novas no Python.

### 2. O trajeto real não era desenhado

`applyDecoration()` só pintava de verde quando encontrava a seta `de:para` já
existente no SVG. A OS 3654 foi `aguardando_reparo → reparo_concluido` — par que
**não existe** em `os_status_transicoes` (de `aguardando_reparo` só há
`reparo_execucao` e `aguardando_peca`) — e o mapa não mostrava linha nenhuma.

Não é caso isolado: desde a [decisão de 09/08/2026](./2026-07-07-modal-status-os-procedimentos-notificacao-cliente.md)
o backend aceita **qualquer** status ativo fora de `closureCodes()`, então a maioria
dos trajetos reais nunca teria seta.

### 3. Quatro cópias divergentes do vocabulário de macrofase, com três ordens

| Fonte | Ordem | `interrupcao` | `finalizado_sem_reparo` |
| --- | --- | --- | --- |
| `OrderStatusMacroGroups.php` | `min(ordem_fluxo)` | Interrupção | Finalizado sem Reparo |
| `orders-status-modal.js` + CSS | declarada | Em espera | Sem reparo |
| `LANES` do Python | fixa nas coordenadas | G2 · INTERRUPÇÃO | G3 · FINALIZADO SEM REPARO |

### 4. Bug: a página cheia não passava `statusDisponiveis`

`map.blade.php` omitia o campo no `window.__DESKTOP_OS_MAP` (a aba do modal passava).
Sem ele `applyState()` caía no fallback: só as `proximas_etapas` ficavam clicáveis e
**os outros ~22 nós recebiam `.is-closure`** — clicar em "Triagem" ou "Retrabalho"
abria "Encerramento é pela baixa da OS" por engano. Mesmo widget, dois comportamentos.

## O que mudou

### `OrderStatusMacroGroups` vira a fonte única

Ganhou `order()`, `exitOrder()`, `orderIndex()`, `sortGroups()`, `flowAccent()` e
`toPayload()`. A ordem cronológica oficial — decisão do usuário, **não** derivada de
`ordem_fluxo` — é:

`Recepção › Diagnóstico › Orçamento › Em espera › Execução › Qualidade › Concluído`,
depois as saídas (Sem reparo, Cancelado) e o Encerramento.

Rótulos unificados nos textos curtos que o técnico já lia no modal: `interrupcao` virou
**"Em espera"** e `finalizado_sem_reparo` virou **"Sem reparo"** (afeta também o donut do
dashboard). `accent()`/`softAccent()` continuam existindo só para o dashboard, que tem
outra exigência de paleta (cada matiz uma vez só); a paleta de fluxo virou `flowAccent()`.

Consumidores repontados: `OrderStatusFlowController`, `AssistanceModelController`,
`orders-status-modal.js` (via `window.__DESKTOP_OS_FLOW_PHASES`) e o mapa. A paleta das
raias do modal saiu do CSS e virou inline — assim uma macrofase criada pelo usuário
também ganha cor, em vez de cair num `[data-phase="..."]` inexistente.

### `OrderFlowMapLayout` calcula a geometria do catálogo

Classe pura (sem I/O). Recebe `status_disponiveis` e devolve `width/height/lanes/cards/port`:

- uma raia por `grupo_macro` presente no catálogo ativo, na ordem oficial;
- cards empilhados por `ordem_fluxo`, rótulo de `os_status.nome` com quebra automática
  de linha (substitui o `lines: [...]` escrito à mão);
- altura da raia derivada da contagem de cards; quebra de linha das raias calculada
  por uma largura-alvo, então o desenho cresce sozinho;
- macrofase desconhecida ganha **raia própria no fim** — nunca some;
- `grupo_macro = 'encerrado'` recebe `kind: closure` e fica atrás da porta de baixa.

`_flow_map_svg.blade.php` virou template (43 KB → 6,7 KB). Quem não tem o catálogo à mão
(o modal, incluído por cinco telas) resolve via `OrderFlowMapLayoutFactory`, singleton
memoizado por request.

### As arestas passaram a ser desenhadas em runtime

Não existem mais no SVG. `orders-map.js` ganhou um roteador ortogonal que liga duas
caixas lidas com `getBBox()`, dentro de `[data-os-map-layer="edges"]`. Camadas:

| Camada | Fonte | Estilo |
| --- | --- | --- |
| Trajeto percorrido | `os_eventos` categoria `status` | verde, **sempre desenhado** |
| Rota provável | frequência real medida | tracejado azul animado |
| Próximas etapas | `proximas_etapas` | tracejado laranja curto |
| Baixa | regra fixa | roxo, até a porta |
| Catálogo (overlay) | `os_status_transicoes` | cinza fino, **desligado por padrão** |

É isto que conserta a cronologia: como a geometria é calculada, **qualquer** par de nós
pode ser ligado — inclusive um salto que não está no catálogo.

#### As setas andam por corredores livres, nunca por cima dos cards

O layout põe as raias numa grade uniforme (mesma largura, mesmo passo, mesma margem), o
que cria duas famílias de faixas garantidamente vazias: os **vãos verticais** entre raias
vizinhas e os **canais horizontais** entre as linhas de raias. Toda seta anda por elas.

A rota padrão sai do card até o vão ao lado da própria raia, sobe/desce por esse vão até
um canal horizontal livre, atravessa, e só então entra no card de destino. Casos
especiais: cards vizinhos na mesma raia descem reto pelo vão entre eles; raias vizinhas
compartilham o mesmo vão e dispensam o canal.

`pickChannel()` descarta canais cuja travessia esbarraria num obstáculo, e o conjunto de
obstáculos inclui **a porta da baixa** — ela fica entre a raia de saídas e a de
encerramento, exatamente na coluna dos cards, e uma reta de "Reparo Recusado" até
"Entregue - Reparado e Pago" passava por cima dela.

As travessias horizontais não se limitam aos vãos entre linhas de raias: qualquer folga
vertical de 26px ou mais entre obstáculos vira candidata. Sem isso, uma seta de Execução
até Concluído só podia contornar as raias por cima e virava um "U" gigante; agora ela
corta rente, por baixo dos cards de Qualidade. Os cantos são arredondados (`CORNER_R`),
o que faz a seta ler como fluxo e não como moldura das raias.

#### A faixa de desfechos divide uma linha só, alinhada à direita

Saídas (Sem reparo, Cancelado) e Encerramento eram duas faixas empilhadas, cada uma com
uma ou duas raias encostadas na margem esquerda: dois terços do desenho ficavam vazios e a
seta roxa da baixa cruzava o mapa inteiro. Agora as três dividem a mesma linha, alinhada
à **direita** — a raia de Encerramento cai logo abaixo de "Concluído", que é onde o fluxo
termina, e a porta da baixa fica encostada nela. Altura do desenho caiu de 1364 para 1040.

#### Legenda

Cada amostra da legenda espelha a classe `.os-map-edge.is-*` correspondente em cor,
espessura e tracejado. Antes ela mostrava "próximas etapas" como uma bolinha (herança de
quando isso era só destaque de nó, não uma seta laranja) e não tinha entrada nenhuma para
o overlay de catálogo. Agora são seis itens, e há um teste que quebra se uma camada de
aresta existir sem amostra na legenda.

### A "rota provável" passou a ser medida, não declarada

Novo `OrderFlowStatisticsService` (backend) + `GET /api/v1/knowledge/os-flow/estatisticas`.

Reconstrói cada salto com `LAG(status_novo) OVER (PARTITION BY os_id ORDER BY created_at, id)`.
**Não usa `status_anterior` direto**: só 728 das 4.394 linhas de `os_status_historico` têm
esse campo preenchido (o resto veio do legado sem ele); a reconstrução recupera 574 saltos
reais em 234 OS. Devolve o catálogo inteiro (não por OS), cacheado por 1 h — um payload
serve a página cheia e a aba do modal, e o JS recalcula a rota quando a OS se move.

O `suggestRoute()` antigo (Dijkstra sobre o catálogo congelado, alvo fixo
`reparo_concluido`, destino fixo `entregue_reparado_pago`) foi removido. A caminhada nova
tem conjunto de visitados (o histórico real tem ciclos), amostra mínima de 3 e teto de 12
passos; abaixo da amostra ela **para** em vez de inventar rota.

Caminho medido hoje, que reproduz a bancada real:

```
triagem → diagnóstico (37,9%) → aguardando avaliação (20,5%) → aguardando orçamento (38,9%)
→ aguardando autorização (65,1%) → aguardando reparo (41,7%) → em execução (40%)
→ reparo concluído (53,8%) → entregue e pago (67,3%)
```

### Correções pontuais

- `map.blade.php` passa `statusDisponiveis` (corrige o `.is-closure` errado em ~22 nós);
- `MAP_W`/`MAP_H` saem do `viewBox` gerado, não mais das constantes `1780x1560`;
- `grupo_macro` ganhou `<datalist>` nas duas telas de cadastro (segue texto livre);
- `sortGroups()` trata `grupo_macro` vazio como grupo legítimo ("Sem grupo macro") em vez
  de descartar o status.

## Arquivos

**Novos:** `frontends/desktop/app/Support/OrderFlowMapLayout.php`,
`OrderFlowMapLayoutFactory.php`, `backend/app/Services/Orders/OrderFlowStatisticsService.php`,
`frontends/desktop/tests/Unit/OrderFlowMapLayoutTest.php`,
`backend/tests/Feature/Api/V1/OrderFlowStatisticsTest.php`.

**Removidos:** `scripts/python/diagrama_fluxo_os_organizado.{py,svg,png}` e
`README-diagrama-fluxo-os.md` — manter um segundo gerador não-mantido era a causa do problema.

## Impactos

- **Contrato de API:** um endpoint novo (`GET /knowledge/os-flow/estatisticas`, gate
  `os:visualizar`). Nenhum endpoint existente mudou de forma.
- **Banco:** nenhuma migration. Só leitura de `os_status_historico`.
- **Regra de fechamento:** inalterada. Os 5 `closureCodes()` continuam entrando só por
  `OrderClosureService::close()`; o mapa segue marcando esses cards como `closure` e
  roteando o clique para a porta de baixa.
- **Visual:** "Interrupção" → "Em espera" e "Finalizado sem Reparo" → "Sem reparo" nas
  telas Status de OS, Modelo da Assistência e no donut do dashboard.

## Validação

- `frontends/desktop`: 557 passando; as 9 falhas restantes são pré-existentes,
  confirmadas com `git stash` na árvore limpa (`ClassIsolationTest`,
  `BudgetCommercialTermsAssetsTest`, `ConfigurationIntegrationsTest`,
  `FinanceiroReportTest` e 5 de `DesktopFrontendTest`).
- `backend`: `OrderFlowTest` + `OrderFlowStatisticsTest` — 104 passando.
- SVG gerado do catálogo real validado como XML: 27 cards, 10 raias, 0 cards fora do
  `viewBox`, 0 cards sobrepostos, 0 setas pré-desenhadas.
- Roteador de arestas exercitado nos 3.645 pares reais (cards entre si e cards → porta da
  baixa), em 5 deslocamentos por par: **0 rotas atravessando card ou porta** e 0
  coordenadas inválidas.
- Caminhada da rota provável rodada contra a matriz medida real a partir de todas as 26
  origens: 0 ciclos, 0 saltos abaixo da amostra mínima, término correto num status de
  encerramento.

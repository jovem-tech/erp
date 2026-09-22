# Orçamento em níveis de manutenção (Básica / Avançada / Completa)

## Contexto

- versao: `pendente de bump` (implementado em 2026-09-15 sobre a 5.88.0.0)
- data: `2026-09-15`
- ambiente-alvo: `dev 192.168.1.100` (validado ponta a ponta) → `VPS`
- migrations: `2026_09_15_000002_add_niveis_manutencao_to_orcamentos_tables`,
  `2026_09_15_000003_add_budget_option_block_to_pdf_templates`

O orçamento saía como proposta única: ou o cliente pagava tudo, ou o aparelho
ficava parado. A oficina passa a oferecer **três opções cumulativas** —
Manutenção Básica (volta a funcionar), Avançada (corrige e previne) e Completa
(como novo) — e o cliente escolhe na própria página de aprovação.

## O que foi entregue

### Um orçamento, uma lista de itens, uma marcação por item

Não existem "três orçamentos". `orcamento_itens.nivel_minimo` (1, 2 ou 3,
default 1) diz a partir de qual opção o item entra; a opção N é a projeção
"itens com `nivel_minimo <= N`" (`App\Support\BudgetTotals::perLevel`). Os
totais por opção aplicam o mesmo desconto/acréscimo global do orçamento, então
a última opção reproduz exatamente o `total` gravado.

Orçamento cujos itens são todos de nível 1 é um orçamento comum: `hasTiers()`
é falso e formulário, detalhe, página pública, PDF e envio ficam **idênticos
aos de antes**. Nada de níveis aparece para o cliente.

`orcamentos.nivel_recomendado` (opcional, manual) marca a opção "Recomendada";
é descartado automaticamente quando o orçamento não tem níveis.

### O escopo contratado continua sendo a lista de itens

A OS lê `budget->items` ao vivo (financeiro, aplicação de peças, fechamento,
auditoria de custo) e a reserva de estoque reconcilia por item. Por isso a
escolha do cliente não é "um filtro para todo mundo lembrar de aplicar": na
aprovação (`BudgetApprovalService::finalizeApproval`, funil comum do link
público e do "Aprovar (outros meios)") os itens acima da opção escolhida são
apagados, `nivel_aprovado` é gravado e os totais recalculados **antes** de
qualquer efeito colateral. Reserva de estoque libera as peças que saíram,
`BudgetOrderSyncService` copia o valor certo para a OS e a notificação/evento
anunciam o total já podado. O que foi oferecido fica em
`orcamento_aprovacoes.niveis_snapshot` (JSON com as três opções) e `nivel`.

Aprovar orçamento com níveis **exige** a opção (`nivel`); sem ela o link
público volta com aviso e a API responde 422 (`BUDGET_LEVEL_REQUIRED`).

### Página pública em dois passos

- `GET /orcamento/{token}`: landing no espírito de tabela de preços
  (`partials/opcoes.blade.php`): cabeçalho compacto ("Cliente · Equipamento" +
  "Ver detalhes do atendimento", que abre as 4 caixas de sempre — extraídas
  para `partials/meta-grid.blade.php`), título "Escolha a manutenção do seu
  {marca modelo}" e um cartão por opção: nome → preço → uma linha → botão
  "Escolher {opção}" → lista com checkmark. A lista é **incremental** ("Inclui"
  no nível 1; "Inclui tudo da {anterior}, mais" nos seguintes, só os itens
  novos daquele nível — `itens_novos` de `BudgetTotals::perLevel`), com
  "Ver os N itens incluídos" (`<details>`) abrindo a cumulativa quando há algo
  herdado. O recomendado tem borda tracejada + badge + leve tingimento, na
  cor do sistema. Rejeição escondida em "Nenhuma das opções serve?". Tema
  claro, igual ao resto da página; o cabeçalho compacto só existe aqui — o
  passo 2 e o orçamento comum mantêm o cabeçalho de 4 caixas.
- `GET /orcamento/{token}?opcao=N`: o orçamento daquela opção — itens e totais
  projetados (nada gravado), chip "Opção escolhida" + "Trocar de opção",
  "Baixar PDF" (`/pdf?opcao=N`) e os botões Aprovar/Rejeitar de sempre, com
  `nivel` escondido no form de aprovação.
- Depois da decisão: escopo contratado + chip "Opção aprovada".

### PDF por opção e PDF definitivo

`BudgetPdfContextFactory` aceita `options['nivel']`: projeta itens/totais da
opção, preenche `orcamento.opcao_texto` e leva o botão do PDF direto para
`?opcao=N`. O template padrão ganhou o campo condicional "Opção de manutenção"
antes da tabela de itens (`PdfDefaultTemplates::blocoOpcaoManutencao`), levado
aos modelos publicados pela migration 000003 no mesmo padrão cirúrgico de
2026_08_11. Em orçamento comum a variável vem vazia e o bloco some.

Orçamento com níveis é **enviado sem PDF** (WhatsApp em texto via
`sendDirectMessage`, e-mail sem anexo, `orcamento_envios.documento_path`
vazio): o documento só existe depois da escolha. O PDF definitivo nasce na
aprovação (`persistApprovedLevelPdf`), atualiza `os.orcamento_pdf` e registra
a versão no acervo da OS via `syncAfterBudgetDispatch`. Falha aí só é
reportada — nunca desfaz a aprovação (a rota pública regenera sob demanda).

### Desktop

- Formulário: select **Nível** (Básica/Avançada/Completa) em cada linha de
  item; bloco "Opções de manutenção" no resumo financeiro com os três totais
  (mesma regra do backend, em JS) e o select "Recomendar ao cliente" — só
  aparece quando algum item está em nível 2 ou 3; rascunho no `localStorage`
  carrega `nivel_minimo` (rascunhos antigos caem no nível 1); revisão final
  lista as opções.
- Detalhe: chip "N opções de manutenção" / "Opção aprovada: …", seção com os
  três totais, coluna "Nível" na tabela de itens, e o confirm de "Aprovar
  (outros meios)" pede a opção escolhida (mesmo mecanismo do seletor de canal).

## Testes

- Backend: `tests/Feature/Api/V1/BudgetMaintenanceLevelsTest.php` (10 testes:
  validação e totais por opção, envio sem PDF, landing/opção/PDF por opção,
  aprovação exige opção, poda + snapshot + OS + reserva liberada +
  notificação com o valor certo, orçamento comum inalterado, aprovação por
  staff com/sem opção, contexto do PDF). Suíte ampla
  (`Budget|EstoqueReserva|OrcamentoPrecificacao|OsAplicacaoPeca|Pdf|Order|Template`):
  380 verdes.
- Desktop: `tests/Feature/Desktop/OrcamentoNiveisTest.php` (7 testes). As
  três falhas restantes da suíte de orçamento já existiam antes (mensagens do
  envio para consulta e `@disabled` no marcador de formas de pagamento).
- Validado no dev: ORC-2609-000010 (cliente Teste 8) — landing, `?opcao=2`,
  PDF da opção pelo template publicado v6 e aprovação da Avançada pelo link.

## Condições comerciais por opção (2026-09-16)

Segundo eixo de diferenciação, além dos itens: garantia, parcelamento sem
juros, formas de pagamento aceitas, **entrega do equipamento no endereço do
cliente** (campo novo, `orcamentos.entrega_domicilio`, mesma família de
`garantia_dias`) e uma lista livre de **diferenciais** por opção (ex.:
"instalação expressa") podem variar por nível.

- **Padrão + override.** Os campos do orçamento continuam sendo o padrão.
  `orcamento_nivel_condicoes` guarda, por `(orcamento_id, nivel)`, só o que o
  nível sobrescreve (`garantia_dias`, `parcelas_sem_juros`, `entrega_domicilio`
  — booleano NULÁVEL: null herda, false é "sem entrega" explícito —,
  `beneficios` JSON); `orcamento_nivel_formas_pagamento` espelha
  `orcamento_formas_pagamento` com `nivel` (qualquer linha substitui a lista
  base naquele nível; nenhuma linha herda). Nível com os cinco campos vazios
  perde a linha.
- **Um só ponto de resolução.** `BudgetCommercialTermsService::forBudget($budget,
  ?$nivel)` devolve o efetivo (override ?? padrão). Depois da aprovação o
  parâmetro é ignorado e vale sempre `nivel_aprovado` — quem chama sem nível
  (detalhe do desktop, PDF definitivo) já recebe o certo. `forEachLevel()`
  alimenta a landing; `diffAcrossLevels()` decide, campo a campo, se a condição
  é dita uma vez no rodapé ("em qualquer opção") ou dentro de cada cartão
  ("Vantagens desta opção", marcador `+`) — quando algum nível diverge. Entrega
  aparece só no cartão que inclui; nos outros, nada (ausência basta).
- **Colapso na aprovação.** `applyApprovedLevel()` chama
  `collapseApprovedLevel()`: o efetivo do nível escolhido vai para as colunas
  base (`garantia_dias`, `parcelas_sem_juros`, `entrega_domicilio`,
  `orcamento_formas_pagamento`) e o override estruturado daquele nível é
  zerado (diferenciais ficam, não têm coluna base). Motivo: `linkBudgetToOrder`,
  `suggestedWarrantyDays` e `cloneCommercialTerms` leem `orcamentos.*` direto —
  mesma lógica da poda de itens. Níveis não escolhidos ficam como estão
  (auditoria do que foi oferecido; ninguém mais resolve para eles).
- **PDF.** `BudgetPdfContextFactory` passa o nível projetado ao serviço;
  variáveis novas `orcamento.entrega_domicilio_texto`, `orcamento.beneficios_texto`
  e coleção `beneficios`. Blocos `blocoBeneficiosOpcao()` (depois de "Opção de
  manutenção") e `blocoEntregaDomicilio()` (depois de "Garantia"); a migration
  `2026_09_16_000002` leva os dois aos modelos já publicados (cirúrgica, como a
  anterior). Texto do WhatsApp (`resumo`) também inclui entrega e diferenciais.
- **Desktop.** Checkbox "Inclui entrega…" no card de condições (marcador `0`
  oculto + checkbox `1`, como `envolve_equipamento`). Seção `<details>`
  "Personalizar condições por opção de manutenção" com um bloco por nível
  (formas / parcelas / garantia / entrega tri-state / diferenciais "um por
  linha"), escondida enquanto o orçamento não tem níveis — `updateLevelsSummary()`
  revela ao vivo, junto com o resumo de opções. O toggle de parcelamento passou
  a rodar por escopo (base e cada nível; nível sem forma marcada segue as da
  base). Detalhe (`show`) lista entrega, diferenciais e o que foi personalizado
  por opção. `budgetDetail()` expõe `entrega_domicilio` e `niveis_condicoes`
  (só o gravado, para o form não pré-preencher com o herdado).
- **Testes.** Backend: +7 em `BudgetMaintenanceLevelsTest` (19 no total —
  override/herança, linha apagada e `false` explícito, rodapé único vs.
  por cartão, colapso + chamada legada, PDF por opção com validação do modelo
  padrão, orçamento comum com entrega). Desktop: +5 em `OrcamentoNiveisTest`
  (12). Validado no dev com ORC-2609-000011 (overrides na Completa) em 1200px
  e 390px, PDFs por opção e um orçamento descartável aprovado pelo link
  (colapso conferido no MySQL, PDF definitivo com os dados da opção).

## Landing de venda (2026-09-16)

A landing funcionava, mas falava como um sistema. Só a landing mudou (passo 2 e orçamento comum
continuam iguais); tudo autocontido, ícones em SVG inline (glifo de fonte já falhou aqui).

- **Hero humano** (`show.blade.php`, ramo `$showOptions`): logo da empresa (mesmo base64 do PDF,
  `CompanyContextProvider::logoDataUri()`; sem logo, nome em texto), "Olá, {primeiro nome}." +
  "Seu {marca modelo} já foi avaliado. Agora é só escolher como cuidar dele.", "Quem cuidou da
  análise: {responsável ?? criador}" com avatar de inicial, pílulas "Orçamento N" / "Válido até", e
  botão "Falar com a gente no WhatsApp" (`wa.me` com `empresa_telefone` das configurações + mensagem
  citando o orçamento — única chave de telefone que existe; some sem telefone). O status ("aguardando
  resposta") saiu da landing. Chaves novas em `publicBudgetPayload()`: `client_first_name`,
  `technician_name`, `company_logo_data_uri`, `company_phone`, `company_whatsapp_url`;
  `findByToken()` passou a carregar `responsible`/`creator`.
- **Cartões** (`opcoes.blade.php`): faixa no topo só no destaque (recomendado = azul cheio; "mais
  escolhida" = suave), cartão elevado em ≥960px e o único botão cheio da página (os outros,
  contornado); ícone por nível (chave / escudo / brilho), medidor de cobertura (segmentos = maior
  nível), preço + "ou Nx de R$ X sem juros" (parcelamento daquela opção) + "+ R$ Y em relação à
  {anterior}" (âncora de upsell); "Vantagens desta opção" com ícone por tipo (entrega, diferencial,
  garantia, parcelamento, pagamento).
- **Faixa de confiança** entre os cartões e o rodapé: garantia/entrega/parcelamento quando iguais em
  todas as opções (mesmas frases de antes, só mudaram de lugar) + "Peças e mão de obra já incluídas no
  valor de cada opção." + "Você aprova só depois de ver o orçamento completo.". Rodapé: formas de
  pagamento, "Ficou com alguma dúvida?" + WhatsApp, e só então "Recusar proposta".
- Testes: +3 em `BudgetMaintenanceLevelsTest` (hero com técnico/logo/WhatsApp, fallback sem
  telefone/logo, parcela + diferença) e o teste da landing ajustado (1 botão cheio, 2 contornados,
  6 segmentos). Verificado em 1200px e 390px com um orçamento descartável (apagado depois).

## Revisão de hierarquia do hero (2026-09-17)

Análise crítica de design sobre o hero de 2026-09-16 achou três problemas reais: quase metade do
card ficava vazia à direita (tudo concentrado à esquerda), o botão verde do WhatsApp competia em
peso visual com a ação principal da página (escolher uma opção), e o texto do badge (`--muted`
sobre o fundo do pill) ficava em ~4,6:1 de contraste — dentro do AA, mas raspando o limite pra um
dado que carrega nº do orçamento e validade. `show.blade.php`, mesmo ramo `$showOptions`:

- **Grid de duas colunas** (`hero-landing-grid`): conteúdo à esquerda, marca (logo + nome) à
  direita — o vazio à direita vira composição em vez de sobra. No celular colapsa pra uma coluna
  só, com a marca numa linha compacta acima da saudação.
- **Nome fantasia em duas linhas quando tem 3+ palavras**: as 2 primeiras como marca (`Jovem
  Tech`), o resto como subtítulo menor (`Celulares e Informática`) — heurística de posição, não
  um campo novo (nome fantasia continua sendo um texto só no cadastro da empresa). Nome de 1–2
  palavras fica numa linha só, sem quebra vazia.
- **WhatsApp deixa de ser o elemento mais forte do hero**: o botão verde cheio (`.btn-whatsapp`)
  sai da linha dos badges e passa a existir só no rodapé das opções (`opcoes.blade.php`, onde já
  vivia); no hero vira um link/selo curto ("Precisa de ajuda?") ao lado de "Ver detalhes do
  atendimento" — mesmo verde, mesma pílula, mas sem o destaque que roubava a cena da escolha de
  manutenção.
- **"Quem cuidou da análise" removido do hero** (nome do técnico + avatar de inicial): reduzia o
  aproveitamento vertical sem ganho proporcional de confiança frente aos outros elementos.
  `technician_name` continua sendo calculado em `publicBudgetPayload()` (outros consumidores podem
  precisar), só a exibição saiu.
- **Contraste do pill**: `color: var(--muted)` → `var(--text)`, saindo de ~4,6:1 para
  folgadamente acima do mínimo AA.
- Testes: `test_public_landing_shows_greeting_technician_logo_and_whatsapp` virou
  `test_public_landing_shows_greeting_logo_and_whatsapp` (sem setup de técnico, que não é mais
  coberto) + `assertDontSee('Quem cuidou da análise')`. Renderizado via Chrome headless (teste
  temporário que só salvava o HTML, removido em seguida) em 1366px e 390px, com e sem "Ver
  detalhes" aberto.

## Selo de NFS-e na faixa de confiança (2026-09-17)

Ver `2026-09-17-selo-nota-fiscal-orcamento-publico.md` para o detalhe completo (migration, checkbox
no desktop, integração com o limite do MEI). Aqui só o que mudou nesta página: a faixa de
confiança (`opcoes.blade.php`, a mesma seção descrita em "Landing de venda" acima) ganhou um
quarto card condicional — "Emissão de nota fiscal de serviço (NFS-e) em qualquer opção
escolhida." — que só aparece quando o orçamento marca a opção **e** a empresa (se MEI) ainda não
passou do teto anual de faturamento; a decisão é do backend (`BudgetApprovalService`), a view só
lê o resultado.

## Itens deixam de ser cumulativos por cascata (2026-09-17)

Bug relatado pelo usuário: itens que são **alternativa** entre si (ex.: RAM
2GB na Básica, RAM 4GB na Avançada, RAM 8GB na Completa) se somavam na opção
mais alta em vez de se substituírem — a Completa cobrava as três memórias
juntas, sendo que só a de 8GB seria realmente usada. A causa era estrutural:
o modelo original (`nivel_minimo`, "opção N = itens com `nivel_minimo <= N`")
é uma cascata pura, sem forma de um item pertencer só a um subconjunto
específico de níveis.

**Modelo novo:** `orcamento_itens.nivel_minimo` (tinyint) foi substituído por
`orcamento_itens.niveis` (JSON, array de inteiros 1..3) — cada item declara
explicitamente em quais opções entra, sem cascata nenhuma (migration
`2026_09_17_000001_replace_nivel_minimo_with_niveis_on_orcamento_itens`, com
backfill `nivel_minimo=k → niveis=[k..3]` para preservar o comportamento dos
orçamentos já existentes). No desktop, o único `<select>` de nível virou 4
checkboxes por item — Básica / Avançada / Completa / Todos ("Todos" é só
atalho de UI que marca os três, nunca é um valor gravado). Padrão de uma
linha nova: só Básica, igual a hoje, até o orçamento já ter algum item com
nível restrito — daí em diante o padrão vira Todos (a maioria dos itens
costuma valer pra toda opção; só a minoria, como a RAM do exemplo, precisa de
exclusão manual).

`App\Support\BudgetTotals::itemsForLevel()` continua sendo o único lugar que
decide "o que pertence ao nível N" (agora por associação exata,
`in_array($nivel, $item->niveis)`) — landing pública, página da opção
(`?opcao=N`), PDF e o resumo do desktop herdam a correção automaticamente.
`BudgetApprovalService::applyApprovedLevel()` (a poda na aprovação) passou a
reaproveitar essa mesma função (`whereNotIn('id', itemsForLevel(...)->pluck('id'))`)
em vez de ter sua própria regra — importante porque um item "perdedor" de uma
alternativa pode ter `niveis` contendo o nível aprovado sem ser quem deveria
sobreviver.

**Efeito colateral corrigido junto:** o subtotal/total gravados ANTES da
aprovação (o que o técnico vê montando o orçamento) somavam cegamente toda
linha, sem olhar nível — inofensivo na cascata antiga (a soma de tudo sempre
batia com a opção mais alta), mas superestimava o total quando há
alternativas. `BudgetWorkflowService::syncItems()` agora soma só os itens que
pertencem ao nível mais alto que os itens realmente formam; o mesmo ajuste foi
espelhado no preview client-side (`orcamentos-form.js`, `updateSummary()`).

**Landing pública, consequência necessária do modelo novo:** com associação
arbitrária por checkbox, "item novo neste nível" (`itens_novos`,
`nivel_minimo === N`) deixou de fazer sentido — um item pode estar na Básica
e na Completa sem estar na Avançada. `opcoes.blade.php` parou de mostrar
"Inclui tudo da X, mais…" com a lista cumulativa escondida atrás de "Ver os N
itens incluídos"; cada cartão agora lista **tudo, sempre, por extenso**
("Itens desta opção") — o que teria exposto o bug da RAM antes de o
orçamento ser enviado. O critério de esconder uma opção redundante (mesmo
preço/lista da vizinha de baixo) passou a comparar a lista+total já
projetados em vez do proxy antigo.

Testes: `BudgetMaintenanceLevelsTest` (2 casos novos: alternância entre
níveis e subtotal do rascunho) + suíte inteira sem regressão nova (baseline
verificado com `git stash` antes/depois: 8 falhas pré-existentes na suíte
`Budget*`, nenhuma nova). `OrcamentoNiveisTest` no desktop atualizado para o
formato `niveis[]`; suíte completa do desktop sem regressão nova (mesmas 3
falhas pré-existentes já documentadas). Verificado visualmente via Chrome
headless (harness com HTML real dumpado por teste, removido em seguida) —
formulário com os 4 checkboxes em 1500px/1300px/430px, e a landing pública
com o cenário exato da RAM (Básica R$150 só com a de 2GB, Avançada R$350 só
com a de 4GB, Completa R$550 só com a de 8GB).

**Correção de layout (mesmo dia):** a coluna "Tipo" da linha de item
(`budget-item-line-primary`, `desktop.css`) estava dimensionada para os 96px
que bastavam quando havia só um `<select>` de nível ao lado; com os 4
checkboxes de nível ocupando mais espaço vertical/horizontal, o `<select>`
Serviço/Peça passou a não caber e invadir visualmente a coluna de Nível
(bug relatado pelo usuário com print). Colunas alargadas para 132px/128px
conforme o breakpoint, e regra defensiva (`min-width: 0` nos `select`/`input`/
`textarea` de `.budget-item-field`) para nenhum controle do grid voltar a
transbordar. Conferido via Chrome headless, antes e depois, em 1360px.

Bump de versão + CHANGELOG feitos nesta entrada: **v6.0.0.0** (tier `major`
— a migration dá `dropColumn('nivel_minimo')`, primeiro critério de MAJOR do
`VERSIONING.md`). Falta só o commit (arquivos tocados: migration nova + `Budget`/`BudgetItem`/
`BudgetTotals`/`BudgetApprovalService`/`BudgetWorkflowService`/
`BudgetRevisionService`/`BudgetPdfContextFactory`/`PdfTemplateRegistry`/
`UpsertBudgetRequest` no backend; `OrcamentoController`, `item-row.blade.php`,
`show.blade.php`, `orcamentos-form.js`, `desktop.css` no desktop;
`opcoes.blade.php` e os dois arquivos de teste no backend/desktop).

## Composição das opções no desktop (2026-09-18)

**Problema.** Os 4 checkboxes por item (Básica/Avançada/Completa/Todos) da
rodada anterior perguntavam ao técnico "em quais opções este item entra?",
mas ele pensa "o que entra em cada opção?". Rótulos truncados, atalho "Todos"
e um padrão silencioso (1º item = só Básica, depois = Todos) produziram, num
orçamento real, "Desmontagem e higienização" só na Básica e "pasta térmica"
fora da Básica — e ninguém via a composição das três opções antes do cliente.
O modelo de dados (`orcamento_itens.niveis`, sem cascata) estava certo; o
**processo de preenchimento** estava errado. Backend: zero mudanças.

**Processo novo (só desktop, o único com formulário de item):**

1. **Interruptor "Oferecer opções de manutenção"** no cabeçalho de "Itens do
   orçamento" (`oferece_opcoes`, par marcador oculto `0` + checkbox `1`, o
   mesmo padrão de `entrega_domicilio` — o rascunho local guarda e restaura
   sozinho). Desligado em orçamento novo; na edição, começa ligado quando o
   orçamento gravado tem opções; some quando há `nivel_aprovado` (a lista já é
   o escopo contratado, e forçar níveis ali mudaria a fingerprint dos itens e
   reabriria a decisão do cliente). Desligado = orçamento comum: nenhum
   controle de nível na tela, toda linha em `[1]`.
2. **Cartão do item volta a ser só "o quê e quanto"**: o campo "Nível" saiu;
   os 3 checkboxes continuam na linha, `hidden`, com o mesmo
   `name="itens[i][niveis][]"`/`id` — wire idêntico, os regexes dos testes de
   edição continuam válidos. No lugar, um selo de leitura
   (`data-budget-item-levels-badge`: "Em todas as opções", "Só na Completa",
   "Avançada e Completa", "Fora de todas as opções" em vermelho) que leva à
   linha do item no quadro.
3. **Quadro "Composição das opções"** (`data-budget-tiers-board`, logo abaixo
   de "Adicionar item"): linhas = itens, colunas = opções, célula = botão
   `role="checkbox"` que inclui/tira o item; rodapé com nº de itens e **total
   por opção** (mesma fórmula de `BudgetTotals::perLevel`: subtotal da opção
   − desconto global (% proporcional, fixo integral) + acréscimo, piso 0);
   por coluna, "todos · nenhum" e **"+ Item só nesta opção"** (cria o item já
   restrito àquela coluna — o gesto natural para RAM 4GB "só na Avançada");
   "Recomendar ao cliente" mudou do resumo para o cabeçalho do quadro (mesmo
   `name`). Coluna recomendada tingida; coluna vazia acima da última com itens
   fica esmaecida com "Não será oferecida: sem itens" (o backend oferece
   sempre `1..maxLevel`). O quadro é reconciliado de forma **incremental** a
   cada `updateSummary` (roda a cada tecla): nunca recria `<tr>`/botões
   existentes, para não perder o foco do teclado. Item novo pelo botão geral
   nasce nas três opções (o técnico só TIRA o que não pertence).
4. **Regras**, ao vivo no quadro (`data-budget-tiers-alerts`) e no envio
   (antes do modal de revisão, ponto comum de criar e editar):
   - ERRO (bloqueia): item com conteúdo fora de todas as opções — "O item X
     não está em nenhuma opção — inclua em uma opção ou exclua o item." Sem
     isto o backend gravava `[1]` em silêncio. Também entra nas pendências da
     aba financeiro do wizard de criação.
   - AVISO (Swal "Salvar assim mesmo / Voltar e ajustar"): opção vazia abaixo
     da última com itens ("o cliente veria uma opção sem itens" — o backend
     gera o cartão "Nenhum item nesta opção"); opção igual à anterior ("o
     cliente não verá esta opção" — a landing esconde a redundante); só a
     Básica com itens ("o cliente verá um orçamento comum"); todas iguais ("o
     cliente verá uma única opção" — aqui o backend AINDA trata como com
     opções: envio sem PDF, aprovação exige escolher).
5. **Desligar o interruptor** com composição diferenciada (linhas com
   conjuntos diferentes, ex.: RAM 2/4/8 GB) pergunta "Manter os itens de qual
   opção?" (só opções com itens, padrão = recomendada ou a mais alta) e remove
   da lista os itens que estão só nas outras — juntar tudo numa lista única
   recriaria a soma 2GB+4GB+8GB por outra porta. Sem diferenciação, desliga em
   silêncio; cada linha guarda backup (`data-budget-levels-backup`) e religar
   restaura o que sobrou.
6. **Servidor** (`OrcamentoController::applyMaintenanceOptionsSwitch`):
   `oferece_opcoes=0` força `niveis=[1]` em todo item e zera
   `nivel_recomendado` (verdade no servidor mesmo se o JS falhar);
   `oferece_opcoes=1` + item com conteúdo sem nível → `ValidationException`
   em `itens.N.niveis` com mensagem autoexplicativa (o flash lista os erros
   planos). A chave nunca vai ao backend. Na reexibição após erro, o partial
   devolve o item órfão como órfão (`$field('niveis', old('itens') ? [] : [1])`)
   em vez de "só Básica".
7. **Detalhe** (`show.blade.php`): a coluna "Nível" com chips virou **uma
   coluna por opção** (✓/—, `data-budget-show-level-col/-cell`), a mesma
   matriz do formulário; o rodapé "Totais dos itens" some com opções (soma
   cega de toda linha não é o valor de nada quando há alternativas); novo link
   **"Abrir página do cliente"** (`target=_blank`; `GET /orcamento/{token}` não
   registra visualização — única mutação é `markExpired` em token vencido —
   mas a página mostra Aprovar/Recusar: é para conferir, não para clicar pelo
   cliente). Texto de cascata ("cada opção inclui tudo da anterior") corrigido
   aqui e no recap do formulário.
8. **Modal de revisão**: abaixo do total de cada opção, a lista literal dos
   itens que a compõem ("Itens da Básica: …").

**Bugs pré-existentes corrigidos no caminho:** (a) `createRow` usava
`count(linhas)` como índice — excluir uma linha do meio e adicionar outra
duplicava `itens[N]` e o PHP ficava com um item só; agora `nextRowIndex()` =
maior `data-index` + 1; (b) `getRowLevels` caía em `[1]` quando nada estava
marcado, escondendo o órfão e somando-o na Básica — devolve o conjunto cru
(o fallback fica só em `createRow`, para rascunhos antigos).

**Verificação:** `OrcamentoNiveisTest` 17/17 (5 novos: edição com opções
liga o interruptor e marca os checkboxes ocultos; sem opções fica desligado;
com `nivel_aprovado` some; `oferece_opcoes=0` manda `[1]` em todos e zera a
recomendação; `oferece_opcoes=1` rejeita item sem nível); conjunto
`Orcamento|Budget` do desktop 82 testes com as mesmas 3 falhas pré-existentes
(mensagens do envio para consulta e `@disabled` no marcador
`formas_pagamento[]`). Harness Chrome headless com o `orcamentos-form.js` real
(HTML dumpado por teste descartável, depois removido): ligar → 1 linha ✓✓✓;
"+ Item só nesta opção" na Completa → `false,false,true` e selo "Só na
Completa"; "nenhum" na Básica → aviso de opção vazia; RAM 2/4/8 + Diagnóstico
→ colunas R$ 150/350/550 iguais ao recap, subtotal gravado = 550; órfão →
linha vermelha + erro; excluir do meio e adicionar → `data-index` únicos;
desligar → Swal com select "Básica (2 itens) / Avançada / Completa", cancelar
mantém ligado, confirmar Avançada remove 2GB e 8GB e deixa tudo em `[1]`;
religar restaura. Screenshots em 1500/1300/430 px (em 430 px a tabela rola
dentro de `.table-responsive`; ≤768 px esconde o subtítulo das colunas).
Bump: **v6.0.1.0** (patch — só arquivos existentes, sem migration).

## 2026-09-22 — histórico do que foi oferecido, consulta pelo cliente, PDF da opção e detalhe na OS (v6.2.0.0)

**Problema:** a aprovação podava os itens fora da opção escolhida e gravava em
`orcamento_aprovacoes.niveis_snapshot` só nomes de item + totais — e ninguém lia
isso de volta. Depois da decisão, a página pública mostrava só o escopo contratado,
o PDF imprimia a opção como um campo solto, o detalhe do orçamento escondia o card
de opções (gated por `has_tiers`) e a OS não sabia de níveis.

**Decisões do usuário:** duas camadas — snapshot COMPLETO na aprovação (o que o
cliente vê depois) **e** os itens não aprovados preservados no banco, exclusivos
para uso técnico na edição ("reaproveitar", ex.: aprovou a Básica e agora quer a
Completa); PDF com a opção escolhida em seção organizada, sem citar as outras;
OS com resumo + itens por opção expansíveis (não a matriz).

**Peça central: `BudgetOfferedOptionsService`** (backend; depende só de
`BudgetCommercialTermsService`). `capture()` = `BudgetTotals::perLevel()` + por
nível `itens_detalhe` (id, tipo, referência, qtd, unitário, total, níveis) +
`condicoes_comerciais` (`forEachLevel()`); `termsLayout()` (o
`condicoes_comerciais_layout`, saiu de `publicBudgetPayload()`); `normalize()`
lê snapshot antigo (só strings) e novo; `forBudget()` é o único ponto que decide
o que mostrar: projeção viva enquanto `hasTiers()`, senão a última aprovação com
snapshot (`origem: atual|aprovacao`, `aprovacao.vigente` diz se a opção daquela
aprovação ainda é a contratada — uma edição posterior reabre a decisão e o bloco
vira histórico). `summarize()` alimenta `aprovacoes[].niveis_resumo`.

**Aprovação (`finalizeApproval`)**: snapshot agora é `capture()`; antes de
`applyApprovedLevel()`, os itens fora de `itemsForLevel()` (mesma função de
decisão) são copiados 1:1 para **`orcamento_itens_descartados`** (migration
`2026_09_22_000001`; model `BudgetDiscardedItem`; `Budget::discardedItems()`),
ligados à aprovação (`aprovacao_id`, `nivel_aprovado`, `item_original_id` só
como referência — `syncItems` recria ids a cada edição). Tabela própria, e não
um flag em `orcamento_itens`: há leitores crus/joins da tabela de itens
(`OrderWorkflowService` DB::table, `OrderCompletePdfContextFactory`,
`OrderClosureService` join, `OsAplicacaoPecaService`, `EstoqueReservaService`,
`BudgetOrderSyncService`, `BudgetTotals`) que ignorariam um global scope; o
invariante "itens do orçamento = escopo contratado" fica intocado.

**Payloads:** `budgetDetail()` ganha `niveis_ofertados`, `aprovacoes[].niveis_resumo`
e `itens_descartados` (com tudo que `createRow()` do form consome; uma revisão
enxerga também os da base, `orcamento_id IN (id, orcamento_revisao_de_id)`);
`OrderWorkflowService::mapLinkedBudget()` ganha `has_tiers`, `nivel_recomendado`,
`nivel_aprovado(_label)` e `niveis_ofertados`; `publicBudgetPayload()` ganha
`niveis_ofertados`, `aprovado_em` e `modo_consulta_opcoes`.

**Página pública:** `?opcoes=1` (só quando o cliente não pode mais responder e
há opções registradas — `BudgetPublicController@show` → `publicViewData($token,
$opcao, $consultarOpcoes)`) reabre a landing em modo consulta: mesmo partial
`opcoes.blade.php` com `$readOnly` (`offeredOptions`/`offeredLayout` do
snapshot), sem "Escolher esta opção", sem recusa, sem "Mais escolhida" automática,
selo verde "Sua escolha" + "Opção aprovada" no cartão escolhido, "Não escolhida"
nos outros, botão "Voltar ao orçamento aprovado". Na página aprovada, ao lado de
"Opção aprovada: X", link "Ver as opções apresentadas". Hero: "Estas foram as
opções apresentadas para o seu {aparelho}. Você escolheu a X em {data}."

**PDF:** `BudgetPdfContextFactory` ganha `orcamento.opcao_titulo` ("Opção de
manutenção: X" na projeção, "Opção aprovada: X" depois), `opcao_subtitulo`,
`opcao_itens_texto` ("2 itens (1 peça, 1 serviço)"), `opcao_aprovacao_texto`
("Aprovada pelo cliente em … pelo link público." / "Aprovada em nome do cliente
por Fulano em … pelo painel.") e `entrega_domicilio_label` — registradas em
`PdfTemplateRegistry`. `PdfDefaultTemplates::blocoOpcaoManutencao()` virou seção:
`cabecalho_secao` com o título + `grade_campos` (Opção, Cobertura, Valor total,
Itens incluídos) + campos condicionais Garantia/Parcelamento/Entrega + parágrafo
da aprovação; segue "Diferenciais desta opção" e a tabela de itens. Migration
`2026_09_22_000002` troca o bloco antigo (marcador `orcamento.opcao_texto`) pelo
novo em toda família `os_orcamento` publicada (idempotente por
`orcamento.opcao_titulo`; dev ficou na v8). PDF real do ORC-2609-000018: 51 KB.

**Desktop — detalhe do orçamento:** partial `orcamentos/partials/opcoes-oferecidas.blade.php`
substitui o card gated por `$hasTiers`: cartões por opção (aprovada em destaque
com chip "Aprovada pelo cliente"; "Não escolhida" nas outras; condições por opção
quando variam), "Comparativo do que foi oferecido" (linhas = união dos
`itens_detalhe`, ✓/— por opção, coluna aprovada em verde, itens fora do escopo
marcados, totais por opção; snapshot antigo → só descrição) e, pré-aprovação, o
mesmo card de sempre. Itens: subtítulo "Escopo contratado — X". Aprovações
recentes: nível + "Opções apresentadas: Básica R$ … · Avançada R$ …". Bloco
"Personalizado por opção" deixa de sumir pós-aprovação (rótulos do snapshot).
Fallback: payload sem `niveis_ofertados` mas com `has_tiers` usa `niveis`.

**Desktop — edição (reaproveitar):** painel `<details>` "Itens das outras opções
(não contratados) · N" logo abaixo da tabela de itens, agrupado com origem
("Ofertado na Avançada e Completa · 1 × R$ 200,00 · Cliente aprovou a Básica em
…") e botão "Adicionar ao orçamento" (`data-budget-discarded-add` + `data-item`
JSON). JS: `createRow({...item})` **sem `niveis`** (a linha segue o interruptor —
orçamento aprovado tem o interruptor escondido → `[1]`), `updateSummary()`, botão
vira "Adicionado". Nada no servidor até salvar; `syncItems` grava como item comum
e a edição de orçamento decidido já reabre a decisão (L912-920).

**Desktop — detalhe da OS:** em "Valores e Orçamento": linha "Opção aprovada";
bloco "Opções de manutenção oferecidas" (Opção | Itens (`<details>` com a lista)
| Total | Situação: Aprovada / Recomendada / Não escolhida / Em aberto) com "Ver
comparativo completo" → `/orcamentos/{id}#opcoes`; "Peças e serviços do
orçamento — escopo aprovado (X)".

**Testes:** backend `BudgetMaintenanceLevelsTest` 31/31 (3 asserts de redirect
atualizados para a querystring `resultado/mensagem`, que já era o contrato; novos:
snapshot completo + descartados + consulta + API + OS, consulta ignorada com
`can_respond`, snapshot legado, PDF (contexto + HTML renderizado por
`PdfTemplateRenderer` + validador) e a migration do template). Desktop
`OrcamentoNiveisTest` 21/21 (histórico pós-aprovação, snapshot legado, painel de
descartados e ausência dele), `DesktopFrontendTest` OS show com níveis.
Pré-existentes, NÃO desta entrega: 4 de `BudgetFlowTest` (mesmo redirect) + 1 de
`BudgetCommercialTermsTest` (textarea de rejeição fora do `<form>`, modal) no
backend; `BudgetCommercialTermsAssetsTest`, 2 "orcamentos send approval" e 2
"orders index" (trabalho em andamento de outra sessão) no desktop.
Verificação visual: página pública em 1200/390 px, detalhe do orçamento e da OS
via harness (payload real por tinker → view → Chrome headless), PDF via
`pdftocairo`.

## O que isto NÃO faz (v2, se os dados justificarem)

Procedência estruturada da peça, corte por valor de mercado do aparelho,
itens opcionais dentro de uma opção (a exceção continua sendo rejeitar →
revisão → reenviar), PDF comparativo das três opções, taxa/endereço/agendamento
da entrega (o campo base já fica pronto para a OS ler). Revisão de orçamento
já aprovado herda `nivel_aprovado` e por isso é sempre de escopo único.

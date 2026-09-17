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

## O que isto NÃO faz (v2, se os dados justificarem)

Procedência estruturada da peça, corte por valor de mercado do aparelho,
itens opcionais dentro de uma opção (a exceção continua sendo rejeitar →
revisão → reenviar), PDF comparativo das três opções, taxa/endereço/agendamento
da entrega (o campo base já fica pronto para a OS ler). Revisão de orçamento
já aprovado herda `nivel_aprovado` e por isso é sempre de escopo único.

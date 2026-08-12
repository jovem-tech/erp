# Integridade de leitura no motor de PDF, botão de aprovação e nome do documento editável

## Contexto

- versao: `5.24.0.0`
- data: `2026-08-11`
- ambiente-alvo: `Ubuntu VPS`

Três problemas observados no orçamento `ORC-2607-000027` emitido em produção:

1. A seção "GARANTIA" imprimiu o cabeçalho no pé da página 1 e o texto na
   página 2, quebrando o raciocínio da mensagem. O mesmo acontecia com a tabela
   de chave Pix (cabeçalho da tabela numa página, a linha na seguinte).
2. A aprovação online saía como uma URL crua de ~90 caracteres quebrada em duas
   linhas — nada intuitivo para o cliente.
3. O nome do documento (`{{ documento.nome }}`) era fixo em código, sem forma de
   alterar pelo editor.

## Entrega

### 1. Nenhuma informação separada entre páginas (regra do motor)

- `PdfTemplateRenderer::renderBlocks()` passou a amarrar todo bloco de cabeçalho
  (`cabecalho_secao`, `titulo`, `subtitulo`) ao primeiro bloco **visível** que
  vem depois, dentro de um grupo `.pdfe-keep` (`page-break-inside: avoid`).
  Cabeçalhos em sequência entram no mesmo grupo até chegar em conteúdo real.
- Blocos invisíveis no formato atual (ex.: `visivel_em: [a4]` ao gerar 80mm) são
  pulados: o cabeçalho se prende ao que realmente aparece.
- Tabelas com até 12 linhas ficam indivisíveis (`SHORT_TABLE_ROWS`), para o
  cabeçalho da tabela nunca ficar sozinho no pé. Tabelas maiores continuam
  quebrando e repetindo o `thead` — forçá-las inteiras só empurraria o problema.
- **É regra do motor, não de um modelo**: vale para os 7 documentos de fábrica e
  para qualquer modelo criado no editor, hoje ou no futuro, sem precisar tocar
  em schema nenhum.

### 1b. Correção do agrupamento: a seção INTEIRA, não só o primeiro bloco (v5.24.1.0)

A primeira versão prendia o cabeçalho apenas ao bloco seguinte. Não bastava:
"Condições de pagamento" ainda saía com "Formas aceitas" na página 1 e o
parcelamento + a tabela de chave Pix na página 2. Agora o grupo vai do
cabeçalho até o início da próxima seção.

A fronteira entre seções não é "o próximo cabeçalho", e sim **o próximo bloco
que carrega um cabeçalho em qualquer profundidade** (`containsHeading()`):

- um `condicional` COM cabeçalho dentro abre a própria seção → fecha o grupo;
- um `condicional` SEM cabeçalho é conteúdo da seção atual → entra no grupo
  (é o caso da linha de parcelamento e da tabela de chave Pix).

Sem essa distinção, "Itens do orçamento" absorvia todas as seções seguintes
(que são condicionais com cabeçalho) e empurrava o documento inteiro para a
página 2 — efeito observado e corrigido durante a validação.

### 2. Aprovação online como botão

- Novo tipo de bloco `botao_link` (validador + renderer + partial + catálogo do
  editor): texto, variável de destino, legenda e alinhamento.
- O destino sai **sempre de uma variável** do tipo documental e só vira `<a>`
  clicável quando é `http(s)` com host — `javascript:`, `file:`, `data:` e
  strings inválidas caem para um botão inativo. Um modelo não consegue
  transformar o botão em vetor de execução apontando a variável para outra coisa.
- No papel térmico (80mm) não existe clique: o endereço é impresso por extenso.
- O modelo de orçamento passou a usar o botão "Aprovar ou recusar orçamento",
  com a legenda "Link válido até <data>" logo abaixo. Cor verde (#198754), de
  ação positiva.
- **O botão some quando o orçamento vence** (v5.24.1.0): `BudgetPdfContextFactory`
  devolve `link_aprovacao` vazio para orçamento expirado, e o condicional do
  modelo esconde a seção inteira. Vale para qualquer modelo, não só o padrão —
  o documento não convida o cliente a clicar num link que já responde 410.
- A regra de prazo virou `Budget::publicLinkDeadline()` / `publicLinkExpired()`,
  consumida tanto pelo PDF quanto por `BudgetApprovalService` (que antes tinha
  a mesma lógica duplicada). Assim o 410 do link, a legenda impressa e a decisão
  de exibir o botão nunca discordam entre si.
- A legenda usa a nova variável `orcamento.validade_link` — o prazo que o
  backend realmente honra (`token_expira_em`, com fallback para
  `validade_data`). Assim o documento não promete uma data que o 410 vai
  desmentir. Também foi exposta `orcamento.validade_data`.

### 3. Nome do documento editável

- `PATCH /api/v1/knowledge/pdf-engine/templates/{template}` renomeia a família
  (nome + descrição). O `tipo_codigo` nunca muda: documentos já emitidos
  continuam apontando para ele.
- `PdfTemplateRegistry::get()` passou a preferir o nome gravado em
  `pdf_templates` inclusive nos tipos de sistema — o rótulo em código vira só o
  padrão de fábrica. O nome vale para as listagens e para `{{ documento.nome }}`
  impresso.
- Desktop: botão "Renomear" no editor abre modal com nome e descrição.

## Impactos

- **Migration** `2026_08_11_000001_replace_budget_approval_link_with_button`:
  troca, na posição original, o condicional que imprimia a URL pelo bloco do
  botão. Cirúrgica e idempotente (pula famílias que já têm `botao_link`);
  rascunhos são editados no lugar e versões publicadas são arquivadas com nova
  publicação, preservando a trilha de auditoria.
- **Contrato de rota**: só adição (`PATCH .../templates/{template}`).
- **Compatibilidade**: `.pdfe-keep` é puramente visual; nenhum schema existente
  precisou mudar por causa da regra de quebra de página. Modelos que já tinham
  a caixa de URL continuam válidos caso a migration seja pulada.
- Consequência esperada da regra de quebra: quando um bloco não cabe no resto
  da página, ele desce inteiro, deixando algum espaço em branco no fim da
  página anterior. É o custo de não separar a informação, e foi verificado que
  conteúdo maior que uma página continua quebrando normalmente.

## Pos-deploy obrigatorio

```bash
cd backend           && php artisan config:cache && php artisan route:cache
cd frontends/desktop && php artisan config:cache && php artisan route:cache
```

Rotas novas nos dois apps; reconstruir só um deixa a tela em 500 (ver nota de
`5.23.0.0`).

## Validacao

- `php -l` em todos os PHP alterados; `node --check` no JS do editor; Blades
  compilados via `BladeCompiler::compileString` + `php -l`.
- **Reprodução empírica antes de corrigir**: harness que renderiza um schema,
  gera o PDF e reporta em qual página cada marcador caiu. Com 32 parágrafos de
  enchimento o cabeçalho ficava na página 1 e o corpo na 2 — exatamente o bug
  relatado. Depois da correção, varredura de 28 a 44 parágrafos: sempre juntos.
- **Pior caso testado**: cabeçalho seguido de tabela de 90 linhas (maior que uma
  página) começando perto do pé. Resultado: cabeçalho desceu junto da primeira
  linha e a tabela quebrou normalmente entre as páginas 2 e 5 — sem estouro.
- **Varredura nos 7 tipos documentais**: para cada modelo publicado, comparação
  da última linha de cada página contra a lista real de `cabecalho_secao` do
  schema. Nenhum cabeçalho órfão em nenhum documento.
- PDF real do orçamento #52 regerado: "GARANTIA" e seu texto na mesma página; a
  tabela de chave Pix inteira na mesma página; botão presente com **anotação
  `/URI` clicável** de verdade no PDF (verificado no binário).
- **14 testes** em `tests/Unit/Services/Pdf/PdfDocumentLayoutTest.php`:
  agrupamento do cabeçalho, cabeçalhos consecutivos, bloco invisível no formato,
  **seção inteira junta**, **bloco com cabeçalho próprio abre nova seção**,
  tabela curta x longa, botão clicável com legenda, 5 destinos inseguros
  recusados, endereço impresso no 80mm e validação do bloco.
- **2 testes** de expiração em `BudgetCommercialTermsTest`: link somindo do
  contexto do PDF quando vence, e a regra única de prazo do link.
- **3 testes novos** de rename em `PdfTemplateEngineControllerTest`.
- **13 testes destravados**: `PdfTemplateEngineControllerTest` inteiro falhava
  desde que o catálogo RBAC compartilhado passou a semear o id 8 (`converter_os`),
  colidindo com o insert fixo de `publicar`/`restaurar` no `setUp`. Os ids agora
  saem de `max(id)+1`. Bug de fixture pré-existente, corrigido por cobrir
  justamente o motor alterado aqui.
- Suíte completa comparada contra worktree limpo em `HEAD`:
  - backend 480 passed / 12 failed vs. baseline 455 / 25 — **0 regressões**,
    13 corrigidas;
  - desktop 19 falhas em ambos, **0 regressões**.
  - As 12 falhas remanescentes são ambientais e pré-existentes (permissão de
    escrita em `storage/app/private`, comparações float/int em
    `FinanceiroMargemTest`).

## Correcao de infraestrutura de teste (v5.24.4.0)

`backend/phpunit.xml` passou a definir `VIEW_COMPILED_PATH` apontando para
`storage/framework/testing/views`. Antes, suíte e site compilavam Blade no mesmo
diretório: quem compilasse primeiro virava dono do arquivo e o outro quebrava com
`touch(): Utime failed: Operation not permitted` — o site com 500 e a suíte com
41 falhas em massa (todos os testes que renderizam view), sintoma que parece bug
de feature e não é. Com o diretório próprio, a colisão deixa de existir.

## Nota de metodo

A comparação de suítes das entregas anteriores (`5.23.x`) usava
`grep -oE "⨯ ..."` sobre a saída do Pest **sem remover os códigos ANSI**, o que
produzia dois arquivos vazios e um `diff` vazio — ou seja, não comprovava nada.
Aqui o filtro passou a remover ANSI antes de comparar (`sed 's/\x1b\[[0-9;]*m//g'`)
e a comparação é real. As conclusões das entregas anteriores foram reverificadas
com o método correto e continuam válidas.

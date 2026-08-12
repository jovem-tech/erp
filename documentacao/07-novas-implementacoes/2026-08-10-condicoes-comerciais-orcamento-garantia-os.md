# Condições comerciais estruturadas no orçamento e garantia registrada na OS

## Contexto

- versao: `5.23.0.0`
- data: `2026-08-10`
- ambiente-alvo: `Ubuntu VPS`
- spec: `specs/026-condicoes-comerciais-orcamento-garantia/spec.md`

O orçamento tinha um único campo livre ("Condições comerciais") onde formas de
pagamento, chave Pix, parcelamento sem juros e garantia precisavam ser digitados
manualmente a cada proposta. Por ser lento e repetitivo, o campo ficava em branco
na maioria dos orçamentos — o cliente não recebia nem como pagar nem por quanto
tempo o serviço estava garantido. As colunas `os.garantia_dias` e
`os.garantia_validade` existiam no banco desde o legado, mas nenhuma tela as
preenchia.

## Entrega

### Chaves Pix (Financeiro > Configurações > Formas de Pagamento)

- Nova tabela `financeiro_chaves_pix` (tipo, chave, titular, instituição,
  principal, ativo, ordem) com CRUD em `/api/v1/financeiro/chaves-pix`.
- Gerenciadas pelo botão **Chaves** na própria linha da forma "Pix" — é onde o
  usuário procura. O painel abre sozinho quando já existem chaves.
- Apenas uma chave permanece marcada como principal (`enforceSinglePrincipalPix`).
- O catálogo (`/api/v1/financeiro/catalogo`) passou a expor `chaves_pix` e
  `chaves_pix_tipos`.

### Condições comerciais do orçamento

- `orcamentos` ganhou `garantia_dias` e `parcelas_sem_juros` (ambas nullable).
- Nova tabela `orcamento_formas_pagamento`: as formas aceitas são gravadas com
  código, rótulo e tipo **congelados** — renomear ou excluir uma forma no
  catálogo não reescreve o que já foi proposto ao cliente.
- `BudgetCommercialTermsService` é a fonte única do texto exibido: monta formas
  aceitas, parcelamento, chaves Pix e garantia para a tela, o link público de
  aprovação e o PDF, para as três superfícies dizerem exatamente a mesma coisa.
- As chaves Pix são resolvidas **na leitura**, não congeladas: se a empresa
  trocar de chave, a proposta ainda válida passa a exibir a chave certa em vez
  de mandar o cliente pagar numa chave morta.
- Parcelamento só existe acompanhando um cartão parcelável; débito é cartão mas
  não parcela, e fica fora do texto.
- O campo livre continua, renomeado para "Observações complementares".
- Formulário (`orcamentos/form.blade.php` + `orcamentos-form.js`): checkboxes das
  formas ativas, select de parcelas (só quando há cartão parcelável), prévia das
  chaves Pix (só quando Pix é aceito) e select de garantia (90 dias / 180 dias /
  1 ano / 2 anos).
- **Revisão final** (modal "Confirmar salvamento do orçamento", v5.23.1.0): novo
  card "Condições comerciais" listando formas aceitas, parcelamento, chave(s) Pix
  e garantia. Antes o modal só lia o campo livre e mostrava
  "Condicoes comerciais: Nao informado" mesmo com tudo preenchido. O campo livre
  virou "Observacoes complementares" ali também, alinhado ao formulário.
- O rascunho automático (localStorage) passou a guardar grupos de múltipla
  escolha como lista: sem isso todas as caixas colapsariam na mesma chave e a
  seleção de formas de pagamento se perderia ao restaurar — justamente o dado
  que esta entrega existe para parar de perder.

### Garantia na OS

- O prazo do orçamento acompanha a OS desde o vínculo
  (`OrderWorkflowService::linkBudgetToOrder`), sem sobrescrever prazo já definido.
- `GET /api/v1/orders/{id}/closure` passou a devolver `garantia` (opções + prazo
  sugerido): a OS primeiro, o orçamento aprovado mais recente como fallback.
- A baixa aceita `garantia_dias` e, nos encerramentos que entregam equipamento
  reparado (`entregue_reparado_pago`, `entregue_reparado_sem_custo`,
  `entregue_reparado_garantia`), grava o prazo e calcula
  `garantia_validade = data_entrega + dias`.
- Devolução sem reparo e descarte não concedem garantia e **não apagam** o que já
  estava gravado.
- `orders/show.blade.php` passou a exibir "Entrega", "Garantia" e "Garantia até".

### Motor de PDF

- Novas variáveis em `os_orcamento`: `orcamento.formas_pagamento`,
  `orcamento.chaves_pix`, `orcamento.parcelamento`, `orcamento.garantia_dias`,
  `orcamento.garantia_prazo`, `orcamento.garantia_texto`,
  `orcamento.condicoes_comerciais`; coleções `formas_pagamento` e `chaves_pix`.
- `os.garantia_prazo` (prazo por extenso) disponível em todos os tipos de OS.
- Modelo padrão ganhou as seções "Condições de pagamento" e "Garantia"
  (`PdfDefaultTemplates::blocosCondicoesComerciais()`, compartilhada com a
  migration para modelo novo e antigo nunca divergirem).

### Apresentacao na pagina publica de aprovacao (v5.24.2.0)

O bloco de condições comerciais no link do cliente saía como uma sequência de
linhas pequenas e cinzas, difícil de ler. Passou a usar a linguagem visual da
própria página:

- **Formas de pagamento** viram chips individuais; o parcelamento fica como nota
  no mesmo cartão.
- **Garantia** ganha cartão próprio com o prazo em destaque e a explicação
  abaixo.
- **Chave Pix** ganha caixa destacada em verde, com a chave em fonte
  monoespaçada, corpo maior e `user-select: all` — é o dado que o cliente copia.
  Titular e instituição ficam como linha secundária.
- Grade responsiva: dois cartões lado a lado no desktop, empilhados no celular,
  com a chave reduzida para caber sem cortar.
- **Botão "Copiar" ao lado de cada chave** (v5.24.3.0), com dois caminhos
  encadeados: `navigator.clipboard` quando existe e é permitido; senão (ou se a
  permissão for negada) seleção do nó + `execCommand('copy')`. O encadeamento
  importa porque o link costuma ser aberto por IP com certificado próprio, onde
  o navegador **não** considera a origem segura e a API de clipboard nem existe.
  Quando nem o `execCommand` funciona, a chave fica selecionada e o rótulo vira
  "Selecione e copie" — o cliente ainda copia com Ctrl+C.
  Cada botão aponta para o id da sua própria chave, então com várias chaves
  cadastradas nenhuma copia a do vizinho.

### Ordem dos botoes na pagina publica (v5.24.4.0)

A decisão passou a vir primeiro e o download por último:

```
[ Aprovar proposta ]  [ Rejeitar proposta ]
Se desejar, informe o motivo da rejeição
[ ................................... ]

[ Baixar PDF ]
```

Detalhe que a mudança exigiu: o "Rejeitar" agora fica **acima** do campo de
motivo, e aninhar `<form>` dentro de `<form>` é inválido. Os dois formulários
ficam sem botão dentro e os botões se ligam a eles pelo atributo HTML `form`.
Sem isso, o cliente digitaria o motivo e ele não seria enviado.

## Impactos

- **Migrations aditivas**: `CREATE TABLE financeiro_chaves_pix`,
  `CREATE TABLE orcamento_formas_pagamento`, `ADD COLUMN` nullable em
  `orcamentos`. Nenhum `DROP`, nenhuma alteração de FK/índice existente.
- **Modelos PDF já publicados**: `2026_08_10_000003_add_commercial_terms_to_pdf_templates`
  insere os blocos novos antes da assinatura (ou ao final do corpo) e troca
  `{{ os.garantia_dias }} dia(s)` por `{{ os.garantia_prazo }}`. Nada é removido
  ou reposicionado; rascunhos são editados no lugar e versões publicadas são
  arquivadas com uma nova publicação, preservando a trilha de auditoria e as
  personalizações do usuário. É idempotente (não duplica os blocos).
- **Contratos de rota**: só adições. `condicoes_comerciais` no detalhe do
  orçamento, `condicoes_comerciais_catalogo` no form-data, `garantia` na
  metadata de baixa, `chaves_pix` no catálogo financeiro. Nenhum consumidor
  existente depende da ausência desses campos.
- **Compatibilidade**: `orcamentos.condicoes` continua existindo e sendo exibido;
  orçamentos antigos não perdem nada. Ambientes sem as migrations aplicadas
  degradam para lista vazia em vez de erro (`Schema::hasTable`).
- Uma migration pré-existente do módulo `chat`
  (`2026_07_19_000003_add_managed_file_uuid_to_message_attachments`) falha neste
  ambiente por falta de permissão do usuário `erp_app` no banco
  `sistema_erp_chat`; por isso as três migrations desta entrega foram aplicadas
  por `--path`. Não é efeito colateral desta entrega.

## Pos-deploy obrigatorio

Esta entrega adiciona rotas **nos dois apps**. Como ambos rodam com rota em
cache (`bootstrap/cache/routes-v7.php`), é obrigatório reconstruir os dois —
reconstruir só um deixa a tela de Configurações financeiras em **500**
(`Route [financeiro.configuracoes.pix.save] not defined`, visível apenas em
`frontends/desktop/storage/logs/laravel-<data>.log`):

```bash
cd backend           && php artisan config:cache && php artisan route:cache
cd frontends/desktop && php artisan config:cache && php artisan route:cache
```

Se o cache de views compilado ficar com arquivos de outro dono que não
`www-data`, rodar `php artisan view:clear` nos dois apps (ver
`documentacao/` sobre o 500 de `touch(): Utime failed`).

## Validacao

- `php -l` em todos os PHP alterados (sem erros).
- Blades alterados compilados individualmente via `BladeCompiler::compileString`
  + `php -l` (sem tocar o cache de views compartilhado, que neste ambiente causa
  500 por `touch(): Utime failed` quando gerado por usuário != www-data).
- `node --check` em `orcamentos-form.js`.
- **20 testes novos**, todos passando:
  - `tests/Feature/Api/V1/BudgetCommercialTermsTest.php` (9): persistência das
    formas/garantia/parcelas, chaves Pix só com Pix aceito, descarte de
    parcelamento sem cartão parcelável, payload parcial preservando condições,
    limpeza explícita, códigos desconhecidos ignorados, catálogo do form-data,
    contexto de PDF e rótulos de prazo.
  - `tests/Feature/Api/V1/OrderWarrantyClosureTest.php` (6): data de término na
    baixa, ausência de garantia em devolução sem reparo, fallback para o prazo da
    OS, sugestão vinda do orçamento, precedência da OS sobre o orçamento, 422 em
    prazo inválido.
  - `tests/Feature/Api/V1/FinanceiroChavePixTest.php` (5): catálogo, chave
    principal única, duplicidade rejeitada, desativação/exclusão, tipo inválido.
  - `BudgetCommercialTermsTest` cobre ainda um botão "Copiar" por chave, cada um
    com `data-copy`/`data-copy-target` apontando para a sua — o erro que faria
    todos copiarem a mesma chave.
  - Envio do motivo verificado em Chrome real: com o botão acima do campo, o
    submit interceptado carrega `motivo_rejeicao` preenchido e aponta para a
    rota `rejeitar` — a regressão silenciosa que a reordenação poderia causar.
  - Verificação do botão em Chrome real (headless), clicando via script: o alvo
    correto é selecionado (clicar no 2º botão seleciona a 2ª chave). A escrita
    efetiva na área de transferência **não** pôde ser verificada aqui — o
    headless nega a permissão de clipboard nos dois caminhos —, então o que está
    provado é a fiação e a degradação, não o `write` em si.
  - `BudgetCommercialTermsTest` cobre ainda a renderização da página pública
    (chips, garantia em destaque e chave Pix isolada) — regressão que só
    apareceria em runtime, como a variável indefinida encontrada ao montar o
    bloco.
  - `frontends/desktop/tests/Unit/BudgetCommercialTermsAssetsTest.php` (10):
    prende o contrato entre a Blade e o JS — cada marcador `data-budget-*`
    precisa existir dos dois lados, o campo é enviado como array com marcador
    vazio (para permitir desmarcar tudo) e o rascunho trata grupos `[]` como
    lista. Renomear um marcador de um lado só não quebraria nenhum teste de
    request, mas quebraria a tela em silêncio.
- Suíte completa comparada contra um worktree limpo em `HEAD`:
  - backend 452 passed / 25 failed vs. baseline 426 / 25 — conjunto de falhas
    **byte a byte idêntico** (`diff` vazio), todas pré-existentes
    (permissão em `storage/app/private`, `permissoes.id` duplicado no
    `PdfTemplateEngineControllerTest`, comparações float/int em
    `FinanceiroMargemTest`);
  - desktop 205 passed / 19 failed vs. baseline 203 / 19 — falhas idênticas,
    todas causadas por `no such table: user_preferences` no fixture de teste.
- `PdfSchemaValidator` executado contra o modelo de orçamento republicado:
  zero erros.
- As 6 views alteradas renderizadas server-side com dados representativos
  (`view()->render()`, sem HTTP), conferindo o HTML resultante: configurações
  financeiras (chaves Pix), formulário e detalhe do orçamento, baixa e detalhe
  da OS, e página pública de aprovação. Os 44 nomes de rota usados nessas views
  resolvidos um a um por `Router::has()`.
- Smoke test end-to-end em transação com `ROLLBACK` sobre o orçamento real #53:
  texto gerado, contexto de PDF e **PDF renderizado** (944 KB) conferidos —
  seções "Condições de pagamento" (com tabela de chave Pix) e "Garantia"
  presentes e no lugar certo.

## Pendencia conhecida (pre-existente, fora do escopo)

`OrderClosureService::metadata()` devolve `orcamento_pendente_aprovacao`, mas o
controller de API não repassa essa chave, então
`orders/closure.blade.php` sempre a lê como ausente. O bloqueio real continua
valendo no backend (`close()` retorna `delivery_requires_approved_budget`), então
não há falha de regra de negócio — apenas o aviso preventivo na tela não aparece.
Não foi alterado aqui para não misturar escopos.

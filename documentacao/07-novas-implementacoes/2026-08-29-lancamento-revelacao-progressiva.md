# Formulário de lançamento: revelação progressiva (2026-08-29)

**Tipo:** refinamento de UX + correção de 6 defeitos (MINOR)

## O problema

O dono abriu `/financeiro/novo` e disse: *"existem campos sendo exibidos que não
têm necessidade de ser exibidos no momento, causando confusão ao operador"*.

Ao inventariar a tela, os quatro blocos que ele apontou não sofriam só de
excesso — **três tinham bug de verdade**:

- **"Repetir 12 meses" era inalcançável no caminho normal.** O checkbox só
  aparecia com o select `dre_fixo_mensal` valendo exatamente `'1'`. Deixar o
  filtro em "Todas as categorias" e escolher **Aluguel** produzia um lançamento
  fixo no backend com o checkbox invisível. O caso de uso mais óbvio da
  funcionalidade estava fora de alcance.
- **Trocar "a pagar" → "a receber" deixava o Repetir visível e marcado** num
  recebimento: `syncRepetirVisibility()` não estava na lista de handlers do Tipo.
  O servidor limpava; a tela mentia.
- **Dois donos do mesmo `d-none`**: escolher 12x escondia o Repetir, mexer no
  select depois o trazia de volta.

Mais três: a tela **apagava trabalho do operador** (trocar de categoria — ou só
dar F5 — limpava OS e Cliente já escolhidos); o switch **"Lançamento avulso"
ficava visível sem efeito nenhum** quando OS/Cliente já estavam escondidos; e o
aviso *"Selecionar Pago gera a baixa automaticamente"* ficava na tela em
lançamentos pendentes.

E uma contradição de negócio: marcar **Despesa fixa escondia a seção de vínculos
inteira e forçava `avulso`** — uma despesa fixa não podia ter fornecedor,
enquanto o texto do próprio campo dizia "toda conta a pagar deve estar vinculada
a um fornecedor". Aluguel tem locador.

## A regra que governa o refinamento

> **Campo que some nunca perde o que o operador digitou. O que muda é se o valor
> viaja no submit.**

Esconder ⇒ `disabled`, nunca limpar. Campo `disabled` não é enviado no POST e não
participa da validação HTML5 — mata de quebra a classe de bug "campo `required`
invisível trava o submit sem mensagem", que o próprio código já conhecia.

Zerar só **flags de ação** que criam registros que ninguém pediu:
`repetir_proximos_meses` (gera 11 títulos) e `parcelas` (gera N).

## O que mudou

| Antes | Agora |
|---|---|
| `Despesa fixa?` com opção neutra `Todas as categorias` — pergunta de um eixo, resposta de outro | **`Mostrar: Todas / Só fixas / Só variáveis`** (`categoria_filtro`), filtro honesto que não classifica nada |
| A classificação era uma pergunta ao operador | É um **resultado exibido**: *"Classificação: Despesa fixa (padrão de Aluguel) · alterar"* |
| `dre_fixo_mensal` no select do topo | `dre_fixo_mensal` no **override**, fechado e `disabled` por padrão — o backend aplica o padrão da categoria, que é o caminho normal |
| Repetir amarrado ao select | Segue a **classificação efetiva** |
| Despesa fixa escondia VÍNCULOS inteiro e apagava o fornecedor | Seção **sempre visível**; somem só OS e Cliente. Fornecedor **obrigatório em toda conta a pagar** |
| Forma de pagamento sempre visível | Só quando faz efeito: `tipo === 'pagar' \|\| status === 'pago'` |
| Cliente em "a pagar" prometia gravar | Texto honesto: o backend **descarta** `cliente_id` em despesas — o campo só pré-filtra a busca de OS |
| Seção "DATAS E STATUS" | Vira **PAGAMENTO** e recebe **Valor** e **Data de vencimento** (v5.69.2.0): o núcleo responde só *o que é* — tipo, categoria, descrição — e a seção agrupa *quanto, quando e como* |

`categoria_filtro` está fora do whitelist de `validatedPayload()`, então **nunca
chega à API** — zero mudança de controller e de backend nesta entrega.

## Donos únicos (a causa-raiz de dois bugs)

Três funções passaram a ser as **únicas** que escrevem no que controlam:

- **`syncRepetir()`** — único dono de `#financeiroRepetirWrapper`. Promovida ao
  escopo do módulo justamente para que nenhum `init` "seja dono" dela.
- **`syncOsHabilitada()`** — único dono do `disabled` do select de OS, fazendo o
  OU das duas razões (avulso marcado, OS oculta). Sem isso o bug dos dois donos
  voltaria de outra roupa.
- **`initStatusHints()`** — único dono dos dois `<small>` sob o Status, rodando
  **incondicionalmente**. Antes quem alternava era `syncStatusLock()`, dentro de
  `initCartaoCredito`, que faz `return` cedo quando não há cartão cadastrado:
  numa instalação sem cartões os hints nunca eram sincronizados.

E **`sincronizarTudo()`** substituiu as três listas paralelas de chamadas (carga,
handlers, `pageshow`) que já tinham divergido — é o que produziu o B1.

## `classificacaoEfetiva()` espelha o backend

`override > valor gravado > padrão da categoria > false`, igual a
`FinanceiroService::resolveClassification()`. Duas consequências:

- **Categoria criada na hora** (Select2 `tags`) cai em variável, que é
  literalmente o `?? false` do backend. A linha diz *"(categoria nova — o sistema
  assume variável)"*.
- **Em edição o backend prioriza o valor gravado** sobre o padrão da categoria:
  trocar a categoria **não** reclassifica. A linha diz *"(deste lançamento)"* —
  sem isso a tela prometeria uma mudança que o servidor ignora, que é a mesma
  classe de mentira que motivou a entrega.

`shouldRepeatFixedExpense()` já testava `$financeiro->dre_fixo_mensal`
**resolvido**, não o payload — revelar o checkbox bastou.

## O filtro precisava filtrar de verdade

Descoberta durante a implementação: o filtro antigo marcava `option.hidden` +
`disabled`, e **o Select2 renderiza opções `disabled`** — cinzentas, mas
visíveis. Um filtro que promete "só as fixas" e mostra as variáveis em cinza não
é filtro. Foi adicionado um `matcher`, com duas exceções que passam sempre: a
opção **já selecionada** (o filtro não pode invalidar a escolha do operador) e a
**sem `data-fixo`** (a pessoa está digitando um nome novo).

## Testes

Reescritos porque o comportamento mudou de propósito: os dois que afirmavam que
VÍNCULOS sumia na despesa fixa, e o dos rótulos do campo antigo. Substitutos:
`test_despesa_fixa_ainda_oferece_fornecedor`,
`test_despesa_fixa_esconde_o_switch_avulso`,
`test_recebimento_mantem_avulso_e_esconde_fornecedor`,
`test_repetir_aparece_para_categoria_fixa_sem_filtro` (trava o bug principal),
`test_filtro_nao_esconde_a_categoria_ja_escolhida`.

**448 testes no desktop** com as mesmas 5 falhas pré-existentes da baseline;
backend intocado (907, 1 falha pré-existente).

⚠️ **Armadilha de regex:** três wrappers estão presos a
`/id="X"\s+class="d-none"/`, que exige `class` imediatamente após o `id` e
`d-none` como único valor — `#financeiroOsWrapper`, `#financeiroClienteWrapper`,
`#financeiroCartaoCreditoWrapper`. Neles, nunca adicionar segunda classe nem
inserir atributo entre o `id` e o `@class`. Todo teste novo usa a forma frouxa
`[^>]*\bd-none\b`; afrouxar as três existentes é PR separado.

## Ajuste seguinte (v5.69.1.0)

**A seção "Entrada no estoque" passou a aparecer só na categoria de compra de
peça.** Antes bastava ser "a pagar", então ela acompanhava imposto, aluguel e
folha — despesas que nunca dão entrada em estoque —, poluindo justamente o
caminho mais comum.

O critério é o **grupo de DRE** `Custo Direto (OS)`, não o nome da categoria:
hoje só "Compra de peças" está nesse grupo, mas o catálogo já prevê "Compra
emergencial de peças", e casar pelo nome exigiria lembrar de mexer no código.
É o mesmo `$isPecaCategoria` que já governa OS e Cliente.

O botão **"Entrada por compra"** da tela de Estoque passou a pré-selecionar a
categoria de peça (`primeiraCategoriaDePeca()`, também pelo grupo). Sem isso ele
levaria a uma tela onde a seção prometida não está.

### O bug que apareceu no caminho

`<div class="desktop-form-section" id="..." @class(['d-none' => ...])>` emite
**dois atributos `class`**, e o navegador honra o primeiro — o `d-none` era
simplesmente ignorado. A seção de estoque nunca esteve escondida no HTML
renderizado desde a `039`; só o JS a escondia depois da carga, o que também
significa um flash visível em conexão lenta e nada de degradação sem JS.

Corrigido em **5 lugares** do formulário (dois vinham da entrega anterior, dois
do bloco de cartão e um da seção de estoque), fundindo tudo num `@class([...])`
só. Vale como regra: neste projeto, `class="..."` e `@class([...])` **não
convivem na mesma tag**.

## Fora de escopo

- **`financeiro/help.blade.php`** — a dor é in-loco e se resolve no ponto de uso
  (7 tooltips + 16 textos inline). Além disso o módulo não segue a convenção
  `<modulo>/help.blade.php` dos outros dez (usa `financeiro/cartoes-help.blade.php`
  com rota própria); padronizar é conversa de spec.
- **`cliente_id` descartado em "a pagar"** — nesta entrega só o texto ficou
  honesto. Remover o campo ou passar a gravá-lo é decisão de produto.

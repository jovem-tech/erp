# Correcao de colisao do Select2 no formulario Financeiro (desktop)

## Contexto

- versao: `3.9.1.0`
- data: `2026-07-05`
- ambiente-alvo: `Ubuntu VPS` (reproduzido em `192.168.1.100`)
- area afetada: `frontends/desktop/resources/views/financeiro/form.blade.php`
- scripts envolvidos: `frontends/desktop/public/assets/js/financeiro-form.js` e `frontends/desktop/public/assets/js/desktop.js`

## Problema observado

Ao abrir `Financeiro > Lancamentos > Novo`, o console do navegador registrava, na
inicializacao dos campos `Categoria` e `Cliente`:

- `Uncaught TypeError: r.GetData(...).destroy is not a function`
- capturado tambem pelo `[Desktop][window.onerror]`, com stack apontando para
  `initCategoriaSelect` em `financeiro-form.js`.

## Causa raiz

- os selects `financeiroCategoria` e `financeiroClienteId` usavam
  `data-select2="false"` apenas para impedir o init global do `desktop.js`
  (`initSelect2`, que varre `select.form-select` automaticamente);
- jQuery expoe atributos `data-*` do HTML automaticamente via `.data(chave)` —
  `data-select2="false"` fica acessivel como `$(select).data('select2') === false`;
- o construtor do Select2 roda `null != GetData(el, "select2") && GetData(el, "select2").destroy()`
  antes de qualquer coisa. Como `false !== null`, ele tentava chamar `.destroy()`
  no booleano `false`, quebrando a inicializacao manual feita por
  `initCategoriaSelect`/`initClientSelect`;
- esta e' a mesma causa raiz ja documentada em
  `2026-06-29-select2-manual-init-collision-desktop.md` (tela Nova OS), so' que
  o formulario de Financeiro foi construido depois e nao seguiu a "regra de
  ouro" ja registrada naquela nota.

## Correcao aplicada

- os dois selects em `financeiro/form.blade.php` passaram a usar
  `data-native-select="true"` em vez de `data-select2="false"` — mesmo
  atributo de exclusao ja usado em `orders/_wizard.blade.php` e
  `knowledge/reported-defects/form.blade.php`, sem colisao com a chave interna
  do Select2;
- `financeiro-form.js` nao precisou de mudanca de logica: o guard
  `if ($(select).data('select2')) { return; }` ja funciona corretamente uma vez
  que o atributo conflitante deixou de existir.

## Validacao

- `node --check public/assets/js/financeiro-form.js` sem erros de sintaxe;
- recarga manual de `/financeiro/novo` sem o `TypeError` no console;
- mudanca nao altera contrato de API, banco ou permissao — apenas atributo HTML
  em uma view do desktop.

## Regra de ouro (reforco)

Nunca usar `data-select2="false"` para excluir um `<select>` do init global do
Select2 — o nome colide com a chave de dados interna do proprio plugin. Usar
sempre `data-native-select="true"` (ja suportado pelo seletor de
`initSelect2` em `desktop.js`).

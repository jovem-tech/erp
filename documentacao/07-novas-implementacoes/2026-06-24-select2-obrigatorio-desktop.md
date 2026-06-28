# 2026-06-24 - Select2 obrigatório no desktop ERP

## Versão

- Desktop ERP: `v3.1.7`

## Escopo entregue

- regra global de plataforma para que todo `select` visível do `frontends/desktop` use `Select2`;
- uso do tema `Bootstrap 5` para manter a leitura visual alinhada ao shell do desktop;
- inicialização compartilhada em `frontends/desktop/public/assets/js/desktop.js`, sem inicializações soltas por view;
- suporte a `dropdownParent` automático em `modal` e `offcanvas`;
- helper global exposto como `window.DesktopUi.refreshSelect2(container)` para reinicializar conteúdo dinâmico;
- suporte à reconstrução de cascatas de catálogo em runtime, como `tipo -> marca -> modelo` no cadastro de equipamentos;
- mensagens do Select2 ajustadas para `pt-BR`.

## Ajustes técnicos relevantes

- o layout base passou a carregar `select2-bootstrap-5-theme.min.css` junto com os assets globais do desktop;
- o CSS do desktop passou a tratar a apresentação do Select2 como parte do padrão visual oficial;
- o helper global aceita exceções explícitas apenas para controles técnicos ou ocultos por meio de `data-native-select="true"` ou `data-select2="false"`.

## Regra obrigatória: como reagir a mudanças de valor em select controlado por Select2

Todo `select.form-select` do desktop é automaticamente envolvido pelo Select2 (`frontends/desktop/public/assets/js/desktop.js`). Quando o usuário escolhe uma opção pela interface do Select2 (mouse ou teclado), o valor do `<select>` original é atualizado e o aviso de mudança é disparado via **jQuery**, internamente equivalente a `jQuery(select).trigger('change')`.

Esse disparo via `jQuery.trigger()` **não invoca listeners registrados com a API nativa `addEventListener('change', ...)`**, pois o jQuery, para tipos de evento sem método nativo correspondente (`change` não é uma função em `HTMLSelectElement`), apenas percorre seu próprio registro interno de handlers — registrado por `jQuery(...).on(...)` — sem chamar `element.dispatchEvent(...)`. Esse comportamento foi confirmado com jQuery `3.7.1` e Select2 `4.1.0-rc.0` (as mesmas versões carregadas pelo layout) em ambiente de teste isolado.

Na prática:

- ❌ `select.addEventListener('change', handler)` em um select controlado por Select2 nunca é chamado quando a opção é escolhida pela UI do Select2;
- ✅ `jQuery(select).on('change', handler)` é chamado tanto pela seleção feita via Select2 quanto por um evento `change` nativo genuíno (disparado, por exemplo, por `select.dispatchEvent(new Event('change'))`).

### Causa raiz encontrada em 2026-06-24

O cadastro de equipamentos (`frontends/desktop/public/assets/js/equipments-create.js`) vinculava os campos `tipo`, `marca`, `modalidade desktop` e `cliente` com `addEventListener('change'|'select2:select'|'select2:clear', ...)`. Resultado: ao escolher o `Tipo` pela UI do Select2, a cascata `tipo -> marca -> modelo` nunca era recalculada e o campo `Marca` permanecia desabilitado. Corrigido com um helper local (`onSelectEvent`) que usa `jQuery(...).on(...)` quando disponível, com fallback para `addEventListener` apenas se o jQuery não estiver carregado.

### Mesmo bug corrigido no dashboard

- `frontends/desktop/public/assets/js/dashboard.js` (função `bindFilters`) vinculava os filtros `Ano`, `Mês do equipamento` e `Ano do equipamento` com `addEventListener('change', syncDashboard)`. Como esses três `select` também são `form-select` controlados pelo Select2, a troca de filtro pela UI não recarregava os widgets. Corrigido com o mesmo padrão: `jQuery(element).on('change', syncDashboard)` quando o jQuery está disponível, com fallback para `addEventListener` apenas se o jQuery não estiver carregado.

## Validação executada

- revisão dos selects visíveis do desktop para confirmar que a seleção visível segue o helper global;
- checagem do carregamento do Select2 na base do layout;
- checagem da compatibilidade do dropdown em modais e offcanvas;
- reprodução isolada (jQuery `3.7.1` + Select2 `4.1.0-rc.0` reais, fora do navegador) confirmando que `jQuery(...).trigger('change')` não chega a listeners registrados com `addEventListener('change', ...)`, e que o mesmo disparo chega normalmente a `jQuery(...).on('change', ...)`.

## Próximo passo sugerido

- aplicar o mesmo padrão Select2-first em qualquer novo formulário ou filtro do desktop, sem criar inicializações locais por página;
- ao adicionar um novo filtro ou cascata de selects, vincular sempre via `jQuery(...).on('change', ...)` (ou helper equivalente) em vez de `addEventListener`, para não repetir o mesmo bug.

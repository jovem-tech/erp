# Plano — Atalhos F1..F4 do "+ Novo"

Só `frontends/desktop`. Nenhuma rota, migration ou mudança de backend.

## Marcação

`resources/views/layouts/partials/navbar.blade.php` — cada item do dropdown ganha
`data-desktop-quick-create="os|orcamento|venda|lancamento"` (o alvo do atalho) e um
`<kbd class="desktop-shortcut-key">F1</kbd>` visível. Os `@if` de permissão que já
existiam continuam intactos: são eles que decidem se o alvo existe.

## Comportamento

`public/assets/js/desktop.js` — `initQuickCreateShortcuts()`, registrada no bloco de
bootstrap junto das demais `initX()`. Um `keydown` em `document` que:

1. ignora a tecla se houver modificador;
2. desiste se alguma `[data-desktop-fkeys-owner]` da página reivindicar **essa tecla** (valor vazio reivindica todas);
3. desiste se houver `.modal.show`;
4. procura `[data-desktop-quick-create="<alvo>"]` — não achou (sem permissão),
   devolve a tecla ao navegador;
5. `preventDefault()`, chama `window.erpMarkInternalNavigation?.()` e dispara
   `link.click()`.

O `.click()` no próprio `<a>` é o que faz o atalho herdar de graça a URL, o RBAC e o
listener de navegação interna do guard de sessão. A chamada explícita a
`erpMarkInternalNavigation` é redundância deliberada: esse guard já causou logout
falso em navegação por JavaScript antes.

## Opt-out do PDV

`resources/views/vendas/pdv.blade.php` — um
`<div data-desktop-fkeys-owner="F2 F3 F4" hidden>` no topo do `@section('content')`.
F1 fora da lista de propósito.

## Estilo

`public/assets/css/desktop.css` — `.desktop-quick-create-item` vira flex com
`space-between` e `.desktop-shortcut-key` desenha a tecla. Nenhuma regra existente
disputa `display` com essas.

## Testes

`tests/Feature/Desktop/QuickCreateShortcutsTest.php` (5): alvos e teclas renderizados;
alvo some sem permissão de criar; o PDV reivindica exatamente F2/F3/F4; telas comuns
não reivindicam nada. O quinto lê o `vendas-pdv.js` e confere que as teclas que ele
trata são as mesmas que o Blade reivindica — sem isso, o dia em que o PDV mudar de
tecla a lista passa a mentir e o atalho global volta a brigar com o balcão em silêncio.

O comportamento do `keydown` em si não é coberto — a suíte é de renderização, sem
navegador. Fica na verificação manual.

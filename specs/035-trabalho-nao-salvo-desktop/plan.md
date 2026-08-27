# Plano — Proteção de trabalho não salvo

## Shell

`public/assets/js/desktop.js`, no topo do IIFE (fora do `init()`, porque os scripts
de página se registram no mesmo `DOMContentLoaded` e precisam da API já existindo):

- `unsavedWorkProbes` (Set) e `hasUnsavedWork()`, que engole exceção de sonda;
- `window.erpRegisterUnsavedWork(probe)`, que devolve uma função de cancelamento;
- um `beforeunload` que, havendo trabalho, agenda `hidePageLoader` e dispara o
  diálogo nativo com `preventDefault()` + `returnValue = ''`.

Fica **antes** de `initPageTransitions` de propósito: `tests/Unit/PageTransitionScriptTest.php`
fatia o arquivo de `initPageTransitions` até `initSidebar` e exige que não haja
`beforeunload` nesse trecho.

## PDV

`public/assets/js/vendas-pdv.js`:

- registra a sonda `() => !submitLiberado && itensBody.querySelectorAll('.pdv-item').length > 0`;
- `limparVenda()` (a limpeza crua, que já existia inline no Esc) e
  `limparVendaComConfirmacao()` (SweetAlert2, `focusConfirm`, fallback para
  `window.confirm` se o Swal não carregar);
- o Esc passa a chamar a versão com confirmação. É o único caminho que limpa o
  carrinho — não há botão "Limpar" na tela.

## Testes

`tests/Unit/UnsavedWorkGuardTest.php` (5), no padrão de asset JS que
`PageTransitionScriptTest` já usa: API exposta de forma síncrona; o guard solta o
loader; sonda quebrada não prende; a sonda do PDV exclui `submitLiberado`; o Esc
não apaga direto.

O comportamento em si (o diálogo do navegador) não dá para testar sem navegador —
fica na verificação manual.

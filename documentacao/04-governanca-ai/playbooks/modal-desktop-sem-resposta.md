# Playbook - Modal desktop sem resposta no clique

## Objetivo

Dar um norte rápido para agentes de IA quando um botão dentro de modal do `frontends/desktop` aparenta estar clicável, mas não salva, não fecha o modal e não mostra nenhuma mensagem de erro.

## Incidente de referência

- tela: `http://127.0.0.1:8080/equipamentos/novo`
- fluxo: cadastro rápido de cliente
- sintoma reportado: o botão `Cadastrar cliente` não fazia nada
- hipótese inicial do operador: problema visual de `z-index`

## O que este incidente provou

Nem todo modal “morto” é problema de `z-index`, `backdrop` ou `pointer-events`.

Neste caso, o problema era de ciclo de vida do DOM:

- o layout base renderizava `@yield('scripts')` antes de `@stack('modals')`;
- `frontends/desktop/public/assets/js/equipments-create.js` executava imediatamente;
- nesse momento, `#quickClientModal`, `#quickClientForm` e `#quickClientSubmit` ainda não existiam no DOM;
- os listeners nunca eram vinculados;
- o botão ficava visível, mas sem comportamento;
- como o clique nem chegava ao submit real, também não apareciam erros inline nem toast.

## Processo executado

1. Reproduzir o sintoma informado na tela de `Novo equipamento`.
2. Comparar o fluxo com a OS, que já usa o parcial compartilhado `clients.quick-modal`.
3. Confirmar que o modal de cliente em `Equipamentos` foi unificado com o mesmo componente da OS.
4. Inspecionar `frontends/desktop/resources/views/layouts/app.blade.php`.
5. Validar a ordem real de render:
   - `window.__DESKTOP_FLASH`
   - scripts globais
   - `@yield('scripts')`
   - `@stack('modals')`
6. Cruzar essa ordem com `frontends/desktop/public/assets/js/equipments-create.js`, que resolve elementos do modal no topo do arquivo e chama `initQuickAdd()` sem aguardar `DOMContentLoaded`.
7. Concluir que o problema era ausência do elemento no momento do bind, não camada visual.
8. Corrigir o layout para renderizar `@stack('modals')` antes dos scripts.
9. Proteger a correção com teste de render conferindo que o modal aparece antes de `equipments-create.js` e `clients-form.js`.

## Correção aplicada

### Regra de layout

No layout base do desktop, modais empilhados por `@push('modals')` devem existir no HTML antes de qualquer script de página que dependa deles.

Arquivo corrigido:

- `frontends/desktop/resources/views/layouts/app.blade.php`

Mudança aplicada:

- `@stack('modals')` foi movido para antes de:
  - jQuery
  - Bootstrap
  - Select2
  - SweetAlert2
  - `desktop.js`
  - `@yield('scripts')`

### Regra de diagnóstico

Quando um modal do desktop “não faz nada”:

1. Não assumir `z-index` como causa raiz.
2. Verificar se o elemento existe no DOM no momento em que o script tenta fazer `document.getElementById(...)`.
3. Verificar se o script depende de `DOMContentLoaded`, ou se executa imediatamente.
4. Conferir se o modal veio por `@push('modals')` e se o layout o renderiza antes dos scripts.
5. Só depois investigar `z-index`, `dropdownParent`, `pointer-events`, backdrop ou clipping visual.

## Checklist para futuras IAs

Use esta ordem:

1. Confirmar se o clique aciona listener algum.
2. Conferir console e logs do frontend.
3. Conferir se os elementos do modal existem antes do bind JS.
4. Conferir a ordem entre `@stack('modals')` e `@yield('scripts')`.
5. Conferir se o modal foi duplicado na view ou se deveria usar parcial compartilhado.
6. Conferir se o problema é visual ou de lifecycle.
7. Escrever ou atualizar teste de regressão do HTML renderizado.

## Testes usados para validar a correção

- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=equipment_create_page`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=quick_client_store`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=nova_os_page_renders_quick_client_modal`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=nova_os_button_visible_and_create_page_renders_form`

## Arquivos de referência

- `frontends/desktop/resources/views/layouts/app.blade.php`
- `frontends/desktop/resources/views/equipments/create.blade.php`
- `frontends/desktop/resources/views/clients/quick-modal.blade.php`
- `frontends/desktop/public/assets/js/equipments-create.js`
- `frontends/desktop/public/assets/js/orders-create.js`
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`

## Regra permanente

Se um script do desktop depende de elementos de modal renderizados por `@push('modals')`, a arquitetura deve garantir uma destas duas condições:

- o modal é renderizado antes do script executar; ou
- o bind é atrasado explicitamente até `DOMContentLoaded` ou outra etapa segura de inicialização.

Sem isso, o sistema pode parecer “visualmente correto” e ainda assim falhar silenciosamente.

# 2026-06-25 - Modal de cliente sem resposta no cadastro de equipamentos

## Versão

- Desktop ERP: `v3.1.9`

## Resumo

Foi corrigido um bug silencioso no cadastro rápido de cliente dentro de `equipamentos/novo`: o botão `Cadastrar cliente` aparecia normalmente, mas não executava o submit nem mostrava erro.

## Causa raiz

O layout base do desktop renderizava `@stack('modals')` depois dos scripts. Como `frontends/desktop/public/assets/js/equipments-create.js` faz o bind dos elementos do modal logo ao carregar, os elementos `#quickClientModal`, `#quickClientForm` e `#quickClientSubmit` ainda não existiam no DOM naquele momento.

Resultado:

- nenhum listener era preso;
- o clique não disparava submit;
- não havia toast, erro inline nem fechamento do modal;
- o sintoma podia ser confundido com `z-index`, mas não era problema visual.

## Correção aplicada

- o modal de cliente em `Equipamentos` foi mantido no parcial compartilhado `clients.quick-modal`;
- o layout `frontends/desktop/resources/views/layouts/app.blade.php` passou a renderizar `@stack('modals')` antes dos scripts globais e dos scripts específicos da página;
- o teste de render do cadastro de equipamentos passou a validar que o HTML do modal aparece antes de `equipments-create.js` e `clients-form.js`.

## Aprendizado registrado

- em modais do desktop, primeiro validar lifecycle do DOM antes de suspeitar de CSS;
- qualquer botão “morto” dentro de modal deve levar à checagem da ordem `@stack('modals')` x `@yield('scripts')`;
- incidentes desse tipo devem ser documentados em `documentacao/04-governanca-ai/playbooks/`.

## Validação executada

- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=equipment_create_page`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=quick_client_store`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=nova_os_page_renders_quick_client_modal`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=nova_os_button_visible_and_create_page_renders_form`

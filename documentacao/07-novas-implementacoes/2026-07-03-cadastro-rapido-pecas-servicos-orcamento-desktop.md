# Cadastro rapido de pecas e servicos no orcamento desktop

**Data:** 2026-07-03
**Versao:** 3.5.3
**Modulo:** `frontends/desktop` + `backend`

## Contexto

O fluxo de orcamento precisava permitir a criacao imediata de uma peca ou de um servico quando o catalogo ainda nao possui o item desejado, sem obrigar o usuario a abandonar a OS ou recarregar a tela.

## Entrega

- botao `Cadastrar` ao lado da referencia do item no formulario de orcamento;
- modal compartilhado para cadastro rapido de peca ou servico, com campos especificos por tipo;
- rotas autenticadas no desktop para persistir rapidamente servicos e pecas no backend central;
- retorno do item criado para a linha atual do orcamento, com atualizacao imediata do catalogo local da tela;
- filtro de referencia mantido por tipo selecionado, evitando mistura entre pecas e servicos;
- feedback de erro tratado em JSON para nao quebrar o fluxo da pagina.

## Impactos

- o backend central continua como fonte de verdade para os catalogos;
- o desktop so orquestra a experiencia, sem acesso direto ao banco;
- nao houve alteracao de contrato no `backend/openapi.yaml`;
- o modal respeita a permissao do usuario para exibir apenas os tipos de cadastro liberados.
- o tipo inicial do modal agora segue o tipo da linha que disparou a acao, evitando abrir servico quando a linha e peca e vice-versa.

## Validacao

- `node --check frontends/desktop/public/assets/js/orcamentos-form.js`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter="test_orcamentos_create_page_renders_dynamic_item_reference_select_without_select2_exclusion|test_orcamentos_create_page_renders_quick_item_modal_and_inline_button_when_catalog_permissions_exist|test_quick_service_store_creates_service_and_returns_json|test_quick_part_store_creates_part_and_returns_json"`
- `php scripts/php/sync-agent-docs.php`

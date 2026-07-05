# Orçamentos: campos monetários em BRL com entrada segura

**Data:** 2026-07-03  
**Versão:** 3.5.3  
**Módulo:** `frontends/desktop` + `backend`

## Contexto

A tela de orçamento exibia valores monetários em formato inconsistente e, em alguns cenários, a digitação com vírgula era interpretada de forma errada, o que fazia `330,00` virar `33000.00` na composição do total.

## Entrega

- campos monetários do orçamento, do resumo financeiro e do cadastro rápido agora exibem `R$ 0,00` como padrão visual;
- os campos continuam prontos para edição ao receber foco, sem bloquear a digitação;
- o JavaScript do orçamento passou a interpretar corretamente `R$`, vírgula decimal e separadores de milhar;
- os totais da tela são recalculados com base em valores numéricos normalizados;
- o submit do orçamento, do cadastro rápido de serviço e do cadastro rápido de peça converte valores monetários para formato numérico antes de enviar ao backend central;
- o backend do desktop normaliza valores monetários antes da validação, garantindo compatibilidade com entradas como `R$ 330,00`, `330,00` e `330.00`.

## Impactos

- a experiência visual fica padronizada em BRL sem quebrar o fluxo de edição;
- a API central continua recebendo valores numéricos normalizados, mantendo a fonte de verdade no backend;
- não houve alteração de `backend/openapi.yaml` nesta entrega;
- a mudança reduz risco de erro de cálculo por parsing incorreto de moeda.

## Segurança

- a normalização acontece no desktop antes do envio e é revalidada no backend, reduzindo risco de payload inconsistente;
- não houve introdução de acesso direto a banco nem de operação insegura de formulário.

## Validação

- `node --check frontends/desktop/public/assets/js/orcamentos-form.js`
- `php -l frontends/desktop/app/Http/Controllers/DesktopController.php`
- `php artisan test --filter='test_orcamentos_create_page_renders_dynamic_item_reference_select_without_select2_exclusion|test_orcamentos_create_page_renders_quick_item_modal_and_action_button_when_catalog_permissions_exist|test_orcamentos_create_page_renders_quick_item_button_label_for_piece_rows'`
- `php artisan test --filter='test_orcamentos_store_normalizes_brazilian_currency_values_before_forwarding_to_backend|test_quick_service_store_creates_service_and_returns_json|test_quick_part_store_creates_part_and_returns_json'`


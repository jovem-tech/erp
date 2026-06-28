# 2026-06-25 - Vinculo de catalogo no quick-add de equipamentos

## Versao

- Desktop ERP: `v3.1.11`

## Problema observado

- ao cadastrar uma marca nova dentro de `/equipamentos/novo`, a UI mantinha o item disponivel apenas na sessao atual;
- ao cadastrar um modelo novo, o vinculo com o tipo selecionado tambem nao ficava persistido fora do estado local do JavaScript;
- o sintoma aparecia como "a marca/modelo salvou, mas nao ficou amarrado ao tipo".

## Causa raiz

- o frontend mantinha o escopo do catalogo apenas em estruturas transientes de memoria no `equipments-create.js`;
- a API de quick-add nao recebia `tipo_id`, entao o backend criava a marca/modelo sem persistir a relacao de catalogo;
- a tabela legada `equipamentos_catalogo_relacoes` exige `modelo_id` nao nulo, o que impedia registrar um vinculo puro `tipo -> marca` sem uma estrategia de compatibilidade.

## Solucao aplicada

- `POST /api/v1/equipments/brands` e `POST /api/v1/equipments/models` passaram a exigir `tipo_id`;
- o desktop same-origin passou a validar e repassar `tipo_id` para o backend central;
- a criacao rapida de modelo agora grava explicitamente o vinculo `tipo -> marca -> modelo`;
- a criacao rapida de marca usa uma ancora tecnica inativa `__CATALOG_BRAND_SCOPE__` para manter o vinculo `tipo -> marca` sem expor um modelo falso ao usuario;
- o `form-data` continua retornando `catalog_relations`, enquanto a lista `models` segue ocultando a ancora tecnica por usar apenas modelos ativos.

## Arquivos principais afetados

- `backend/app/Services/EquipmentWorkflowService.php`
- `backend/app/Http/Requests/Api/V1/StoreEquipmentBrandRequest.php`
- `backend/app/Http/Requests/Api/V1/StoreEquipmentModelRequest.php`
- `backend/app/Http/Controllers/Api/V1/EquipmentController.php`
- `frontends/desktop/app/Http/Controllers/EquipmentController.php`
- `frontends/desktop/public/assets/js/equipments-create.js`
- `backend/tests/Concerns/BuildsLegacyErpSchema.php`
- `backend/tests/Feature/Api/V1/EquipmentCreationTest.php`
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`

## Validacao executada

- `php artisan test tests/Feature/Api/V1/EquipmentCreationTest.php --filter=quick_add_brand_and_model_create_catalog_entries`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=quick_`
- `node --check public/assets/js/equipments-create.js`
- `php scripts/php/sync-agent-docs.php`

## Nota para futuras IAs

Se o escopo de catalogo voltar a se perder, consultar primeiro `documentacao/04-governanca-ai/playbooks/catalogo-equipamentos-vinculo-rapido.md`. O ponto critico nao e visual: e contratual entre desktop, API e a tabela legada de relacoes.

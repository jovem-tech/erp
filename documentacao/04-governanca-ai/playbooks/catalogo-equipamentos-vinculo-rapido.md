# Playbook - Vinculo rapido de catalogo em equipamentos

## Quando consultar este playbook

- a marca criada no quick-add some ao trocar de tela ou recarregar o formulario;
- o modelo novo salva, mas nao volta filtrado para o tipo selecionado;
- a UI parece correta no momento do cadastro, porem o catalogo perde o escopo depois.

## Sintoma real ja encontrado

O `frontends/desktop/public/assets/js/equipments-create.js` adicionava a marca e o modelo novos apenas em estruturas transientes de memoria (`transientBrandIdsByType` e `transientModelIdsByTypeBrand`). Isso fazia a UX funcionar na sessao atual, mas o backend nao persistia o escopo em `equipamentos_catalogo_relacoes`.

## Regra correta

1. quick-add de marca deve enviar `tipo_id` junto com `nome`;
2. quick-add de modelo deve enviar `tipo_id`, `marca_id` e `nome`;
3. o backend central deve persistir a relacao de catalogo no momento da criacao;
4. o desktop so pode considerar o fluxo concluido quando o reload do `form-data` mantiver o mesmo escopo.

## Nuance critica do legado

A tabela real `equipamentos_catalogo_relacoes` exige `modelo_id` nao nulo.

Por isso, para registrar `tipo -> marca` antes do primeiro modelo real:

- o backend usa o modelo tecnico inativo `__CATALOG_BRAND_SCOPE__`;
- esse modelo fica com `ativo = 0`;
- `formData()` nao expoe esse modelo em `models`, entao ele nao aparece para o usuario;
- `catalog_relations` continua trazendo a linha, o que mantem a marca habilitada para o tipo certo.

## Arquivos de referencia

- `backend/app/Services/EquipmentWorkflowService.php`
- `backend/app/Http/Requests/Api/V1/StoreEquipmentBrandRequest.php`
- `backend/app/Http/Requests/Api/V1/StoreEquipmentModelRequest.php`
- `frontends/desktop/app/Http/Controllers/EquipmentController.php`
- `frontends/desktop/public/assets/js/equipments-create.js`
- `backend/tests/Feature/Api/V1/EquipmentCreationTest.php`
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`

## Checklist de diagnostico

1. confirmar se o request same-origin do desktop envia `tipo_id`;
2. confirmar se a API central recebe `tipo_id` e responde `201`;
3. consultar `equipamentos_catalogo_relacoes` para verificar a linha do tipo/marca/modelo;
4. se houver apenas marca nova sem modelo real, verificar a ancora `__CATALOG_BRAND_SCOPE__` em `equipamentos_modelos` com `ativo = 0`;
5. validar se `GET /api/v1/equipments/form-data` devolve `catalog_relations` com o escopo esperado;
6. validar se o dropdown de `models` continua sem expor a ancora tecnica.

## Validacao minima

- `php artisan test tests/Feature/Api/V1/EquipmentCreationTest.php --filter=quick_add_brand_and_model_create_catalog_entries`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=quick_`
- `node --check public/assets/js/equipments-create.js`

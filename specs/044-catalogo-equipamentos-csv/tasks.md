# Tarefas — Catálogo de equipamentos com importação CSV

## 1. Backend — CONCLUÍDA

- [x] `EquipmentCatalogService` extraído de `EquipmentWorkflowService` — mesmos
      helpers de escopo (`ensureBrandTypeCatalogScope`, `ensureModelTypeCatalogScope`,
      `ensureCatalogRelationRecord`, `ensureBrandScopeAnchorModel`), agora
      públicos e reaproveitados. `EquipmentWorkflowService::createBrand/createModel`
      passam a delegar — comportamento idêntico, coberto por
      `EquipmentCreationTest::test_quick_add_brand_and_model_create_catalog_entries`.
- [x] Lookups case-insensitive (`findTypeByName/findBrandByName/findModelByName`)
      via `LOWER(nome)` — nunca depender da collation do banco (MySQL é CI,
      SQLite dos testes não é).
- [x] `paginateModels/listBrands/listTypes` com filtros (busca, tipo, marca,
      status) e contagens, excluindo sempre a âncora técnica das listagens.
- [x] `renameModel/setModelActive/renameBrand/setBrandActive/createType/
      renameType/setTypeActive` — nunca excluem, protegem a âncora.
- [x] `importCsv` — upsert aditivo, aliases de cabeçalho, dedupe dentro do
      arquivo, limite de 5000 linhas, relatório por linha.
- [x] `exportRows`/`csvTemplateRows` — round-trip com o import.
- [x] `EquipmentCatalogController` (12 endpoints) em `Api/V1`, RBAC via
      `equipamentos:visualizar|criar|editar|exportar|importar` (módulo já
      existia, sem migration de RBAC).
- [x] Rotas em `routes/api.php`, prefixo `equipments/catalog/*` (literais,
      sem colisão com `equipments/{equipment}`).
- [x] `openapi.yaml` — 12 paths documentados.

## 2. Desktop — CONCLUÍDA

- [x] `EquipmentCatalogService` (wrapper fino sobre `ApiClient`).
- [x] `EquipmentCatalogController` — index por aba (`?aba=modelos|marcas|tipos`),
      save/toggle de modelo/marca/tipo, export/template/import CSV.
- [x] Rotas em `routes/web.php`, prefixo `/equipamentos/catalogo/*` (antes de
      `/equipamentos/{equipment}`).
- [x] Item **Equipamentos** na sidebar (`DesktopNavigation`, seção Cadastros),
      posicionado antes do item oculto "Aparelhos / Equip." — mesmo módulo
      `equipamentos`, então também vira o novo destino de fallback de
      `firstAllowedRouteName()` para quem só tem `equipamentos:visualizar`.
- [x] Views: `equipments/catalog/{index,help}.blade.php` +
      `partials/{tab-modelos,tab-marcas,tab-tipos,modals,import-report}.blade.php`.
      Abas por link (`?aba=X`, não JS) para preservar filtro/paginação por
      página carregada do servidor.
- [x] `equipments-catalog.js` — abre modal de novo/renomear, desabilita os
      campos de escopo (tipo/marca) que só fazem sentido na criação. Sem
      fetch: forms clássicos POST/PATCH + reload.

## 3. Testes — CONCLUÍDA

| Arquivo | Testes |
|---|---|
| `backend/tests/Feature/Api/V1/EquipmentCatalogTest.php` | 10 — listagem/filtros/sem-âncora, contagens de marcas/tipos, criar/renomear/desativar modelo sem excluir, marca vinculada via âncora, import com casing+erro por linha, cabeçalho inválido, limite de linhas, export→reimport idempotente, permissões |
| `backend/tests/Feature/Api/V1/EquipmentCreationTest.php` | 17 (regressão pós-extração do serviço) |
| `frontends/desktop/tests/Feature/Desktop/EquipmentCatalogTest.php` | 9 — menu na sidebar com/sem permissão, aba Modelos preserva filtros na paginação, abas Marcas/Tipos com contagens, salvar modelo (POST), desativar marca (PATCH), relatório de import com erro por linha, import sem arquivo válido, botões de export/import respeitam permissão |

Suítes completas rodadas (backend 1306 testes, desktop 615) — únicas falhas
são em `EstoqueFlowTest`/`DesktopFrontendTest` (Peça/Orçamento/Financeiro),
arquivos sendo editados por outra sessão concorrente no mesmo servidor,
confirmado via `git status` (não tocados por esta entrega).

Verificação extra contra o banco real `sistema_hml` (dev, `192.168.1.100`) via
`DB::transaction()` com rollback forçado: import de CSV misto (marca com
casing diferente do já cadastrado, modelo novo, tipo inexistente) produziu os
contadores e o erro de linha esperados, sem alterar o banco.

## 4. Documentação — CONCLUÍDA

- [x] `specs/044-catalogo-equipamentos-csv/{spec.md,tasks.md}` (este arquivo).
- [x] `documentacao/07-novas-implementacoes/2026-09-11-catalogo-equipamentos-tipos-marcas-modelos-csv.md`.
- [x] `backend/openapi.yaml` atualizado.

## 5. Versionamento

- [x] `./scripts/bump-version.sh --tier=minor --desc="Catálogo de equipamentos (tipos/marcas/modelos) com importação CSV"`.

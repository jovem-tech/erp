# Tarefas — Edição em massa por ID e mesclagem de duplicados

## 1. Backend — CONCLUÍDA

- [x] `exportRows()` ganha `id` (id do modelo, vazio nas linhas só-marca) e
      `mesclar_com_id` (sempre vazio na exportação) — coluna `id` primeiro,
      `mesclar_com_id` por último. `csvTemplateRows()` e o header hardcoded
      de `EquipmentCatalogController::exportCsv()` atualizados junto.
- [x] `mapHeaderColumns()` reconhece `id`/`mesclar_com_id` como aliases
      opcionais — CSV sem essas colunas continua casando por nome, sem
      nenhuma mudança de comportamento.
- [x] `applyImportRow()` bifurca no início: `id` preenchido vai para
      `applyImportRowById()`; sem `id`, comportamento idêntico ao da `044`.
- [x] `applyRenameByIdRow()` — mira o registro exato, exige `marca` batendo
      com a marca atual (case-insensitive), garante o escopo do `tipo` via
      `ensureModelTypeCatalogScope` (já existente), aplica nome/ativo. Mesmo
      `id` repetido no arquivo com dados diferentes gera erro na 2ª
      ocorrência; repetido idêntico é idempotente (`ignorados`).
- [x] `applyMergeRow()` + `mergeModel()` — valida alvo (existe, ativo, mesma
      marca, diferente de si mesmo, não é perdedor de outra linha no mesmo
      arquivo via `collectLoserIdsInFile()`), repontam `equipamentos.modelo_id`
      e `orcamentos.equipamento_modelo_id`, migram as relações do perdedor
      via `ensureCatalogRelationRecord()` (evita violar a unique key quando o
      vencedor já cobre o mesmo tipo), desativam o perdedor e chamam
      `OrderSearchIndexService::rebuildForEquipment()` para cada aparelho
      afetado.
- [x] Resultado de `importCsv()` ganha `mesclados` (int) e `mesclagens`
      (`[{de, para}]`).
- [x] `openapi.yaml` — descrições de `exportar-csv`/`importar-lote`
      atualizadas com as duas colunas novas.

## 2. Desktop — CONCLUÍDA

- [x] Coluna **ID** nas tabelas de Modelos e Marcas (ajuda a cruzar com o
      CSV exportado).
- [x] `partials/import-report.blade.php` — seção "mesclado(s)" no resumo e
      tabela "ID perdedor → ID vencedor" quando houver mesclagens.
- [x] `help.blade.php` — seção nova explicando `id` (edição em massa) e
      `mesclar_com_id` (mesclagem), incluindo o aviso de mesma marca/alvo
      ativo e a nota sobre `php artisan os:reindexar-busca`.

## 3. Testes — CONCLUÍDA

| Arquivo | Testes novos |
|---|---|
| `backend/tests/Feature/Api/V1/EquipmentCatalogTest.php` | +6 — export inclui id, rename por id sem casar por nome, rejeita marca incompatível, id repetido (conflitante gera erro / idêntico é idempotente), mesclagem reponta equipamentos+orçamentos+relações e desativa o perdedor, mesclagem rejeita marcas diferentes/alvo inativo/ele mesmo/cadeia |
| `frontends/desktop/tests/Feature/Desktop/EquipmentCatalogTest.php` | +2 — coluna ID renderiza, relatório de mesclagens renderiza |

Suíte completa: backend 1312 testes (1 falha pré-existente e não
relacionada, em `EstoqueFlowTest`/Peça — arquivo em edição por outra sessão
concorrente); desktop 617 testes (10 falhas pré-existentes e não
relacionadas, em Peça/Orçamento/Financeiro — mesma causa, confirmado via
`git status` antes desta entrega).

Verificação extra contra o banco real de desenvolvimento (`sistema_hml`,
`192.168.1.100`) via `DB::transaction()` com rollback forçado, reproduzindo
o cenário real do pedido: criados dois modelos "J7 TESTE-045"/"j 7 TESTE-045"
na marca Samsung de verdade, um aparelho apontando para um deles, mesclados
os dois em um terceiro modelo "SM-J730G TESTE-045" — aparelho repontado,
ambos os perdedores desativados, relação de catálogo do vencedor preservada
sem violar a unique key, tudo revertido ao final.

## 4. Documentação — CONCLUÍDA

- [x] `specs/045-catalogo-equipamentos-edicao-massa-mesclagem/{spec.md,tasks.md}`.
- [x] `documentacao/07-novas-implementacoes/2026-09-11-catalogo-equipamentos-edicao-massa-mesclagem.md`.
- [x] `backend/openapi.yaml` atualizado.

## 5. Versionamento

- [x] `./scripts/bump-version.sh --tier=minor` — v5.85.0.0.

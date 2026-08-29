# Tarefas — Estoque: núcleo, razão e custo médio

## Bloco A — Tipos decimais ✅
- [x] Migration de alargamento (`DB::statement`, no-op SQLite, `down()` vazio)
- [x] Espelho em `BuildsLegacyErpSchema` (pecas + movimentacoes)
- [x] Casts `decimal:4` em `Peca` e `Movimentacao`
- [x] `SaleStockService::quantidadeSql()` + 3 interpolações + 4 leituras
- [x] `EstoqueController`: validação, resposta, CSV export/import
- [x] Desktop: `StockController`, 3 views, helper `$qtd()`, `step="any"`
- [x] `EstoqueQuantidadeDecimalTest` (3 casos, inclui guarda de locale)
- [x] `EstoqueTest` no desktop — **primeiro teste de estoque do frontend**
- [x] Aceite duro: `SaleFlowTest`/`SaleReturnFlowTest` sem alteração

## Bloco B — Razão e motor único
- [ ] Migration de colunas do razão + `estoque_localizacoes`
      ⚠️ `movimentacoes.custo_unitario` **já foi criada pela `039`** (entrada de
      estoque no lançamento): com N itens numa compra, o custo por linha só
      existe no instante do lançamento, então não dava para esperar. Use
      `Schema::hasColumn()` — vira no-op. A semântica é a desta spec: custo
      congelado na movimentação.
      ⚠️ `movimentacoes.financeiro_id` também já existe (mesma entrega). Não é
      `compra_id`: um título a pagar não é uma nota de compra, e os dois vão
      coexistir na mesma linha quando Compras nascer.
- [x] `EstoqueMovimentacaoService` + DTOs + exceções — **entregue pela `038`**
      (motor único, lock ordenado, agregação por peça). Falta só o custo médio.
- [ ] `CustoMedioCalculator` + teste unitário das 4 bordas
- [ ] `SaleStockService` como fachada (testes de venda intocados)
- [ ] Fechar `update()` / `store()` / `importCsv()`
- [ ] `Peca::scopeEstoqueBaixo()` unificando os 4 lugares
- [ ] Telas: razão global, localizações, colunas novas
- [ ] Teste de concorrência no grupo `mysql`

## Pendente do usuário (externo)
- [ ] Confirmar que a aplicação legada (outro servidor) não formata
      `quantidade_atual` — MySQL passa a devolver `"3.0000"` no lugar de `"3"`
- [ ] Aplicar a migration na base de desenvolvimento

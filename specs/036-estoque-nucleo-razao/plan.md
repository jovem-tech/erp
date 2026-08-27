# Plano — Estoque: núcleo, razão e custo médio

## Fase 1a — Tipos decimais (ENTREGUE)

### Banco
`2026_08_27_000001_widen_stock_quantities_to_decimal.php` — ALTER via
`DB::statement` (doctrine/dbal não é dependência direta, `->change()` não
funciona), no-op em SQLite. `down()` vazio de propósito: DECIMAL→INT truncaria
saldo em silêncio.

Espelho obrigatório em `tests/Concerns/BuildsLegacyErpSchema.php` — o trait roda
**depois** das migrations e recria `pecas`/`movimentacoes` do zero.

### Models
`Peca::$casts` e `Movimentacao::$casts` de `'integer'` para `'decimal:4'`. Era o
bug mais provável da entrega: o cast trunca ao LER, mesmo com a coluna certa.

### Serviços
`SaleStockService` ganhou `quantidadeSql()`. As três atualizações de saldo são
`DB::raw` com valor interpolado; float sob locale pt_BR sairia `"1,5"` e
quebraria o SQL, e valores pequenos virariam notação científica.

### API
`EstoqueController`: validação `integer` → `numeric`, casts de resposta
`(int)` → `(float)`, CSV com `formatQuantidadeCsv()` (formato pt-BR igual ao dos
preços, para o import conseguir reler), import passa a usar `normalizeDecimal()`.

### Desktop
`StockController` idem. Views ganham o helper `$qtd()` que apara zeros à direita
("10", não "10,0000"). Campos numéricos com `step="any"`.

## Fase 1b — Razão, motor único e custo médio (PENDENTE)

### Banco
Colunas em `pecas` (`tipo_item`, `controla_estoque`, `custo_medio`,
`custo_ultima_entrada`, datas, `quantidade_reservada`, `fornecedor_id`,
`localizacao_id`, `lead_time_dias`, `estoque_seguranca`, `ponto_pedido`,
`curva_abc`) e em `movimentacoes` (`custo_unitario`, `custo_total`,
`saldo_anterior`, `saldo_posterior`, `custo_medio_posterior`, `motivo_codigo`,
`documento`, `fornecedor_id`, `compra_id`, `compra_item_id`, `contagem_id`,
`reserva_id`, `estorno_de_id` UNIQUE). Tabela nova `estoque_localizacoes`.

### Serviços
`App\Services\Estoque\EstoqueMovimentacaoService` como motor único;
`CustoMedioCalculator` puro; `SaleStockService` vira fachada fina.

### API
Fechar os três furos do razão (`update`, `store`, `importCsv`), razão global,
estorno, localizações.

### Desktop
Disponível/Reservado/Custo médio/Valor em estoque; quantidade readonly no
formulário; razão global; cadastro de localizações.

# Estoque: núcleo, razão e custo médio

## Problema

O ERP entregou margem de contribuição e DRE gerencial na v5.57.0.0, e o CMV é
**R$ 0,00 nas 2.187 OS entregues e pagas**. Não é erro de cálculo: nenhum
caminho do sistema cria movimentação de estoque a partir de uma OS. O consumo
de peça é 100% manual e ninguém lança. A margem funciona e está faminta.

O que existe hoje chamado de "estoque" é um CRUD de catálogo com um contador
denormalizado (`pecas.quantidade_atual`) e um log raso de 10 colunas
(`movimentacoes`), sem custo, sem saldo histórico e sem documento. Três
caminhos gravam saldo **sem passar por movimentação** (`update()`, `store()`,
`importCsv()` do `EstoqueController`), e existem **duas** implementações
independentes de baixa — uma correta com lock (`SaleStockService`) e uma sem
lock que trunca em zero (`EstoqueController::storeMovement()`).

Estado real medido em 2026-08-27: **9 peças de teste, 1 movimentação, 2
fornecedores sem vínculo com peça**. O sistema legado parou de escrever em
`os_itens` em 30/04/2026 e `peca_id` está nulo nas 2.306 linhas.

É terreno limpo. A janela para construir certo sem dor de migração é agora.

## Objetivo

Um razão de estoque auditável, com custo médio ponderado móvel, servido por um
único motor de movimentação — de modo que o CMV do DRE passe a ser verdade e o
dono saiba o que tem, o que vale e quanto custou.

## Decisões

- **Quantidade vira `DECIMAL(14,4)`.** Custa 9 linhas hoje; daqui a um ano
  custa o razão inteiro. Insumo se mede em fração (0,5 m de cabo, 1,5 g de
  pasta térmica) e INT impede isso para sempre.
- **`custo_medio` é coluna separada de `preco_custo`.** `preco_custo` é lido
  como custo de *precificação* por `PrecificacaoService`, `BudgetWorkflowService`
  e `SaleWorkflowService`; sobrescrevê-lo com a média móvel mudaria o preço
  sugerido do orçamento a cada nota de compra que entrasse.
- **`tipo` continua apenas `entrada`/`saida`** — é o sinal, e é o que todas as
  consultas existentes já usam. A semântica nova mora em `motivo_codigo`. Isso
  preserva 100% das queries e dá a distinção que importa: `inventario_falta` é
  **perda, não CMV**.
- **Custo congelado na movimentação** (`custo_unitario`, `custo_total`). Sem
  isso, alterar o preço de custo de uma peça reescreve retroativamente a margem
  de toda OS que já a consumiu.
- **`saldo_anterior`/`saldo_posterior` gravados dentro do lock.** Dão
  auditabilidade ("por que o saldo é 7?"), a invariante
  `posterior(N) == anterior(N+1)`, e permitem derivar estoque médio mensal
  depois sem tabela de snapshot.
- **Razão é imutável**: sem `updated_at`, e estorno é linha nova apontando para
  a original via `estorno_de_id` **UNIQUE** — a idempotência mora no schema,
  não num `if`.
- **Sem `deposito_id`.** MEI, uma bancada, um armário. Multi-depósito depois é
  mover o saldo para `estoque_saldos(peca_id, deposito_id)`; a mitigação é o
  motor único — no dia em que chegar, só ele muda.
- **Sem lote, validade ou série** (decisão do dono). O modelo não impede
  adicionar depois.

## Escopo

### 1. Tipos (Fase 1a, isolada)
`pecas.quantidade_atual`, `estoque_minimo`, `estoque_maximo` e
`movimentacoes.quantidade` para `DECIMAL(14,4)`. Casts dos models corrigidos.
Interpolação decimal locale-safe no `SaleStockService`.

### 2. Colunas do razão
`pecas`: `tipo_item`, `controla_estoque`, `custo_medio`, `custo_ultima_entrada`,
`data_ultima_entrada`, `data_ultima_saida`, `quantidade_reservada`,
`fornecedor_id`, `localizacao_id`, `lead_time_dias`, `estoque_seguranca`,
`ponto_pedido`, `curva_abc`.

`movimentacoes`: `custo_unitario`, `custo_total`, `saldo_anterior`,
`saldo_posterior`, `custo_medio_posterior`, `motivo_codigo`, `documento`,
`fornecedor_id`, `compra_id`, `compra_item_id`, `contagem_id`, `reserva_id`,
`estorno_de_id`.

Tabela nova `estoque_localizacoes` — texto livre gera "Gaveta A"/"gaveta a" e
mata a contagem por endereço, que é como se conta na prática.

### 3. Motor único — `App\Services\Estoque\EstoqueMovimentacaoService`
`registrar()`, `registrarLote()`, `estornar()`, `saldoDisponivel()`,
`conferirDisponibilidade()`. Sempre em transação; `lockForUpdate()` ordenado
por `id`; saldo só por expressão atômica; quantidade sempre positiva.
`SaleStockService` vira fachada fina e **seus testes não podem mudar**.

### 4. Custo médio ponderado móvel
Só entrada move a média. Saída consome ao médio vigente e congela. Bordas:
saldo resultante ≤ 0 usa o custo da entrada; entrada sem custo (devolução,
estorno) não recalcula e volta ao custo de saída original.

### 5. Fechar os três furos do razão
`update()` passa a proibir `quantidade_atual`; `store()` e `importCsv()`
convertem quantidade inicial em movimento `carga_inicial`; `importCsv` vira
upsert por `codigo` (hoje reimportar duplica o cadastro inteiro).

### 6. Telas
Disponível/Reservado/Custo médio/Valor em estoque na listagem; quantidade vira
readonly no formulário; razão global novo; cadastro de localizações.

## Fora de escopo

Compras (037), baixa na OS (038), reserva (039), inventário (040), analítico
(041). Lote/validade/série. Multi-depósito.

## Riscos

- **Mudança de tipo em tabela legada compartilhada.** MySQL passa a devolver
  `"3.0000"` onde devolvia `"3"`. A aplicação legada roda em outro servidor e
  não pôde ser inspecionada daqui — exige confirmação do dono antes de aplicar.
- **Casts `integer` truncando em silêncio** após o ALTER. É o bug mais provável
  da entrega.
- **`lockForUpdate()` é no-op em SQLite** e a suíte padrão roda SQLite: teste de
  concorrência só prova algo no grupo `mysql`.
- **Regressão em vendas.** O aceite duro é `SaleFlowTest` e `SaleReturnFlowTest`
  passarem sem uma linha alterada; se precisarem mudar, a refatoração está
  errada.

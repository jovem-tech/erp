# Estoque: quantidades decimais (2026-08-27)

**Spec:** `specs/036-estoque-nucleo-razao/spec.md` (Fase 1a)
**Tipo:** fundação do módulo de estoque (MINOR)

## Por que agora

O ERP entregou margem de contribuição e DRE gerencial na v5.57.0.0, e o CMV é
**R$ 0,00 nas 2.187 OS**: nenhum caminho do sistema cria movimentação de estoque
a partir de uma OS. O trabalho de margem está pronto e faminto, e o estoque é o
que vai alimentá-lo.

Antes de construir o razão, uma decisão de tipo que só é barata hoje: o estoque
tem **9 peças e 1 movimentação**. Trocar INT por DECIMAL agora custa nada; com o
razão populado, custaria o razão inteiro.

## O problema

`pecas.quantidade_atual`, `estoque_minimo`, `estoque_maximo` e
`movimentacoes.quantidade` eram `INT`. Insumo de assistência técnica se mede em
fração — meio metro de cabo flat, 1,5 g de pasta térmica, meio rolo de solda — e
qualquer fração era **truncada em silêncio**: sem erro, sem aviso, saldo errado.

## O que foi entregue

| Camada | Mudança |
|---|---|
| Banco | `DECIMAL(14,4)` nas quatro colunas |
| Models | `Peca` e `Movimentacao`: cast `'integer'` → `'decimal:4'` |
| Vendas | `SaleStockService::quantidadeSql()` para interpolação segura em SQL cru |
| API | Validação `numeric`, resposta `float`, CSV pt-BR com round-trip |
| Desktop | Controller, 3 views, helper `$qtd()`, campos com `step="any"` |

### Três armadilhas que o trabalho encontrou

**O cast do model.** Trocar a coluna não basta: com `'quantidade_atual' => 'integer'`
o Eloquent trunca **ao ler**, mesmo com o banco correto. Era o bug mais provável
da entrega e tem teste dedicado.

**Interpolação de float em SQL cru.** `SaleStockService` atualiza saldo com
`DB::raw('quantidade_atual - '.$valor)`. Sob locale pt_BR um float vira `"1,5"`
— a vírgula quebra o SQL — e valores pequenos viram notação científica
(`"1.0E-5"`). `quantidadeSql()` usa `number_format` com ponto fixo. Há teste que
força `LC_NUMERIC` para `pt_BR` e prova que o saldo sobrevive.

**Round-trip do CSV.** Exportar e reimportar tem de fechar. A exportação passou
a usar o mesmo formato pt-BR dos preços e o import passou a usar o mesmo parser
(`normalizeDecimal`), que já entendia `"1.234,5"`.

### Uma mudança de contrato, deliberada

A API passou a devolver `quantidade_atual` como **float** (`5.0`) onde devolvia
inteiro (`5`). Foi verificado que:
- o app mobile **não consome** esses campos (zero ocorrências em `frontends/mobile/src`);
- o `openapi.yaml` **não documentava** nenhuma rota `/estoque`, então não havia
  contrato publicado a quebrar;
- o desktop, único consumidor, foi atualizado na mesma entrega.

Por isso **MINOR** e não MAJOR. Um único teste precisou mudar
(`EstoqueFlowTest`, `assertJsonPath` compara com `===` e travava no tipo) —
adaptado com `assertEqualsWithDelta`, o mesmo precedente que `FinanceiroMargemTest`
já havia criado no repositório para este exato problema.

## Testes

- `backend/tests/Feature/Api/V1/EstoqueQuantidadeDecimalTest.php` — 3 casos:
  movimentação fracionada, leitura pelo model, guarda de locale.
- `frontends/desktop/tests/Feature/Desktop/EstoqueTest.php` — **o primeiro teste
  de estoque do desktop**, que tinha 14 rotas e 4 telas sem nenhuma cobertura.
- **Aceite duro cumprido:** `SaleFlowTest` e `SaleReturnFlowTest` passam sem uma
  linha alterada.

Backend: **850 passando, 0 falhas**. Desktop: **412 passando**, com as 5 falhas
pré-existentes já documentadas.

## Pendente

1. **Confirmar o legado.** A aplicação legada roda em outro servidor e não pôde
   ser inspecionada daqui. O MySQL passa a devolver `"3.0000"` onde devolvia
   `"3"` — comparação frouxa de PHP sobrevive, exibição direta muda de aparência.
2. **Aplicar a migration** na base de desenvolvimento (não aplicada ainda, à
   espera do item 1).

## Próximo

Fase 1b: colunas do razão (custo congelado, saldo anterior/posterior,
`motivo_codigo`), motor único de movimentação e custo médio ponderado móvel.
Depois, a Fase 2 — que é onde o CMV deixa de ser zero.

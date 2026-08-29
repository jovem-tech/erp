# Precificação integrada: fundação e margem no PDV (2026-08-27)

**Spec:** `specs/037-precificacao-integrada-ao-fluxo/spec.md` (Fases 0 e 1)
**Tipo:** integração do motor de precificação (MINOR)

## Por que

O dono olhou as telas e disse que "todo o processo financeiro parece destoado
do estoque, dos serviços e do orçamento". A investigação deu razão a ele:
`PrecificacaoService` tinha **exatamente um chamador** — a própria tela de
configuração e o simulador. **Zero** chamadas de qualquer fluxo real.

A matemática existia e estava correta. Ela só nunca era invocada por nada que o
operador usasse para decidir um preço. O sistema **media depois** e **não guiava
na hora**.

O caso mais claro estava no PDV: o backend calculava o custo, **enviava ao
navegador**, e o JS descartava. `grep custo vendas-pdv.js` não devolvia nada. O
operador dava desconto às cegas com a informação a um `console.log` de
distância.

## Fase 0 — Fundação

Nenhum pixel mudou. Ela existe para tornar as fases seguintes pequenas.

**Um impedimento físico removido.** `loadSettings()` fazia uma query por chave —
16 SELECTs — e `buildPieceQuote()`/`buildServiceQuote()` o chamavam **de novo**
internamente. Ligar isso ao orçamento custaria ~300 queries numa cotação de 20
linhas. Virou uma query. Não era otimização prematura: era pré-condição.

**Três catálogos fechados**, no molde de `App\Support\RegimeTributario`:

| Catálogo | Decisão que ele carrega |
|---|---|
| `VisibilidadeCusto` | `completo`/`indicativo`/`nenhum`; aceita `financeiro:visualizar` **ou** `precificacao:visualizar` — senão o gerente que só tem `precificacao` editaria as regras de margem sem enxergar custo |
| `FaixaMargem` | Custo desconhecido é **`indefinido`**, nunca verde. Peça sem custo tem margem aritmética de 100%; pintar verde premiaria o cadastro incompleto |
| `ModoPrecificacao` | Resolvido **no servidor por comparação**. Vindo do cliente, a coluna registraria intenção declarada, não fato |

**`PrecoQuote`, onde a redação acontece.** `@if` em Blade esconde o pixel e deixa
o número no devtools. `toArray()` **apaga a chave** — o custo não chega ao DOM de
quem não pode vê-lo. O piso continua indo para todos, porque quem vende precisa
saber que passou dele.

### Uma brecha fechada antes de ser aberta

`normalizeItems()` **confiava** no `custo_unitario` vindo do cliente. Era inócuo
enquanto o desktop não enviava o campo — e deixaria de ser exatamente na Fase 1,
quando o campo passa a existir no payload. Um POST com `custo_unitario: 0` numa
peça cadastrada zeraria `vendas.custo_total` e faria a margem gravada marcar
100%.

Agora item de catálogo usa sempre o custo do cadastro; só **avulso** aceita
custo informado, porque ali não há cadastro para consultar. Mesma postura que a
linha vizinha já aplicava a `baixa_estoque`.

## Fase 1 — PDV

- `searchItems()` devolve a cotação **já redigida** pela permissão de quem
  buscou, mais `preco_minimo`, `faixa` e os limites do semáforo.
- **O piso do PDV é o custo**, não o preço mínimo do motor. No balcão, o que o
  operador precisa saber na hora do desconto é onde começa o **prejuízo**, não
  onde começa a margem-alvo.
- Margem por linha vive **dentro da célula de Total**, não como sétima coluna —
  o PDV roda em tela cheia e sete colunas não caberiam.
- **O desconto de cabeçalho é rateado por linha** antes de comparar com o piso.
  Sem ratear, 30% de desconto no total apareceria como se nenhuma linha tivesse
  mudado de faixa.
- **O aviso de piso vale para todos**, inclusive quem não vê número: é a única
  forma de o técnico saber que passou do limite.
- **Nunca bloqueia a venda.** PDV que se recusa a vender vira recibo manual. O
  bloqueio fica para a Fase 6, via `precificacao_servico_aplicar_piso`.
- Limites vêm do servidor no payload, nunca hard-coded no JS: semáforo que
  discorda entre tela e banco é pior que semáforo nenhum.

**Adiado de propósito:** a mão de obra no custo do serviço. Entra na Fase 3,
quando o custo-hora passar a ser calculado dos custos fixos reais — até lá,
somar um custo-hora digitado sem lastro seria trocar um número incompleto por
um número errado.

## Testes

- `CatalogosPrecificacaoTest` (8) — bordas puras: custo desconhecido nunca
  verde, piso vence percentual, redação remove a chave, modo resolvido por
  comparação.
- `VendaCustoSpoofingTest` (2) — custo do cliente ignorado em peça cadastrada,
  aceito em item avulso.
- `PrecificacaoVisibilidadeTest` (3) — testa o **JSON**, não o HTML: quem não
  tem permissão não recebe a chave.
- `VendaTest` (desktop) — ganchos de margem e aviso de piso no PDV.

Backend: **869 passando, 0 falhas**. Desktop: **419 passando**, 5
pré-existentes documentadas.

**Aceite duro cumprido:** `SaleFlowTest`, `SaleReturnFlowTest`,
`BudgetFlowTest` e `FinanceiroPrecificacaoTest` passam sem uma linha alterada.

## Próximo

Fase 2 (preço sugerido no cadastro de peça) e Fase 3 (custo-hora calculado dos
custos fixos do DRE + cadastro de serviço) — a fase que fecha o ciclo entre o
custo fixo real e o preço cobrado.

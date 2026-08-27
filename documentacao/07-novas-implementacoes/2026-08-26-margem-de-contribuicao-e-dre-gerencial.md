# Margem de contribuição e DRE gerencial (2026-08-26)

## O problema

O ERP tinha um relatório chamado "Margem por OS" e um DRE, e nenhum dos dois
respondia a pergunta que uma assistência técnica precisa fazer todo mês:
**quanto sobra de cada real vendido para pagar o aluguel e a folha?**

Quatro defeitos independentes, todos no mesmo eixo:

### 1. A margem por OS ignorava dois custos variáveis que o ERP já conhecia

`OsMargemService` calculava `receita − peças − comissão`. Ficavam de fora:

| Custo variável | Estava no ERP? | Entrava na margem? |
|---|---|---|
| Peças (custo real de estoque) | sim | sim |
| Comissão do técnico | sim | sim |
| **Taxa de recebimento** | sim — lançada como despesa na baixa | **não** |
| **Imposto sobre venda** | sim — parâmetro da precificação | **não** |

Numa OS de R$ 250 no crédito (3,49%) com Simples de 6%, são **R$ 23,73 de custo
invisível** — a margem reportada saía ~9,5 p.p. acima da real. Pior: o motor de
precificação **já dividia** por taxa e imposto para chegar no preço mínimo
(`PrecificacaoService::buildServiceQuote()`), então plano e realizado usavam
bases diferentes e nunca fechavam.

### 2. A "margem média" era média aritmética de percentuais

`$rows->avg('percentual_margem')` não é a margem do período. Num mix
heterogêneo — o caso normal aqui — ela mente:

| OS | Receita | MC | % |
|---|---:|---:|---:|
| Formatação | R$ 80 | R$ 72 | 90% |
| Reparo de placa | R$ 1.200 | R$ 240 | 20% |

O relatório exibia **55%**. A realidade econômica do mês era **24,4%**
(312 / 1.280). O correto é o **índice de contribuição**: MC total ÷ receita total.

### 3. O DRE não tinha linha de margem de contribuição — e não enxergava o CMV

A estrutura era de absorção: `Receita → Custos diretos → Lucro bruto → …`.
Duas consequências:

- A separação fixo/variável **já existia** no banco (`dre_fixo_mensal`), mas era
  usada só como detalhe visual **abaixo** do lucro bruto, nunca para calcular MC.
- O custo das peças **não entrava no DRE de forma nenhuma**. A categoria semeada
  para compra de peças nasce com `impacta_dre_padrao = false` (correto — peça
  comprada é estoque, não despesa), mas faltava a outra metade: reconhecer o CMV
  no consumo. O custo simplesmente evaporava, e `lucro_bruto` = receita líquida.

Não havia ponto de equilíbrio, margem de segurança nem alavancagem operacional
em lugar nenhum do sistema.

### 4. A taxa de cartão da baixa de OS era invisível no DRE

`OrderClosureService::registerCardFeeExpense()` criava o título com
`grupo_dre` **nulo**. O DRE agrupa **por grupo** (`groupByCompetencia()` filtra
`where grupo_dre = 'Despesas Operacionais'`), então o título não caía em
nenhuma linha — apesar de `impacta_dre = true`. A taxa saía do caixa e nunca
aparecia no resultado.

A irmã `FinanceiroService::registerCardFeeExpense()` (taxa de lançamento avulso)
sempre classificou corretamente. Era divergência entre as duas, não regra.

## O que foi entregue

### Margem de contribuição completa por OS

`OsMargemService` passa a calcular:

```
MC = receita − peças (custo de estoque)
            − comissão do técnico
            − taxa de recebimento (valor REAL cobrado pela operadora)
            − imposto sobre a venda
```

A taxa vem do título que a baixa gerou (`origem_tipo = 'os_recebimento_cartao'`),
não de uma reestimativa por percentual — assim piso e teto da operadora são
respeitados. Título cancelado (taxa estornada) não consome margem.

O imposto usa a chave `margem_imposto_percentual`, que cai em
`precificacao_servico_imposto_percentual` quando não definida — uma alíquota só
para precificar e conferir. Padrão `0`: nada muda até ser configurado.

### Índice de contribuição no lugar da média de percentuais

`margem_media_percentual` passa a ser `MC total ÷ receita total`, no período e
por técnico. O card na tela foi renomeado para **Índice de contribuição**.

### Margem por hora de técnico

Nova coluna `os.tempo_tecnico_horas`, apontada pelo técnico **ao concluir o
reparo** — o modal "Alterar status" mostra o campo quando o status de destino
congela o prazo (`OrderStatus::DEADLINE_FREEZE_CODES`). A tela de baixa aceita
como rede de segurança, e **nunca sobrescreve** o que o técnico informou.

Isso habilita a priorização correta quando a bancada, e não o caixa, é o recurso
restrito: uma OS de margem alta que trava o técnico o dia inteiro pode render
menos que dois serviços rápidos. OS sem apontamento fica **fora** do ranking
(null, não zero) e o relatório diz quantas são.

### DRE gerencial (custeio variável)

Novo bloco `gerencial` no payload do DRE, com a demonstração que faltava:

```
Receita líquida (OS entregue)
(-) CMV — peças aplicadas, custo de estoque
(-) Comissões de técnicos
(-) Despesas variáveis (taxas, impostos)
(-) Custos diretos de OS
= MARGEM DE CONTRIBUIÇÃO           (+ índice de contribuição)
(-) Custos e despesas fixas
(+) Outras receitas
= Resultado operacional
```

**Fontes, e por quê:** CMV e comissões vêm de `os_margem` (mede o custo real da
peça baixada por OS); o grupo "Custo Direto (OS)" do financeiro reconhece pela
competência da **compra**, e um lote comprado em março e aplicado em maio jogaria
o custo no mês errado. Despesas variáveis vêm do `financeiro`, que é o registro
real — usar também a taxa de `os_margem` duplicaria o mesmo título.

`lucro_bruto` e `resultado_liquido` **foram mantidos** no payload por
compatibilidade de contrato (API pública, mobile, testes). São a leitura por
absorção e continuam sem enxergar o CMV — a tela agora rotula essa tabela como
"Demonstração de resultado (contábil)" e explica a diferença.

### Análise custo-volume-lucro

```
Ponto de equilíbrio (R$) = custos fixos ÷ índice de contribuição
Margem de segurança      = receita − ponto de equilíbrio
GAO                      = MC ÷ resultado operacional
```

Com MC negativa, **não existe** ponto de equilíbrio: cada venda nasce sem cobrir
o próprio custo variável, e nenhum volume zera o resultado. O relatório devolve
`null` e a tela explica que o caminho é preço/custo, não volume. O GAO só é
calculado acima do equilíbrio — abaixo dele o quociente troca de sinal e deixa
de significar alavancagem.

**MC não é apurada no regime de caixa.** O custo da peça pertence ao mês da
entrega, não ao mês em que o cliente pagou; confrontá-los produziria um número
que não corresponde a período nenhum. O DRE de caixa devolve
`gerencial.disponivel = false` com o motivo, e a tela mostra o aviso e o link
para o relatório por competência.

### Correção da classificação da taxa de cartão

`OrderClosureService::registerCardFeeExpense()` passa a gravar
`grupo_dre = 'Despesas Operacionais'` / `subgrupo_dre = 'Taxas e impostos'`,
igual à irmã do módulo financeiro.

## Impacto nos números exibidos

> **Atenção operacional.** Estes relatórios vão mostrar valores diferentes dos
> de ontem. Não porque o mês piorou — porque antes estavam otimistas por omissão.

| Onde | O que muda |
|---|---|
| Margem por OS | Cai pela taxa de recebimento e pelo imposto (se configurado) |
| Margem média do período | Muda de média de percentuais para índice de contribuição; num mix heterogêneo cai bastante |
| DRE | Ganha a demonstração gerencial, que desconta o CMV — invisível no lucro bruto |
| DRE de meses fechados | Passa a exibir as taxas de cartão de OS antes omitidas (após o backfill) |

## Migrations

| Migration | Tipo | O que faz |
|---|---|---|
| `2026_08_26_000001_add_custos_variaveis_to_margem_module` | schema | `os.tempo_tecnico_horas`; `os_margem`: `custo_taxa_recebimento`, `custo_imposto`, `tempo_tecnico_horas`, `margem_por_hora` |
| `2026_08_26_000002_backfill_grupo_dre_taxa_cartao_os` | **dados** | Reclassifica taxas de cartão de OS históricas que estavam com `grupo_dre` nulo |

A segunda tem `down()` preciso (reverte só a combinação exata que escreveu) e
não toca em títulos já classificados manualmente nem em cancelados.

`os` é tabela legada e não nasce de migration deste repositório — em teste é
montada por `tests/Concerns/BuildsLegacyErpSchema.php`, que roda **depois** das
migrations. Daí o guard `hasTable()`, e a coluna precisa ser declarada nos dois
lugares.

## Regime tributário: imposto variável ou fixo

A pergunta "qual a alíquota?" estava mal colocada. Antes dela vem outra: **o
imposto varia com a venda?** É isso que decide de que lado da linha da margem
ele entra.

| Regime | Comportamento | Onde entra |
|---|---|---|
| **MEI** (padrão) | DAS é valor **fixo mensal** — não muda se você fizer 10 ou 100 OS | Custo **fixo**, abaixo da linha, dentro do ponto de equilíbrio |
| **Simples Nacional** | Proporcional ao faturamento | Custo **variável**, desconta da margem de cada OS |
| **Outro** (Presumido/Real) | Carga efetiva sobre a receita | Custo **variável** |

Descontar o DAS do MEI de cada OS seria errado duas vezes: subestimaria a
margem unitária **e** tiraria do ponto de equilíbrio uma despesa que existe
todo mês — justamente o número que o ponto de equilíbrio serve para cobrir.

`App\Support\RegimeTributario` centraliza a regra, e
`OsMargemService::resolveImpostoPercentual()` devolve **0 para MEI mesmo com
alíquota configurada**: o regime tem precedência sobre o percentual, para que
um valor esquecido no campo não passe a subtrair de cada venda uma despesa que
não varia com venda nenhuma.

Configurável em **Financeiro > Precificação**, com a explicação trocando
conforme o regime selecionado. Crescer e migrar para o Simples é ajuste de
tela, nunca deploy.

> **Para MEI:** lance o DAS como despesa **fixa mensal** no financeiro
> (`dre_fixo_mensal = true`). Sem isso ele fica fora dos custos fixos e o ponto
> de equilíbrio sai subestimado — o relatório vai dizer que você precisa
> faturar menos do que realmente precisa.

## Reprocessamento do histórico

`os_margem` é **cache**: cada linha é gravada uma vez, na baixa da OS, e nunca
mais revisitada. É o comportamento certo no dia a dia — a margem de uma OS
entregue não muda — mas significa que mudar a **fórmula** só vale para OS
futuras até alguém reprocessar o histórico.

Sem isso, os relatórios ficam no pior dos dois mundos: OS antigas com a margem
antiga (inflada) e OS novas com a nova, num mesmo gráfico. O número fica errado
**e** incomparável entre meses.

```bash
# Simula (padrão) — mostra quantas OS seriam tocadas e o efeito na margem
php artisan financeiro:recalcular-margem

# Aplica
php artisan financeiro:recalcular-margem --aplicar

# Só a partir de uma data
php artisan financeiro:recalcular-margem --aplicar --desde=2026-01-01
```

O comando também reafirma a invariante da tabela: `os_margem` só pode conter OS
com `status = entregue_reparado_pago`. Linhas remanescentes de OS que voltaram
para o fluxo são removidas — elas somavam margem de uma venda que não aconteceu.

`OsMargemService::recalcularEmLote()` já existia, mas **não tinha nenhum
chamador** — nem comando, nem rota. Era código inalcançável.

## Fora de escopo (decidido)

**Retrabalho em garantia.** Uma OS que volta consome peça nova sem abater a
margem da original. Tratar isso exige vincular a OS de garantia à original (nova
coluna + UX de abertura) e decidir a regra contábil — abater retroativo distorce
meses já fechados; provisionar no período corrente não. Fica como entrega
própria. A precificação já tem `reserva_garantia_valor` e `risco_percentual`
provisionando no preço, sem confronto com o custo real.

## Arquivos

**Backend**
- `app/Services/Financeiro/OsMargemService.php` — MC completa, índice ponderado, margem por hora, `totaisPorPeriodo()`
- `app/Services/Financeiro/FinanceiroReportService.php` — bloco `gerencial`, `buildManagerialSummary()`, `buildCvpAnalysis()`
- `app/Services/Orders/OrderClosureService.php` — classificação da taxa, `resolveTempoTecnicoFallback()`
- `app/Services/Orders/OrderWorkflowService.php` — apontamento de horas, `tempo_tecnico_horas` no detalhe
- `app/Console/Commands/RecalcularMargemOs.php` — reprocessamento do histórico (novo)
- `app/Models/Order.php`, `app/Models/OsMargem.php` — casts
- `app/Http/Requests/Api/V1/UpdateOrderStatusRequest.php`, `CloseOrderRequest.php`
- `app/Http/Controllers/Api/V1/OrderController.php`

**Desktop**
- `resources/views/financeiro/relatorios/dre.blade.php` — demonstração gerencial + painel CVP
- `resources/views/financeiro/relatorios/margem.blade.php` — composição dos custos variáveis + ranking por hora
- `resources/views/orders/_status_modal.blade.php`, `public/assets/js/orders-status-modal.js` — campo de horas
- `resources/views/orders/closure.blade.php` — fallback de horas
- `app/Http/Controllers/OrderController.php`, `app/Services/OrderService.php`

**Testes** — 12 novos (`FinanceiroMargemTest` 7, `FinanceiroReportTest` 4 backend,
`FinanceiroReportTest`/`FinanceiroMargemTest` 3 desktop).

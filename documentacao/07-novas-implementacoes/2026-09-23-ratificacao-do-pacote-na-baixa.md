# Ratificação do pacote de manutenção contratado na baixa da OS

## Contexto

- versao: `6.3.0.0`
- data: `2026-09-23`
- ambiente-alvo: `Ubuntu VPS`
- doc relacionado: `2026-09-15-orcamento-em-niveis-manutencao.md`, `2026-08-10-condicoes-comerciais-orcamento-garantia-os.md`

O orçamento pode ser vendido em níveis de manutenção (Básica/Avançada/Completa).
Cada nível promete ao cliente, na página pública de aprovação, condições próprias:
prazo de **garantia**, **parcelamento sem juros**, **formas de pagamento aceitas** e
**entrega em domicílio**. `BudgetCommercialTermsService::collapseApprovedLevel()` copia
essas condições para as colunas base de `orcamentos` na aprovação — justamente para a
OS e a baixa poderem lê-las.

**A baixa nunca leu.** Levantamento feito no código e no banco:

| Condição prometida | O que a baixa fazia |
|---|---|
| Garantia | Sugeria por heurística frágil; o operador trocava por qualquer prazo |
| Formas de pagamento | Ignorava `orcamento_formas_pagamento`; listava o catálogo global |
| Parcelamento sem juros | `orcamentos.parcelas_sem_juros` sem nenhum leitor em `Orders/*`; campo `min=1 max=99` |
| Entrega em domicílio | `orcamentos.entrega_domicilio` sem nenhum leitor em `Orders/*` |

Pior: a baixa nem identificava com segurança **qual** era o pacote. Havia quatro
critérios diferentes de "o orçamento desta OS" no mesmo fluxo. Numa OS real
(OS26090006) com dois orçamentos aprovados, `suggestedWarrantyDays()` ordenava por
`aprovado_em` e sugeria a garantia do orçamento errado (365 dias, do nível 1), enquanto
o detalhe da OS ordenava por `id` e apontava para o outro (180 dias, do nível 2).

## Decisões de negócio

1. **Restringir por padrão, liberar com motivo gravado.** Não é bloqueio duro (não exige
   `os:administrar`, ao contrário do desconto) nem aviso passivo.
2. **As quatro condições** são ratificadas.
3. **Garantia é assimétrica**: dar MAIS que o prometido passa livre (cortesia). Só prazo
   MENOR, ou "Sem garantia" num encerramento que deveria conceder, é desvio.

Invariante que governa tudo: **OS sem pacote contratado continua idêntica** — nenhum
campo novo, nenhuma validação nova, nenhuma chave obrigatória. Cada dimensão é
independente: pacote sem formas cadastradas não agrupa nada, `parcelas_sem_juros` nulo
não sinaliza nada.

## Entrega

### Fonte única do orçamento contratado

- `Budget::scopeEffectivelyApproved()` e `Budget::contractedForOrder(int $osId)`. Desempate
  por `id DESC`, o **mesmo** de `OrderWorkflowService::mapLinkedBudget()`, para baixa e
  detalhe da OS nunca falarem de orçamentos diferentes.
- `OrderClosureService::hasUnapprovedBudget()` passou a usá-la (predicado idêntico).
- `mapLinkedBudget()` **não** mudou de critério: o detalhe precisa linkar o documento mais
  recente, inclusive revisão em rascunho.

### O pacote na tela de baixa

- `OrderClosureService::contractedPackage()` lê por `BudgetCommercialTermsService::forBudget()`
  — o mesmo ponto que a página pública usou para prometer — e devolve o shape completo
  mesmo sem pacote (`tem_pacote` false).
- `GET /api/v1/orders/{id}/closure` ganhou `pacote` e, em `garantia`, `dias_prometido` /
  `label_prometido`. `garantia.opcoes` continua a lista inteira (a assimetria exige poder dar mais).
- Passo 1: card "Pacote contratado" com nível, garantia, formas, parcelamento e entrega; a
  opção de garantia prometida vem marcada com "— prometido no pacote".
- Passo 2: o select de forma de pagamento ganha `<optgroup>` "Do pacote contratado" e
  "Fora do pacote". **Agrupa, nunca filtra** — nenhuma opção some.
- Passo 4: bloco único de ratificação, listando os desvios detectados, com o switch
  "fora do pacote" e o motivo. Um bloco só porque os desvios nascem em três etapas
  diferentes e pedir justificativa em cada uma espalharia três motivos.

### Parcelas: capacidade x promessa

`syncParcelasLimits()` continua dono exclusivo de `min`/`max` do campo (capacidade real das
taxas de cartão cadastradas). O pacote escreve num elemento próprio
(`data-parcelas-package-hint`) e **nunca** em `min`/`max`: parcelar acima do prometido
continua permitido (vira venda com juros), só deixa de ser o que foi vendido.

### Validação e registro

- `OrderClosureService::resolvePackageRatification()` roda antes da transação (mesma
  disciplina do desconto e da simulação de cartão). Havendo desvio sem `fora_pacote` +
  motivo, devolve 422 `ORDER_CLOSURE_OUTSIDE_PACKAGE_REQUIRES_REASON` com a lista em
  `error.details.desvios`.
- Persistência em `os.pacote_desvios` (CSV), `pacote_desvio_motivo`, `pacote_desvio_por`,
  `pacote_desvio_em` (migration `2026_09_23_000001`). Escritas **sempre** (null quando não há
  desvio), diferente do precedente `desconto_baixa_*`: assim uma OS reaberta por
  `cancelClosure()` e refechada dentro do pacote não fica com o desvio velho colado.
- O evento `fechamento_concluido` ganhou no `dados` o bloco `pacote` (prometido × realizado),
  e há evento dedicado `baixa_fora_pacote` na linha do tempo quando houve desvio.
- `registerAdvance()` (adiantamento/sinal) ratifica só as duas dimensões de dinheiro —
  não entrega nem concede garantia, mas move dinheiro, e ficar de fora seria a porta
  documentada para cobrar fora do pacote sem justificar.

### Baixa em lote

Não mudou. Funciona porque `normalizeWarrantyDays()` passou a **adotar o prazo prometido**
quando a chave `garantia_dias` está ausente no payload, e porque a entrega só conta como
desvio quando `entrega_domicilio_cumprida` é enviada e falsa. O lote manda apenas
`encerrar_como`/`data_entrega`/`observacao`, e dois dos seus encerramentos concedem garantia.

## Bugs pré-existentes corrigidos no caminho

1. **"Sem garantia" nunca chegava ao backend.** O `array_filter` de
   `OrderController::closureStore()` descarta `''`, então a chave sumia e o backend lia como
   "não informou", mantendo o prazo herdado. Reinserida explicitamente.
2. **"Sem garantia" não zerava nada.** Mesmo chegando, `close()` só deixava de escrever. Agora,
   escolhido e justificado, grava `garantia_dias = 0` e `garantia_validade = null`. Exceção:
   `entregue_reparado_garantia`, onde o prazo que corre é o da garantia anterior.
3. **`orcamento_pendente_aprovacao` nunca era repassado pela API.** O service calculava e a tela
   consumia, mas a chave não ia no payload: a opção "Entregue - Reparado e Pago" jamais chegava
   desabilitada. O bloqueio real em `close()` sempre existiu — faltava a dica visual.
4. **Lista de prazos de garantia duplicada.** O desktop tinha `in:90,180,365,730` hardcoded
   enquanto o backend deriva de `Budget::WARRANTY_TERMS`; acrescentar um prazo lá faria a baixa
   recusá-lo antes de chegar à API. Removida, com `CloseOrderRequest` como autoridade.
5. **Desempate errado do orçamento** (o "1 ano" em vez de "180 dias" descrito no contexto).

## Testes

- `backend/tests/Feature/Api/V1/OrderClosurePackageTest.php` (16 casos): forma dentro/fora,
  parcelas acima/dentro do limite, débito nunca é desvio, entrega não confirmada, persistência
  + evento, garantia maior/menor, "sem garantia" zerando, adoção implícita, adiantamento, OS sem
  pacote, e a guarda da baixa em lote.
- `backend/tests/Feature/Api/V1/OrderWarrantyClosureTest.php`: novo
  `test_two_approved_budgets_use_the_most_recent_as_contracted_package`, reprodução literal da
  OS26090006. Os 6 casos anteriores seguem verdes sem edição.
- `frontends/desktop/tests/Feature/Desktop/OrderClosurePackageTest.php` (4 casos): tela com e
  **sem** pacote (guarda do caminho comum), repasse do motivo e do "Sem garantia".

Verificado também com harness Chrome headless: card no passo 1, optgroups no passo 2 e a
máquina de estados do passo 4 (desvio sem motivo → botão travado; assumido sem texto → travado;
justificado → liberado; de volta ao pacote → bloco some).

## Gotchas

- `.closure-pdv-panel` tem `height: 100%` (para casar as duas colunas). Empilhar um segundo
  painel na mesma coluna estica o primeiro e empurra o de baixo para fora da tela — daí a classe
  `.closure-package-card` com `height: auto`.
- Checkbox desmarcado não é enviado pelo navegador: `fora_pacote` e `entrega_domicilio_cumprida`
  precisam do marcador `<input type="hidden" value="0">` antes, senão o backend não distingue
  "não cumpriu" de "não perguntei".
- Os fixtures de teste do desktop (`DocumentoFiscalTest::metadadosDeBaixa()`) não têm nem a chave
  `garantia` — todo acesso a `$closure['pacote']` precisa de `?? []` ou quebra 10+ testes alheios.
- A migration é aplicada com `--path` (o `migrate` geral aborta no grant do `sistema_erp_chat`) e
  o schema é espelhado em `BuildsLegacyErpSchema`.

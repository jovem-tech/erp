# Tarefas — Precificação integrada ao fluxo

## Fase 0 — Fundação ✅
- [x] `loadSettings()` de 16 queries para 1 (`whereIn`+`pluck`)
- [x] `App\Support\VisibilidadeCusto` (completo/indicativo/nenhum + `paraUsuario()`)
- [x] `App\Support\FaixaMargem` (verde/amarelo/vermelho/**indefinido**)
- [x] `App\Support\ModoPrecificacao` (resolvido por comparação, não pelo cliente)
- [x] `App\Services\Financeiro\PrecoQuote` — redação por visibilidade no DTO
- [x] Config de capacidade, janela do custo-hora e limites do semáforo
- [x] Guarda anti-spoofing do `custo_unitario` em `normalizeItems()`
- [x] `CatalogosPrecificacaoTest` (8 casos) e `VendaCustoSpoofingTest` (2 casos)
- [x] Aceite duro: `FinanceiroPrecificacaoTest`/`BudgetFlowTest`/`SaleFlowTest` intocados

## Fase 1 — PDV ✅
- [x] `searchItems()` devolve `preco_minimo`, `faixa` e limites; custo redigido
- [x] Piso do PDV é o CUSTO (onde começa o prejuízo), não o preço mínimo do motor
- [x] `vendas-pdv.js`: margem por linha dentro da célula de Total
- [x] Desconto de cabeçalho rateado por linha antes de comparar com o piso
- [x] Aviso de piso vale para todos, inclusive quem não vê número
- [x] Limites vêm do servidor no payload, sem `fetch` em `recalcular()`
- [x] `PrecificacaoVisibilidadeTest` (JSON, não HTML) e `VendaTest` (ganchos)

## Fase 2 — Preço sugerido na peça ✅
- [x] Autorização aceita `estoque:criar|editar`, com resposta redigida
- [x] `estoque-form.js` com a regra do "sujo" (nasce sujo em edição e em `old()`)
- [x] `change`/`blur` com debounce 400ms, nunca `input`
- [x] `valor_calculado` exposto — trata `respeitar_preco_venda`
- [x] `PrecificacaoSugestaoPecaTest` (4) e `EstoqueTest` (desktop)

## Fase 3 — Custo-hora + serviço ✅ (parcial)
- [x] `Financeiro::scopeFixasDre()` extraído e compartilhado com o DRE
- [x] `CustoHoraService` com a escada de guardas (nunca devolve 0)
- [x] Janela de meses fechados, com limite inferior (o DRE não tem)
- [x] Capacidade global; cache 10 min invalidado no `save()`
- [x] Motor usa o custo-hora calculado; procedência sobe no payload
- [x] Cadeia de custo visível no formulário de serviço + preço sugerido
- [x] Rótulo novo de `custo_direto_padrao` (corrige o bug de dupla contagem)
- [x] Simulador de serviço aceita `servicos:criar|editar`, resposta redigida
- [ ] **Adiado:** custo de serviço no PDV/orçamento passar a somar mão de obra.
      Depende de os cadastros existentes serem revisados sob o rótulo novo —
      aplicar antes dupla-contaria quem já inclui mão de obra no custo direto.
- [ ] Relatório "serviços com custo direto > 60% do valor" para revisão manual
- [ ] Ressuscitar as colunas de consumíveis/garantia/perdas do override

## Fase 4 — Orçamento ✅ (parcial)
- [x] `resolveItemReferenceData()` chama o motor; colunas com valor real
- [x] `percentual_margem` guarda a margem **cobrada**, não a meta
- [x] `modo_precificacao` resolvido por comparação no servidor
- [x] Não reprecificar orçamento fechado (snapshot congelado)
- [x] Colunas Custo unit. e Margem na tela, com semáforo — e a legenda
      mentirosa corrigida, agora condicional
- [x] Payload redigido: custo não existe no JSON de quem não pode ver
- [x] `OrcamentoPrecificacaoItemTest` (4 casos)
- [ ] **Adiado:** `MargemPrevistaService` — depende de decidir como tratar
      comissão e taxa antes de existir pagamento
- [ ] `resumo_precificacao` consolidado no rodapé do orçamento

## Fase 5 — Fechar o ciclo com a OS ✅
- [x] `buildCostSummary()` da mesma fonte da margem — mata o R$ 0,00
- [x] Custo de serviço vem de `orcamento_itens`, que a Fase 4 passou a preencher
- [x] Varredura de visibilidade: `vendas/show` e `vendas/index` fechados
      (regressão de UX assumida e documentada)
- [x] `OrderClosureCustoSummaryTest` (2) e `VendaTest` (desktop)
- Nota: o número só aparece quando a OS tiver saída de estoque — hoje nenhuma
  tem, porque a baixa de peça na OS ainda é manual (`specs/036`)

## Fase 6 — Limpeza
- [ ] Telas para `syncCategorias`/`syncComponentes`
- [ ] `salvarOverrideServico()` no formulário do serviço (apaga as 91 linhas)
- [ ] Implementar `precificacao_servico_aplicar_piso`
- [ ] Dropar as 4 colunas de capacidade do override

## Pendência herdada
- [ ] `specs/036` Entrega 2 — unidade de medida (antes da Fase 3)

# Tarefas — Entrada de estoque no lançamento financeiro

## Bloco 1 — Banco
- [ ] Migration `add_financeiro_link_to_movimentacoes` — `financeiro_id` e
      `custo_unitario`, com guardas `hasTable`/`hasColumn`, **sem FK** (nenhuma
      coluna dessa família tem), índice em `financeiro_id`
- [ ] Espelho em `BuildsLegacyErpSchema` (o trait recria a tabela do zero)
- [ ] `Movimentacao`: relação `financeiro()` + cast `custo_unitario => decimal:4`

## Bloco 2 — Motor e contrato (backend)
- [ ] `EntradaPecaService` — registra entradas, atualiza `preco_custo`, estorna
- [ ] `EstoqueMovimentacaoService`: repassar `financeiro_id`/`custo_unitario`
- [ ] `UpsertFinanceiroRequest`: regras de `itens_estoque` + `withValidator`
      (só `pagar`, só POST, incompatível com repetição, soma ≤ valor)
- [ ] `FinanceiroService::create()` — chamada **antes** do ramo de parcelas
- [ ] `FinanceiroService::resolveClassification()` — `unset` de `itens_estoque`
- [ ] `FinanceiroService::cancel()` — envolver em transação + estorno
- [ ] `FinanceiroController`: autorização dupla, 409 no delete, flag
      `permitir_estoque_negativo` no cancel
- [ ] `FinanceiroEntradaEstoqueTest` (11 casos, incl. os dois de rollback e o de
      compra parcelada)

## Bloco 3 — Busca e payload (desktop, sem UI)
- [ ] Rota `financeiro.parts.search` + `FinanceiroController::searchParts()`
- [ ] `validatedPayload()` com `itens_estoque`, limpeza e gate de método
- [ ] Testes de encaminhamento e permissão

## Bloco 4 — UI
- [ ] Seção "Entrada no estoque" em `financeiro/form.blade.php` (só em create)
- [ ] Partials `entrada-estoque-item-row` e `peca-quick-modal`
      (⚠️ `quantidade_atual = 0` fixo)
- [ ] `financeiro-entrada-estoque.js` (bind duplo jQuery+nativo nos Select2)
- [ ] Expor os helpers de máscara de `financeiro-form.js` em vez de duplicar
- [ ] Testes de render por permissão

## Bloco 5 — Rastreabilidade e atalho
- [ ] `detailContext()` devolve `entradas_estoque`
- [ ] Tabela das entradas em `financeiro/show.blade.php`
- [ ] Botão "Entrada por compra" em `estoque/index.blade.php`

## Bloco 6 — Fechamento
- [ ] Teste de concorrência no grupo `mysql`
- [ ] Nota em `documentacao/07-novas-implementacoes/`
- [ ] `./scripts/bump-version.sh`

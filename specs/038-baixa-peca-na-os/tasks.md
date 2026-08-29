# Tarefas — Baixa de peça na OS

## Bloco A — Motor e aplicação ✅
- [x] `EstoqueMovimentacaoService` — motor único (lock ordenado, decremento
      atômico, agregação por peça com `ksort`, quantidade sempre positiva)
- [x] `SaldoInsuficienteException` com os ofensores no formato do PDV
- [x] `OsAplicacaoPecaService` — contexto pré-preenchido e aplicação
- [x] `OrderStockController` + rotas, com autorização dupla
- [x] Botão "Aplicar peças do orçamento" no lugar do alerta passivo
- [x] Modal pré-preenchido com o que falta aplicar
- [x] `OsAplicacaoPecaTest` (5), incluindo **"CMV da OS deixa de ser zero"**

## Bloco B — Pendente
- [ ] `SaleStockService` passar a delegar ao motor único (hoje ainda é a
      segunda implementação; os testes de venda não podem mudar)
- [ ] `EstoqueController::storeMovement()` passar a delegar (terceira porta)
- [ ] Teste de concorrência no grupo `mysql`
- [ ] Custo congelado na movimentação (`036` Bloco B) — até lá o CMV usa o
      `preco_custo` atual, não o do dia da baixa

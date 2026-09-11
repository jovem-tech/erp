# Tarefas — Reserva de peça de estoque vinculada ao orçamento

## Bloco A — Razão de reserva ✅
- [x] Migration `estoque_reservas` (UNIQUE `orcamento_id`+`peca_id`, FKs, índices)
- [x] Migration `pecas.quantidade_reservada` (DB::statement, no-op fora do MySQL)
- [x] Espelho em `BuildsLegacyErpSchema` (`createEstoqueReservasTable`, coluna em
      `createPartsTable`, helper `createEstoqueReservaRecord`)
- [x] Model `EstoqueReserva` + cast `decimal:4` em `Peca`

## Bloco B — Motor de reserva ✅
- [x] `EstoqueReservaService`: `sincronizar`, `liberarManual`, `consumir`,
      `disponibilidade`, `recalcular`, `reservadoDoOrcamento`
- [x] Lock de `pecas` ordenado por id, sempre antes de tocar `estoque_reservas`
- [x] Cache reescrito por soma absoluta dentro do lock (auto-reparável)
- [x] `EstoqueReservaTest` — 15 casos

## Bloco C — Pontos de reconciliação ✅
- [x] `BudgetApprovalService`: envio, aprovação, rejeição, cancelamento, expiração
- [x] `BudgetWorkflowService::syncItems()` — cobre criar/editar/revisão de uma vez
- [x] `OrderWorkflowService::linkBudgetToOrder()` — desce `os_id`/`equipamento_id`
- [x] Expiração por validade sai de graça: `app:expire-budgets` já roda `hourly()`

## Bloco D — Disponível passa a valer ✅
- [x] `EstoqueMovimentacaoService::conferirFaltas()` desconta reservado e soma de
      volta a reserva do próprio orçamento
- [x] `SaleStockService` (PDV) vende do disponível — **sem alterar `SaleFlowTest`
      nem `SaleReturnFlowTest`** (aceite duro cumprido)
- [x] `OsAplicacaoPecaService`: contexto expõe disponível/reservado e `aplicar()`
      consome a reserva
- [x] `EstoqueController::mapPecaSummary()` expõe reservado/disponível
- [x] 3 casos novos em `OsAplicacaoPecaTest`

## Bloco E — Furos de escrita ✅
- [x] `EstoqueController::update()` recusa `quantidade_atual` com 422
      (fecha também o bug do PATCH que zerava o saldo ao omitir o campo)
- [x] `storeMovement()` entrada/saída pelo motor único; `ajuste` com guarda de
      reserva e confirmação explícita
- [x] Desktop: quantidade readonly na edição, com link para a movimentação
- [x] `EstoqueFurosDeEscritaTest` — 7 casos
- [ ] `store()` e `importCsv()` — **036 Bloco B**, fora de escopo aqui

## Bloco F — Telas ✅
- [x] Badge Em estoque / Parcial / A encomendar no detalhe do orçamento, com
      alerta de resumo
- [x] Aviso de estoque na linha do item do formulário, reagindo à quantidade
- [x] Busca remota paginada de peça (`orcamentos.parts.search`), com o catálogo
      estático como fallback automático
- [x] Endpoint `GET /estoque/a-comprar` + tela "Peças a comprar" com drill-down
      e link para a entrada por compra da `039`
- [x] Colunas Reservado/Disponível na listagem de estoque

## Pendente
- [ ] Teste de concorrência no grupo `mysql` (duas conexões PDO; `lockForUpdate()`
      é no-op em SQLite, então nada hoje prova o lock da reserva)
- [ ] `backend/openapi.yaml` — campos novos em `Peca`, `GET /estoque/a-comprar`,
      `orcamento_itens[].disponibilidade`
- [ ] Nota de release + índices em `documentacao/`
- [ ] `./scripts/bump-version.sh` — **bloqueado**: `VERSION`, `CHANGELOG.md` e
      `shared/version.php` estão com alteração não commitada de outra sessão
      (entrega do PDF "OS completa"). Bumpar por cima tangeria a release note
      dela.
- [ ] Aplicar as duas migrations na base de desenvolvimento (usar `--path`: o
      grant de `sistema_erp_chat` aborta o `migrate` seco)

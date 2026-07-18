# Tasks: Gestão de contas financeiras e disponibilidade de caixa

## Phase 1 - Especificação e fundação

- [x] T001 Formalizar escopo, regras, modelo e contratos em `specs/021-gestao-contas-financeiras/`.
- [x] T002 Criar migration aditiva de contas, movimentos patrimoniais, transferências, defaults e vínculo nas baixas.
- [x] T003 Criar models e relações sem expor regra de saldo no frontend.

## Phase 2 - Posição financeira (US1)

- [x] T004 [US1] Implementar agregação de saldos disponíveis, cartão a receber e posição total.
- [x] T005 [US1] Implementar CRUD seguro de contas e saldo inicial auditável.
- [x] T006 [US1] Implementar extrato paginado e movimentos não classificados.
- [x] T007 [US1] Cobrir posição, cartão líquido e data inicial com testes de backend.

## Phase 3 - Integração das baixas (US2)

- [x] T008 [US2] Resolver conta explícita/padrão em `FinanceiroService::registerMovement()`.
- [x] T009 [US2] Adicionar conta aos FormRequests e ao contrato de baixa financeira.
- [x] T010 [US2] Integrar conta à baixa/adiantamento/sinal da OS sem alterar as regras de status.
- [x] T011 [US2] Confirmar crédito efetivo de cartão e preservar valor líquido sem taxa duplicada.
- [x] T012 [US2] Cobrir baixa direta, OS, default, ausência de mapeamento e cartão com testes.

## Phase 4 - Transferências e conciliação (US3/US4)

- [x] T013 [US3] Implementar transferência atômica, validação de saldo e cancelamento auditável.
- [x] T014 [US4] Implementar ajuste de conciliação com justificativa obrigatória.
- [x] T015 [US4] Implementar resumo mensal por conta.
- [x] T016 [US3/US4] Cobrir concorrência lógica, invariância do consolidado e ausência de impacto na DRE.

## Phase 5 - Desktop

- [x] T017 Criar service/controller/rotas BFF para contas financeiras.
- [x] T018 Criar painel responsivo com posição, fechamento mensal, pendências e extrato.
- [x] T019 Criar fluxos de conta, ajuste, transferência, cancelamento e confirmação de cartão.
- [x] T020 Adicionar seleção/default de conta às baixas de Financeiro e OS.
- [x] T021 Atualizar navegação e atalhos do Financeiro.
- [x] T022 Cobrir rotas, payloads, RBAC e renderização desktop com testes.

## Phase 6 - Conclusão

- [x] T023 Atualizar `backend/openapi.yaml` e documentação técnica/operacional.
- [x] T024 Executar lint, migrations, testes direcionados e regressões financeiras/OS.
- [ ] T025 Validar visualmente nos breakpoints críticos e executar auditoria independente. A prévia foi renderizada, mas o navegador interno bloqueou o host local; concluir após implantação em ambiente acessível.
- [x] T026 Sincronizar contexto vivo e versionar como `minor` (`4.19.0.0`).

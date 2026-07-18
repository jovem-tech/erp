# Implementation Plan: Gestão de contas financeiras e disponibilidade de caixa

**Branch**: `develop` | **Date**: 2026-07-18 | **Spec**: [spec.md](./spec.md)

## Summary

Adicionar uma camada de tesouraria ao financeiro existente. Baixas continuam sendo a fonte do faturamento/DRE, mas recebem `conta_financeira_id`; saldos são derivados dessas baixas mais movimentos exclusivamente patrimoniais (saldo inicial, ajustes e transferências). Cartão permanece previsto pelo valor líquido até confirmação do crédito.

## Technical Context

**Language/Version**: PHP 8.3/8.5, Laravel 13, JavaScript e Blade

**Primary Dependencies**: Eloquent, Sanctum/RBAC, Bootstrap 5, Select2

**Storage**: MySQL 8.0/8.4 em produção; SQLite em memória nos testes

**Testing**: PHPUnit/Feature no backend e desktop

**Target Platform**: Ubuntu Linux, Nginx e PHP-FPM

**Project Type**: monorepo web com backend API central e BFF desktop

**Performance Goals**: painel com agregações constantes por conta; extrato paginado; sem N+1 por movimento

**Constraints**: migration apenas aditiva, banco compartilhado com legado, contratos atuais retrocompatíveis

**Scale/Scope**: assistência técnica pequena/média, dezenas de contas e centenas de milhares de movimentos ao longo dos anos

## Constitution Check

- Backend central mantém toda regra monetária, validação e atomicidade: **PASS**.
- Desktop continua consumindo apenas API: **PASS**.
- Novas rotas/payloads serão documentados no OpenAPI: **PASS**.
- Textos pt-BR/UTF-8, Select2-first e layout responsivo: **PASS**.
- Migration aditiva e segura para MySQL/SQLite: **PASS**.
- Fechamento de OS não ganha novo caminho de status: **PASS**.

## Architecture

1. `financeiro_contas` guarda metadados e o início do controle, mas não um saldo mutável.
2. `financeiro_conta_movimentos` guarda apenas movimentos patrimoniais que não existem em `financeiro_movimentos`: saldo inicial, ajustes e pares de transferência.
3. `financeiro_movimentos.conta_financeira_id` associa as baixas já existentes à fonte/destino real.
4. O saldo é calculado por agregação das duas fontes, evitando ledger duplicado e inconsistência em estornos/cancelamentos.
5. Cartões usam `financeiro_movimentos_cartao.valor_liquido`; sem `data_credito_efetivo` ficam previstos, com a data confirmada tornam-se disponíveis.
6. `FinanceiroContaService` concentra CRUD, posição, extrato, ajustes, transferências, confirmação de cartão e resolução de conta padrão.
7. O desktop usa `FinanceiroAccountService` como BFF e uma tela única de posição, extratos e ações operacionais.

## Data and Transaction Decisions

- Valores persistidos em `DECIMAL(14,2)` e sempre positivos; natureza define sinal.
- Transferência cria cabeçalho e dois movimentos dentro de uma transação, com `lockForUpdate` nas contas.
- Saldos não são editáveis. Divergências geram ajuste com justificativa.
- Conta desativada deixa de aceitar novos movimentos, mas preserva histórico.
- Default de forma de pagamento é normalizado numa tabela com uma linha por forma.
- A data inicial representa o primeiro dia rastreado; o saldo inicial é lançado no dia anterior.

## Source Code

```text
backend/
├── app/Models/FinanceiroConta*.php
├── app/Services/Financeiro/FinanceiroContaService.php
├── app/Http/Controllers/Api/V1/FinanceiroContaController.php
├── app/Http/Requests/Api/V1/*FinanceiroConta*.php
├── database/migrations/2026_07_18_*.php
├── routes/api.php
└── tests/Feature/Api/V1/FinanceiroContaTest.php

frontends/desktop/
├── app/Services/FinanceiroAccountService.php
├── app/Http/Controllers/FinanceiroAccountController.php
├── resources/views/financeiro/contas/index.blade.php
├── routes/web.php
└── tests/Feature/Desktop/FinanceiroAccountTest.php
```

## Trade-offs

- Derivar saldos dos movimentos financeiros elimina sincronização duplicada, ao custo de queries de agregação mais elaboradas; índices compostos mitigam o custo.
- Confirmação de cartão é manual nesta fase porque uma data prevista não comprova crédito efetivo; integração bancária/adquirente fica futura.
- Movimentos históricos sem conta permanecem visíveis como “não classificados”; inferência automática poderia produzir saldos falsos.
- Não há fechamento contábil bloqueado nesta fase. O relatório mensal e as transferências cobrem a rotina gerencial sem introduzir reabertura de período prematura.

## Delivery

- atualizar backend, desktop, OpenAPI, testes, documentação e contexto vivo;
- classificar como `minor` por novas entidades, rotas e migration aditiva;
- executar migrations e testes no desenvolvimento oficial;
- não promover para `main` nem implantar na VPS sem autorização específica.

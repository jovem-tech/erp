# Implementation Plan: Orcamentos - Ajustes Monetarios ou Percentuais

**Branch**: `018-orcamentos-ajustes-percentuais-desktop` | **Date**: 2026-07-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/018-orcamentos-ajustes-percentuais-desktop/spec.md`

## Summary

Permitir que desconto e acrescimo do orcamento, tanto nos itens quanto no resumo financeiro, sejam operados em `R$` ou `%`, preservando a fonte de verdade no backend central e mantendo retrocompatibilidade com payloads antigos.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.x no backend central, Laravel 11+ no desktop, JavaScript vanilla no frontend desktop

**Primary Dependencies**: Blade, Bootstrap 5, SweetAlert2, Select2, Eloquent, HTTP client do desktop

**Storage**: MySQL do backend central para orcamentos e itens; sessao server-side no desktop

**Testing**: PHPUnit/Pest no backend e no desktop; validacao de sintaxe JS/PHP

**Target Platform**: Windows/XAMPP em desenvolvimento; Ubuntu VPS em producao

**Project Type**: backend API + frontend desktop Laravel/Blade

**Constraints**: backend central continua como fonte unica de verdade; payload antigo continua aceito; sem acesso direto a banco no desktop

## Constitution Check

- regra de negocio de recalculo fica no backend central: aprovado;
- desktop apenas projeta UX e manda metadados do ajuste: aprovado;
- contrato de API precisa ser revisto em `backend/openapi.yaml`: aprovado;
- documentacao e nota versionada acompanham a entrega: aprovado.

## Project Structure

### Documentation (this feature)

```text
specs/018-orcamentos-ajustes-percentuais-desktop/
|-- spec.md
|-- plan.md
`-- tasks.md
```

### Source Code

```text
backend/
|-- app/Http/Requests/Api/V1/UpsertBudgetRequest.php
|-- app/Models/Budget.php
|-- app/Models/BudgetItem.php
|-- app/Services/Budgets/BudgetWorkflowService.php
|-- database/migrations/
|-- openapi.yaml
`-- tests/Feature/Api/V1/BudgetFlowTest.php

frontends/desktop/
|-- app/Http/Controllers/DesktopController.php
|-- app/Http/Controllers/OrcamentoController.php
|-- public/assets/js/orcamentos-form.js
|-- resources/views/orcamentos/form.blade.php
|-- resources/views/orcamentos/partials/item-row.blade.php
`-- tests/Feature/Desktop/DesktopFrontendTest.php
```

## Implementation Phases

1. adicionar metadados de modo/percentual no backend central com migracao e retrocompatibilidade;
2. recalcular valores absolutos no backend a partir do modo informado;
3. adaptar o desktop para alternar entre `R$` e `%` sem quebrar o layout;
4. garantir restauracao correta em create/edit e no rascunho local;
5. atualizar testes, contrato e documentacao.

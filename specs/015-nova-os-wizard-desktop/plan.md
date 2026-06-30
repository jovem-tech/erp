# Implementation Plan: Nova OS em modo wizard no desktop

**Branch**: `015-nova-os-wizard-desktop` | **Date**: 2026-06-29 | **Spec**: [spec.md](./spec.md)

## Summary

Evoluir `/os/criar` no desktop para um wizard em duas colunas inspirado no legado, com painel lateral de resumo e formulário segmentado em abas. A implementação precisa manter a fonte única de verdade no backend central, adicionar suporte real para fotos na criação da OS e preservar RBAC, storage privado e compatibilidade com Ubuntu VPS.

## Technical Context

**Language/Version**:
- backend: PHP 8+ com Laravel
- desktop: PHP 8+ com Laravel/Blade
- frontend runtime: JavaScript vanilla com Bootstrap 5 e Select2 já carregados

**Primary Dependencies**:
- Bootstrap 5 para tabs e layout responsivo
- Select2 para selects de cliente/equipamento/técnico
- Laravel HTTP client no desktop para repasse de multipart

**Storage**:
- MySQL/MariaDB compartilhado
- arquivos da OS em `storage/app/private`
- rota autenticada existente para fotos de OS

**Testing**:
- `php artisan test` no backend (`backend/tests/Feature/Api/V1/`)
- `php artisan test` no desktop (`frontends/desktop/tests/Feature/Desktop/`)

**Target Platform**:
- Windows + XAMPP em desenvolvimento
- Ubuntu VPS em produção

**Project Type**:
- backend central Laravel API
- frontend desktop Laravel/Blade com sessão server-side

**Performance Goals**:
- a troca entre abas e a atualização do resumo devem ocorrer sem reload
- os selects devem continuar responsivos com o menor número de chamadas extras possível

**Constraints**:
- sem acesso direto ao banco pelo desktop
- sem quebrar a API central existente
- compatível com filesystem case-sensitive
- arquivos privados não podem ficar públicos por atalho de URL

**Scale/Scope**:
- altera o fluxo de criação de OS no desktop
- adiciona suporte de upload de fotos na criação
- impacta backend, desktop, testes, documentação e versionamento

## Constitution Check

- Backend como fonte única: mantido.
- Storage privado: mantido e reforçado para fotos da OS.
- Compatibilidade Linux/VPS: caminhos relativos, sem hardcode Windows.
- Documentação sincronizada: obrigatória no final.
- Contrato API: precisa ser revisto para incluir o upload de fotos.

## Project Structure

### Documentation (this feature)

```text
specs/015-nova-os-wizard-desktop/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code (repository root)

```text
backend/
├── app/Http/Controllers/Api/V1/OrderController.php
├── app/Http/Requests/Api/V1/UpsertOrderRequest.php
├── app/Services/Orders/OrderWorkflowService.php
├── openapi.yaml
└── tests/Feature/Api/V1/OrderFlowTest.php

frontends/desktop/
├── app/Http/Controllers/OrderController.php
├── app/Services/OrderService.php
├── resources/views/orders/create.blade.php
├── public/assets/js/orders-create.js
├── public/assets/css/desktop.css
└── tests/Feature/Desktop/
```

**Structure Decision**: manter a arquitetura atual, com o backend central persistindo tudo e o desktop apenas compondo a experiência e repassando arquivos por multipart.

## Phase 0 - Research Decisions

- Resumo lateral: usar dados já retornados por `ClientService::paginate()` e `EquipmentService::paginate()`, enriquecendo apenas o selecionado quando necessário.
- Foto do equipamento: reaproveitar `primary_photo_url` retornado pelo endpoint de equipamentos.
- Fotos da OS: persistir no backend central em storage privado via o mesmo estilo de upload já usado em equipamentos, adaptado ao fluxo da OS.
- Tabs e resumo: implementar em JavaScript leve, sem dependência nova.
- Validação de arquivos: limitar a `jpg/jpeg/png/webp` e 2MB por arquivo, com máximo de 4 fotos.


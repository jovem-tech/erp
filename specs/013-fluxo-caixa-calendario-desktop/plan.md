# Implementation Plan: Fluxo de caixa no desktop com lista e calendário

**Branch**: `013-fluxo-caixa-calendario-desktop` | **Date**: 2026-06-28 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/013-fluxo-caixa-calendario-desktop/spec.md`

## Summary

Adicionar ao relatório `/financeiro/relatorios/fluxo-caixa` uma alternância entre lista e calendário mensal, preservando o payload atual do backend e centralizando a montagem visual no desktop.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 11+ no desktop

**Primary Dependencies**: Blade, Bootstrap 5, Carbon, Select2 já presente no shell do desktop

**Storage**: Sessão server-side no desktop; os dados vêm do backend central já existente

**Testing**: Pest/PHPUnit do desktop com `Http::fake()` para o relatório financeiro

**Target Platform**: Windows + XAMPP em desenvolvimento; VPS/Linux em produção

**Project Type**: Web application com frontend Laravel/Blade separado do backend central

**Performance Goals**: alternância instantânea via GET, renderização do calendário sem chamadas extras e layout responsivo com overflow controlado

**Constraints**: sem acesso direto ao banco; manter o padrão visual do desktop; preservar o contrato atual do relatório

**Scale/Scope**: uma tela de relatório financeiro com duas leituras do mesmo conjunto de dados

## Constitution Check

- backend central como fonte única de verdade: respeitado.
- sem acesso direto ao banco pelo desktop: respeitado.
- responsividade e segurança de ambientes Linux/Ubuntu: respeitados.
- documentação e rastreabilidade em `specs/`: obrigatórias.

## Project Structure

### Documentation (this feature)

```text
specs/013-fluxo-caixa-calendario-desktop/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code

```text
frontends/desktop/
├── app/Http/Controllers/FinanceiroReportController.php
├── public/assets/css/desktop.css
├── resources/views/financeiro/relatorios/fluxo-caixa.blade.php
└── tests/Feature/Desktop/FinanceiroReportTest.php

documentacao/
├── 03-arquitetura-tecnica/frontend-desktop-laravel.md
└── 07-novas-implementacoes/
```

## Implementation Phases

1. acrescentar o modo de visualização no controller do desktop e preparar os dados do calendário;
2. atualizar a Blade do relatório para alternar entre lista e calendário mensal;
3. adicionar classes visuais no CSS publicado do desktop para o grid do calendário;
4. cobrir a nova visualização com testes de renderização;
5. atualizar documentação, nota versionada e sincronização do contexto vivo.

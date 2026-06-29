# Implementation Plan: Financeiro - Cartões e Taxas no Desktop

**Branch**: `012-financeiro-cartoes-taxas-desktop` | **Date**: 2026-06-28 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/012-financeiro-cartoes-taxas-desktop/spec.md`

## Summary

Entregar no `frontends/desktop` o módulo de cartões e taxas com a mesma linguagem operacional do legado, consumindo o backend central como única fonte de dados, com Select2 obrigatório em todos os selects visíveis.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 11+ no desktop

**Primary Dependencies**: Blade, Bootstrap 5, Select2, SweetAlert2, HTTP client do Laravel

**Storage**: Sessão server-side no desktop; dados operacionais vêm da API central

**Testing**: Pest/PHPUnit do desktop e contratos HTTP fakeados

**Target Platform**: Windows + XAMPP em desenvolvimento; VPS/Linux em produção

**Project Type**: Web application com frontend Laravel/Blade separado do backend central

**Performance Goals**: carregamento inicial sem bloqueio visual e atualização de abas sem reload completo quando possível

**Constraints**: sem acesso direto ao banco; tokens fora do navegador; todos os selects visíveis com Select2; respostas em pt-BR

**Scale/Scope**: um módulo financeiro de configuração e simulação com múltiplas abas e catálogos curtos

## Constitution Check

- Select2-first obrigatório para qualquer select visível do desktop: aprovado pela constituição.
- Sessão server-side para o token: aprovado.
- Sem acesso direto ao banco: aprovado.
- pt-BR e UTF-8: aprovado.

## Project Structure

### Documentation (this feature)

```text
specs/012-financeiro-cartoes-taxas-desktop/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api.md
└── tasks.md
```

### Source Code

```text
backend/
├── app/Http/Controllers/Api/V1/FinanceiroCartaoController.php
├── app/Services/Financeiro/FinanceiroCartaoService.php
└── routes/api.php

frontends/desktop/
├── app/Http/Controllers/FinanceiroCartaoController.php
├── app/Services/FinanceiroCartaoService.php
├── public/assets/js/financeiro-cartoes.js
├── resources/views/financeiro/cartoes.blade.php
└── resources/views/financeiro/cartoes-help.blade.php
```

## Implementation Phases

1. consolidar o contrato da API de cartões e taxas;
2. entregar a tela desktop com abas, formulários, ajuda e simulador;
3. garantir Select2 em todos os selects visíveis;
4. adicionar testes de renderização e contrato;
5. atualizar documentação, versão e trilha de governança.

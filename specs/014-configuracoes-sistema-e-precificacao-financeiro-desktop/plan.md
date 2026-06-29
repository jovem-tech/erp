# Implementation Plan: Configuracoes do Sistema e Precificacao no desktop

**Branch**: `014-configuracoes-sistema-e-precificacao-financeiro-desktop` | **Date**: 2026-06-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/014-configuracoes-sistema-e-precificacao-financeiro-desktop/spec.md`

## Summary

Separar Integracoes das Configuracoes do Sistema no desktop e trazer a Precificacao para o menu Financeiro com backend central, contrato de API e tela propria inspirada no modulo legado.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 11 no frontend desktop e Laravel 11 no backend central

**Primary Dependencies**: Blade, Bootstrap 5, Http client do Laravel, Eloquent, Sanctum, Carbon

**Storage**: Banco central do backend para regras de negocio; sessao server-side apenas no desktop

**Testing**: PHPUnit/Pest do backend e do desktop com `Http::fake()` e cobertura de rotas/API

**Target Platform**: Windows/XAMPP para desenvolvimento local e Ubuntu VPS para producao

**Project Type**: Aplicacao web modular com backend central e frontend desktop Laravel/Blade

**Performance Goals**: reduzir carga visual da pagina de Integracoes, manter o shell leve e evitar chamadas desnecessarias no carregamento das telas de configuracao

**Constraints**: sem acesso direto a banco pelo frontend; manter seguranca de configuracoes sensiveis; seguir caminhos POSIX e case-sensitive no Linux

**Scale/Scope**: reorganizacao de navegacao e entrega de um modulo financeiro de precificacao com formulacao basica, catalogo e simulador

## Constitution Check

- backend central como fonte unica de verdade: respeitado.
- sem acesso direto ao banco pelo desktop: respeitado.
- documentacao e rastreabilidade em `specs/`: obrigatorias.
- compatibilidade com Ubuntu VPS e seguranca: obrigatorias.

## Project Structure

### Documentation (this feature)

```text
specs/014-configuracoes-sistema-e-precificacao-financeiro-desktop/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code

```text
backend/
├── app/Http/Controllers/Api/V1/ConfigurationController.php
├── app/Http/Controllers/Api/V1/FinanceiroPrecificacaoController.php
├── app/Models/Precificacao*.php
├── app/Services/Financeiro/Precificacao*.php
├── database/migrations/*
└── openapi.yaml

frontends/desktop/
├── app/Http/Controllers/ConfigurationController.php
├── app/Http/Controllers/FinanceiroPrecificacaoController.php
├── app/Services/ConfigurationService.php
├── app/Services/FinanceiroPrecificacaoService.php
├── app/Support/DesktopNavigation.php
├── resources/views/configurations/*
├── resources/views/financeiro/precificacao.blade.php
└── tests/Feature/Desktop/*
```

## Implementation Phases

1. separar visualmente Integracoes de Configuracoes do Sistema no desktop e ajustar a navegacao lateral;
2. criar a pagina de Configuracoes do Sistema com tabs de Aparencia, Dados da Empresa, Sessao e Seguranca;
3. portar a precificacao do legado para o backend central com tabelas, modelos, servicos e contratos de API;
4. criar a tela desktop de precificacao dentro do Financeiro e ligar os fluxos de configuracao e simulador;
5. atualizar testes, documentacao, contexto vivo e historico de versao.

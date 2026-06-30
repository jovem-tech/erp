# Implementation Plan: Modelo Ideal da Assistência Técnica no Desktop

**Branch**: `016-modelo-assistencia-tecnica-desktop` | **Date**: 2026-06-29 | **Spec**: [spec.md](./spec.md)

## Summary

Criar uma nova página de conhecimento no desktop para representar um modelo ideal de assistência técnica, com foco em fluxo visual, prevenção de gargalos, fila saudável e saída controlada para exceções como garantia, peça pendente, pagamento pendente e cancelamento.

## Technical Context

**Language/Version**:
- frontend desktop: PHP 8+ com Laravel/Blade

**Primary Dependencies**:
- Bootstrap 5 já disponível no shell desktop
- classes visuais existentes de workflow e surface cards

**Storage**:
- sem persistência nova

**Testing**:
- `php artisan test` no desktop (`frontends/desktop/tests/Feature/Desktop/`)

**Target Platform**:
- Windows + XAMPP em desenvolvimento
- Ubuntu VPS em produção

**Project Type**:
- frontend desktop Laravel/Blade com sessão server-side

**Performance Goals**:
- render estático leve
- diagrama legível sem dependência de API nova

**Constraints**:
- sem acesso direto ao banco
- sem introduzir contrato novo desnecessário
- compatível com filesystem case-sensitive

**Scale/Scope**:
- nova rota em gestão de conhecimento
- novo item no menu lateral
- novo teste funcional e atualização documental

## Constitution Check

- Backend como fonte única: preservado, sem regra de negócio nova.
- UX operacional: a página precisa ser clara e responsiva.
- Segurança: rota protegida por permissão de conhecimento.
- Documentação sincronizada: obrigatória no final.

## Project Structure

### Documentation (this feature)

```text
specs/016-modelo-assistencia-tecnica-desktop/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code (repository root)

```text
frontends/desktop/
├── app/Http/Controllers/AssistanceModelController.php
├── app/Support/DesktopNavigation.php
├── resources/views/knowledge/assistance-model/index.blade.php
├── routes/web.php
└── tests/Feature/Desktop/DesktopFrontendTest.php
```

**Structure Decision**: manter a página como conteúdo visual do desktop, sem adicionar API, service ou migration.

## Phase 0 - Research Decisions

- usar os padrões visuais já existentes do desktop para manter consistência;
- estruturar o diagrama em fases operacionais para leitura rápida;
- destacar regras anti-gargalo como cards e blocos curtos;
- manter a página útil tanto para treinamento quanto para operação;
- não misturar a exceção com a fila principal.

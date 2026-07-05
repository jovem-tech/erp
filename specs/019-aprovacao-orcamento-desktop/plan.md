# Implementation Plan: Orcamentos - Revisao antes de salvar e envio para aprovacao

**Branch**: `019-aprovacao-orcamento-desktop` | **Date**: 2026-07-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/019-aprovacao-orcamento-desktop/spec.md`

## Summary

Adicionar uma etapa de revisão visual antes de salvar o orçamento no desktop e ativar o fluxo comercial mínimo de envio para aprovação, com PDF, token público, página de decisão do cliente e rastreabilidade de envios/aprovações no backend central.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.x no backend, Laravel 11+ no desktop, JavaScript vanilla no frontend desktop

**Primary Dependencies**: Blade, Bootstrap 5, SweetAlert2, DomPDF, Eloquent, HTTP client do desktop

**Storage**: MySQL para orçamentos/envios/aprovações; storage privado do backend para PDFs temporários ou persistidos

**Testing**: PHPUnit/Pest no backend e no desktop; validação de sintaxe PHP/JS

**Target Platform**: Windows/XAMPP em desenvolvimento; Ubuntu VPS em produção

**Project Type**: backend API + backend web pública para aprovação + frontend desktop Laravel/Blade

**Constraints**:
- backend central continua sendo a fonte única da regra comercial;
- a UI do desktop não pode decidir aprovação, só revisar e disparar o fluxo;
- arquivos do orçamento devem permanecer em storage privado;
- a primeira fase seguirá o padrão mais sólido do legado: envio primário via WhatsApp com PDF e link público.

## Constitution Check

- regra de negócio e rastreabilidade ficam no backend central: aprovado;
- desktop atua apenas como camada de revisão e intenção de disparo: aprovado;
- a mudança toca UI, fluxo operacional, contrato e web pública: exige spec, documentação e revisão de API;
- a decisão pública do cliente precisa ser segura, auditável e compatível com Ubuntu VPS: aprovado.

## Project Structure

### Documentation (this feature)

```text
specs/019-aprovacao-orcamento-desktop/
|-- spec.md
|-- plan.md
`-- tasks.md
```

### Source Code

```text
backend/
|-- app/Http/Controllers/Api/V1/BudgetController.php
|-- app/Http/Controllers/Web/BudgetPublicController.php
|-- app/Http/Requests/Api/V1/
|-- app/Services/Budgets/
|-- resources/views/budgets/
|-- routes/api.php
|-- routes/web.php
|-- openapi.yaml
`-- tests/

frontends/desktop/
|-- app/Http/Controllers/OrcamentoController.php
|-- app/Services/OrcamentoService.php
|-- public/assets/js/orcamentos-form.js
|-- public/assets/css/desktop.css
|-- resources/views/orcamentos/form.blade.php
`-- tests/Feature/Desktop/DesktopFrontendTest.php
```

## Implementation Phases

1. abrir a trilha da feature e estabilizar o contrato do fluxo;
2. criar no backend o serviço de PDF, o disparo para aprovação e a página pública de decisão;
3. refletir envio/aprovação na OS vinculada e na rastreabilidade do orçamento;
4. interceptar o submit do desktop com modal de revisão e intenção de envio;
5. validar create/edit, aprovação pública e falhas de envio sem perda de dados;
6. atualizar documentação, contrato e contexto vivo.

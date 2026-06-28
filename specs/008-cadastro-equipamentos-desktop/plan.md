# Implementation Plan: Cadastro Completo de Equipamentos no Desktop

**Branch**: `008-cadastro-equipamentos-desktop` | **Date**: 2026-06-24 | **Spec**: [spec.md](./spec.md)

## Summary

Extensao aplicada em 2026-06-25: o mesmo escopo agora cobre tambem a edicao operacional em `/equipamentos/{id}/editar`, reaproveitando a interface, o submit multipart e as regras de foto principal do fluxo de cadastro.

Entregar o fluxo completo de `Novo Equipamento` no `frontends/desktop`, com paridade operacional e visual ao legado, usando o `backend/` como fonte única de dados, catálogo, storage privado, sugestões externas e coletor local legado como fluxo principal, mantendo o pareamento remoto como apoio.

## Technical Context

**Language/Version**:
- backend: PHP 8+ com Laravel
- desktop: PHP 8+ com Laravel/Blade
- frontend runtime: JavaScript vanilla no desktop

**Primary Dependencies**:
- Bootstrap 5
- SweetAlert2
- Chart.js já usado no desktop
- Cropper.js via CDN para recorte local de imagem

**Storage**:
- MySQL/MariaDB em `sistema_hml`
- storage privado do backend para fotos de equipamentos
- sessão file no desktop em desenvolvimento

**Testing**:
- `php artisan test` no backend
- `php artisan test` no desktop

**Target Platform**:
- Windows + XAMPP em desenvolvimento
- VPS Linux em produção

**Project Type**:
- backend central Laravel API-only
- frontend desktop Laravel/Blade com sessão server-side

**Performance Goals**:
- quick-adds e sugestões devem responder com fallback seguro sem travar a página
- importação do coletor deve atualizar o formulário atual sem reload completo
- quando a execução automática falhar, o sistema deve aproveitar o último snapshot local disponível sem travar o cadastro

**Constraints**:
- sem acesso direto ao banco pelo desktop
- sem token no navegador para consumir a API central
- no máximo 4 fotos por cadastro
- storage privado obrigatório
- paridade visual com o shell do desktop já existente
- dropdowns do desktop padronizados com Select2 como regra de interface

**Scale/Scope**:
- o mesmo modulo passa a cobrir criacao e edicao com reaproveitamento do formulario operacional
- um novo fluxo completo de cadastro dentro do módulo de equipamentos
- impacto em backend, desktop, testes, docs e versionamento compartilhado

## Constitution Check

- Documentação sincronizada: obrigatório atualizar documentação técnica, API, nota de implementação e README.
- pt-BR e UTF-8: obrigatório em labels, feedbacks e documentação.
- Backend central como fonte única: mantido, sem acesso direto ao banco pelo desktop.
- UX operacional e falha segura: obrigatório manter cadastro manual utilizável quando integrações auxiliares falharem.
- Compatibilidade Windows/VPS: obrigatório suportar leitura local do coletor somente quando o ERP estiver na mesma máquina Windows, com falha segura e fluxo manual preservado em VPS/Linux.

## Project Structure

### Documentation (this feature)

```text
specs/008-cadastro-equipamentos-desktop/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── analysis.md
├── tasks.md
└── contracts/
    └── equipment-create-api.md
```

### Source Code (repository root)

```text
backend/
├── app/
│   ├── Http/Controllers/Api/V1/
│   ├── Http/Requests/Api/V1/
│   ├── Models/
│   └── Services/
├── config/
├── database/migrations/
└── tests/Feature/Api/V1/

frontends/desktop/
├── app/Http/Controllers/
├── app/Services/
├── public/assets/css/
├── public/assets/js/
├── resources/views/equipments/
└── tests/Feature/Desktop/

shared/
└── version.php
```

**Structure Decision**: manter o backend e o desktop separados, com a feature dividida entre contrato/API, façade same-origin no desktop, UI Blade, scripts de interação local e documentação sincronizada.

## Phase 0 - Research Decisions

- a tela de edicao deve reutilizar o mesmo Blade e o mesmo JavaScript do cadastro, acrescentando apenas hidratacao inicial, sincronizacao de fotos existentes e permissoes auxiliares coerentes com RBAC;

- coletor local legado em `C:\JovemTechBenchCollector` como fluxo principal, com execução automática quando possível;
- o cartão do coletor local só aparece para tipos da família `desktop` ou `notebook`;
- pareamento remoto com código, TTL curto e consumo único preservado como apoio;
- sugestão externa de modelo via backend, com cache e timeout curto;
- fotos tratadas no browser com preview local e envio multipart ao backend;
- cliente rápido reutilizando a API central de clientes por façade JSON no desktop;
- `Cropper.js` via CDN para manter o stack simples e alinhado ao desktop atual.

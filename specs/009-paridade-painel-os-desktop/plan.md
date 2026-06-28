# Implementation Plan: Paridade Operacional do Painel de Ordens de Serviço no Desktop

**Branch**: `009-paridade-painel-os-desktop` | **Date**: 2026-06-26 | **Spec**: [spec.md](./spec.md)

## Summary

Enriquecer `GET /api/v1/orders` (backend) e `resources/views/orders/index.blade.php`
(desktop) para dar à listagem de OS do sistema-erp a mesma utilidade
operacional do painel do sistema-hml — foto do equipamento, cliente com
WhatsApp, equipamento resumido, datas com cor de atraso, status do orçamento
vinculado e breakdown financeiro (Total/Recebido/Saldo) — sem repetir os
problemas do legado (consultas por linha, lógica de formatação espalhada,
modais com iframe). O breakdown financeiro fica viável nesta entrega porque
o módulo `Financeiro`/`FinanceiroMovimento` e o módulo de Orçamentos
(`Budget`) já existem no backend.

## Technical Context

**Language/Version**:
- backend: PHP 8+ com Laravel
- desktop: PHP 8+ com Laravel/Blade
- frontend runtime: JavaScript vanilla (`desktop.js`) com jQuery+Select2 já carregados

**Primary Dependencies**:
- Bootstrap 5 (já em uso, inclusive `collapse` para o novo bloco de filtros avançados)
- Select2 com tema `bootstrap-5`, inicializado globalmente por `DesktopUi.initSelect2()`

**Storage**:
- MySQL/MariaDB compartilhado pelo backend (`os`, `clientes`, `equipamentos`,
  `equipamentos_fotos`, `orcamentos`, `financeiro`, `financeiro_movimentos`)
- Nenhuma migration nova é necessária — todos os dados já existem nas tabelas atuais

**Testing**:
- `php artisan test` no backend (`backend/tests/Feature/Api/V1/`)
- `php artisan test` no desktop (`frontends/desktop/tests/Feature/Desktop/`)

**Target Platform**:
- Windows + XAMPP em desenvolvimento
- VPS Linux em produção

**Project Type**:
- backend central Laravel API-only
- frontend desktop Laravel/Blade com sessão server-side

**Performance Goals**:
- a listagem não pode emitir consultas adicionais proporcionais à quantidade
  de OS exibidas por página (orçamento e financeiro resolvidos em lote)

**Constraints**:
- sem acesso direto ao banco pelo desktop
- sem criação de migration nova (reaproveita schema já existente)
- todo `<select>` novo no desktop segue Select2 (constituição, princípio 5)
- responsividade obrigatória nos breakpoints já adotados pelo projeto
  (`1280px`, `992px`, `768px`, `430px`, `390px`, `360px`, `320px`)

**Scale/Scope**:
- altera o contrato de resposta de `GET /api/v1/orders` (campos novos,
  nenhum campo existente removido) e os filtros aceitos
- altera apenas a tela de listagem (`orders/index.blade.php`); a tela de
  detalhe (`orders/show.blade.php`) não faz parte desta entrega
- impacto em backend, desktop, testes, documentação e versionamento
  compartilhado

## Constitution Check

- Documentação sincronizada: obrigatório atualizar `backend/openapi.yaml`,
  contrato da API e nota de implementação.
- pt-BR e UTF-8: obrigatório em rótulos novos (datas, prazo, orçamento,
  valores).
- Backend central como fonte única: mantido — todo cálculo (prazo,
  resumo curto, agregação financeira) acontece no `backend/`, o desktop só
  renderiza o que a API devolve.
- UX operacional, responsividade real e falha segura: obrigatório manter os
  breakpoints já validados pelo projeto e os novos `<select>` em Select2;
  estados ausentes (sem foto, sem orçamento, sem cobrança, sem técnico) MUST
  exibir um indicador neutro em vez de quebrar a tela.
- Compatibilidade Windows/VPS: sem dependência de caminho físico novo; a
  foto do equipamento reaproveita o storage privado e a rota já existentes.

## Project Structure

### Documentation (this feature)

```text
specs/009-paridade-painel-os-desktop/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code (repository root)

```text
backend/
├── app/Services/Orders/OrderWorkflowService.php   # baseSummaryQuery, filtros, mapSummary, contexto em lote
├── app/Models/Order.php                           # sem mudança de schema, só leitura
├── routes/api.php                                 # sem rota nova (reaproveita api.v1.equipments.photos.show)
├── openapi.yaml                                   # contrato atualizado de GET /api/v1/orders
└── tests/Feature/Api/V1/OrdersTest.php            # cobertura dos novos campos e da ausência de N+1

frontends/desktop/
├── app/Http/Controllers/OrderController.php       # repassa os novos filtros
├── app/Services/OrderService.php                  # idem
├── resources/views/orders/index.blade.php         # novas colunas + bloco de filtros avançados
├── public/assets/css/desktop.css                  # ajustes pontuais de células novas, se necessário
└── tests/Feature/Desktop/DesktopFrontendTest.php  # cobertura da tela /orders

shared/
└── version.php                                    # nova versão funcional
```

**Structure Decision**: mantém a separação já existente entre `backend/`
(fonte única de verdade e cálculo) e `frontends/desktop/` (somente
apresentação via API), sem introduzir rota nova nem tabela nova — a feature
é aditiva em cima do schema e dos endpoints já existentes.

## Phase 0 - Research Decisions

- Foto do equipamento: reaproveitar `Equipment::photos()` (`is_principal`) e
  a rota já existente `api.v1.equipments.photos.show` em vez de criar um
  novo endpoint de foto para OS.
- Orçamento vinculado: resolver em lote com uma query ordenada por
  `created_at` agrupada em PHP por `os_id` (sem revisitar o banco por OS).
- Financeiro vinculado: resolver em duas queries agregadas por página —
  uma para os títulos `financeiro` (`os_id IN (...)`, `tipo=receber`) e uma
  para a soma de `financeiro_movimentos` agrupada por `financeiro_id` — em
  vez de chamar `FinanceiroService::movementSummary()` por título (que faz
  2 queries por título e reintroduziria N+1 se chamado em loop).
- Prazo/atraso: cálculo puro em PHP por linha (sem query), reproduzindo a
  mesma regra de cores do legado (`resolvePrazoClass`), mas como um único
  método pequeno no `OrderWorkflowService` em vez de lógica espalhada.
- Resumo curto do equipamento: montado a partir dos joins já existentes
  (`equipment.type`/`brand`/`model`), com fallback para `resumo_tecnico`
  truncado quando o equipamento não tiver catálogo associado (equipamentos
  legados).
- Filtros avançados no desktop: bloco `collapse` do Bootstrap 5 (já
  disponível no projeto), sem nova dependência JS; os `<select>` dentro do
  bloco recebem Select2 automaticamente pelo `DesktopUi.initSelect2()`
  global, mesmo estando inicialmente recolhidos.

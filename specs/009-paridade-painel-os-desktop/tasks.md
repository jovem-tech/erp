# Tasks: Paridade Operacional do Painel de Ordens de Serviço no Desktop

## Phase 1: Setup e governanca

- [X] T001 Atualizar `.specify/feature.json` e criar `specs/009-paridade-painel-os-desktop/` (spec.md, plan.md, tasks.md)
- [X] T002 Atualizar `shared/version.php` para a nova versao funcional (3.1.25)

## Phase 2: Backend central

- [X] T003 Estender `backend/app/Services/Orders/OrderWorkflowService.php`: `baseSummaryQuery()` com novas colunas (datas, valores, telefone do cliente, joins de tipo/marca/modelo, foto principal do equipamento) e novos filtros (`grupo_macro`, `data_abertura_de/ate`, `valor_min/max`) em `paginateForUser()`
- [X] T004 Adicionar em `OrderWorkflowService.php` o calculo de prazo por linha (estado + dias) e o resumo curto do equipamento, sem query extra
- [X] T005 Adicionar em `OrderWorkflowService.php` a resolucao em lote do orcamento mais recente por OS (`Budget::whereIn('os_id', ...)`) e do financeiro vinculado (`Financeiro` + `FinanceiroMovimento` agregados por `financeiro_id`), expostos em `mapSummary()`
- [X] T006 Atualizar `backend/openapi.yaml` com o novo payload de `GET /api/v1/orders`
- [X] T006a Corrigir `backend/app/Services/EquipmentWorkflowService.php::resolvePhotoAccess()`: fallback para `sistema-hml/public/uploads/equipamentos_perfil/` quando a foto principal de equipamento importado do legado nao existe no storage privado novo (achado durante a validacao visual da coluna de foto desta feature; bug pre-existente do modulo 008, corrigido aqui por afetar diretamente a coluna nova)

## Phase 3: Frontend desktop

- [X] T007 Repassar os novos filtros em `frontends/desktop/app/Http/Controllers/OrderController.php` e `frontends/desktop/app/Services/OrderService.php`
- [X] T008 Atualizar `frontends/desktop/resources/views/orders/index.blade.php`: colunas de Foto, Cliente+WhatsApp, Equipamento curto (com tooltip), Datas coloridas, Status+Orcamento, Valor (Total/Recebido/Saldo), reaproveitando `layouts.partials.status-pill`, `layouts.partials.pagination` e `layouts.partials.empty-state`
- [X] T009 Adicionar bloco "Filtros avancados" (`collapse` Bootstrap, `<select class="form-select">` para tecnico/macrofase, inputs de intervalo de data/valor). Refinamento em relacao ao plano original: em vez de expandir/recolher por breakpoint fixo (`>=992px`), o bloco expande automaticamente quando algum filtro avancado ja esta em uso (em qualquer tamanho de tela) e fica recolhido por padrao nos demais casos — mais simples de implementar corretamente com o `collapse` do Bootstrap e reage ao uso real, nao so ao tamanho de tela
- [X] T010 Ajustar `frontends/desktop/public/assets/css/desktop.css`: novas classes pontuais `os-photo-cell`, `os-dates-cell`, `os-status-cell`, `os-value-cell`, `desktop-filter-advanced-toggle/panel`; fotos reaproveitam `equipment-list-photo`/`equipment-list-photo-placeholder` ja existentes

## Phase 4: Testes

- [X] T011 Expandido `backend/tests/Feature/Api/V1/OrderFlowTest.php` (arquivo existente reaproveitado em vez de criar `OrdersTest.php`) cobrindo: novos campos presentes no summary, OS sem foto/orcamento/financeiro (estado neutro), filtros novos (macrofase/datas/valores), e contagem de queries estavel independente da quantidade de OS na pagina (com warm-up de cache para isolar o efeito medido)
- [X] T012 Adicionado `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php::test_orders_index_renders_summary_enrichment_and_advanced_filters` cobrindo a renderizacao da listagem `/os` com os novos campos (foto, WhatsApp, prazo, orcamento, valor) e o bloco de filtros avancados

## Phase 5: Documentacao e revisao final

- [X] T013 Atualizado o contrato da API em `specs/004-os-mobile-flow/contracts/orders-api.md` com o novo payload do summary e os novos filtros
- [X] T014 Criada nota em `documentacao/07-novas-implementacoes/2026-06-26-paridade-painel-os-desktop.md` (inclui a correcao relacionada de foto de equipamento)
- [X] T015 Rodado `php scripts/php/scaffold-release-note.php` (v3.1.25) e `php scripts/php/sync-agent-docs.php`; `historico-de-versoes.md` atualizado manualmente apos o script (o script tem um bug de comparacao de encoding no cabecalho `# Historico de versoes`/`# Histórico de versões` que faz ele inserir a entrada no fim do arquivo em vez do topo — corrigido manualmente, nao reportado como tarefa separada por ser de baixo risco)
- [X] T016 Validacao: render do Blade via `php artisan tinker` com dados reais cobrindo "tudo presente" e "nada vinculado" (sem erro, ambos os estados corretos); validacao visual feita pelo usuario no navegador real em `127.0.0.1:8080/os`, que confirmou a paridade visual com o legado e revelou o bug de foto de equipamento corrigido em T006a. Não foi possível testar os breakpoints reduzidos (768/430/390/360/320) por falta de driver de navegador neste ambiente — recomenda-se conferir manualmente ou via `/run-skill-generator` para criar um driver reutilizavel.

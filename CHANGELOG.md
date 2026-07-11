# Changelog — Sistema ERP Jovem Tech

## v4.1.0.1 — 2026-07-10 22:04
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** ajuste da cor do painel administrativo em tema azul
- **Arquivos:** frontends/desktop/public/assets/css/themes/jovem-tech.css

## v4.1.0.0 — 2026-07-10 20:11
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** correção e ajustes das notificações (sino)
- **Arquivos:** backend/app/Console/Commands/NotifyOrderDeadlines.php,backend/app/Events/NotificationCreated.php,backend/app/Notifications/Channels/MobileInboxChannel.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Notifications/NotificationDispatchService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/channels.php,backend/routes/console.php,documentacao/03-arquitetura-tecnica/notificacoes-sino.md,frontends/desktop/app/Http/Controllers/NotificationController.php,frontends/desktop/app/Services/NotificationService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/routes/web.php

## v4.0.2.0 — 2026-07-10 18:01
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move o botao de notificacoes (sino) do lado direito para o lado esquerdo da barra superior, ficando ao lado do botao de inicio (casa)
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v4.0.1.1 — 2026-07-10 17:54
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Adiciona botao de atalho para o Dashboard (icone de casa) ao lado do toggle do menu lateral, e corrige sobreposicao entre a logo e o botao de expandir/recolher quando a sidebar esta recolhida (agora empilham verticalmente no hover)
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v4.0.1.0 — 2026-07-10 15:30
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste de segurança
- **Arquivos:** .agents/skills/sistema-erp-autenticacao-step-up/SKILL.md,backend/app/Http/Controllers/Api/V1/AuthController.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Web/BudgetPublicController.php,backend/app/Http/Requests/Api/V1/RevealEquipmentPasswordRequest.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/EquipmentWorkflowService.php,backend/config/services.php,backend/openapi.yaml,backend/phpunit.xml,backend/routes/api.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/BroadcastAuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Services/ConfigurationService.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/config/session.php,frontends/desktop/public/assets/js/configurations-integrations.js,frontends/desktop/public/assets/js/equipments-reveal-password-modal.js,frontends/desktop/public/assets/js/orders-list.js,frontends/desktop/resources/views/equipments/_reveal_password_modal.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/routes/web.php

## v4.0.0.0 — 2026-07-10 03:38
- **Tier:** major
- **Autor/Agente:** Codex
- **Descrição:** Hardening de seguranca: remove token do frontend, protege secrets de integracoes, mascara senhas de equipamentos, expira orcamentos publicos e endurece sessao/RBAC
- **Arquivos:** backend/app/Http/Controllers/Api/V1/AuthController.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Web/BudgetPublicController.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/EquipmentWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/BroadcastAuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ConfigurationService.php,frontends/desktop/config/session.php,frontends/desktop/public/assets/js/configurations-integrations.js,frontends/desktop/public/assets/js/orders-list.js,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/routes/web.php

## v3.21.0.0 — 2026-07-10 00:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** correções na tela de login, correções no RBCA, correções e ajustes na baixa da os
- **Arquivos:** .agents/skills/sistema-erp-os-fluxo-fechamento/references/regra-fechamento-os.md,.agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md,backend/app/Console/Commands/BackfillOsEventos.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Controllers/Api/V1/UserController.php,backend/app/Http/Requests/Api/V1/CloseOrderRequest.php,backend/app/Http/Requests/Api/V1/StoreUserRequest.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Http/Requests/Api/V1/UpdateUserRequest.php,backend/app/Models/OrderEvent.php,backend/app/Models/Order.php,backend/app/Notifications/FrontendPasswordResetNotification.php,backend/app/Providers/AppServiceProvider.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Company/CompanyProfileService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderEventService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/bootstrap/app.php,backend/database/migrations/2026_07_09_000001_create_os_eventos_table.php,backend/openapi.yaml,backend/routes/api.php,backend/routes/web.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,backend/tests/Feature/Api/V1/PasswordResetFlowTest.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,documentacao/03-arquitetura-tecnica/eventos-os.md,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Http/Controllers/UserController.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/app/Services/UserService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/_event_timeline.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/users/index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Unit/

## v3.20.0.1 — 2026-07-09 20:14
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Aproxima paineis do login em telas grandes
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.20.0.0 — 2026-07-09 08:22
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** modernizaçao e alinhamento do painel de login
- **Arquivos:** backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Services/Company/CompanyProfileService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/layouts/guest.blade.php,frontends/desktop/routes/web.php

## v3.19.1.2 — 2026-07-09 07:46
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta login para azul institucional e layout mobile enxuto
- **Arquivos:** frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.19.1.1 — 2026-07-09 07:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Moderniza tela de login com branding da assistência técnica
- **Arquivos:** backend/app/Services/Company/CompanyProfileService.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/routes/api.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/layouts/guest.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/routes/web.php

## v3.19.1.0 — 2026-07-09 04:23
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** implantação de botão de detalhes em um lançamanto
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php

## v3.19.0.1 — 2026-07-09 04:19
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Adiciona detalhe operacional dos lancamentos financeiros
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php

## v3.19.0.0 — 2026-07-09 03:56
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** recuperação da base de conhecimento, ajustes na visualização da os
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_09_000001_seed_conhecimento_module.php,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/routes/web.php

## v3.18.2.3 — 2026-07-09 03:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Restaura modulo de conhecimento e implementa checklist de entrada operacional na OS
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/routes/api.php,backend/database/migrations/2026_07_09_000001_seed_conhecimento_module.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/css/desktop.css

## v3.18.2.2 — 2026-07-09 03:24
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** ajusta status e diagnostico na tela de detalhe da os
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v3.18.2.1 — 2026-07-09 02:59
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Detalhe da OS passa a exibir tipo, marca e modelo no card Equipamento, mantendo serie e resumo tecnico como complemento.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v3.18.2.0 — 2026-07-09 02:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste e correção no processo de baixa da os
- **Arquivos:** backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/resources/views/orders/show.blade.php

## v3.18.1.3 — 2026-07-09 02:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Aba Valores da OS passa a exibir forma de pagamento resolvida pelos movimentos financeiros e alerta de peca orcada sem baixa de estoque vinculada.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/show.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v3.18.1.2 — 2026-07-09 01:42
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta status da OS conforme status do orçamento
- **Arquivos:** backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v3.18.1.1 — 2026-07-09 01:29
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Garante link publico copiavel e valor da OS em orcamentos
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v3.18.1.0 — 2026-07-09 01:06
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste de layout de elementos de paginas de listagem
- **Arquivos:** frontends/desktop/app/Http/Controllers/ServicoController.php,frontends/desktop/app/Http/Controllers/StockController.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/clients/index.blade.php,frontends/desktop/resources/views/components/,frontends/desktop/resources/views/equipments/index.blade.php,frontends/desktop/resources/views/estoque/index.blade.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/groups/index.blade.php,frontends/desktop/resources/views/orcamentos/index.blade.php,frontends/desktop/resources/views/servicos/index.blade.php,frontends/desktop/resources/views/suppliers/index.blade.php,frontends/desktop/resources/views/users/index.blade.php

## v3.18.0.0 — 2026-07-09 00:11
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** ajuste e correção de layout de graficos do dashboard
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/dashboard.js,frontends/desktop/resources/views/dashboard/index.blade.php

## v3.17.4.3 — 2026-07-09 00:05
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Protege graficos do dashboard no mobile
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.17.4.2 — 2026-07-09 00:02
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta densidade visual dos graficos do dashboard
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.17.4.1 — 2026-07-08 23:54
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Reorganiza graficos do dashboard
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/index.blade.php,frontends/desktop/public/assets/js/dashboard.js,frontends/desktop/public/assets/css/desktop.css

## v3.17.4.0 — 2026-07-08 22:57
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste no grafico de os entregues reparadas mes de março 2026
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.3.1 — 2026-07-08 22:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige serie mensal de entregas reparadas do dashboard para ignorar atualizacoes de importacao legado
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.3.0 — 2026-07-08 22:00
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajustes no dashboard
- **Arquivos:** frontends/desktop/app/Services/DocumentationService.php,frontends/desktop/resources/views/configurations/system.blade.php

## v3.17.2.1 — 2026-07-08 21:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Alinha KPI de OS abertas do dashboard ao escopo operacional da listagem de OS
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.2.0 — 2026-07-08 21:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** mostrar versionamento na documentaçãodo sistema nas configurações
- **Arquivos:** frontends/desktop/app/Services/DocumentationService.php

## v3.17.1.0 — 2026-07-08 21:20
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige permissao de execucao dos scripts (core.fileMode=false ignorava chmod +x) via git update-index --chmod, e corrige deploy-completo.sh lendo VERSION/CHANGELOG de develop (nao do main antigo) para a mensagem do merge
- **Arquivos:** scripts/bash/atualizar-dev.sh,scripts/bash/deploy-completo.sh,scripts/bash/deploy-producao.sh,scripts/bump-version.sh,scripts/classify-change.sh,scripts/versionar.sh

## v3.17.0.0 — 2026-07-08 21:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona scripts/versionar.sh e scripts/bash/deploy-completo.sh para versionar e publicar (dev->main) sem depender de IA
- **Arquivos:** AGENTS.md,.agents/skills/sistema-erp-deploy-producao/SKILL.md,documentacao/10-deploy/workflow-git-multiambiente.md,scripts/bash/deploy-completo.sh,scripts/versionar.sh,VERSIONING.md

## v3.16.0.1 — 2026-07-08 19:42
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige Baixa sumindo para Irreparavel/Reparo Recusado na listagem (is_encerrada ausente em mapSummary) e evita N+1 em OrderStatus::closureCodes()
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/index.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v3.16.0.0 — 2026-07-08 19:42
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Bloqueia mudanca de status em OS encerrada e adiciona Cancelar baixa com gate de administrador (step-up auth)
- **Arquivos:** backend/app/Models/OrderStatus.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/CancelOrderClosureRequest.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/app/Services/Financeiro/OsMargemService.php,backend/routes/api.php,backend/bootstrap/app.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_cancel_closure_modal.blade.php,frontends/desktop/public/assets/js/orders-cancel-closure-modal.js

## v3.15.2.4 — 2026-07-08 12:32
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige botao Limpar da listagem de OS para remover filtros via rota limpa
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php

## v3.15.2.3 — 2026-07-08 12:27
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Indica filtros ativos e trava recolhimento do painel de filtros da listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.2 — 2026-07-08 11:58
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta altura do badge de resultados e botao Filtros na listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.1 — 2026-07-08 11:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Alinha contador de resultados e botao Filtros ao campo de busca na listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.0 — 2026-07-08 11:46
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS: sincronizacao instantanea Status/Macrofase com Select2 e limpeza sem recarregar pagina
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.15.1.0 — 2026-07-08 11:32
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS: Macrofase movida para filtros principais e sincronizada bidirecionalmente com Status
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.15.0.0 — 2026-07-08 11:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS passam a usar catalogo proprio de status autorizado por os:visualizar, restaurando Select2 de status e macrofase
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/DesktopOrderStatusFlowService.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.14.2.0 — 2026-07-08 10:50
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Listagem inicial de OS passa a ocultar encerramentos canonicos e entregas com cobranca pendente, mantendo filtros explicitos para historico
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/index.blade.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.14.1.0 — 2026-07-07 12:54
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Listagem de OS: botao 'Filtrar' ao lado do campo de busca no cabecalho + correcao do campo de busca que estava fora do <form> (nao era submetido ao filtrar). A busca agora submete o form de filtros via atributo HTML5 form=osFilterPanel, carregando status/itens por pagina/filtros avancados junto, mesmo com o painel recolhido.
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.14.0.0 — 2026-07-07 12:45
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Overhaul do modal 'Alterar status da OS': card de equipamento passa a exibir tipo+marca+modelo; switch 'Notificar o cliente' movido para o rodape do modal; nova aba 'Procedimentos' em 2 colunas — registro de procedimentos executados com historico datado por tecnico (nova tabela os_procedimentos_historico) + campos de diagnostico e solucao salvos junto com o status; botao 'Salvar status' sempre habilitado (permite salvar diagnostico/solucao sem trocar o status, sem gerar historico/notificacao espuria); notificacao WhatsApp ao cliente na mudanca de status quando o switch esta ativo, com fallback de envio direto pela Evolution API quando a Central de Atendimento (banco chat) esta indisponivel; corrigido fundo transparente do modal (faltava a classe modal-shell). Novo endpoint POST /api/v1/orders/{order}/procedures; migration aditiva os_procedimentos_historico.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpdateOrderStatusRequest.php,backend/app/Http/Requests/Api/V1/StoreOrderProcedureRequest.php,backend/app/Models/Order.php,backend/app/Models/OrderProcedureHistory.php,backend/database/migrations/2026_07_07_000001_create_os_procedimentos_historico_table.php,backend/routes/api.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/_status_modal.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/js/orders-status-modal.js,frontends/desktop/public/assets/css/desktop.css

## v3.13.1.0 — 2026-07-06 23:59
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige 'Ocorreu um erro inesperado' ao salvar orcamento (INSERT falhava com SQLSTATE 42S22 Unknown column desconto_tipo): a migration 2026_07_03_000001_add_adjustment_modes_to_orcamentos_tables estava marcada como executada no laravel_migrations, mas as 4 colunas de ajuste percentual (desconto_tipo, desconto_percentual, acrescimo_tipo, acrescimo_percentual) nunca existiram de fato nas tabelas orcamentos e orcamento_itens deste banco (drift de schema, mesma classe de problema ja documentada no deploy Contabo). Corrigido com ALTER aditivo direto no banco de dev (192.168.1.100), sem alterar nenhum arquivo de codigo
- **Arquivos:** (nenhum arquivo de codigo — correcao aplicada diretamente no banco sistema_hml de 192.168.1.100)

## v3.13.0.0 — 2026-07-06 23:44
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cadastro rapido de item no orcamento: campo 'Tipo de equipamento' virou Select2 com tags (escolher existente ou digitar novo), reaproveitando o catalogo ja usado em Servicos/Estoque; corrigido bug generico de dropdown do Bootstrap dentro de tabela responsiva (abria para cima sobre a propria linha e ficava cortado em tabelas curtas) — menu agora e movido para o body enquanto aberto, corrigindo Acoes em todas as listagens (OS, equipamentos, financeiro etc.)
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/quick-item-modal.blade.php

## v3.12.0.1 — 2026-07-06 12:04
- **Tier:** hotfix
- **Autor/Agente:** Claude
- **Descrição:** Documenta na armadilha do runbook Contabo o erro 'untracked working tree files would be overwritten by merge' no passo [2/5] do deploy-producao.sh (arquivos nao versionados na VPS colidindo com o commit remoto) e como resolver movendo-os para backup antes de repetir o script
- **Arquivos:** documentacao/10-deploy/deploy-producao-contabo-vps.md

## v3.12.0.0 — 2026-07-06 11:12
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Fluxo de caixa: coluna 'Entrada projetada' (dia em que o dinheiro efetivamente cai na conta para vendas em cartão, podendo cruzar de mês) e coluna 'Saldo líquido em conta' (acumulado já líquido de taxa, corrigindo também um bug pré-existente em que o saldo inicial só somava o dia anterior ao período em vez do histórico completo); botão de Detalhes por dia com modal de lançamentos (pago/recebido e previsto para cair no dia) e submodal de detalhes do cartão (operadora, bandeira, modalidade, parcelas, taxa, prazo); correção de um bug do Bootstrap 5 (modal empilhado perde o scroll-lock do modal externo ao fechar o interno)
- **Arquivos:** backend/app/Models/Financeiro.php,backend/app/Models/FinanceiroMovimento.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/tests/Feature/Api/V1/FinanceiroReportTest.php,frontends/desktop/resources/views/financeiro/relatorios/fluxo-caixa.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js

## v3.11.0.0 — 2026-07-06 11:12
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Cancelamento de lançamento financeiro: botão Cancelar no dropdown de Ações (estorna movimentos do título e, se houver, da despesa de Taxa de cartão vinculada), exclusão de títulos cancelados do DRE por competência, e taxa da operadora passa a ser registrada como despesa separada (Despesas Operacionais / Taxas e impostos) no dia do pagamento, em vez de ficar invisível no fluxo de caixa e no DRE
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/routes/api.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/lang/pt_BR/validation.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v3.10.0.0 — 2026-07-05 23:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Baixa de lancamento financeiro: botoes de valor total/parcial e forma de pagamento com campos de cartao (operadora/bandeira/modalidade/parcelas) e estimativa de taxa, no mesmo padrao da baixa da OS; backend passa a expor valor_aberto por lancamento e o catalogo de cartao, e registra FinanceiroMovimentoCartao quando a baixa e' em cartao. Corrigido tambem um bug critico pre-existente (ja presente antes desta entrega): o modal de baixa era um `<div>` filho direto de `<tbody>` (invalido em HTML), o que faz o navegador aplicar "foster parenting" e esvaziar o `<form>` — o `Confirmar baixa` submetia o formulario sem nenhum campo. Os modais agora sao renderizados num loop separado, fora de `<table>`/`<tbody>`
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCatalogController.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Http/Requests/Api/V1/RegisterFinanceiroMovementRequest.php,backend/app/Services/Financeiro/FinanceiroService.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/public/assets/js/financeiro-pay.js

## v3.9.1.0 — 2026-07-05 23:13
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige TypeError 'destroy is not a function' no Select2 da tela Financeiro (Cliente/Categoria): atributo data-select2="false" colidia com a chave interna do plugin e foi trocado por data-native-select="true"
- **Arquivos:** frontends/desktop/resources/views/financeiro/form.blade.php

## v3.9.0.0 — 2026-07-05 20:30
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Lançamentos financeiros avulsos sem OS, opcionais por cliente, com histórico protegido no cliente e bloqueio no fluxo da OS
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertFinanceiroRequest.php,backend/app/Models/Financeiro.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/database/migrations/2026_07_05_190000_add_avulso_to_financeiro_table.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/resources/views/financeiro/index.blade.php,specs/020-lancamentos-avulsos-financeiro-cliente,documentacao/07-novas-implementacoes/2026-07-05-lancamentos-avulsos-financeiro-cliente.md

## v3.8.0.0 — 2026-07-05 18:04
- **Tier:** minor
- **Autor/Agente:** jovem-tech
- **Descrição:** Sistema de desenvolvimento e deploy profissional: repositorio GitHub (jovem-tech/erp) como fonte unica da verdade, branches develop (dev 192.168.1.100) / main (producao VPS Contabo), deploy keys dedicadas por servidor, scripts de deploy git-based, XAMPP definitivamente descontinuado. AGENTS.md ganha mandato LEIA ISTO PRIMEIRO para qualquer IA.
- **Arquivos:** AGENTS.md,README.md,documentacao/10-deploy/workflow-git-multiambiente.md,scripts/bash/deploy-producao.sh,scripts/bash/atualizar-dev.sh

## v3.7.3.1 — 2026-07-05 16:37
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Documentacao em dia com a producao: notas do deploy Contabo (subdominios, copia de dados reais, fixes de schema/broadcasting/DNS) e da padronizacao de cliente; novo runbook Contabo; historico 3.7.1-3.7.3; AGENTS e skill de deploy com topologia atual

## v3.7.3.0 — 2026-07-05 16:24
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Padroniza nome (Title Case pt-BR, so pessoa fisica) e telefone (mascara (DDD) numero) no cadastro de cliente do desktop (rapido e completo): JS para UX ao vivo + ClientController autoritativo
- **Arquivos:** frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/public/assets/js/clients-form.js

## v3.7.2.0 — 2026-07-05 06:08
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige broadcasting/auth 403 em producao: channels.php passa a ser carregado com require (nao loadRoutesFrom) para sobreviver ao route:cache, registrando os canais de broadcasting (tempo real da Central de Atendimento e OS ao vivo)
- **Arquivos:** backend/app/Providers/AppServiceProvider.php

## v3.7.1.0 — 2026-07-04 22:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige OrderController::jsonFailure ausente (500 na busca de clientes/Select2 da Nova OS) e reconcilia schema de clientes/usuarios/financeiro com colunas que o ERP espera (referencia etc.), aplicado em dev e VPS
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrderController.php

## v3.7.0.0 — 2026-07-04 17:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Ambiente de desenvolvimento oficial migrado para Linux (BANCADA-02); nova topologia de portas (desktop 443, backend 8443); correcoes de auditoria: pools FPM dedicados, upload 25M, MySQL/Redis tuning, UFW+fail2ban, SSH hardening, TLS com SAN, backup diario, cookie Secure; ApiClient Guzzle 8-ready; raiz backend sem welcome page
- **Arquivos:** documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md,backend/routes/web.php,frontends/desktop/app/Services/ApiClient.php,AGENTS.md

## v3.6.0.1 — 2026-07-04 09:20
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Rodape do desktop passa a exibir a versao de 4 posicoes lida do arquivo VERSION (fonte unica), com fallback para shared/version.php
- **Arquivos:** frontends/desktop/app/Providers/DesktopAppServiceProvider.php

## v3.6.0.0 — 2026-07-04 08:35
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Deploy de producao em LAN Ubuntu documentado (runbook 10-deploy), aba Documentacao em Configuracoes>Sistema no desktop, correcao ForceHttps com BinaryFileResponse, adocao do protocolo de versionamento 4 posicoes
- **Arquivos:** documentacao/10-deploy/deploy-producao-lan-ubuntu.md,frontends/desktop/app/Services/DocumentationService.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/configurations/system.blade.php,backend/app/Http/Middleware/ForceHttps.php,VERSIONING.md,VERSION,CHANGELOG.md

## v3.5.3.0 — Baseline
- **Tier:** —
- **Autor/Agente:** Otávio
- **Descrição:** Ponto de partida do novo protocolo de versionamento de 4 posições. Versão anterior era V3.5.3 (3 posições); a partir daqui todo commit deve gerar uma entrada aqui.

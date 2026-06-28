# Tasks: Cadastro Completo de Equipamentos no Desktop

## Phase 1: Setup e governanca

- [X] T001 Atualizar `.specify/feature.json`, `.specify/memory/constitution.md` e criar `specs/008-cadastro-equipamentos-desktop/`
- [X] T002 Atualizar `shared/version.php` para a nova versao funcional do desktop

## Phase 2: Backend central

- [X] T003 Expandir `backend/app/Services/EquipmentWorkflowService.php` e `backend/app/Http/Controllers/Api/V1/EquipmentController.php` para cadastro completo, quick-adds, sugestoes, fotos, coletor local legado e pareamento remoto de apoio
- [X] T004 Ajustar `backend/app/Http/Requests/Api/V1/StoreEquipmentRequest.php`, `backend/routes/api.php`, `backend/config/services.php` e `backend/.env.example`
- [X] T005 Validar `backend/app/Http/Controllers/Api/V1/EquipmentCollectorController.php`, `backend/app/Models/EquipmentCollectorPairing.php` e `backend/database/migrations/2026_06_24_010000_create_equipment_collector_pairings_table.php`
- [X] T023 Abrir `PUT/PATCH /api/v1/equipments/{equipment}` com validacao de fotos existentes e RBAC auxiliar coerente ao editar

## Phase 3: Frontend desktop

- [X] T006 Estender `frontends/desktop/app/Services/ApiClient.php` e `frontends/desktop/app/Services/EquipmentService.php`
- [X] T007 Implementar criacao em `frontends/desktop/app/Http/Controllers/EquipmentController.php` e `frontends/desktop/routes/web.php`
- [X] T008 Criar `frontends/desktop/resources/views/equipments/create.blade.php` e ajuda contextual da tela
- [X] T009 Implementar interacoes locais em `frontends/desktop/public/assets/js/equipments-create.js`, incluindo leitura/execucao do coletor local e fallback seguro
- [X] T010 Ajustar `frontends/desktop/public/assets/css/desktop.css` e integrar os novos assets em `frontends/desktop/resources/views/layouts/app.blade.php`
- [X] T011 Ajustar a listagem em `frontends/desktop/resources/views/equipments/index.blade.php` para expor a entrada de novo cadastro
- [X] T021 Expor a acao `Editar` na listagem e no detalhe com gate visual por `equipamentos:editar`
- [X] T022 Reutilizar `frontends/desktop/resources/views/equipments/create.blade.php` e `frontends/desktop/public/assets/js/equipments-create.js` para modo de edicao

## Phase 4: Testes

- [X] T012 Expandir `backend/tests/Concerns/BuildsLegacyErpSchema.php` para suportar catalogos, fotos e pareamentos
- [X] T013 Criar cobertura em `backend/tests/Feature/Api/V1/EquipmentCreationTest.php`, incluindo snapshot local e fallback quando o executavel nao estiver disponivel
- [X] T014 Expandir `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php` para o fluxo de novo equipamento
- [X] T019 Criar cobertura de update em `backend/tests/Feature/Api/V1/EquipmentCreationTest.php`, incluindo sincronizacao de fotos existentes, remocao de arquivos e troca da foto principal
- [X] T020 Expandir `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php` para o fluxo de edicao e para o submit multipart com fotos retidas

## Phase 5: Documentacao e revisao final

- [X] T015 Atualizar `documentacao/03-arquitetura-tecnica/frontend-desktop-laravel.md`
- [X] T016 Atualizar `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md` e `backend/openapi.yaml`
- [X] T017 Criar nota em `documentacao/07-novas-implementacoes/` e atualizar `frontends/desktop/README.md`
- [X] T018 Registrar a analise final de consistencia e seguranca em `specs/008-cadastro-equipamentos-desktop/analysis.md`
- [X] T024 Atualizar versionamento compartilhado e nota release da edicao operacional de equipamentos

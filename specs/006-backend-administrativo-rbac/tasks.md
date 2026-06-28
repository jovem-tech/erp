# Tasks: Backend administrativo e RBAC central

## Phase 1: Inspeção e base do RBAC

Story goal: preparar a fundação do RBAC no backend central sem assumir schema e sem criar migrations legadas indevidas.

Independent test criteria:
- o schema legado de RBAC foi inspecionado;
- os models do RBAC refletem o banco real;
- nenhuma migration legada nova foi criada.

- [X] T001 [P] Inspecionar o schema real com `SHOW CREATE TABLE` e `SHOW TABLES LIKE` antes da modelagem em `backend/.env` e no banco compartilhado
- [X] T002 Criar os models `Group`, `Module`, `Permission` e `GroupPermission` em `backend/app/Models/`
- [X] T003 Ajustar `User` e a fundação do controller para suportar RBAC em `backend/app/Models/User.php` e `backend/app/Http/Controllers/Controller.php`

## Phase 2: US1 - Autorização central

Story goal: resolver permissões efetivas por `módulo:ação`, com Gate, middleware, cache e fallback legado auditado.

Independent test criteria:
- o usuário autenticado recebe seus acessos efetivos;
- o fallback admin gera warning;
- o cache por usuário é reutilizado e pode ser invalidado.

- [X] T004 [P] Criar a infraestrutura de teste com schema legado em `backend/tests/Concerns/BuildsLegacyErpSchema.php`
- [X] T005 [US1] Escrever a cobertura inicial de auth e RBAC em `backend/tests/Feature/Api/V1/AuthFlowTest.php` e `backend/tests/Feature/Api/V1/RbacAdministrationTest.php`
- [X] T006 [US1] Implementar `RbacAuthorizationService` com cache, fallback auditado e catálogos em `backend/app/Services/Auth/RbacAuthorizationService.php`
- [X] T007 [US1] Registrar Gates e middleware `rbac` em `backend/app/Providers/AppServiceProvider.php` e `backend/bootstrap/app.php`
- [X] T008 [US1] Enriquecer `GET /api/v1/auth/me` em `backend/app/Http/Controllers/Api/V1/AuthController.php`

## Phase 3: US2 - OS administrativa

Story goal: preservar o fluxo mobile do técnico e ampliar a API para listagem geral, criação e edição administrativa de OS.

Independent test criteria:
- técnicos continuam restritos às próprias OS;
- usuários administrativos com permissão veem listagem geral;
- criação e edição de OS funcionam com RBAC e validação.

- [X] T009 [P] Atualizar as regras de request de OS em `backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php` e `backend/app/Http/Requests/Api/V1/UpdateOrderStatusRequest.php`
- [X] T010 [US2] Ampliar `OrderWorkflowService` para filtros administrativos, create e update em `backend/app/Services/Orders/OrderWorkflowService.php`
- [X] T011 [US2] Recriar `OrderController` e ampliar as rotas em `backend/app/Http/Controllers/Api/V1/OrderController.php` e `backend/routes/api.php`
- [X] T012 [US2] Cobrir o fluxo administrativo e o fluxo mobile de OS em `backend/tests/Feature/Api/V1/OrderFlowTest.php`

## Phase 4: US3 - Catálogos operacionais

Story goal: disponibilizar leitura administrativa de clientes, equipamentos, módulos e permissões para o futuro frontend desktop.

Independent test criteria:
- clientes e equipamentos respondem com busca e paginação;
- módulos e permissões respondem em catálogo;
- o envelope padrão é mantido.

- [X] T013 [P] Implementar leitura de clientes em `backend/app/Http/Controllers/Api/V1/ClientController.php`
- [X] T014 [P] Implementar leitura de equipamentos em `backend/app/Http/Controllers/Api/V1/EquipmentController.php`
- [X] T015 [P] Implementar catálogos de módulos e permissões em `backend/app/Http/Controllers/Api/V1/CatalogController.php`
- [X] T016 [US3] Cobrir clientes, equipamentos e catálogos em `backend/tests/Feature/Api/V1/RbacAdministrationTest.php`

## Phase 5: US4 - Usuários e grupos

Story goal: permitir gestão administrativa de usuários, grupos e matriz de permissões com bloqueio de grupos de sistema.

Independent test criteria:
- usuários podem ser criados, editados e ativados/desativados;
- grupos podem ser lidos, criados, editados e excluídos quando não forem de sistema;
- grupos de sistema rejeitam alteração;
- alteração de matriz invalida cache dos usuários afetados.

- [X] T017 [P] Criar requests administrativos em `backend/app/Http/Requests/Api/V1/StoreUserRequest.php`, `UpdateUserRequest.php`, `UpdateUserActiveRequest.php`, `StoreGroupRequest.php`, `UpdateGroupRequest.php` e `UpdateGroupPermissionsRequest.php`
- [X] T018 [US4] Implementar `UserController` com invalidação de cache em `backend/app/Http/Controllers/Api/V1/UserController.php`
- [X] T019 [US4] Implementar `GroupController` com imutabilidade de grupos de sistema e atualização da matriz em `backend/app/Http/Controllers/Api/V1/GroupController.php`
- [X] T020 [US4] Cobrir usuários, grupos e cache em `backend/tests/Feature/Api/V1/RbacAdministrationTest.php`

## Phase 6: Consistência, segurança e documentação

Story goal: fechar a fase com validação do backend inteiro e documentação suficiente para onboarding e continuidade da arquitetura.

Independent test criteria:
- `php artisan test` fica verde;
- a documentação descreve contratos, fronteiras e segurança;
- os artefatos da fase ficam rastreáveis em `specs/006-backend-administrativo-rbac/`.

- [X] T021 [P] Executar a suíte completa do backend com `php artisan test` em `backend/`
- [X] T022 [P] Criar os artefatos equivalentes do Spec Kit em `specs/006-backend-administrativo-rbac/`
- [X] T023 Atualizar a documentação técnica e de arquitetura em `README.md`, `documentacao/README.md`, `documentacao/03-arquitetura-tecnica/README.md`, `documentacao/03-arquitetura-tecnica/backend-central-minimo.md`, `documentacao/03-arquitetura-tecnica/ordens-mobile.md` e `documentacao/03-arquitetura-tecnica/backend-administrativo-rbac.md`
- [X] T024 Criar a nota de implementação da fase em `documentacao/07-novas-implementacoes/2026-06-22-fase-6-backend-administrativo-rbac.md`

## Dependências

- A Phase 1 precisa terminar antes das fases seguintes.
- A Phase 2 é pré-requisito das fases 3, 4 e 5.
- A Phase 3 preserva o mobile e deve estar estável antes do frontend desktop.
- A Phase 5 depende da Phase 2 e da base de rotas concluída.
- A Phase 6 fecha a fase após testes e documentação.

## Execução Paralela

- T001 e T002 podem ocorrer em paralelo.
- T013, T014 e T015 podem ocorrer em paralelo depois da base RBAC.
- T021 e T022 podem acontecer em paralelo ao fechamento documental.

## Estratégia de MVP

O MVP desta fase foi concluir a autorização central e manter o fluxo mobile intacto. A expansão administrativa de OS, usuários e grupos fecha a base para o próximo passo: o frontend desktop Laravel consumir a mesma API.

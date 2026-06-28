# Tasks: Fluxo de OS Mobile

## Phase 1: Backend

- [X] T001 [P] Criar os modelos `Order`, `OrderStatus` e `OrderStatusHistory` em `backend/app/Models/`
- [X] T002 [P] Criar o `OrderWorkflowService` em `backend/app/Services/Orders/`
- [X] T003 [P] Criar o `UpdateOrderStatusRequest` em `backend/app/Http/Requests/Api/V1/`
- [X] T004 [P] Criar o `OrderController` em `backend/app/Http/Controllers/Api/V1/`
- [X] T005 Expor `GET /api/v1/orders` e `PATCH /api/v1/orders/{order}/status` em `backend/routes/api.php`

## Phase 2: Regras de Negócio

- [X] T006 Garantir que a listagem use o técnico autenticado como escopo padrão
- [X] T007 Garantir que a atualização de status sincronize `status` e `estado_fluxo`
- [X] T008 Garantir que o histórico de status seja gravado em `os_status_historico`
- [X] T009 Garantir retorno `403` quando a OS não pertencer ao técnico autenticado
- [X] T010 Garantir validação de status baseada no catálogo ativo do banco

## Phase 3: Validação

- [X] T011 Criar a cobertura de testes em `backend/tests/Feature/Api/V1/OrderFlowTest.php`
- [X] T012 Validar a implementação com `php artisan test --filter=OrderFlowTest`
- [X] T013 Validar o fluxo de auth existente com `php artisan test --filter=AuthFlowTest`
- [X] T014 Fazer smoke seguro no banco real com rollback para confirmar a integração

## Phase 4: Documentação

- [X] T015 Atualizar `README.md` com a fase 4 concluída
- [X] T016 Atualizar `documentacao/README.md` com a leitura recomendada da fase 4
- [X] T017 Atualizar `documentacao/03-arquitetura-tecnica/README.md` com o novo fluxo
- [X] T018 Criar `documentacao/03-arquitetura-tecnica/ordens-mobile.md`
- [X] T019 Criar a documentação de especificação em `specs/004-os-mobile-flow/`

## Phase 5: Detalhe e anexos da OS

- [X] T020 Expor `GET /api/v1/orders/{order}` com cliente, equipamento, histórico recente e URLs de anexos
- [X] T021 Expor `GET /api/v1/orders/{order}/photos/{photo}` e `GET /api/v1/orders/{order}/documents/{document}` com acesso controlado
- [X] T022 Adicionar o disco/raiz configurável para leitura segura dos arquivos do legado
- [X] T023 Ampliar a cobertura de testes do fluxo de OS com detalhe, 403 e anexos
- [X] T024 Atualizar os contratos e a documentação técnica com o novo detalhe da OS

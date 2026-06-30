# Tasks: Nova OS em modo wizard no desktop

## Phase 1: Setup e governanca

- [ ] T001 Atualizar `.specify/feature.json` para `specs/015-nova-os-wizard-desktop`
- [ ] T002 Criar nota versionada e preparar o tracking da entrega

## Phase 2: Backend central

- [ ] T003 Estender `backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php` para validar `fotos[]` como upload opcional na criação da OS
- [ ] T004 Atualizar `backend/app/Http/Controllers/Api/V1/OrderController.php` para repassar arquivos anexados ao service
- [ ] T005 Implementar em `backend/app/Services/Orders/OrderWorkflowService.php` o armazenamento privado das fotos da OS e a leitura correta no detalhe
- [ ] T006 Revisar `backend/openapi.yaml` para documentar multipart e fotos na criação da OS
- [ ] T007 Cobrir o fluxo no `backend/tests/Feature/Api/V1/OrderFlowTest.php` ou em teste dedicado

## Phase 3: Frontend desktop

- [ ] T008 Atualizar `frontends/desktop/app/Http/Controllers/OrderController.php` para enviar o contexto selecionado e os arquivos para o backend central
- [ ] T009 Atualizar `frontends/desktop/app/Services/OrderService.php` para suportar `postMultipart()` na criação de OS com fotos
- [ ] T010 Refatorar `frontends/desktop/resources/views/orders/create.blade.php` para o layout em duas colunas com tabs e resumo lateral
- [ ] T011 Evoluir `frontends/desktop/public/assets/js/orders-create.js` para tabs, preview do resumo e preview das fotos
- [ ] T012 Ajustar `frontends/desktop/public/assets/css/desktop.css` para o novo wizard e os estados responsivos

## Phase 4: Testes e validação

- [ ] T013 Criar/ajustar teste desktop da nova criação em `frontends/desktop/tests/Feature/Desktop/`
- [ ] T014 Executar testes do backend e desktop relevantes

## Phase 5: Documentação e versionamento

- [ ] T015 Atualizar a documentação técnica afetada e criar nota de implementação em `documentacao/07-novas-implementacoes/`
- [ ] T016 Sincronizar o contexto vivo com `php ./scripts/php/sync-agent-docs.php`
- [ ] T017 Atualizar `shared/version.php` e registrar a entrega no histórico de versões

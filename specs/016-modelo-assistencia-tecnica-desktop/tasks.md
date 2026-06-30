# Tasks: Modelo Ideal da Assistência Técnica no Desktop

## Phase 1: Setup e governança

- [ ] T001 Atualizar `.specify/feature.json` para `specs/016-modelo-assistencia-tecnica-desktop`
- [ ] T002 Criar nota versionada e registrar o novo modelo de fluxo

## Phase 2: Frontend desktop

- [ ] T003 Criar `frontends/desktop/app/Http/Controllers/AssistanceModelController.php`
- [ ] T004 Criar `frontends/desktop/resources/views/knowledge/assistance-model/index.blade.php`
- [ ] T005 Registrar rota em `frontends/desktop/routes/web.php`
- [ ] T006 Adicionar o item no menu de `Gestão de Conhecimento` em `frontends/desktop/app/Support/DesktopNavigation.php`

## Phase 3: Testes e validação

- [ ] T007 Adicionar cobertura funcional em `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`
- [ ] T008 Executar os testes relevantes do desktop

## Phase 4: Documentação e versionamento

- [ ] T009 Atualizar a documentação técnica afetada
- [ ] T010 Sincronizar o contexto vivo com `php ./scripts/php/sync-agent-docs.php`
- [ ] T011 Atualizar `shared/version.php` e o histórico de versões

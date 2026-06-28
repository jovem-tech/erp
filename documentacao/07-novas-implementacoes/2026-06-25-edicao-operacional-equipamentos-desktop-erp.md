# Edicao operacional de equipamentos no desktop ERP

## Contexto

- versao: `3.1.15`
- data: `2026-06-25`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- a listagem e o detalhe de equipamentos passaram a expor a acao `Editar` quando o usuario possui `equipamentos:editar`
- o desktop reutiliza o mesmo layout e a mesma estrutura de `/equipamentos/novo` em `/equipamentos/{id}/editar`, sem manter um segundo formulario divergente
- o backend central passou a aceitar `PUT/PATCH /api/v1/equipments/{equipment}` com payload multipart para atualizar dados operacionais e a galeria do equipamento
- a aba `Fotos` agora combina fotos existentes e novas no mesmo preview, com troca da principal, remocao controlada e sincronizacao por ids antes do submit
- a atualizacao preserva storage privado, remove arquivos descartados e exige que o estado final continue com 1 a 4 fotos
- rotas auxiliares de formulario, sugestoes, coletor e foto privada passaram a aceitar o contexto de edicao sem alargar os quick-adds de catalogo, que continuam presos a permissao de criacao

## Impactos

- contratos atualizados: `specs/008-cadastro-equipamentos-desktop/spec.md`, `specs/008-cadastro-equipamentos-desktop/plan.md`, `specs/008-cadastro-equipamentos-desktop/tasks.md`, `backend/openapi.yaml`, `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`
- modulos alterados: `backend` (request de update, controller e sincronizacao de fotos) e `frontends/desktop` (rotas, controller, service, Blade, JS e RBAC visual)
- banco: sem nova migration; reaproveita `equipamentos_fotos` e o storage privado existente
- deploy: compativel com Ubuntu VPS; sem dependencia de path local ou acesso direto do frontend ao banco
- seguranca: quick-add de cliente, marca e modelo continua condicionado as permissoes corretas; leitura de fotos segue autenticada e same-origin no desktop
- integridade: a foto principal final permanece unica, e ids de fotos antigas sao validados contra o proprio equipamento antes de qualquer remocao

## Validacao

- `php -l backend/app/Http/Requests/Api/V1/UpdateEquipmentRequest.php`
- `php -l backend/app/Http/Controllers/Api/V1/EquipmentController.php`
- `php -l backend/app/Services/EquipmentWorkflowService.php`
- `php -l frontends/desktop/app/Http/Controllers/EquipmentController.php`
- `php -l frontends/desktop/app/Http/Middleware/EnsureRoutePermission.php`
- `php -l frontends/desktop/app/Services/EquipmentService.php`
- `node --check frontends/desktop/public/assets/js/equipments-create.js`
- `php artisan test tests/Feature/Api/V1/EquipmentCreationTest.php` em `backend/`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=equipment` em `frontends/desktop/`

## Nota para futuras IAs

Se houver regressao em `Editar equipamento`, revisar primeiro a reutilizacao entre `create.blade.php` e `equipments-create.js`. O ponto critico nao e visual: e a sincronizacao entre fotos existentes, novas e permissao efetiva de edicao no backend central.

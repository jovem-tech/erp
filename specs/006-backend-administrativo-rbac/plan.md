# Plan: Backend administrativo e RBAC central

## Contexto Técnico

- Backend central: Laravel 13.x em `backend/`
- Banco compartilhado: `sistema_hml`
- Auth: Sanctum com Bearer token
- API: `/api/v1`
- Frontend consumidor imediato: PWA mobile já ativo
- Próximo consumidor planejado: `frontends/desktop/`
- Tabelas legadas de RBAC: `grupos`, `modulos`, `permissoes`, `grupo_permissoes`
- Tabela de identidade: `usuarios`

## Decisões Fixas

- O backend continua API-only.
- O RBAC legado é consumido diretamente a partir do schema real.
- Nenhuma migration é criada para tabelas legadas desta fase.
- O fallback `perfil = admin` existe apenas como ponte controlada e auditada.
- O cache de permissões usa a chave `rbac_user_{id}` com TTL de 5 minutos.
- A invalidação do cache é obrigatória em alteração de grupo, permissões de grupo e vínculo de grupo em usuário.
- O `auth/me` retorna apenas os acessos efetivos do usuário autenticado, não a matriz completa do sistema.
- OS mobile continuam preservadas; a camada administrativa amplia, não substitui, o fluxo entregue.

## Sequência de Execução

1. Inspecionar o schema real do RBAC e validar a política de migrations.
2. Modelar RBAC legado no Laravel e escrever testes primeiro para autorização.
3. Registrar Gates e middleware de autorização por `módulo:ação`.
4. Enriquecer `auth/me`.
5. Expandir OS administrativas.
6. Expor clientes e equipamentos em leitura.
7. Expor usuários, grupos, módulos, permissões e matriz do grupo.
8. Fechar testes, consistência e documentação.

## Estrutura Entregue

### Backend

- `app/Models/Group.php`
- `app/Models/Module.php`
- `app/Models/Permission.php`
- `app/Models/GroupPermission.php`
- `app/Services/Auth/RbacAuthorizationService.php`
- `app/Http/Middleware/AuthorizeModuleAction.php`
- `app/Http/Controllers/Api/V1/AuthController.php`
- `app/Http/Controllers/Api/V1/OrderController.php`
- `app/Http/Controllers/Api/V1/ClientController.php`
- `app/Http/Controllers/Api/V1/EquipmentController.php`
- `app/Http/Controllers/Api/V1/UserController.php`
- `app/Http/Controllers/Api/V1/GroupController.php`
- `app/Http/Controllers/Api/V1/CatalogController.php`
- `app/Http/Requests/Api/V1/*`
- `app/Services/Orders/OrderWorkflowService.php`
- `routes/api.php`

### Testes

- `tests/Concerns/BuildsLegacyErpSchema.php`
- `tests/Feature/Api/V1/AuthFlowTest.php`
- `tests/Feature/Api/V1/OrderFlowTest.php`
- `tests/Feature/Api/V1/RbacAdministrationTest.php`

### Documentação

- `README.md`
- `documentacao/README.md`
- `documentacao/03-arquitetura-tecnica/README.md`
- `documentacao/03-arquitetura-tecnica/backend-central-minimo.md`
- `documentacao/03-arquitetura-tecnica/ordens-mobile.md`
- `documentacao/03-arquitetura-tecnica/backend-administrativo-rbac.md`
- `documentacao/07-novas-implementacoes/2026-06-22-fase-6-backend-administrativo-rbac.md`

## Riscos e Mitigações

- **Risco**: divergir do schema legado real.
  - **Mitigação**: inspeção explícita com `SHOW CREATE TABLE` antes da modelagem.
- **Risco**: cache de permissões manter acesso obsoleto.
  - **Mitigação**: invalidar por usuário e por grupo em toda mudança relevante.
- **Risco**: fallback admin virar bypass permanente.
  - **Mitigação**: logar `warning`, documentar e manter apenas quando `grupo_id` for nulo.
- **Risco**: quebrar o mobile ao ampliar OS.
  - **Mitigação**: preservar comportamento do técnico e manter testes do fluxo mobile.
- **Risco**: desktop futuro acoplar direto no banco.
  - **Mitigação**: documentar a fronteira `frontends/* -> backend -> banco`.

## Validação

- `php artisan test --filter=AuthFlowTest`
- `php artisan test --filter=OrderFlowTest`
- `php artisan test --filter=RbacAdministrationTest`
- `php artisan test`

## Critério de Saída

Esta fase só é considerada concluída quando o backend expõe a base administrativa e o RBAC central com testes verdes, documentação atualizada e sem violar a política de banco compartilhado e migrations do projeto.

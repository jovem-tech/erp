# Backend Administrativo e RBAC Central

## Objetivo

Esta fase prepara o backend central para atender o canal desktop e futuros clientes usando a mesma API e o banco dedicado `sistema_hml`, sem acesso direto ao banco por parte dos frontends.

## Fronteira arquitetural

- `backend/` continua **API-only**
- `backend/` não renderiza Blade
- `backend/` não gerencia sessão de frontend
- `frontends/desktop/` já consome esta API como cliente Laravel separado
- mobile, desktop e futuros canais consomem o mesmo backend central

## RBAC legado como fonte de verdade

O backend Laravel passa a consumir diretamente:

- `usuarios.grupo_id`
- `grupos`
- `modulos`
- `permissoes`
- `grupo_permissoes`

### Política desta fase

- nenhuma migration foi criada para essas tabelas legadas;
- o schema foi inspecionado antes da modelagem no banco dedicado;
- o fallback `perfil = admin` foi mantido apenas para usuários sem `grupo_id`;
- o fallback gera `warning` para auditoria e futura remoção.

## Resolução de autorização

### Serviço central

`App\Services\Auth\RbacAuthorizationService`

Responsabilidades:

- resolver permissões efetivas por `módulo:ação`;
- expor catálogos de módulos e permissões;
- aplicar fallback legado controlado;
- invalidar cache quando o acesso mudar.

### Gate

O `AppServiceProvider` registra `Gate::define('modulo:acao', ...)` para as combinações ativas de módulo e permissão.

### Middleware

`AuthorizeModuleAction` foi preparado para autorização por `modulo:acao`, mantendo compatibilidade com uso de `authorize()` nos controllers.

## Cache de permissões

- chave: `rbac_user_{id}`
- TTL: 5 minutos
- fonte do cache: permissões efetivas derivadas do banco

### Invalidação

O cache é invalidado quando:

- um grupo tem sua matriz alterada em `PUT /api/v1/groups/{id}/permissions`;
- um usuário muda de `grupo_id`;
- um usuário sofre alteração relevante de acesso;
- um grupo é alterado ou removido.

## `auth/me` enriquecido

`GET /api/v1/auth/me` agora retorna:

- `group`
- `modules`
- `permissions`

O endpoint não retorna a matriz inteira do sistema, apenas o acesso efetivo do usuário autenticado.

## API administrativa entregue

### Ordens de serviço

- `GET /api/v1/orders`
- `GET /api/v1/orders/{id}`
- `POST /api/v1/orders`
- `PUT/PATCH /api/v1/orders/{id}`
- `PATCH /api/v1/orders/{id}/status`

#### Regra de escopo

- técnico: apenas OS atribuídas a ele;
- usuário não técnico com `os:visualizar`: listagem geral com filtros;
- edição e criação dependem de `os:criar` e `os:editar`.

### Clientes e equipamentos

- `GET /api/v1/clients`
- `GET /api/v1/clients/{id}`
- `POST /api/v1/clients`
- `PUT/PATCH /api/v1/clients/{id}`
- `GET /api/v1/equipments`
- `GET /api/v1/equipments/{id}`

### Usuários e grupos

- `GET /api/v1/users`
- `POST /api/v1/users`
- `PUT/PATCH /api/v1/users/{id}`
- `PATCH /api/v1/users/{id}/active`
- `GET /api/v1/groups`
- `POST /api/v1/groups`
- `PUT/PATCH /api/v1/groups/{id}`
- `DELETE /api/v1/groups/{id}`
- `GET /api/v1/groups/{id}/permissions`
- `PUT /api/v1/groups/{id}/permissions`
- `GET /api/v1/modules`
- `GET /api/v1/permissions`

## Grupos de sistema

Grupos com `sistema = 1` são imutáveis:

- não podem ser editados;
- não podem ter a matriz de permissões alterada;
- não podem ser excluídos.

O backend responde `403 GROUP_SYSTEM_IMMUTABLE` nesses casos.

## Política de migrations desta fase

- permitido: manter a base Sanctum já existente;
- não utilizado nesta fase: migration `sessions`, porque `SESSION_DRIVER=file`;
- proibido nesta fase: recriar `grupos`, `modulos`, `permissoes`, `grupo_permissoes`, `usuarios`, `clientes`, `equipamentos` ou `os` por migrations novas.

## Validação executada

- `php artisan test --filter=AuthFlowTest`
- `php artisan test --filter=OrderFlowTest`
- `php artisan test --filter=RbacAdministrationTest`
- `php artisan test`

Resultado final:

- `26 passed`
- `142 assertions`

## Desdobramento da arquitetura

Com esta base pronta, o `frontends/desktop/` já consome esta API sem acesso direto ao banco e sem duplicar regras de autenticação ou autorização. Isso consolida o backend central como núcleo reutilizável para os canais mobile, desktop e futuras interfaces.

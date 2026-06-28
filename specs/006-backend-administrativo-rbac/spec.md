# Spec: Backend administrativo e RBAC central

**Feature Branch**: `006-backend-administrativo-rbac`  
**Status**: Implementado e validado

## Resumo

Esta fase amplia o backend central Laravel para suportar administração do ERP por API, mantendo o backend como fonte única de autenticação, autorização e regras operacionais. O RBAC legado passa a ser consumido diretamente a partir das tabelas reais do banco compartilhado, com cache controlado, fallback legado auditado e contratos administrativos em `/api/v1`.

## Objetivos

- Centralizar autorização por módulo e ação no backend Laravel.
- Preservar o contrato da API para o mobile e preparar a base para o frontend desktop.
- Reutilizar as tabelas legadas de RBAC como fonte de verdade, sem recriá-las por migration.
- Entregar endpoints administrativos para OS, clientes, equipamentos, usuários, grupos, módulos e permissões.
- Enriquecer `auth/me` com acesso efetivo do usuário autenticado.
- Documentar claramente a fronteira entre `backend/` e `frontends/desktop/`.

## Histórias de Usuário

### US1 - Resolver autorização central

Como plataforma, quero que o backend resolva permissões por `módulo:ação`, para que mobile, desktop e futuros canais dependam da mesma regra de acesso.

### US2 - Gerenciar ordens de serviço de forma administrativa

Como usuário não técnico com permissão de OS, quero listar, criar e editar OS no backend central, para que o desktop possa operar o fluxo completo sem acessar o banco diretamente.

### US3 - Consultar catálogos operacionais

Como frontend administrativo, quero consultar clientes, equipamentos, módulos e permissões por API, para montar telas de gestão e seleção com busca e paginação.

### US4 - Gerenciar usuários e grupos

Como administrador, quero listar, criar, editar e ativar/desativar usuários, além de gerenciar grupos e sua matriz de permissões, para controlar o acesso ao ERP sem depender do legado como escritor principal dessas regras.

## Requisitos Funcionais

- **FR-001** - O sistema deve inspecionar o schema real de `grupos`, `modulos`, `permissoes` e `grupo_permissoes` antes de modelar o RBAC no backend.
- **FR-002** - O sistema deve reutilizar o RBAC legado como fonte de verdade, sem criar migrations para tabelas legadas já existentes.
- **FR-003** - O sistema deve resolver permissões efetivas do usuário autenticado por `módulo:ação`.
- **FR-004** - O sistema deve registrar autorizações no Laravel Gate e impedir lógica inline de permissão nos controllers.
- **FR-005** - O sistema deve manter o fallback legado `perfil = admin` apenas para usuários sem `grupo_id`, registrando `warning` sempre que ele for usado.
- **FR-006** - O sistema deve cachear permissões efetivas por usuário com invalidação explícita quando grupo, permissões de grupo ou vínculo de grupo forem alterados.
- **FR-007** - O sistema deve enriquecer `GET /api/v1/auth/me` com `group`, `modules` e `permissions` efetivas do usuário autenticado.
- **FR-008** - O sistema deve preservar o comportamento mobile de OS para técnicos e ampliar `GET /api/v1/orders` para listagem administrativa quando o ator não for técnico e tiver permissão.
- **FR-009** - O sistema deve permitir criar e editar OS completas pelos endpoints administrativos em `/api/v1/orders`.
- **FR-010** - O sistema deve permitir consultar clientes e equipamentos com busca e paginação, sem escrita nesta fase.
- **FR-011** - O sistema deve permitir listar, criar, editar e ativar/desativar usuários.
- **FR-012** - O sistema deve permitir listar, criar, editar, excluir grupos e gerenciar sua matriz de permissões.
- **FR-013** - O sistema deve bloquear edição, alteração de permissões e exclusão de grupos com `sistema = 1`.
- **FR-014** - O sistema deve expor os catálogos de módulos e permissões para consumo do frontend administrativo.
- **FR-015** - O sistema deve manter todos os contratos em `/api/v1`, sem namespace `/admin`.

## Requisitos Não Funcionais

- **NFR-001** - O backend deve permanecer API-only, sem Blade, HTML ou sessão de frontend.
- **NFR-002** - O contrato do envelope da API deve permanecer estável: `status`, `data`, `error`, `meta`.
- **NFR-003** - O cache de permissões deve ter TTL curto e invalidação explícita para evitar acesso obsoleto.
- **NFR-004** - O fluxo de arquivos e anexos deve continuar mediado pelo backend, sem exposição pública direta.
- **NFR-005** - O backend deve manter compatibilidade com o banco compartilhado e com os fluxos já entregues ao mobile.
- **NFR-006** - A documentação da fase deve permitir que outro desenvolvedor entenda o contrato do RBAC, a política de migrations e a fronteira com o desktop.

## Critérios de Aceite

- `GET /api/v1/auth/me` retorna grupo, módulos e permissões efetivas.
- Técnicos continuam vendo apenas as OS atribuídas a eles.
- Usuários administrativos com `os:visualizar` conseguem listar OS em escopo geral com filtros.
- `POST /api/v1/orders` e `PUT/PATCH /api/v1/orders/{id}` funcionam com validação e RBAC.
- `GET /api/v1/clients`, `GET /api/v1/equipments`, `GET /api/v1/users`, `GET /api/v1/groups`, `GET /api/v1/modules` e `GET /api/v1/permissions` respondem no envelope padrão.
- `PUT /api/v1/groups/{id}/permissions` atualiza a matriz e invalida o cache dos usuários afetados.
- Grupos de sistema rejeitam update, update de permissões e delete com `403`.
- O fallback legado `perfil = admin` gera `warning` quando acionado.
- `php artisan test` permanece verde no backend inteiro.

## Premissas

- O frontend desktop ainda não entra nesta fase.
- O banco compartilhado continua sendo a fonte única de dados.
- A migration `personal_access_tokens` já existe e continua válida.
- `SESSION_DRIVER` permanece em arquivo nesta fase, então a migration `sessions` não é necessária.
- Os artefatos desta fase ficam em `specs/006-backend-administrativo-rbac/` como equivalente operacional do fluxo Spec Kit.

## Fora de Escopo

- Implementar o frontend desktop Laravel.
- Reescrever o legado `sistema-hml`.
- Introduzir microservices ou novos bancos.
- Criar escrita para clientes e equipamentos nesta fase.
- Alterar o contrato de autenticação já consumido pelo mobile.

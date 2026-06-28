# Fase 6 - Backend Administrativo e RBAC Central

## Resumo executivo

A Fase 6 transformou o backend central em uma base administrativa reutilizável por múltiplos canais, sem quebrar o fluxo do mobile e sem tocar no `sistema-hml`.

Entregas principais:

- RBAC central consumindo as tabelas legadas reais;
- `auth/me` enriquecido com grupo, módulos e permissões efetivas;
- cache de permissões com invalidação explícita;
- fallback legado `perfil = admin` mantido como ponte auditada;
- listagem, criação e edição administrativa de OS;
- leitura administrativa de clientes e equipamentos;
- gestão de usuários, grupos e matriz de permissões;
- bloqueio de alteração em grupos de sistema;
- documentação e artefatos da fase em `specs/006-backend-administrativo-rbac/`.

## Implementação entregue

### RBAC

- Models de `Group`, `Module`, `Permission` e `GroupPermission`
- serviço central `RbacAuthorizationService`
- registro de `Gate::define('modulo:acao', ...)`
- middleware `rbac`
- warning de auditoria no fallback legado

### API administrativa

- `GET/POST/PUT/PATCH` de OS conforme escopo
- `GET` de clientes e equipamentos com busca e paginação
- `GET/POST/PUT/PATCH` de usuários
- `GET/POST/PUT/PATCH/DELETE` de grupos
- `GET/PUT` da matriz de permissões do grupo
- `GET` de módulos e permissões

### Segurança

- grupos de sistema imutáveis
- cache de permissões invalidado quando necessário
- backend segue API-only
- anexos continuam servidos por rotas controladas

## Testes executados

```bash
php artisan test --filter=AuthFlowTest
php artisan test --filter=OrderFlowTest
php artisan test --filter=RbacAdministrationTest
php artisan test
```

Resultado final:

- `26 passed`
- `142 assertions`

## Impacto na arquitetura

- o mobile continua consumindo o backend sem mudança de contrato;
- o desktop agora tem uma API administrativa pronta para consumo;
- a plataforma fica melhor preparada para novos canais futuros.

## Observação final

Toda a implementação desta fase permaneceu dentro de `C:\xampp\htdocs\sistema-erp`, mantendo o `sistema-hml` intacto.

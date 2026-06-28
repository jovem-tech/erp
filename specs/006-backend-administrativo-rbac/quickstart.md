# Quickstart: Backend administrativo e RBAC central

## Pré-requisitos

- PHP e Composer operacionais
- banco `sistema_hml` acessível ao backend
- migration `personal_access_tokens` já aplicada

## Subir o backend

```bash
cd C:\xampp\htdocs\sistema-erp\backend
php artisan serve --host=127.0.0.1 --port=8000
```

## Validar a fase

```bash
php artisan test --filter=AuthFlowTest
php artisan test --filter=OrderFlowTest
php artisan test --filter=RbacAdministrationTest
php artisan test
```

## Smoke manual recomendado

1. Fazer `POST /api/v1/auth/login`.
2. Validar `GET /api/v1/auth/me` com `group`, `modules` e `permissions`.
3. Validar `GET /api/v1/orders` como técnico e como usuário administrativo.
4. Criar uma OS com `POST /api/v1/orders`.
5. Editar uma OS com `PATCH /api/v1/orders/{id}`.
6. Listar clientes e equipamentos.
7. Criar e editar usuário.
8. Atualizar a matriz de permissões de um grupo não sistêmico.
9. Confirmar que grupo `sistema = 1` rejeita alteração.

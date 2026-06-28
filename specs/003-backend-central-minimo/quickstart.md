# Quickstart: Backend Central Minimo

## Validacao esperada

1. Subir o backend em `backend/`.
2. Rodar `php artisan migrate --force`.
3. Executar `php artisan test --filter=AuthFlowTest`.
4. Executar `php artisan install:api` se a instalacao estiver sendo refeita do zero.
5. Confirmar `GET /api/v1/health`.
6. Confirmar login com `POST /api/v1/auth/login`.
7. Confirmar `GET /api/v1/auth/me` com token Bearer.
8. Confirmar `POST /api/v1/auth/logout`.
9. Confirmar `POST /api/v1/auth/refresh`.

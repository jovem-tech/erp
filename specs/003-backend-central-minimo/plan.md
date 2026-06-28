# Plan: Backend Central Minimo

## Contexto Tecnico

- Backend: Laravel 13.x
- Instalacao: `composer create-project laravel/laravel backend`
- API: `/api/v1`
- Auth: Laravel Sanctum via `php artisan install:api`
- Mobile: token Bearer
- Banco: MySQL/MariaDB compartilhado
- Ambiente local: Windows + XAMPP
- Producao: VPS Linux
- Repositorio de migrations Laravel: `laravel_migrations`
- Filas: `sync` enquanto nao houver jobs assíncronos

## Decisoes

- Usar Composer para criar o projeto.
- Usar Sanctum para token authentication do PWA mobile.
- Expor apenas `backend/public`.
- Manter arquivos privados e logs em `backend/storage`.
- Manter o backend API-first, sem redirecionamento web de login.
- Criar health check antes de migrar modulos de negocio.
- Remover as migrations padrao de `users`, `cache` e `jobs` para nao colidir com o legado.

## Artefatos da Fase

- Projeto Laravel instalado em `backend/`
- `.env` base ajustado para `sistema_hml`
- `routes/api.php` habilitado com `/api/v1`
- `GET /api/v1/health`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/refresh`
- `GET /api/v1/auth/me`
- Documentacao de uso e validacao

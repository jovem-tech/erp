# Backend Central Minimo

## Objetivo

Esta fase instala o backend central do `sistema-erp` em Laravel 13.x e entrega o contrato minimo necessario para o PWA mobile trabalhar sem depender do legado PHP.

## Arquitetura entregue

- backend central em `backend/`
- API versionada em `/api/v1`
- auth mobile com Sanctum e token Bearer
- envelope padrao de resposta: `status`, `data`, `error`, `meta`
- storage privado dentro do projeto
- acesso controlado aos arquivos do legado via backend central
- logs internos dentro do projeto
- banco compartilhado com o legado `sistema_hml`

## Contratos tecnicos

### API

- `GET /api/v1/health`
- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/refresh`
- `GET /api/v1/orders`
- `GET /api/v1/orders/{id}`
- `PATCH /api/v1/orders/{id}/status`
- `GET /api/v1/orders/{id}/photos/{photo}`
- `GET /api/v1/orders/{id}/documents/{document}`

### Autenticacao

- O backend usa o model `App\Models\User` apontando para a tabela legada `usuarios`.
- O campo de senha continua sendo `usuarios.senha`.
- O token Bearer e emitido com Sanctum.
- O mobile nao depende de sessao web tradicional.

### Banco de dados

- A tabela `usuarios` continua sendo a fonte de identidade da equipe.
- A migration `personal_access_tokens` foi aplicada no banco compartilhado para suportar Sanctum.
- O repositório de migrations do Laravel usa a tabela `laravel_migrations` para nao colidir com a tabela `migrations` do legado.
- As migrations padrao de `users`, `cache` e `jobs` foram removidas do backend central porque nao fazem parte desta arquitetura.

### Arquivos e logs

- Arquivos privados ficam em `backend/storage/app/private`.
- Logs da aplicacao ficam em `backend/storage/logs`.
- O disco padrao do Laravel aponta para a area privada local, entao os arquivos nao sao expostos por URL publica direta.

## Respostas padrao

O backend sempre responde no envelope abaixo:

```json
{
  "status": "success",
  "data": {},
  "error": null,
  "meta": {
    "timestamp": "2026-06-22T00:00:00-03:00",
    "request_id": "req_..."
  }
}
```

Em erros:

```json
{
  "status": "error",
  "data": null,
  "error": {
    "code": "AUTH_REQUIRED",
    "message": "Usuário não autenticado.",
    "details": null
  },
  "meta": {
    "timestamp": "2026-06-22T00:00:00-03:00",
    "request_id": "req_..."
  }
}
```

## Smoke test operacional

### Banco e migrations

```bash
cd backend
php artisan migrate --force
```

### Testes

```bash
php artisan test --filter=AuthFlowTest
```

### Servidor local

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

### Fluxo validado

1. `GET /api/v1/health` retorna `200`
2. `POST /api/v1/auth/login` retorna `access_token`
3. `GET /api/v1/auth/me` funciona com Bearer token
4. `POST /api/v1/auth/logout` revoga o token atual
5. `POST /api/v1/auth/refresh` emite um token novo
6. `GET /api/v1/orders` lista apenas as OS do técnico autenticado
7. `GET /api/v1/orders/{id}` traz detalhe, histórico recente e URLs de anexos
8. `GET /api/v1/orders/{id}/photos/{photo}` e `GET /api/v1/orders/{id}/documents/{document}` servem arquivos por acesso controlado

## Observacao de seguranca

O backend nao expõe acesso direto a arquivos privados nem depende de rotas web de login.
O controle de autenticacao e feito pelo backend central e pelo Sanctum, o que reduz a superficie de ataque do mobile/PWA.

# Contrato Resumido da API - Spec 008

Atualizado em 24/06/2026.

## Fonte de verdade

- Runtime atual: `backend/routes/api.php`.
- Contrato técnico oficial: `backend/openapi.yaml`.
- Guia humano obrigatório: `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`.

## Envelope padrão

Sucesso:

```json
{
  "status": "success",
  "data": {},
  "error": null,
  "meta": {
    "timestamp": "2026-06-24T10:00:00-03:00",
    "request_id": "req_..."
  }
}
```

Erro:

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
    "timestamp": "2026-06-24T10:00:00-03:00",
    "request_id": "req_..."
  }
}
```

## Autenticação

- `POST /auth/login` emite `access_token`, `token_type`, `expires_at` e `user`.
- Endpoints protegidos usam `Authorization: Bearer <token>`.
- O BFF deve guardar o token apenas em sessão server-side.
- `POST /auth/refresh` renova token autenticado.
- `POST /auth/logout` revoga o token atual.

## Rotas públicas

- `GET /health`
- `POST /auth/login`
- `POST /auth/password/forgot`
- `POST /auth/password/reset`

## Rotas autenticadas

- Autenticação: `GET /auth/me`, `PATCH /auth/me`, `PUT /auth/password`, `POST /auth/refresh`, `POST /auth/logout`
- Dashboard: `GET /dashboard/summary`
- Notificações: `GET /notifications`, `PATCH /notifications/{notification}/read`, `PATCH /notifications/read-all`
- OS: `GET /orders`, `GET /orders/{order}`, `POST /orders`, `PUT/PATCH /orders/{order}`, `PATCH /orders/{order}/status`, anexos autenticados
- Clientes: `GET /clients`, `POST /clients`, `GET /clients/{client}`, `PUT/PATCH /clients/{client}`
- Equipamentos: `GET /equipments`, `GET /equipments/{equipment}`
- Usuários: `GET /users`, `POST /users`, `PUT/PATCH /users/{user}`, `PATCH /users/{user}/active`
- Grupos: `GET /groups`, `POST /groups`, `PUT/PATCH /groups/{group}`, `DELETE /groups/{group}`, permissões do grupo
- Catálogos RBAC: `GET /modules`, `GET /permissions`

## Política para frontends

- Frontends não acessam banco.
- Frontends não duplicam regra de negócio.
- Frontends não servem anexos operacionais por pasta pública.
- Todo consumo passa por cliente HTTP centralizado.
- Retry automático deve ser conservador e nunca repetir mutações sem idempotência explícita.

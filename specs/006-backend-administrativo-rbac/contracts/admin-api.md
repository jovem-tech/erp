# Contract: API administrativa e RBAC central

## Envelope padrão

Todas as respostas seguem:

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

## Autenticação enriquecida

### `GET /api/v1/auth/me`

Retorna:

- `id`
- `nome`
- `email`
- `perfil`
- `grupo_id`
- `group`
- `modules`
- `permissions`
- `ativo`
- `ultimo_acesso`

## Ordens de serviço

### `GET /api/v1/orders`

Filtros suportados:

- `status`
- `search`
- `technician_id`
- `client_id`
- `page`
- `per_page`

### `POST /api/v1/orders`

Campos principais:

- `cliente_id`
- `equipamento_id`
- `tecnico_id`
- `status`
- `estado_fluxo`
- `relato_cliente`
- `diagnostico_tecnico`
- `solucao_aplicada`
- `procedimentos_executados`
- `data_abertura`
- `data_entrada`
- `data_previsao`
- `data_conclusao`
- `data_entrega`
- `garantia_dias`
- `garantia_validade`

### `PUT/PATCH /api/v1/orders/{id}`

Mesmo payload base de criação, com atualização parcial suportada.

## Catálogos operacionais

- `GET /api/v1/clients`
- `GET /api/v1/clients/{id}`
- `GET /api/v1/equipments`
- `GET /api/v1/equipments/{id}`
- `GET /api/v1/modules`
- `GET /api/v1/permissions`

## Usuários

- `GET /api/v1/users`
- `POST /api/v1/users`
- `PUT/PATCH /api/v1/users/{id}`
- `PATCH /api/v1/users/{id}/active`

## Grupos

- `GET /api/v1/groups`
- `POST /api/v1/groups`
- `PUT/PATCH /api/v1/groups/{id}`
- `DELETE /api/v1/groups/{id}`
- `GET /api/v1/groups/{id}/permissions`
- `PUT /api/v1/groups/{id}/permissions`

## Regras de erro relevantes

- `401 AUTH_REQUIRED`
- `403 FORBIDDEN`
- `403 GROUP_SYSTEM_IMMUTABLE`
- `403 ORDER_FORBIDDEN`
- `404 ORDER_NOT_FOUND`
- `422 VALIDATION_ERROR`
- `422 ORDER_EQUIPMENT_CLIENT_MISMATCH`
- `422 ORDER_STATUS_INVALID`

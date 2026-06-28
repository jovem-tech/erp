# Quickstart: Fluxo de OS Mobile

## Pré-requisitos

- Backend Laravel instalado em `backend/`
- Banco compartilhado disponível
- Usuário autenticado com ao menos uma OS atribuída

## Validação automática

```bash
cd backend
php artisan test --filter=OrderFlowTest
```

## Smoke local

```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

1. Faça login com um usuário técnico.
2. Chame `GET /api/v1/orders`.
3. Confirme que apenas OS atribuídas ao usuário aparecem.
4. Chame `GET /api/v1/orders/{id}`.
5. Confirme que o detalhe traz cliente, equipamento, histórico recente e URLs de anexos.
6. Chame `PATCH /api/v1/orders/{id}/status` com um código válido do catálogo.
7. Confirme que `status`, `estado_fluxo` e histórico foram atualizados.

## Casos esperados

- Um código fora do catálogo deve retornar erro de validação.
- Uma OS não atribuída ao técnico deve retornar `403`.
- O detalhe de uma OS não atribuída deve retornar `403`.
- A atualização com sucesso deve devolver o novo `estado_fluxo` na resposta.
- Os anexos devem ser acessados por endpoint controlado e não por bytes embutidos no JSON.

## Observação

O passo de transições entre status fica para uma fase posterior.

# Plan: Fluxo de OS Mobile

## Contexto Técnico

- Backend: Laravel 13.x
- API: `/api/v1`
- Auth: Sanctum com token Bearer
- Banco: MySQL/MariaDB compartilhado com o legado
- Dominio: Ordens de Serviço
- Base operacional: `os`, `os_status`, `os_status_historico`

## Decisões

- Usar um serviço de domínio para concentrar listagem e mudança de status.
- Validar status em runtime com o catálogo ativo do banco.
- Atualizar `status` e `estado_fluxo` na mesma operação.
- Registrar histórico da alteração dentro da mesma transação.
- Não aplicar validação de transição nesta fase.
- Retornar `403` quando a OS existir, mas não estiver atribuída ao técnico autenticado.
- Eager load do detalhe da OS com cliente, equipamento, histórico recente e anexos controlados.
- Expor fotos e PDFs por endpoint protegido, sem retornar bytes no JSON.

## Estrutura de Implementação

- `backend/app/Models/Order.php`
- `backend/app/Models/OrderStatus.php`
- `backend/app/Models/OrderStatusHistory.php`
- `backend/app/Models/Client.php`
- `backend/app/Models/Equipment.php`
- `backend/app/Models/OrderPhoto.php`
- `backend/app/Models/OrderDocument.php`
- `backend/app/Services/Orders/OrderWorkflowService.php`
- `backend/app/Http/Requests/Api/V1/UpdateOrderStatusRequest.php`
- `backend/app/Http/Controllers/Api/V1/OrderController.php`
- `backend/routes/api.php`
- `backend/tests/Feature/Api/V1/OrderFlowTest.php`

## Validação

- Teste automatizado com `OrderFlowTest`
- Smoke seguro no banco real com rollback
- Verificação de resposta `403`, `404`, `422` e `200`
- Verificação do detalhe da OS com histórico limitado e URLs de anexos
- Verificação dos endpoints seguros de fotos e documentos

## Critério de Saída

O fluxo está pronto quando o técnico consegue listar suas OS, abrir o detalhe completo da OS, visualizar anexos controlados e alterar o status com `estado_fluxo` sincronizado, histórico gravado e bloqueio explícito para OS de terceiros.

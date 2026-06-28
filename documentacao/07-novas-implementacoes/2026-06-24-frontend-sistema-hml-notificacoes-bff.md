# Notificações BFF do frontend sistema-hml

Data: 24/06/2026.

## Resumo

Foi concluída a migração da primeira fatia simples de notificações do clone `frontend/sistema-hml` para o backend central do `sistema-erp`.

O objetivo desta entrega foi retirar o topo do clone da leitura e escrita diretas na tabela local `mobile_notifications`, transformando o módulo em BFF real, sem alteração de schema, migration ou estrutura do banco.

## Arquivos alterados

- `backend/app/Models/MobileNotification.php`
- `backend/app/Notifications/Channels/MobileInboxChannel.php`
- `backend/app/Notifications/MobileNotification.php`
- `backend/app/Services/Notifications/NotificationInboxService.php`
- `backend/app/Http/Controllers/Api/V1/NotificationController.php`
- `backend/routes/api.php`
- `backend/openapi.yaml`
- `backend/tests/Concerns/BuildsLegacyErpSchema.php`
- `backend/tests/Feature/Api/V1/NotificationInboxTest.php`
- `frontend/sistema-hml/app/Services/ErpBackendApiClient.php`
- `frontend/sistema-hml/app/Services/ErpBackendNotificationService.php`
- `frontend/sistema-hml/app/Controllers/Notificacoes.php`
- `frontend/sistema-hml/app/Views/layouts/navbar.php`
- `frontend/sistema-hml/public/assets/js/navbar-notifications.js`
- `README.md`
- `documentacao/README.md`
- `documentacao/03-arquitetura-tecnica/README.md`
- `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md`
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`
- `frontend/sistema-hml/README.sistema-erp.md`

## O que mudou

- O backend central passou a tratar a inbox oficial do sistema em `mobile_notifications`.
- O endpoint `GET /notifications` deixou de ler a tabela Laravel `notifications` e passou a listar a inbox operacional real.
- O backend central passou a suportar:
  - `GET /notifications`
  - `PATCH /notifications/{notification}/read`
  - `PATCH /notifications/read-all`
  - `DELETE /notifications/read`
- O canal `App\Notifications\MobileNotification` passou a gravar em `mobile_notifications`, preservando compatibilidade com a inbox real usada pelo sistema.
- O clone `frontend/sistema-hml` ganhou `ErpBackendNotificationService` e deixou de consultar `MobileNotificationModel` no controller do topo.
- O topo continua com a mesma interface visual, mas agora fala com o backend central via BFF.
- Nesta fase, o topo usa polling controlado em vez de depender do stream legado local. Isso evita manter uma ponte SSE improvisada até existir contrato realtime dedicado no backend central.

## Estado da migração após esta entrega

Já em BFF:

- login;
- `auth/me`;
- sessão server-side;
- logout;
- recuperação e redefinição de senha;
- dashboard;
- `admin/stats`;
- notificações do topo.

Ainda legados:

- busca global;
- shell/layout em partes auxiliares;
- módulos operacionais mais acoplados, como listagens e CRUDs completos.

## Validação executada

- `php -l` nos arquivos PHP novos e alterados do backend e do clone.
- `php artisan route:list --path=api/v1/notifications`
- `php artisan test --filter=NotificationInboxTest`
- chamada real ao backend central em `GET /api/v1/notifications` com token válido, retornando itens reais de `mobile_notifications`
- validação em navegador do clone autenticado:
  - dashboard abriu normalmente;
  - dropdown de notificações exibiu a inbox correta;
  - contador de não lidas apareceu;
  - ações `Marcar todas` e `Limpar lidas` permaneceram disponíveis na interface.

## Restrições respeitadas

- nenhuma migration nova foi criada;
- nenhuma tabela foi alterada;
- nenhuma estrutura do banco foi modificada;
- o módulo migrado no clone não lê mais a inbox direto do banco local.

## Observação arquitetural

O topo ainda opera em polling controlado. Isso é intencional nesta fase:

- reduz risco;
- evita uma falsa sensação de realtime com ponte local ad hoc;
- mantém a fronteira correta de BFF;
- deixa espaço para uma próxima etapa, mais pesada, onde um contrato realtime oficial do backend central poderá substituir o polling.

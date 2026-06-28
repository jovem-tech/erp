# Dashboard BFF do frontend sistema-hml

Data: 24/06/2026.

## Resumo

Foi implementada a segunda fatia funcional do clone `frontend/sistema-hml` como frontend server-side/BFF do `sistema-erp/backend`, cobrindo login operacional do clone e a migração inicial do dashboard.

Esta entrega não altera esquema, migration, tabela ou dado do banco. O backend central continua como fonte de verdade para o módulo migrado.

## Arquivos alterados

- `frontend/sistema-hml/.env`
- `frontend/sistema-hml/app/Controllers/Admin.php`
- `frontend/sistema-hml/app/Services/ErpBackendDashboardService.php`
- `frontend/sistema-hml/app/Views/admin/dashboard.php`
- `frontend/sistema-hml/README.sistema-erp.md`
- `backend/app/Services/Dashboard/DashboardSummaryService.php`
- `backend/tests/Feature/Api/V1/DashboardSummaryTest.php`
- `README.md`
- `documentacao/README.md`
- `documentacao/03-arquitetura-tecnica/README.md`
- `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md`
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`

## O que mudou

- `Admin.php` do clone deixou de montar o dashboard por models locais e passou a consumir apenas `ErpBackendDashboardService`.
- `ErpBackendDashboardService` chama `GET /dashboard/summary` no backend central e adapta o payload para a view legado/CodeIgniter.
- `admin/stats` do clone passou a responder a partir do mesmo resumo do backend central, preservando o formato esperado pelos gráficos existentes.
- A view do dashboard passou a aceitar badges de status já normalizadas pelo BFF.
- O carregamento AJAX do dashboard passou a enviar `Accept: application/json` e `X-Requested-With: XMLHttpRequest` para evitar injeção do Debug Toolbar em respostas JSON no ambiente local `development`.
- O backend central passou a expor no resumo do dashboard os alertas operacionais e o faturamento do mês anterior usados pelo BFF.
- A `.env` do clone foi ajustada com base URL da API, sessão server-side e conexão local temporária para módulos ainda não migrados do shell legado.
- `public/assets/vendor/` do clone foi sincronizado com a cópia original para restaurar jQuery, Chart.js e demais bibliotecas estáticas do shell atual.

## Fronteira técnica após esta entrega

Já em BFF:

- login;
- `auth/me`;
- sessão server-side;
- logout;
- recuperação e redefinição de senha;
- dashboard;
- `admin/stats` do dashboard.

Ainda legados:

- menu superior e trechos auxiliares do shell;
- notificações e busca global;
- demais módulos fora do dashboard.

Regra aplicada:

- módulo migrado não consulta banco local;
- módulo migrado não duplica regra de negócio;
- módulo migrado usa somente a API central;
- conexão local no clone existe apenas para estabilidade temporária de módulos ainda não migrados.

## Validação executada

- `php -l frontend/sistema-hml/app/Services/ErpBackendDashboardService.php`
- `php -l frontend/sistema-hml/app/Controllers/Admin.php`
- `php -l frontend/sistema-hml/app/Views/admin/dashboard.php`
- `php -l backend/app/Services/Dashboard/DashboardSummaryService.php`
- `php -l backend/tests/Feature/Api/V1/DashboardSummaryTest.php`
- `php artisan test --filter=DashboardSummaryTest` em `backend/`
- `GET http://127.0.0.1:8000/api/v1/health`
- login real no clone do BFF com credencial válida do backend central
- acesso real ao dashboard em `http://127.0.0.1:8081/dashboard`
- validação de `GET http://127.0.0.1:8081/admin/stats` com retorno JSON puro quando a requisição envia os headers AJAX esperados

## Observações operacionais

- Em produção, o backend central deve rodar em VPS Linux (Ubuntu) e ser consumido pelo BFF via HTTPS ou rede interna segura.
- O clone não substituiu 100% o shell legado ainda. Ele já pode ser acessado para inspeção interna do login e do dashboard, mas outras áreas continuam em convivência temporária com o legado.

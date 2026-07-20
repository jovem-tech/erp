# Arquitetura Técnica - Fases 4, 5, 6 e 7

Este diretório concentra a documentação técnica do backend central Laravel, do fluxo mobile, da base administrativa/RBAC e do novo frontend desktop Laravel que consome a mesma API.

## Leitura principal

- [Backend central mínimo](backend-central-minimo.md)
- [Backend administrativo e RBAC](backend-administrativo-rbac.md)
- [Contrato da API do backend central](contrato-api-backend-central.md)
- [Frontend desktop Laravel](frontend-desktop-laravel.md)
- [Frontend sistema-hml como BFF](frontend-sistema-hml-bff.md)
- [Gerenciador Central de Arquivos](gerenciador-central-arquivos.md)
- [Idempotência e confirmação segura na criação de OS](idempotencia-criacao-os.md)
- [Inventário de arquivos funcionais](inventario-arquivos-funcionais.md)
- [Mapa completo de migração e limpeza do frontend sistema-hml](mapa-migracao-legado-frontend-sistema-hml.md)
- [Fluxo de OS mobile](ordens-mobile.md)

## O que estas fases entregam

- backend Laravel 13.x instalado em `backend/`;
- API versionada em `/api/v1`;
- contrato técnico oficial em `backend/openapi.yaml`;
- contrato humano obrigatório em `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`;
- autenticação mobile com Sanctum e token Bearer;
- recuperação de senha por e-mail com link temporário apontando para o desktop;
- sessão do mobile persistida com expiração explícita e refresh controlado;
- health check funcional;
- acesso ao banco dedicado `sistema_hml`;
- armazenamento privado em `backend/storage/app/private`;
- logs internos em `backend/storage/logs`;
- listagem de OS atribuídas ao técnico autenticado;
- detalhe da OS com cliente, equipamento, histórico recente, status disponíveis e anexos controlados;
- alteração de status com atualização conjunta de `status` e `estado_fluxo`;
- RBAC central consumindo tabelas legadas reais;
- `auth/me` enriquecido com permissões efetivas;
- listagem, criação e edição administrativa de OS;
- consulta, cadastro, edição e detalhe operacional de clientes e equipamentos;
- gestão de usuários, grupos, módulos e permissões;
- grupos de sistema protegidos contra alteração;
- fotos e PDFs acessados pelo backend central, sem exposição direta do caminho físico;
- CSP do frontend mobile ajustada para desenvolvimento local e produção;
- frontend desktop separado em `frontends/desktop/`;
- sessão server-side do desktop com token guardado no servidor;
- camada `app/Services/` obrigatória no desktop para toda comunicação com a API;
- middlewares `desktop.auth` e `desktop.permission` protegendo rotas Blade;
- menu do desktop montado dinamicamente com base em `auth/me`;
- dropdowns visíveis do desktop padronizados com `Select2` + tema `Bootstrap 5`, helper compartilhado e `dropdownParent` adequado em modais/offcanvas;
- tratamento centralizado de `401` e `403` no `ApiClient`.
- cópia segura do legado `sistema-hml` em `frontend/sistema-hml/`, preparada para evolução como frontend server-side/BFF consumindo o backend central.
- governança explícita impedindo que `frontend/sistema-hml` vire backend paralelo, acesse banco em módulos migrados, duplique regra de negócio ou armazene anexos operacionais em pasta pública.
- primeira fatia BFF implementada em `frontend/sistema-hml`: cliente HTTP único, login, `auth/me`, logout, recuperação de senha e dashboard consumindo a API central, com token Bearer somente em sessão server-side.
- dashboard do clone adaptado para renderização server-side + atualização AJAX via `admin/stats`, sempre consumindo `GET /dashboard/summary` do backend central.
- notificações do topo migradas no clone para consumo do backend central, usando polling controlado e sem leitura direta local da inbox.
- mapa completo do legado remanescente do clone documentado por módulo, camada técnica, prioridade e ordem segura de limpeza.
- conexão local do clone preservada apenas para partes ainda não migradas do shell legado, sem criar backend paralelo nem alterar banco de dados.

## Validação

Use a sequência abaixo para validar a fase:

1. `php artisan migrate --force`
2. `php artisan test --filter=AuthFlowTest`
3. `php artisan test --filter=OrderFlowTest`
4. `php artisan test --filter=RbacAdministrationTest`
5. subir o backend central no Apache do XAMPP em `http://127.0.0.1:8000`
6. `GET /api/v1/health`
7. `POST /api/v1/auth/login`
8. `GET /api/v1/auth/me`
9. `GET /api/v1/orders`
10. `GET /api/v1/orders/{id}`
11. `PATCH /api/v1/orders/{id}/status`
12. `php artisan route:list` em `frontends/desktop`
13. `php artisan test` em `frontends/desktop`
14. `GET http://127.0.0.1:8080/login`
15. login HTTP no desktop com redirecionamento para `/dashboard`
16. conferir `backend/openapi.yaml` contra `backend/routes/api.php`
17. configurar `ERP_BACKEND_API_BASE_URL` no `.env` de `frontend/sistema-hml`
18. `GET http://127.0.0.1:<porta-bff>/login`
19. login no BFF com usuário válido do backend central
20. conferir que a sessão possui `erp_backend_access_token` somente server-side e `user_permissions` vindo de `auth/me`
21. `GET http://127.0.0.1:<porta-bff>/dashboard` após login
22. confirmar que `GET http://127.0.0.1:<porta-bff>/admin/stats` retorna JSON puro quando a requisição envia `Accept: application/json` e `X-Requested-With: XMLHttpRequest`

# Autenticação BFF do frontend sistema-hml

Data: 24/06/2026.

## Resumo

Foi implementada a primeira fatia funcional de `frontend/sistema-hml` como frontend server-side/BFF do backend central. O escopo desta entrega é autenticação, sessão, recuperação de senha e contrato mínimo de comunicação HTTP com `sistema-erp/backend`.

O projeto original `C:\xampp\htdocs\sistema-hml` não foi alterado.

## Arquivos alterados

- `frontend/sistema-hml/app/Config/ErpBackend.php`
- `frontend/sistema-hml/app/Services/ErpBackendApiClient.php`
- `frontend/sistema-hml/app/Services/ErpBackendAuthService.php`
- `frontend/sistema-hml/app/Services/ErpBackendSessionService.php`
- `frontend/sistema-hml/app/Controllers/Auth.php`
- `frontend/sistema-hml/app/Filters/AuthFilter.php`
- `frontend/sistema-hml/app/Views/auth/login.php`
- `frontend/sistema-hml/app/Views/auth/reset_password.php`
- `frontend/sistema-hml/env`
- `frontend/sistema-hml/README.sistema-erp.md`
- `backend/app/Http/Requests/Api/V1/ForgotPasswordRequest.php`
- `backend/app/Http/Controllers/Api/V1/AuthController.php`
- `backend/app/Models/User.php`
- `backend/app/Notifications/FrontendPasswordResetNotification.php`
- `backend/app/Notifications/DesktopPasswordResetNotification.php` removido por ficar obsoleto
- `backend/config/services.php`
- `backend/openapi.yaml`
- `backend/.env.example`
- `backend/.env.production`
- `backend/tests/Feature/Api/V1/PasswordResetFlowTest.php`

## O que mudou

- Login passou a consumir `POST /auth/login` no backend central.
- Após o login, o BFF chama `GET /auth/me` para carregar usuário, grupo, módulos e permissões.
- Logout passou a consumir `POST /auth/logout` antes de destruir a sessão local.
- Recuperação e redefinição de senha passaram a consumir `POST /auth/password/forgot` e `POST /auth/password/reset`.
- O BFF envia `frontend=sistema-hml` ao solicitar recuperação de senha para que o backend use a URL aprovada `FRONTEND_SISTEMA_HML_URL` no e-mail.
- Token Bearer passou a ficar apenas na sessão server-side do CodeIgniter.
- `user_permissions` passou a ser preenchido com o retorno do backend central, preservando compatibilidade com helpers e filtros legados.
- O fluxo legado de "lembrar-me" foi desativado na cópia BFF e o cookie persistente antigo é descartado.
- A tela de login não exibe mais o checkbox "Lembrar-me".
- O contrato da API passou a aceitar `frontend=desktop|sistema-hml` em `POST /auth/password/forgot`, com URL resolvida por configuração do backend.
- A notificação antiga limitada ao desktop foi substituída por notificação de frontend aprovado por configuração.
- A expiração de tokens emitidos por login e refresh no backend passou a respeitar `SANCTUM_EXPIRATION`, removendo hardcode de 1 dia e alinhando runtime com contrato/testes.

## Decisões de segurança

- Nenhum token Bearer é enviado para o navegador.
- `Auth.php` não consulta `UsuarioModel` para autenticar, recuperar senha ou redefinir senha.
- `AuthFilter.php` não restaura sessão por cookie persistente nem consulta banco local para autenticação.
- Falhas de API são registradas sem senha, token ou payload sensível.
- O BFF encerra a sessão local quando perde o vínculo com o token do backend central.

## Variáveis de ambiente

```text
ERP_BACKEND_API_BASE_URL=http://127.0.0.1:8000/api/v1
ERP_BACKEND_API_TIMEOUT=15
ERP_BACKEND_API_CONNECT_TIMEOUT=5
ERP_BACKEND_AUTH_DEVICE=frontend-sistema-hml
```

Em produção, esses valores devem apontar para a API v1 do backend central na VPS Linux (Ubuntu), com HTTPS ou rede interna segura conforme o contrato de ambiente.

No backend central, configurar também:

```text
FRONTEND_SISTEMA_HML_URL=https://sistema.seudominio.com
```

## Validação executada

- `php -l` nos novos serviços e arquivos alterados de autenticação.
- `php -l` nos arquivos backend alterados para canal seguro de redefinição.
- Varredura para confirmar ausência de `UsuarioModel`, `ErpMailService`, `token_recuperacao`, `remember_token` e `Lembrar-me` nos arquivos migrados de autenticação.
- `php artisan test --filter=AuthFlowTest`
- `php artisan test --filter=PasswordResetFlowTest`
- Validação estrutural de `backend/openapi.yaml`.
- Comparação OpenAPI x `php artisan route:list --path=api/v1 --json`: 42 operações documentadas e 42 rotas runtime, sem divergências.

## Próximas etapas

- Validar login/logout/recuperação de senha com o backend central em execução.
- Migrar dashboard e primeiras listagens de leitura para consumo da API central.
- Manter a regra de não criar backend paralelo dentro de `frontend/sistema-hml`.

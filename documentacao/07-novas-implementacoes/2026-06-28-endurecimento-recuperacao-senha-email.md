# Endurecimento da recuperacao de senha por e-mail

## Contexto

- data: `2026-06-28`
- ambiente-alvo: `Ubuntu VPS`
- motivacao: o fluxo publico de redefinicao de senha nao podia continuar aceitando preview local automatico quando o backend estava com `MAIL_MAILER=log`.

## Entrega

- o backend passou a aplicar automaticamente a configuracao SMTP salva em `Configuracoes > Integracoes > E-mail` para envios operacionais;
- `POST /auth/password/forgot` agora falha com `503 AUTH_PASSWORD_RESET_CHANNEL_UNAVAILABLE` quando nao existe canal real de e-mail disponivel;
- a notificacao de redefinicao reaplica a configuracao SMTP tambem no job enfileirado, evitando worker preso a configuracao antiga;
- o desktop trata o erro operacional da recuperacao sem quebrar a tela publica e sem abrir preview local do link;
- documentacao de ambiente e contrato foi atualizada para refletir o comportamento fail-closed.

## Impactos

- desenvolvimento local com `MAIL_MAILER=log` deixa de simular entrega de redefinicao de senha por atalho;
- se o SMTP estiver configurado no banco do backend, a recuperacao continua funcionando mesmo quando o `.env` local usa `MAIL_MAILER=log`;
- se o SMTP nao estiver configurado, o usuario recebe erro operacional explicito em vez de um falso positivo de envio.

## Validacao

- `php artisan test tests/Feature/Api/V1/PasswordResetFlowTest.php`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter="password_reset"`
- requisicao real local para `POST http://127.0.0.1:8000/api/v1/auth/password/forgot` com conta existente retornando `status=success` e `delivery.mode=email`

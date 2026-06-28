# Plan 007 - Frontend Desktop Laravel

## Stack

- Laravel 13.x
- Blade
- Bootstrap 5 via CDN
- SweetAlert2
- sessão server-side do Laravel

## Estrutura técnica

- `app/Services/ApiClient.php` como porta HTTP central
- services por domínio para OS, clientes, equipamentos, usuários e grupos
- `DesktopSession` para token e perfil
- `DesktopNavigation` para o menu orientado a permissões
- middlewares `desktop.auth` e `desktop.permission`
- views Blade por módulo

## Restrições obrigatórias

- nenhum acesso direto ao banco `sistema_hml`
- nenhum Model de negócio no desktop
- nenhum `Http::` dentro de controllers
- backend central continua stateless para os clientes

## Validação prevista

- `php artisan route:list`
- `php artisan test`
- smoke test HTTP de login e dashboard
- revisão documental da arquitetura e da nota da fase

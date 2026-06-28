# Quickstart 007 - Frontend Desktop Laravel

## Backend central

```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

## Frontend desktop

```bash
cd frontends/desktop
composer install
copy .env.example .env
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8080
```

## Verificação

1. abrir `http://127.0.0.1:8080/login`
2. autenticar com um usuário válido do `sistema_hml`
3. confirmar redirecionamento para `/dashboard`
4. navegar pelos módulos permitidos no menu lateral

# Research: Backend Central Mínimo

## Decisoes consolidadas

### 1. Instalação por Composer

O uso de `composer create-project` garante uma instalação reproduzível e controlada do Laravel.

### 2. Sanctum para mobile

O Laravel 13 documenta `php artisan install:api` como o caminho para habilitar API routes e Sanctum. Sanctum é apropriado para mobile e tokens Bearer.

### 3. Health check primeiro

O health check é o menor contrato útil para confirmar que o backend subiu corretamente antes de migrar quaisquer módulos.

### 4. Sem sessão para o mobile

Como o mobile será um PWA separado, token Bearer é mais simples e mais coerente que sessão tradicional.


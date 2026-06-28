# VPS Linux (Ubuntu)

## Objetivo

Publicar o backend de forma segura e previsível em uma VPS Ubuntu, mantendo o mesmo contrato funcional do ambiente local.

## Regras

- O servidor web deve apontar para `/var/www/sistema-erp/backend/public`.
- Os logs devem ficar em `backend/storage/logs`.
- Os arquivos privados devem ficar em `backend/storage/app/private`.
- O scheduler deve rodar por cron a cada minuto.
- As filas só precisam de worker dedicado quando deixarem de usar `sync`.
- O ambiente oficial de produção é VPS Linux (Ubuntu).

## Templates

- `infra/linux/nginx-site.conf`
- `infra/linux/cron-scheduler.example`
- `scripts/bash/validate-prod-env.sh`

## Observação de operação

Em produção, não existir dependência de comportamento específico do Windows nem de paths com letra de unidade.
Se jobs assíncronos forem introduzidos no futuro, usar worker dedicado com Supervisor ou serviço equivalente.

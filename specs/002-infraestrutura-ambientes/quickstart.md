# Quickstart: Infraestrutura de Desenvolvimento e Produção

## Validacao local

1. Confirmar a estrutura de `C:\xampp\htdocs\sistema-erp`.
2. Confirmar `backend/storage/app/private` e `backend/storage/logs`.
3. Copiar `backend/.env.example` para `backend/.env` quando o backend existir.
4. Ajustar `APP_URL`, `PRIVATE_FILES_PATH`, `LOGS_PATH` e origens de CORS.
5. Se houver jobs assíncronos, definir a estrategia de fila; caso contrario, manter `sync`.
6. Executar os scripts de validacao desta fase.

## Validacao em VPS

1. Confirmar que o deploy publica apenas `backend/public`.
2. Conferir permissao de escrita em `backend/storage`.
3. Conferir cron do scheduler.
4. Conferir queue worker apenas se a fila estiver em `database` ou `redis`.
5. Conferir logs e acessos privados.

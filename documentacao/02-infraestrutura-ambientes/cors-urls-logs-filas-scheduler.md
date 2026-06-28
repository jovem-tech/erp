# CORS, URLs, Logs, Filas e Scheduler

## URLs

- Backend local: `http://127.0.0.1:8000`
- Mobile local: `http://127.0.0.1:3001` (ou a proxima porta livre informada pelo `pnpm dev`)
- Desktop local: `http://127.0.0.1:8080`
- Chat local: `http://127.0.0.1:3002`
- Reverb local: `ws://127.0.0.1:8090`
- TV local: configurar por ambiente quando o canal estiver ativo
- Totem local: configurar por ambiente quando o canal estiver ativo

Em producao, estas URLs devem vir do ambiente e nao do codigo.

## CORS

- Desenvolvimentos locais podem usar allowlist explicita para os canais conhecidos e padrao local para `localhost`/`127.0.0.1` durante desenvolvimento.
- Producao deve listar explicitamente os dominios aprovados.
- Wildcard aberto nao e permitido como configuracao final.

## Logs

- Logs da aplicacao: `backend/storage/logs`
- Logs de acesso do Apache/Nginx: dentro do mesmo storage ou destino equivalente controlado
- Nenhum log sensivel deve ser publicado por rota web

## Filas

- Fila inicial recomendada: `sync` enquanto nao houver jobs assíncronos
- Se surgirem jobs em background, evoluir para `database` ou `redis`
- Worker em Windows e Linux so passa a ser necessario quando a fila deixar de ser `sync`

## Scheduler

- Windows: Task Scheduler chamando `php artisan schedule:run`
- Linux: cron a cada minuto

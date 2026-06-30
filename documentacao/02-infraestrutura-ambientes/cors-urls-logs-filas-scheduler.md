# CORS, URLs, Logs, Filas, Cache/Sessao e Scheduler

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

- Local (Windows/XAMPP): a partir de 2026-06-30 a fila padrao e `database` (`QUEUE_CONNECTION=database`), pois ja existem jobs assincronos reais (ex.: `SendWhatsappMessageJob`) que antes rodavam de forma sincrona e bloqueante dentro da requisicao HTTP. Isso substitui a decisao anterior (2026-06-25) de manter `sync` localmente por praticidade. Rodar `php artisan queue:work` em um terminal separado para que jobs enfileirados sejam processados; sem o worker ativo, os jobs ficam apenas na tabela `jobs` e nao sao executados. Redis nao esta instalado localmente (sem extensao phpredis, sem servidor na porta 6379).
- Producao: `QUEUE_CONNECTION=redis` ja e o padrao planejado desde 2026-06-25 (`backend/.env.production.example`), com worker via `infra/linux/supervisor-queue-worker.conf` (`php artisan queue:work --sleep=3 --tries=3 --max-time=3600`, 2 processos). Nao alterado nesta entrega.

## Cache e Sessao

- Local (Windows/XAMPP): a partir de 2026-06-30 `CACHE_STORE=database` e `SESSION_DRIVER=database` (antes `file`), pois o driver `file` nao funciona corretamente quando ha mais de um worker/processo PHP atendendo a aplicacao (cache e sessao ficam isolados por processo). Tabelas `cache`, `cache_locks` e `sessions` criadas via `php artisan cache:table` e `php artisan session:table`. Nenhuma instalacao adicional necessaria (usa a mesma conexao MySQL ja configurada).
- Producao: `SESSION_DRIVER=redis` ja e o padrao planejado desde 2026-06-25. `CACHE_STORE` tambem deveria ser `redis`, mas `backend/.env.production.example` tinha o nome de variavel errado (`CACHE_DRIVER`, nao lido por esta versao do Laravel) ate ser corrigido em 2026-06-30 (ver `documentacao/07-novas-implementacoes/2026-06-30-otimizacao-performance-backend-desktop.md`) - conferir/corrigir o `.env` real do VPS, que nao esta neste checkout.

## Scheduler

- Windows: Task Scheduler chamando `php artisan schedule:run`
- Linux: cron a cada minuto

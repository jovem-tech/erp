# Infraestrutura Linux

Templates para producao em VPS.

Arquivos principais:

- `nginx-site.conf`: reverse proxy/vhost do backend (inclui proxy WebSocket em
  `/app/` para o Reverb).
- `cron-scheduler.example`: exemplo de cron para o scheduler do Laravel.
- `supervisor-queue-worker.conf`: worker da fila (`php artisan queue:work`),
  necessario porque `.env.production` usa `QUEUE_CONNECTION=redis`.
- `supervisor-reverb.conf`: processo do servidor WebSocket da Central de
  Atendimento (`php artisan reverb:start`, porta 8090 — ver
  specs/010-inbox-whatsapp-tempo-real/plan.md). Sem isso, tempo real das
  conversas nao funciona em producao.

Recomendacao operacional:

- publicar apenas `backend/public`
- manter permissao de escrita em `backend/storage`
- em producao, copiar `supervisor-queue-worker.conf` para
  `/etc/supervisor/conf.d/` e rodar `supervisorctl reread && supervisorctl update`;
  sem isso, jobs enfileirados (ex.: e-mail de redefinicao de senha) nunca sao
  processados
- em desenvolvimento local (`.env` com `QUEUE_CONNECTION=sync`), o Supervisor
  nao e necessario — os jobs executam de forma sincrona, dentro da própria
  requisicao

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
- Ambientes oficiais Linux: `QUEUE_CONNECTION=redis`, com dois workers do Supervisor consumindo prioritariamente `documents,default`.
- O scheduler executa a cada minuto um worker limitado (`--max-jobs=50`, `--max-time=55`, `--sleep=1`) como rede de seguranca. Ele permanece ouvindo as filas durante quase todo o minuto, reduzindo a latencia do fallback para poucos segundos, e nao substitui o Supervisor.
- O Redis usa `retry_after=180s`, acima do timeout documental de `120s`, para que um lote ainda em execucao nao seja reservado simultaneamente por outro worker.
- O job documental grava erro sanitizado quando esgota as tentativas. O destino de WhatsApp/e-mail fica somente na coluna criptografada; os metadados nao podem manter uma segunda copia em texto puro.

Validacao operacional minima apos qualquer deploy:

```bash
sudo supervisorctl status
php artisan schedule:list
php artisan queue:failed
```

Os dois processos `sistema-erp-queue-worker_*` devem aparecer como `RUNNING`. O script `scripts/bash/atualizar-dev.sh` deve falhar explicitamente se essa pos-condicao nao for atendida; reinicio de fila nao pode ser ocultado com `|| true`.

## Cache e Sessao

- Local (Windows/XAMPP): a partir de 2026-06-30 `CACHE_STORE=database` e `SESSION_DRIVER=database` (antes `file`), pois o driver `file` nao funciona corretamente quando ha mais de um worker/processo PHP atendendo a aplicacao (cache e sessao ficam isolados por processo). Tabelas `cache`, `cache_locks` e `sessions` criadas via `php artisan cache:table` e `php artisan session:table`. Nenhuma instalacao adicional necessaria (usa a mesma conexao MySQL ja configurada).
- Producao: `SESSION_DRIVER=redis` ja e o padrao planejado desde 2026-06-25. `CACHE_STORE` tambem deveria ser `redis`, mas `backend/.env.production.example` tinha o nome de variavel errado (`CACHE_DRIVER`, nao lido por esta versao do Laravel) ate ser corrigido em 2026-06-30 (ver `documentacao/07-novas-implementacoes/2026-06-30-otimizacao-performance-backend-desktop.md`) - conferir/corrigir o `.env` real do VPS, que nao esta neste checkout.

### Divergencia medida em 2026-08-27 (v5.60.0.0) — pendente

Os dois pontos acima descrevem o PLANO. A auditoria de desempenho conferiu o
`.env` real do servidor de desenvolvimento (`192.168.1.100`) e encontrou:

| App | `SESSION_DRIVER` | `CACHE_STORE` | `QUEUE_CONNECTION` |
|---|---|---|---|
| `backend/` | `redis` OK | `redis` OK | `redis` OK |
| `frontends/desktop/` | **`file`** | **`file`** | **`sync`** |

O desktop nunca migrou. Isso importa porque o `FileSessionHandler` do Laravel
grava com `LOCK_EX`: requisicoes concorrentes **da mesma sessao** serializam,
entao uma requisicao lenta congela as outras abas e chamadas AJAX daquele mesmo
operador. E' o sintoma que o usuario descreve como "o sistema travou".

Agravantes medidos: `SESSION_LIFETIME=43200` (30 dias) com `lottery [2,100]`, e
770 arquivos em `storage/framework/sessions` — o GC varre o diretorio em 2% das
requisicoes. A sessao presa ao disco de um no' tambem impede escala horizontal.

O Redis ja esta instalado, com senha, em `127.0.0.1`, e o backend ja o usa: a
correcao nao exige infraestrutura nova, apenas trocar as tres chaves no
`frontends/desktop/.env`. Nao foi aplicada porque o escopo daquela entrega era
codigo; ver `07-novas-implementacoes/2026-08-27-gargalos-travamento-operacao-intensa.md`.

## Scheduler

- Windows: Task Scheduler chamando `php artisan schedule:run`
- Linux: cron a cada minuto — `/etc/cron.d/sistema-erp-scheduler`, como `www-data`

### Mapa de horários

Antes de agendar qualquer coisa nova, confira aqui: colisões de horário em
tarefas pesadas (dump, varredura de arquivos) competem por I/O e por lock.

| Horário | Tarefa | Onde |
|---|---|---|
| a cada minuto | `queue:work` (rede de segurança do Supervisor) | `routes/console.php` |
| a cada minuto | `app:dispatch-pending-document-signature-notifications` (5 min) | `routes/console.php` |
| a cada minuto | `file-manager:sync --pending` | `routes/console.php` |
| a cada minuto | **`backup:executar --pendente`** | `routes/console.php` |
| a cada 5 min | `file-manager:sync` | `routes/console.php` |
| a cada 15 min | `app:process-pending-os-collections` | `routes/console.php` |
| a cada 15 min | **`backup:varrer`** (catálogo unificado) | `routes/console.php` |
| de hora em hora | `app:notify-order-deadlines`, `app:expire-budgets` | `routes/console.php` |
| diário | `sanctum:prune-expired --hours=24` | `routes/console.php` |
| **02:00** | `erp-backup.sh` — dump só de `sistema_hml` | `/etc/cron.d/sistema-erp-backup` (root) |
| **02:30** | `file-manager:purge-trash` | `routes/console.php` |
| **02:40** | `file-manager:purge-scan-runs` (retenção do histórico de varreduras) | `routes/console.php` |
| **03:15** | **`backup:executar --tipo=completo`** | `routes/console.php` |
| **03:50** | **`backup:expurgar`** (retenção) | `routes/console.php` |

### Backup não usa fila

`backup:executar` roda pelo scheduler com `runInBackground()`, e não como job.
Dois motivos, ambos duros:

- `max_execution_time = 60` nos pools PHP-FPM impede execução síncrona;
- `retry_after = 180` na conexão `redis` (`config/queue.php`) re-reservaria um
  backup de mais de 3 minutos, fazendo-o **rodar duas vezes em paralelo**; e o
  Supervisor consome apenas `--queue=documents,default`, então uma fila nova
  exigiria alterar `/etc/supervisor/conf.d/` como root.

Lock compartilhado: `/var/lock/sistema-erp-backup.lock` (`flock` não bloqueante).
Ao mexer em `scripts/bash/deploy-producao.sh`, considere tomar o mesmo lock antes
de migrar — migração durante um dump quebra o `--single-transaction`.

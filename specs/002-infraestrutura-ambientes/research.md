# Research: Infraestrutura de Desenvolvimento e Produção

## Decisoes consolidadas

### 1. URLs por ambiente

As URLs devem ser derivadas de variaveis de ambiente. Isso evita que o frontend ou o backend dependam de caminhos fixos do Windows.

### 2. CORS por allowlist

Produção nao deve usar `*` como origem liberada. Cada frontend precisa estar listado explicitamente.

### 3. Logs e storage do Laravel

O armazenamento seguro permanece em `backend/storage/app/private` e os logs em `backend/storage/logs`, usando os helpers do Laravel quando o backend entrar em producao.

### 4. Filas e scheduler

Como nao ha jobs assíncronos previstos no curto prazo, o modo `sync` reduz infraestrutura e elimina a necessidade imediata de worker em segundo plano.

- filas com driver `sync` enquanto nao houver processamento em background
- scheduler via Task Scheduler no Windows
- scheduler via cron no Linux

Quando surgirem jobs assíncronos reais, a plataforma pode evoluir para `database` ou `redis` com worker dedicado.

### 5. Deploy e vhost

O servidor web deve apontar para `backend/public` e nunca para a raiz do projeto. Em Linux, reverse proxy e/ou vhost devem ser documentados como modelo de producao.

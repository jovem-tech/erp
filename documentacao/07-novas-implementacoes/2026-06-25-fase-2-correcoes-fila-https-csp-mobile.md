# Fase 2 da auditoria: fila assíncrona, HTTPS, CSP e limpeza no mobile

## Contexto

- versao: `3.1.17`
- data: `2026-06-25`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

Continuação da auditoria completa de 2026-06-25
(`2026-06-25-auditoria-completa-e-correcoes-de-seguranca.md`), cobrindo os
itens da Fase 2 do plano de ação, mais a descontinuação do
`frontend/sistema-hml/` decidida pelo responsável pelo sistema (registrada em
nota separada, `2026-06-25-descontinuacao-frontend-sistema-hml.md`).

### Fila assíncrona real no backend

- criadas as tabelas `jobs` e `failed_jobs` via `php artisan queue:table` /
  `queue:failed-table`;
- `FrontendPasswordResetNotification` passou a `implements ShouldQueue` (uso
  de `Queueable`) — o envio de e-mail de redefinição de senha deixa de
  bloquear a requisição HTTP quando a fila não é `sync`;
- criado `infra/linux/supervisor-queue-worker.conf`, já que
  `backend/.env.production` configura `QUEUE_CONNECTION=redis` mas não havia
  nenhum processo definido para consumir a fila — sem isso, jobs enfileirados
  nunca seriam processados em produção;
- `infra/linux/README.md` atualizado com o passo de instalação do Supervisor;
- o `.env` local de desenvolvimento foi mantido em `QUEUE_CONNECTION=sync`
  deliberadamente, por praticidade (evita exigir um worker rodando para
  qualquer teste manual local).

### Limpeza ligada à descontinuação do sistema-hml

- removida a opção `frontend=sistema-hml` de `ForgotPasswordRequest`
  (validação `in:desktop` apenas), simplificada
  `FrontendPasswordResetNotification` (sempre usa a URL do desktop) e
  removida a entrada `frontend_sistema_hml` de `config/services.php` e dos
  arquivos `.env.example`/`.env.production`;
- teste `test_forgot_password_uses_sistema_hml_frontend_url_when_requested`
  substituído por `test_forgot_password_rejects_decommissioned_sistema_hml_frontend`,
  confirmando que o valor agora é rejeitado com 422.
- confirmado, por investigação direta, que `config/filesystems.php` (disco
  `legacy_public`) e `OrderWorkflowService::legacyPublicRootPath()`
  referenciam uma instalação **separada e original** do legado em
  `C:\xampp\htdocs\sistema-hml` (fora do monorepo, com seu próprio `.git`),
  não a cópia `sistema-erp/frontend/sistema-hml/` que foi arquivada — nenhuma
  funcionalidade de fallback de arquivos legados foi afetada.

### HTTPS / ForceHttps

- `app/Http/Middleware/ForceHttps.php` existia, mas nunca tinha sido
  registrado em nenhum kernel/middleware stack — era código morto. Registrado
  via `$middleware->append(ForceHttps::class)` em `bootstrap/app.php`. O
  middleware já se autodesabilita em `local`/`testing`, então não há nenhum
  efeito no ambiente de desenvolvimento atual; em produção, força HTTPS e
  adiciona `Strict-Transport-Security`.

### Busca de clientes (decisão de adiar)

- avaliado o achado de "full table scan" na busca de clientes
  (`ClientController::index`, 17 colunas em `LIKE '%termo%'`): já existem
  índices em `nome_razao`, `cpf_cnpj` e `telefone1` (cobrem ordenação e
  lookups exatos), mas nenhum índice ajuda um `LIKE` com `%` à esquerda.
  Decisão: **não reescrever agora** para full-text. Motivos: (1) a sintaxe
  `MATCH() AGAINST()` é específica do MySQL e quebraria a suíte de testes,
  que roda em SQLite; (2) a tabela `clientes` tem ~1.300 registros hoje — uma
  varredura completa nessa escala é da ordem de milissegundos, não um
  problema real de performance; (3) mudar o comportamento de busca sem testes
  de equivalência rigorosos arrisca regressão silenciosa numa tela usada
  todos os dias. Recomendação registrada para quando o volume de clientes
  crescer significativamente (ordem de 10k+).

### Mobile

- `next.config.ts`: removido `'unsafe-inline'` de `style-src` — confirmado,
  inspecionando o HTML de um build de produção real, que a aplicação não usa
  nenhum `<style>` inline (sem Tailwind, sem styled-jsx). `script-src`
  **manteve** `'unsafe-inline'` deliberadamente: o mesmo teste de build
  mostrou que o App Router do Next.js hidrata via `<script>` inline real
  (`self.__next_f.push(...)`), não apenas dados JSON — remover sem implementar
  o padrão de nonce por requisição documentado pelo Next.js quebraria a
  aplicação inteira. Comentário explicativo deixado no código.
- breakpoints em `globals.css` realinhados de `960px`/`640px` para
  `992px`/`768px`, os valores mais próximos do conjunto exigido pela
  constituição (`1280/992/768/430/390/360/320`).
- removido `src/pages/` (`_app.tsx`/`_document.tsx`, boilerplate do Pages
  Router sem nenhuma rota real) — confirmado via build que a seção
  "Route (pages)" desaparece sem nenhum efeito colateral.
- corrigido um falso positivo da auditoria anterior: o achado "sem
  `package-lock.json`" estava errado — o projeto usa `pnpm` (o próprio
  `README.md` raiz já documentava isso) e `pnpm-lock.yaml` está presente e
  atualizado.

## Impactos

- Backend: nenhuma mudança de contrato de API. `password/forgot` agora
  rejeita `frontend=sistema-hml` com 422 em vez de aceitar.
- Backend/infra: produção passa a exigir um worker Supervisor rodando para
  processar a fila — documentado em `infra/linux/README.md`; sem isso, e-mails
  de redefinição de senha ficariam enfileirados sem nunca serem enviados.
- Mobile: CSP mais restritiva para estilos; nenhuma mudança de comportamento
  visual esperada (nenhum estilo inline existia).

## Validacao

- Backend: `php artisan test` completo — 48 passando, 2 falhando (os mesmos
  2 pré-existentes do SQLite/`YEAR()`, sem relação).
- Backend: `php -l` em todos os arquivos alterados; `curl /up` local
  confirmando que `ForceHttps` não afeta ambiente local (200, sem redirect).
- Mobile: `npx tsc --noEmit` limpo; `next build` de produção bem-sucedido
  antes e depois de cada mudança (CSP, breakpoints, remoção de `src/pages/`);
  inspeção manual do HTML renderizado de `/`, `/login` e `/os` em servidor de
  produção local confirmando ausência de `<style>` inline.

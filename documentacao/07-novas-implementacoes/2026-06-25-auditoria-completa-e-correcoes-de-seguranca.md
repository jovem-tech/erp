# Auditoria completa do sistema-erp e correções de segurança (Fase 0 e Fase 1)

## Contexto

- versao: `3.1.14`
- data: `2026-06-25`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

Auditoria completa de arquitetura, estrutura, segurança, escalabilidade,
latência, boas práticas, padronização e documentação em todo o monorepo
(`backend/`, `frontends/desktop/`, `frontends/mobile/`,
`frontend/sistema-hml/`). Nota geral atribuída: **3,5/10** — uma autoavaliação
anterior do projeto (`IMPLEMENTATION_COMPLETE.md`, 2026-06-23) alegava 10/10;
não se sustentou na verificação contra o código real (ver detalhes na
auditoria completa, registrada nesta sessão).

Correções aplicadas (Fase 0 — crítico, hoje):

- isolados (fora de qualquer diretório acessível pela web e fora do contexto
  de build do Docker, em `_quarentena-seguranca-2026-06-25/`, pasta nunca
  versionada) 18 scripts administrativos/debug do `frontend/sistema-hml`:
  5 deles estavam dentro de `public/` e eram executáveis diretamente via
  HTTP sem nenhuma autenticação (`setup_rbac.php`, `run_migrations.php`,
  `fix_password.php` — resetava a senha do admin para `admin123` —,
  `test_db.php`, `phpinfo.php`); os outros 13 ficavam na raiz do app com
  credenciais de banco hardcoded;
- `database.sql` do `sistema-hml`: removido o hash de senha padrão
  documentado, substituído por instrução explícita de gerar senha forte
  após qualquer restauração futura;
- `.gitignore` reforçado e varredura ampla de segredos em todo o
  repositório antes de qualquer commit;
- confirmado e registrado como pendência: `admin@sistema.com` usa a senha
  fraca `admin123` em `sistema_erp` e `sistema_hml` — troca deferida por
  decisão explícita do responsável pelo sistema, não foi alterada nesta
  sessão;
- commit inicial do monorepo (exceto `frontend/sistema-hml`, deixado de
  fora por decisão explícita) ainda pendente — falta apenas configurar
  identidade do git (`git config user.name`/`user.email`) na máquina.

Correções aplicadas (Fase 1 — esta semana, executadas hoje):

- `backend/app/Services/EquipmentWorkflowService.php`: mensagens de exceção
  do coletor local pararam de hardcodar `C:\JovemTechBenchCollector` e agora
  interpolam o caminho real configurado (`getCollectorLocalRootPath()`);
  adicionada guarda `isWindowsHost()` em `readLocalCollectorSnapshot()`
  (faltava, só existia em `runLocalCollectorCapture()`), para que a leitura
  de snapshot também falhe com mensagem clara em ambiente não-Windows;
- `backend/app/Models/User.php`: reaplicada a remoção de
  `token_recuperacao`/`token_expiracao` de `$fillable` — confirmado que
  nenhum controller/service usa esses campos via mass assignment, fluxo de
  reset de senha usa o mecanismo padrão do Laravel;
- `frontends/mobile/src/lib/api.ts`: removida a duplicação de `apiLogout`
  (existiam duas declarações; a segunda, que "vencia" em runtime, não
  limpava a sessão local após logout) e de refresh (`apiRefreshToken` morto,
  sem nenhum chamador, removido; `apiRefresh`, o realmente usado por
  `session-provider.tsx`, mantido). Removido também `src/lib/api-types.ts`
  (arquivo morto, nunca importado, com 23 erros de exportação conflitante
  que quebravam o type-check do projeto inteiro);
- comentários enganosos sobre "HttpOnly cookies" em `api.ts` corrigidos para
  refletir o mecanismo real (Bearer token lido do armazenamento local); a
  migração completa para cookie de sessão real foi avaliada e deliberadamente
  **não** executada nesta fase — envolve CSRF e configuração cross-origin
  (mobile e backend em origens diferentes) que merece tratamento dedicado,
  não encaixado apressadamente numa fase de correção ampla;
- investigados os 2 testes (`EquipmentCreationTest`) reportados como
  novas falhas pela auditoria — não reproduziram nas execuções desta sessão
  (suíte completa do backend: 47 passando, 2 falhando — ambos pré-existentes
  e relacionados à incompatibilidade `YEAR()` do SQLite usado nos testes,
  sem relação com este trabalho).

## Impactos

- Segurança: elimina vetor de exploração direta (reset de senha sem auth,
  execução de migração, disclosure de `phpinfo`) hoje presente no
  `frontend/sistema-hml`.
- Backend: nenhuma mudança de contrato de API; correções restritas a
  mensagens de erro, guarda de ambiente e modelo de dados (remoção de campos
  do `$fillable`, sem efeito em fluxos existentes).
- Mobile: projeto volta a compilar (`tsc --noEmit`) sem erros; nenhuma
  mudança de comportamento de autenticação foi feita, apenas remoção de
  código morto e correção de documentação inline.
- Pendências explícitas para fases seguintes: rotação da senha do admin,
  commit inicial do repositório, decisão e implementação de uma estratégia
  real de autenticação por cookie no mobile (se for o caminho escolhido),
  fila assíncrona real no backend, limpeza adicional no `sistema-hml`.

## Validacao

- Backend: `php artisan test` (suíte completa) — 47 passando, 2 falhando
  (pré-existentes, sem relação); `php -l` nos arquivos alterados.
- Backend: `php artisan test --filter=Collector`, `--filter=Auth`,
  `--filter=Password` — todos passando após as correções específicas.
- Mobile: `npx tsc --noEmit -p tsconfig.json` — zero erros (antes: 27 erros,
  incluindo duplicação de função exportada); `npx eslint src/lib/api.ts` —
  sem apontamentos.
- Verificação manual via PHP/mysqli (sem expor segredo no console) de que a
  senha de `admin@sistema.com` permanece `admin123` em ambos os bancos —
  pendência registrada, não corrigida nesta sessão por decisão do
  responsável pelo sistema.

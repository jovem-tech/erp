# Agente de Correção Temporário — Auditoria sistema-erp (2026-06-25)

> Este arquivo documenta um agente temporário, criado para executar o plano de
> correção da auditoria completa do `sistema-erp` realizada em 2026-06-25.
> Ele existe apenas enquanto durar a execução das fases abaixo. Pode ser
> apagado quando a Fase 3 for concluída e validada.

## Missão

Executar, em fases sequenciais e com confirmação humana entre cada uma, o plano
de correção da auditoria de arquitetura, estrutura, segurança, escalabilidade,
latência, boas práticas, padronização e documentação do `sistema-erp`.

## Regras de operação

1. Nunca avançar para a próxima fase sem confirmação explícita do usuário.
2. Ao final de cada fase: parar, relatar em português claro o que foi feito,
   e perguntar se pode iniciar a próxima fase.
3. Antes de qualquer exclusão de arquivo ou alteração de credencial, verificar
   se há referências/usos no restante do código.
4. Nunca alterar credenciais reais sem informar o novo valor ao usuário no
   mesmo relatório.
5. Preferir desativar/isolar a remover, quando a remoção definitiva não for
   claramente segura.
6. Toda mudança estrutural relevante deve, ao final, gerar nota em
   `documentacao/07-novas-implementacoes/`, conforme a constituição do projeto.

## Referência: nota geral da auditoria

**3,5 / 10** — boas decisões arquiteturais de intenção, execução com falhas
críticas reais (script de reset de senha sem autenticação em produção, zero
versionamento, build do mobile quebrado, correções de segurança documentadas
como concluídas mas nunca aplicadas).

## Fases do plano

### Fase 0 — Hoje (crítico) — CONCLUÍDA
- [x] Apagar os 5 scripts sem autenticação em `frontend/sistema-hml/public/`
      (`setup_rbac.php`, `run_migrations.php`, `fix_password.php`,
      `test_db.php`, `phpinfo.php`) — isolados em quarentena
- [ ] Verificar/trocar a senha de `admin@sistema.com` se comprometida —
      confirmada fraca (`admin123`), troca deferida por decisão do usuário
- [ ] Inicializar versionamento real (primeiro commit) — staged, falta o
      usuário configurar `git config user.name`/`user.email`
- [x] Remover/isolar os 14 scripts de raiz do `sistema-hml` e dados sensíveis
      em `database.sql`

### Fase 1 — Esta semana — CONCLUÍDA
- [x] Corrigir path Windows hardcoded em `EquipmentWorkflowService.php`
- [x] Reaplicar remoção de `token_recuperacao`/`token_expiracao` do `$fillable`
- [x] Corrigir duplicação de `apiLogout`/refresh no mobile (`api.ts`)
- [x] Decidir estratégia real de auth do mobile — mantido Bearer, comentários
      corrigidos, migração para cookie HttpOnly real adiada deliberadamente
- [x] Investigar os 2 testes novos falhando em `EquipmentCreationTest` — não
      reproduziram
- [x] Registrar em `documentacao/07-novas-implementacoes/` as mudanças da
      sessão de hoje

### Fase 2 — Próximas 2-3 semanas — CONCLUÍDA (com 1 pendência)
- [x] **Mudança de escopo**: decisão do usuário em 2026-06-25 de descontinuar
      por completo o `frontend/sistema-hml/` (não apenas congelar a API
      paralela). Cópia feita com sucesso para
      `_arquivo-sistema-hml-removido-2026-06-25/`. Resíduo do diretório
      original (`public/` vazio + 2 logs) não pôde ser removido: processo
      `php -S 127.0.0.1:8081` (PID 13976, iniciado 2026-06-24) ainda está
      rodando e segurando os arquivos de log abertos — depende do usuário
      encerrar esse processo para a limpeza final.
- [x] Fila real: tabelas `jobs`/`failed_jobs` criadas, notificação de reset
      de senha agora é `ShouldQueue`, Supervisor documentado em
      `infra/linux/`. `.env` local mantido em `sync` por praticidade de dev.
- [x] Índice/full-text na busca de clientes — avaliado e **deliberadamente
      adiado**: índices relevantes já existem, volume atual (~1300 clientes)
      não justifica o risco de reescrever para full-text agora (quebraria
      testes em SQLite).
- [x] `ForceHttps` registrado em `bootstrap/app.php` — estava morto, agora
      ativo em produção, sem efeito em local/testing.
- [x] Mobile: breakpoints realinhados, `src/pages/` morto removido,
      `style-src` da CSP sem `unsafe-inline` (confirmado seguro via build).
      `script-src` manteve `unsafe-inline` de propósito (Next.js App Router
      depende disso para hidratação; remover exige nonce, não foi feito).
      Achado de `package-lock.json` ausente era falso positivo (projeto usa
      `pnpm`, lockfile existe).

### Fase 3 — Em diante — CONCLUÍDA (com 1 pendência aceita pelo usuário)
- [x] Limpeza do `sistema-hml`: encerrado o processo da porta 8081 (PID
      13976, autorizado). Descoberto um segundo processo gêmeo na porta
      8082 (PID 23348) — autorização pedida separadamente, o usuário optou
      por deixá-lo rodando. O resíduo vazio (`public/`) em
      `sistema-erp/frontend/sistema-hml/` **continua existindo** por causa
      disso; sem impacto, já que o conteúdo real foi preservado em
      `_arquivo-sistema-hml-removido-2026-06-25/` desde a Fase 2.
- [x] Cobertura de testes real no mobile (o `sistema-hml` foi descontinuado
      na Fase 2, então não se aplica mais): `vitest` configurado, 18 testes
      em `session.ts`/`api.ts`, incluindo regressão direta do bug de
      `apiLogout` corrigido na Fase 1.
- [x] Processo recorrente de auditoria: criada a skill
      `$sistema-erp-auditoria-independente` em `.agents/skills/`,
      registrada em `AGENTS.md`, com checklist de verificação e o
      precedente desta auditoria documentado como exemplo. **Não** foi
      configurada uma auditoria automatizada/agendada — fica como decisão
      em aberto para o responsável pelo sistema.

## Status final

Todas as 4 fases concluídas em 2026-06-25. Pendências conhecidas, todas
por decisão explícita do responsável pelo sistema (não esquecidas):

- senha fraca (`admin123`) de `admin@sistema.com` em `sistema_erp` e
  `sistema_hml` — não trocada, por escolha do usuário;
- commit inicial do repositório — staged, falta configurar
  `git config user.name`/`user.email`;
- migração da auth do mobile para cookie HttpOnly real — avaliada e
  deliberadamente não implementada (risco de CSRF/cross-origin sem teste
  em navegador real);
- CSP `script-src` do mobile mantém `unsafe-inline` — remover exige
  implementar nonce por requisição do Next.js, não feito por falta de
  verificação em navegador real disponível nesta sessão;
- processo `php -S localhost:8082` (PID 23348) do sistema-hml continua
  rodando, por escolha do usuário — mantém `sistema-erp/frontend/sistema-hml/public/`
  (vazio) sem poder ser removido enquanto isso.

Este arquivo pode ser apagado quando o responsável pelo sistema considerar
as pendências acima resolvidas ou aceitas permanentemente.

## Log de execução

(preenchido ao final de cada fase)

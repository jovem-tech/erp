# Tarefas — Backup e restauração do sistema

Legenda: `[x]` entregue · `[ ]` pendente.

## Fase 1 — catálogo e motor (entregue em 5.40.0.0)

### Configuração e domínio
- [x] `backend/config/backup.php` — raízes, exclusões, cifra, retenção, interruptores
- [x] `app/Enums/Backups/` — `BackupStatus`, `BackupType`, `BackupOrigin`, `BackupContent`, `DestinationDriver`
- [x] `app/Models/Backups/` — `Backup`, `BackupDelivery`, `BackupRestore`

### Banco
- [x] `2026_08_22_000001_create_backup_infrastructure.php`
- [x] `2026_08_22_000002_seed_backup_module_permissions.php`
- [x] `2026_08_22_000003_grant_backup_permissions_to_super_admin.php`
- [x] `'backups'` em `RbacAuthorizationService::DEFAULT_MODULES`

### Serviços
- [x] `Contracts/ProcessRunner` + `SymfonyProcessRunner` (com `pipefail`)
- [x] `BackupRootRegistry` — ID lógico → caminho atual
- [x] `BackupSettingsService` — chave/valor em `configuracoes`
- [x] `BackupPassphraseResolver` — hash, cópia cifrada, impressão
- [x] `ArchiveCipher` — fragmentos de shell do openssl
- [x] `DatabaseDumpService` — sondagem, auditoria de engine, dump, prova de rodapé
- [x] `FileTreeArchiveService` — walker, índice por arquivo, tar
- [x] `ConfigSnapshotService` — `.env`, `VERSION`, procedência
- [x] `BackupManifestBuilder`
- [x] `BackupPreflight`
- [x] `BackupRunner` — orquestrador com `flock`
- [x] `BackupDiscoveryService` — catálogo unificado
- [x] `BackupRetentionPolicy` — 7/4/6 com piso duro
- [x] `BackupVerificationService` — dois formatos

### Comandos e agenda
- [x] `backup:executar`, `backup:varrer`, `backup:verificar`, `backup:expurgar`
- [x] Agenda em `routes/console.php` (03:15, 03:50, 15 min, 1 min)

### API e interface
- [x] `Api/V1/BackupController` — 12 operações
- [x] `BackupDownloadController` — rota assinada em `routes/web.php`
- [x] Desktop: `BackupService` (proxy puro), `BackupController`, 9 rotas
- [x] Aba **Backup** em `configurations/system.blade.php` + partials

### Testes
- [x] `BackupRetentionPolicyTest` — 5
- [x] `BackupDiscoveryTest` — 5
- [x] `BackupManifestAndPassphraseTest` — 7
- [x] `BackupApiTest` — 11
- [x] `BackupPanelTest` (desktop) — 5
- [x] Ensaio manual: pacote aberto só com `openssl` e `tar`

### Documentação
- [x] `spec.md`, `plan.md`, `tasks.md`
- [x] `documentacao/03-arquitetura-tecnica/backup-e-restauracao.md`
- [x] `documentacao/10-deploy/operacao-backup-e-restauracao.md`
- [x] Seção "Grupos de administração total" em `backend-administrativo-rbac.md`
- [x] Mapa de horários em `cors-urls-logs-filas-scheduler.md`
- [x] Ponteiro em `01-fundacao/acesso-seguro-a-arquivos.md`
- [x] Nota de release + `historico-de-versoes.md`
- [x] `backend/openapi.yaml` — 9 paths
- [x] Índices dos READMEs de `03-` e `10-`

### Pendências operacionais (exigem root)
- [ ] `install -d -o www-data -g www-data -m 0700 /var/backups/sistema-erp/erp`
- [ ] `chown -R www-data:www-data backend/storage/app/backups` + `chmod 750`
- [ ] Definir a frase secreta no painel
- [ ] Mesmos passos na VPS, no próximo deploy de produção

## Fase 2 — restauração e HD externo

- [ ] `RestorePlanner` / `RestoreRunner` / `DatabaseRestoreService` / `FileTreeRestoreService`
- [ ] `backup:restaurar` com `--simulacao` (o caminho de desastre real)
- [ ] Bloqueio por incompatibilidade: pacote mais antigo que o schema em execução
- [ ] Step-up com `AdminCredentialVerifier` + confirmação por digitação
- [ ] Backup de segurança automático antes de escrever; aborta se falhar
- [ ] Restauração de banco por schema de rascunho + `RENAME TABLE` atômico
  - [ ] `GRANT ALL ON \`sistema_hml_restauracao_%\`.* TO 'erp_app'@'localhost'`
- [ ] `.env` extraído para revisão manual, **nunca** aplicado automaticamente
- [ ] Árvore legada: mesclar por padrão; `--substituir` renomeia, nunca apaga
- [ ] `MountedPathDestination` com validação por `findmnt --target`
- [ ] `deploy-producao.sh` toma `/var/lock/sistema-erp-backup.lock` antes de migrar
- [ ] Primeiro ensaio de restauração ponta a ponta
- [ ] Só então: decidir sobre aposentar `/etc/cron.d/sistema-erp-backup`

## Fase 3 — nuvem

- [ ] `apt install rclone` na bancada e na VPS
- [ ] `rclone config` com client OAuth próprio, escopo `drive.file`
- [ ] `/var/lib/sistema-erp/rclone.conf` como `0600 www-data`
- [ ] `RcloneRemoteDestination` + retenção no remoto + teste com espaço livre
- [ ] `backup:importar` para pacotes trazidos de fora por `scp`

## Fora deste escopo, mas registrado

- [ ] **Retenção para `file_scan_runs`** — 319,5 MB dos 441 MB do banco (72%),
      crescendo 11.232 linhas/dia sem política nenhuma. O backup contorna
      copiando só a estrutura, mas a tabela passa de 1 GB em três meses.
- [ ] **Grant de leitura em `sistema_erp_chat`** para `erp_app`, se o banco
      existir de fato em produção. É a mesma causa do erro que trava o
      `artisan migrate`.
- [ ] **Colisão `Http/Middleware/SecurityHeaders.php`** entre desktop e backend,
      apontada por `ClassIsolationTest` (falha pré-existente).

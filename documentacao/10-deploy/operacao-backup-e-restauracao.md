# Operação — Backup e restauração

Runbook do sistema de backup completo (banco + arquivos + configuração).
Especificação: `specs/031-backup-restauracao-sistema/spec.md`.

## O que entra no pacote

| Componente | Origem | Observação |
|---|---|---|
| `sistema_hml` | conexão `mysql` | `--single-transaction`; 200 tabelas InnoDB |
| `sistema_erp_chat` | conexão `chat` | sondado; ausente vira aviso, não falha |
| `backend_privado` | `storage/app/private` | orçamentos, assinaturas, docs de OS, chat-media |
| `legado_uploads` | disco `legacy_public` + `/uploads` | fotos de OS, equipamentos, usuários |
| `coletor_bancada` | `storage/app/bench-collector` | opcional |
| `configuracao` | `.env` × 2, `VERSION`, `composer.lock` | contém `APP_KEY` |

**Fora do pacote:** certificados TLS (Let's Encrypt renova sozinho na VPS;
autoassinado se regenera na bancada), `storage/logs`, `storage/framework`,
`file-thumbnails` (regenerável), `storage/app/backups` (evita recursão),
`node_modules`, `vendor`.

**Só estrutura, sem dados:** `file_scan_runs` e `file_scan_findings` —
telemetria do scanner, 72% do banco, regenerável.

## Provisionamento (uma única vez, como root)

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/backups/sistema-erp/erp
sudo chown -R www-data:www-data /var/www/sistema-erp/backend/storage/app/backups
sudo chmod 750 /var/www/sistema-erp/backend/storage/app/backups
```

São os únicos passos privilegiados. Não há sudoers, ACL nem helper com
privilégio: em runtime o sistema roda apenas como `www-data`.

## Primeiro uso

1. **Definir a frase secreta** em Configurações → Backup.
   Ela cifra o pacote. **Guarde-a fora do servidor** — sem ela nada é
   recuperável, por ninguém.
2. **Sincronizar** para catalogar o que já existe no disco.
3. **Gerar backup agora** e acompanhar o progresso.

## Comandos

```bash
# sempre como www-data: as pastas privadas sao 0700 www-data e um backup
# rodado com o usuario errado omite arvores inteiras
sudo -u www-data php /var/www/sistema-erp/backend/artisan backup:executar --tipo=completo
sudo -u www-data php .../artisan backup:varrer          # cataloga o disco
sudo -u www-data php .../artisan backup:verificar <uuid|arquivo> [--frase=...]
sudo -u www-data php .../artisan backup:expurgar [--simulacao]
```

Saídas: `0` em sucesso (mesmo com avisos), `1` em falha — para monitoração
distinguir "rodou com ressalvas" de "não rodou".

## Agenda

| Horário | Comando | Observação |
|---|---|---|
| a cada minuto | `backup:executar --pendente` | atende o botão do painel |
| a cada 15 min | `backup:varrer` | catálogo unificado |
| **03:15** | `backup:executar --tipo=completo` | 02:00 e 02:30 já ocupados |
| 03:50 | `backup:expurgar` | retenção 7/4/6 |

Rodam pelo scheduler (`/etc/cron.d/sistema-erp-scheduler`, como `www-data`), com
`runInBackground()`. **Não usam fila**: `retry_after=180` no Redis faria um
backup longo ser re-reservado e rodar duas vezes.

## Restaurar SEM o sistema

O procedimento que importa. Quando você precisar dele, o painel provavelmente
estará fora do ar. Funciona em qualquer Linux, sem PHP.

```bash
# 1) abrir o envelope
tar -xvf erp-backup-AAAAMMDD-HHMMSS-xxxxxxxx.tar

# 2) guardar a frase temporariamente
umask 077; printf %s 'SUA-FRASE-SECRETA' > frase.txt

# 3) conferir a integridade ANTES de restaurar
sha256sum db/*.enc arquivos/*.enc configuracao/*.enc
cat manifest.json | python3 -m json.tool | less   # compare com "membros"

# 4) restaurar o banco
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha512 \
  -in db/sistema_hml.sql.gz.enc -pass file:frase.txt \
  | gunzip | mysql -u erp_app -p sistema_hml

# 5) restaurar os arquivos (nunca com --same-owner: o processo e www-data)
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha512 \
  -in arquivos/legado_uploads.tar.gz.enc -pass file:frase.txt \
  | tar -xzpv --no-same-owner -C /var/www/sistema-hml-legacy/public/uploads

# 6) apagar a frase
shred -u frase.txt
```

O mesmo texto vai impresso dentro de todo pacote, em `LEIA-ME-RESTAURACAO.txt`.

### Depois de restaurar

1. **Reemitir o certificado TLS** — não vem no pacote.
   VPS: `certbot renew --force-renewal`. Bancada: gerar novo autoassinado.
2. **Conferir o `APP_KEY`.** `configuracao/segredos.tar.gz.enc` traz o `.env` da
   época. Se o `APP_KEY` atual for diferente, as colunas criptografadas
   (`EncryptedSecret`) não abrem. Compare a impressão em `manifest.json` →
   `app_key_fingerprint`.
   **Nunca sobrescreva o `.env` de um servidor em uso automaticamente**: isso
   troca `DB_PASSWORD`, `APP_URL` e os interruptores `FILE_MANAGER_*` de uma vez.
   Extraia, compare chave a chave, aplique à mão.
3. Limpar caches **como www-data** e recatalogar:
   ```bash
   sudo -u www-data php .../artisan config:clear && ... cache:clear && ... view:clear
   sudo -u www-data php .../artisan file-manager:sync
   ```

## Verificação de integridade

`gzip -t` **não é suficiente**: um `mysqldump` que falha deixa um arquivo de 20
bytes que passa nele. A prova real é o rodapé `-- Dump completed`, que
`backup:verificar` confere junto com o sha256 de cada membro.

Verifique os backups periodicamente — inclusive os do cron antigo:

```bash
sudo -u www-data php .../artisan backup:verificar sistema_hml-AAAAMMDD-0200.sql.gz
```

## Catálogo unificado

O painel lista **todo** backup do servidor, não só os que ele gerou. Origens:

| Origem | De onde vem | Painel pode excluir? |
|---|---|---|
| `Painel` / `Agendado` | `/var/backups/sistema-erp/erp` | sim |
| `Cron 02:00` | `/var/backups/sistema-erp` (root:root) | **não** — a retenção é do cron |
| `Pré-deploy` | `deploy-producao.sh` | não |
| `Manual` | `storage/app/backups` | sim, após o `chown` |

## Coexistência com o cron antigo

`/etc/cron.d/sistema-erp-backup` (02:00, só `sistema_hml`) **continua ativo** e é
catalogado como fonte a mais. Só considere aposentá-lo depois do primeiro ensaio
de restauração bem-sucedido.

## Interruptores (`backend/.env`)

| Variável | Padrão | Efeito |
|---|---|---|
| `BACKUP_ENABLED` | `true` | desliga tudo, inclusive a agenda |
| `BACKUP_ALLOW_RESTORE` | `false` | restauração pelo painel (Fase 2) |
| `BACKUP_ALLOW_REMOTE` | `false` | destinos em nuvem (Fase 3) |
| `BACKUP_STORE_PATH` | `/var/backups/sistema-erp/erp` | onde os pacotes ficam |
| `BACKUP_DAILY_TIME` | `03:15` | horário do backup automático |
| `BACKUP_KDF_ITERATIONS` | `600000` | iterações do PBKDF2 |

## Problemas conhecidos

**"Diretório ilegível NÃO copiado"** — o backup está rodando com o usuário
errado. Rode como `www-data`.

**"O diretório de backups não existe"** — falta o passo de provisionamento.

**"Banco sistema_erp_chat não copiado: Access denied"** — esperado na bancada.
Se o banco existir de fato em produção:
```sql
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON `sistema_erp_chat`.* TO 'erp_app'@'localhost';
```

**Testes falhando de forma estranha** — o cache de config sobrescreve o
`CACHE_STORE=array` do phpunit. Limpe antes e recacheie depois:
```bash
php artisan config:clear && php artisan test && php artisan config:cache
```

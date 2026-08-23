# Backup completo do sistema

**Data:** 2026-08-22
**Versão:** 5.40.0.0
**Spec:** `specs/031-backup-restauracao-sistema/spec.md`
**Tipo:** módulo novo (MINOR)
**Arquitetura:** `documentacao/03-arquitetura-tecnica/backup-e-restauracao.md`
**Runbook:** `documentacao/10-deploy/operacao-backup-e-restauracao.md`

## Problema

O sistema não tinha backup completo. O cron de root das 02:00 dumpava **só** o
banco `sistema_hml`. Nunca tiveram cópia, nenhuma vez:

| O que | Tamanho |
|---|---|
| `backend/storage/app/private` — orçamentos, assinaturas, documentos de OS | 17 MB |
| `/var/www/sistema-hml-legacy/public/uploads` — fotos de OS, equipamentos, usuários | 59 MB |
| `.env` com o `APP_KEY` | — |
| Cópia fora do servidor | inexistente |

Perder o servidor significava perder as 670 imagens e PDFs do gerenciador de
arquivos, com sete dias de dump do banco intactos ao lado.

O `.env` era o item mais traiçoeiro: `EncryptedSecret` deixa várias colunas
ilegíveis sem o `APP_KEY`, e isso só aparece na hora de restaurar.

Havia ainda um problema de visibilidade: os oito dumps existentes viviam num
diretório que ninguém abre, e **nenhum deles jamais tinha sido verificado**.

## O que foi entregue

Cópia completa, criptografada e verificável — banco, arquivos e configuração —
gerada sozinha todo dia, operável pelo painel e recuperável **sem o sistema no
ar**. E o painel virou o catálogo único: todo backup do servidor aparece nele,
inclusive os que este sistema não gerou.

### Banco

| Migration | Conteúdo |
|---|---|
| `2026_08_22_000001_create_backup_infrastructure.php` | `backups`, `backup_destinos_envios`, `backup_restauracoes` |
| `2026_08_22_000002_seed_backup_module_permissions.php` | módulo `backups` (ordem 79) + 6 permissões |
| `2026_08_22_000003_grant_backup_permissions_to_super_admin.php` | correção idempotente do grupo supremo |

### Interface

Aba **Backup** em `/configuracoes/sistema?tab=backups`, entre *Usuários* e
*Integrações*. Cartões de status, botões **Gerar backup agora** e
**Sincronizar**, tabela com colunas de **origem** e **conteúdo**, e formulários
de frase secreta e agenda/retenção.

### Comandos

`backup:executar`, `backup:varrer`, `backup:verificar`, `backup:expurgar`.

### Agenda

03:15 (backup diário), 03:50 (retenção), a cada 15 min (catálogo), a cada minuto
(atende o botão do painel). 02:00 e 02:30 já estavam ocupados.

## Decisões que mudaram durante a implementação

**Google Drive por Service Account não funciona.** Ela não tem cota de
armazenamento própria; o arquivo enviado pertence a ela e o Google recusa com
`storageQuotaExceeded`. Só funciona contra um Shared Drive, do Workspace pago.
A nuvem passou a ser rclone, na Fase 3.

**Certificados TLS ficaram fora do pacote.** Na VPS são Let's Encrypt e renovam
sozinhos; na bancada são autoassinados. Liberar a chave privada para o `www-data`
a fim de mandá-la à nuvem seria perda líquida de segurança em troca de um dado
que se regenera.

**Restauração subiu para a Fase 2, antes da nuvem.** Um backup que nunca foi
restaurado não é um backup.

## Achados que valem mais que o código

**`gzip -t` não prova nada.** Verificado na prática: um `mysqldump` que falha
deixa um arquivo de 20 bytes que passa nele sem reclamar. A verificação confere o
rodapé `-- Dump completed`, além do sha256 de cada membro.

**Rodar com o usuário errado omitia 33 diretórios em silêncio.** As pastas
privadas são `drwx------ www-data`; `administrador` está no grupo, mas modo 0700
é só do dono. O walker agora denuncia todo diretório ilegível — um pacote
incompleto que se apresenta como completo é pior que nenhum pacote.

**`file_scan_runs` ocupa 319,5 MB dos 441 MB do banco** (72%), com 323.816 linhas
crescendo 11.232 por dia, sem retenção. É telemetria regenerável do scanner. O
backup copia só a estrutura dela, mas **o crescimento em si continua sem
solução** — a tabela passa de 1 GB em três meses. Merece item próprio.

**`sistema_erp_chat` não existe na bancada** e o `erp_app` não tem grant para
ele — a mesma causa do erro que já trava o `artisan migrate`. O dumper sonda cada
conexão e registra a ausência como aviso.

**O grupo `super administrador` ficava de fora de todo módulo novo.** O padrão de
semeadura herdado casa só `TRIM(nome) = 'Administrador'`. Corrigido aqui e
documentado em
[backend-administrativo-rbac.md](../03-arquitetura-tecnica/backend-administrativo-rbac.md#grupos-de-administração-total)
para não repetir.

**Alegação falsa que valeu conferir:** uma análise apontou falta de
`set -o pipefail` nos scripts de backup existentes. Verificado — os dois já têm
(`erp-backup.sh:3`, `deploy-producao.sh:16`). Os backups atuais não têm esse
defeito.

## Validação

- 28 testes novos (`backend/tests/Feature/Backups/`, `frontends/desktop/tests/Feature/Desktop/BackupPanelTest.php`), todos verdes.
- Backend passou de 640 para 668 testes passando; as 13 falhas restantes são pré-existentes, confirmadas em árvore limpa.
- Backup real de ponta a ponta: **113 MB em 15 s**, 203 tabelas, 434 arquivos.
- Pacote aberto **sem o sistema**, só com `openssl` e `tar`: 83 MB de SQL com rodapé íntegro, 404 arquivos legados, 30 privados, `APP_KEY` presente.
- Os três maiores dumps do cron verificados pela primeira vez: íntegros.

## Pendências operacionais (root, uma vez)

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/backups/sistema-erp/erp
sudo chown -R www-data:www-data /var/www/sistema-erp/backend/storage/app/backups
sudo chmod 750 /var/www/sistema-erp/backend/storage/app/backups
```

Depois: definir a frase secreta no painel. Enquanto os dois não forem feitos, o
painel mostra os bloqueios com o comando exato de cada um.

## Fases seguintes

| Fase | Conteúdo |
|---|---|
| 2 | Restauração (`BACKUP_ALLOW_RESTORE`), HD externo / pasta de rede |
| 3 | Nuvem via rclone, `backup:importar` |

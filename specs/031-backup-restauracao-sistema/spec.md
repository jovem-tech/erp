# Backup e restauração do sistema

## Problema

O sistema não tinha backup completo. O que existia cobria uma fração do que
importa:

- `/etc/cron.d/sistema-erp-backup` → `/usr/local/bin/erp-backup.sh`, todo dia às
  02:00, dump de **apenas** o banco `sistema_hml`, retenção de 7 dias.
- `scripts/bash/deploy-producao.sh` — dump pré-deploy, também só do `sistema_hml`.

Ficavam de fora, sem nenhuma cópia jamais:

| O que | Tamanho | Situação |
|---|---|---|
| `backend/storage/app/private` (orçamentos, assinaturas, documentos de OS) | 17 MB | nunca copiado |
| `/var/www/sistema-hml-legacy/public/uploads` (fotos de OS, equipamentos, usuários) | 59 MB | nunca copiado |
| `.env` com o `APP_KEY` | — | nunca copiado |
| Cópia fora do servidor | — | inexistente |

O `.env` é o mais traiçoeiro: `backend/app/Casts/EncryptedSecret.php` deixa
várias colunas ilegíveis sem o `APP_KEY`. Um dump sem a chave é um dump
parcialmente destruído, e isso não aparece até a hora de restaurar.

Na prática: perder o servidor significava perder as 670 imagens e PDFs do
gerenciador de arquivos, mesmo com sete dias de dump do banco intactos.

Havia ainda um problema de visibilidade. Os oito dumps existentes viviam num
diretório do servidor que ninguém abre. Não havia como saber, pelo sistema, se
existiam, de quando eram, ou se prestavam — nenhum deles jamais foi verificado.

## Objetivo

Uma cópia completa, criptografada e verificável do sistema — banco, arquivos e
configuração — gerada sozinha todo dia, visível e operável pelo painel, e
recuperável **sem o sistema no ar**.

E, junto disso: o painel é o **catálogo único**. Todo backup do servidor aparece
lá, inclusive os que este sistema não gerou.

## Escopo

### 1. O pacote

Um `.tar` por execução, com os membros já comprimidos e cifrados individualmente:

```
erp-backup-AAAAMMDD-HHMMSS-xxxxxxxx.tar
├── LEIA-ME-RESTAURACAO.txt          (texto claro, pt-BR, com os comandos)
├── manifest.json                    (texto claro)
├── manifest.json.sha256
├── db/sistema_hml.sql.gz.enc
├── arquivos/backend_privado.tar.gz.enc   + .index.tsv.gz
├── arquivos/legado_uploads.tar.gz.enc    + .index.tsv.gz
├── arquivos/coletor_bancada.tar.gz.enc   + .index.tsv.gz
└── configuracao/segredos.tar.gz.enc
```

O manifesto fica em **texto claro** de propósito: o painel e a retenção precisam
ler tamanho, data e conteúdo sem a frase secreta. Saber que existe um banco e uma
árvore de arquivos não é vazamento; o conteúdo é o que fica cifrado.

### 2. Criptografia: openssl, não ZIP

`AES-256-CBC + PBKDF2` (600 mil iterações, SHA-512) via `openssl enc`, com a
frase passada por **variável de ambiente** — nunca por `argv`, que qualquer
usuário da máquina lê com `ps aux`.

A escolha do openssl sobre ZIP-com-senha é operacional, não estética: o PHP até
gera ZIP com AES-256 (`ZipArchive::EM_AES_256`), mas o `unzip` padrão do Linux
não abre esse formato. Um backup que não abre numa emergência não é um backup.
Com openssl a recuperação é um pipe, em qualquer máquina, sem PHP e sem o
sistema. E funciona em stream, então os 130 MB nunca entram nos 256 MB de
`memory_limit`.

A fraqueza real do CBC — maleabilidade — é fechada pelo **sha256 de cada membro
no manifesto**, conferido antes de qualquer restauração.

### 3. Onde roda: no scheduler, não na fila

Nem síncrono nem enfileirado:

- **Síncrono é impossível**: `max_execution_time = 60` nos pools PHP-FPM.
- **Fila é pior que impossível**: `retry_after = 180` na conexão Redis faria um
  backup de mais de 3 minutos ser re-reservado e **rodar duas vezes em
  paralelo**; e o Supervisor só consome `documents,default`, então uma fila nova
  exigiria mexer na configuração como root.

A API grava uma linha `pendente`; `backup:executar --pendente`, agendado a cada
minuto com `runInBackground()`, destaca o processo. É o mesmo idioma do
`file-manager:sync --pending` que já existia, com uma linha durável no lugar da
flag de cache, para o painel poder mostrar progresso e histórico.

Horário do backup diário: **03:15** (02:00 e 02:30 já estavam ocupados).

### 4. O catálogo unificado

`backup:varrer` percorre as raízes configuradas e cataloga tudo que encontra,
tenha sido gerado por este sistema ou não. Mesmo padrão que o gerenciador de
arquivos já usa com os arquivos legados.

Cada linha guarda **origem** (`Painel`, `Cron 02:00`, `Pré-deploy`, `Manual`) e
**conteúdo** (`Completo` ou `Somente banco`). Os oito dumps do cron aparecem como
"Somente banco" — o administrador vê de relance que sete dias de histórico não
cobrem nenhuma foto de OS.

Backups que o painel não gerencia (o diretório é `root:root`) podem ser baixados
e verificados, mas o botão de excluir fica desabilitado com a explicação. Melhor
do que oferecer uma ação que falharia toda noite.

A identidade é `caminho + tamanho + mtime`; o `sha256` é calculado **uma vez só**,
sob demanda — rehashear 440 MB a cada 15 minutos seria desperdício puro. Arquivo
que some do disco vira `ausente`, sem perder a linha: saber que um backup existiu
e sumiu vale mais que a linha desaparecer junto.

### 5. Verificação que verifica de verdade

`gzip -t` **não basta**. Verificado na prática: um `mysqldump` que falha deixa um
arquivo de 20 bytes que passa tranquilamente no `gzip -t`. A prova real é o
rodapé `-- Dump completed` que o mysqldump escreve ao terminar — e é isso que o
sistema confere, além do sha256 de cada membro.

### 6. Escopo dos dados

**Bancos.** Cada conexão é *sondada* antes do dump. Na bancada o
`sistema_erp_chat` não existe e o `erp_app` não tem grant para ele: ausência vira
aviso no manifesto e a execução fica `concluído com avisos` — nunca falha o
backup inteiro, senão o sistema nunca produziria backup nenhum.

`--single-transaction` dá instantâneo consistente **sem travar** o banco em
produção. É seguro porque as 200 tabelas são InnoDB — auditado a cada execução e
registrado no manifesto, porque o banco é legado e compartilhado.

**Telemetria fica só como estrutura.** `file_scan_runs` sozinha ocupa 319,5 MB dos
441 MB do banco (72%), com 323.816 linhas crescendo 11.232 por dia, sem nenhuma
retenção. É telemetria regenerável do scanner. Copiá-la faria cada backup
carregar 320 MB de log de varredura.

**Arquivos.** Três raízes, resolvidas por **ID lógico**, nunca por caminho
absoluto: `LEGACY_PUBLIC_PATH` difere entre bancada e VPS, e um pacote de
produção restaurado na bancada não pode escrever no caminho de produção. Esta é
a propriedade de segurança central da restauração.

**Configuração.** `.env` do backend e do desktop, `VERSION`, `composer.lock` e a
procedência (host, commit, impressão do APP_KEY). Capturado sempre; **nunca
aplicado automaticamente**.

**Certificados TLS: fora, de propósito.** Na VPS são Let's Encrypt e renovam
sozinhos; na bancada são autoassinados. Liberar a chave privada para o usuário do
site, a fim de mandá-la para a nuvem, é perda líquida de segurança em troca de um
dado que se regenera. O manifesto declara isso e o runbook ganha o passo de
reemissão.

### 7. Quem roda: `www-data`, e isso é correção, não preferência

`private/private/{assinaturas,os,os_documentos,usuarios}` são `drwx------
www-data`. O usuário `administrador` está no grupo `www-data`, mas modo 0700 é só
do dono — um `ls` nessas pastas como `administrador` devolve *Permission denied*.

Verificado durante a implementação: um backup rodado pelo usuário errado omitia
**33 diretórios** em silêncio. Por isso o walker agora **denuncia todo diretório
ilegível** como aviso, em vez de pular calado. Um pacote incompleto que se
apresenta como completo é pior que nenhum pacote.

### 8. Download sem passar pelo painel

`ApiClient::download()` no desktop faz `$response->body()` — uma string inteira
em memória, com timeout de 15 s. Proxiar 130 MB por ali estoura o
`memory_limit`. O backend devolve uma **URL assinada de 10 minutos** e o navegador
busca o arquivo direto.

Restaurar por upload é impossível (`client_max_body_size 25M`): trazer um pacote
de fora é `scp` + `backup:importar`.

### 9. Permissões

Módulo `backups`, com `visualizar`, `criar`, `baixar`, `excluir`, `restaurar` e
`administrar` — todos os slugs já existiam em
`RbacAuthorizationService::DEFAULT_PERMISSIONS`.

`baixar` é separado de `visualizar` de propósito: o pacote carrega todos os
segredos e todos os arquivos de clientes. Ver a lista e levar o arquivo embora
são poderes diferentes.

## Fora deste escopo (fases seguintes)

- **Restauração** (`BACKUP_ALLOW_RESTORE=false` por padrão): step-up com
  `AdminCredentialVerifier`, backup de segurança automático antes de escrever,
  simulação, e restauração por schema de rascunho com `RENAME TABLE` atômico.
- **HD externo / pasta de rede**: destino com validação por `findmnt --target` —
  `/mnt/usb-Kingston_…` existe como diretório vazio, e escrever ali com o HD
  desconectado encheria a raiz em silêncio.
- **Nuvem via rclone.** A Service Account do Google **não serve**: ela não tem
  cota de armazenamento própria, o arquivo enviado pertence a ela e o Google
  recusa com `storageQuotaExceeded`. Isso só funciona contra um Shared Drive, do
  Workspace pago. Drive exige OAuth de três pernas — que o rclone já resolve, e
  de quebra passa a falar S3, Backblaze, OneDrive e SFTP sem código novo.

## Pendências operacionais (exigem root, uma única vez)

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/backups/sistema-erp/erp
sudo chown -R www-data:www-data /var/www/sistema-erp/backend/storage/app/backups
sudo chmod 750 /var/www/sistema-erp/backend/storage/app/backups
```

Enquanto o segundo comando não rodar, o `www-data` não enxerga o dump manual de
46 MB que está lá (o diretório é `drwx------ administrador`).

## Decisão sobre o cron antigo

`/etc/cron.d/sistema-erp-backup` **continua rodando**. Não é concorrente: é uma
fonte a mais no catálogo. Aposentá-lo só faz sentido depois do primeiro ensaio de
restauração bem-sucedido — que é trabalho da Fase 2.

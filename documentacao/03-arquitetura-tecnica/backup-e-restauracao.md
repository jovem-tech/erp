# Backup e restauração — arquitetura

Desenho do subsistema de backup completo. Operação no dia a dia:
[runbook](../10-deploy/operacao-backup-e-restauracao.md). Especificação:
`specs/031-backup-restauracao-sistema/spec.md`.

## Por que este subsistema existe

O sistema tinha um cron de root desde sempre (`/usr/local/bin/erp-backup.sh`,
02:00) que dumpava **só** o banco `sistema_hml`. Ficavam fora, sem nenhuma cópia:
17 MB de arquivos privados, 59 MB de uploads do sistema legado e o `.env` com o
`APP_KEY`. Perder o servidor significava perder todas as imagens e PDFs dos
clientes, com sete dias de dump do banco intactos.

`documentacao/01-fundacao/acesso-seguro-a-arquivos.md` já determinava tratar
"banco principal, banco chat e storage como uma unidade de backup e
restauração". Este subsistema implementa isso.

## Onde cada peça vive

```
backend/
  config/backup.php                    raízes, exclusões, cifra, retenção, interruptores
  app/Enums/Backups/                   BackupStatus, BackupType, BackupOrigin,
                                       BackupContent, DestinationDriver
  app/Models/Backups/                  Backup, BackupDelivery, BackupRestore
  app/Services/Backups/
    Contracts/ProcessRunner.php        costura de teste (ver "Testabilidade")
    SymfonyProcessRunner.php           implementação real, com pipefail
    BackupRunner.php                   orquestrador — único ponto de entrada
    BackupPreflight.php                binários, disco, permissões, frase
    BackupRootRegistry.php             ID lógico → caminho absoluto ATUAL
    DatabaseDumpService.php            sondagem + auditoria de engine + dump
    FileTreeArchiveService.php         walker + índice por arquivo + tar
    ConfigSnapshotService.php          .env, VERSION, procedência
    BackupManifestBuilder.php          manifest.json
    BackupVerificationService.php      integridade, dois formatos
    BackupDiscoveryService.php         catálogo unificado
    BackupRetentionPolicy.php          7 diários / 4 semanais / 6 mensais
    BackupSettingsService.php          chave/valor em `configuracoes`
    BackupPassphraseResolver.php       hash, cópia cifrada, impressão
    ArchiveCipher.php                  fragmentos de shell do openssl
  app/Console/Commands/Backups/        executar, varrer, verificar, expurgar
  app/Http/Controllers/Api/V1/BackupController.php
  app/Http/Controllers/BackupDownloadController.php   rota assinada

frontends/desktop/
  app/Services/BackupService.php                      proxy puro da API
  app/Http/Controllers/BackupController.php
  resources/views/configurations/backups/_panel.blade.php
  resources/views/configurations/backups/_scripts.blade.php
```

O backend é a fonte da verdade; o desktop é só BFF e interface, sem nenhuma
regra de negócio — mesma divisão do gerenciador de arquivos.

## Decisões de projeto

### 1. Execução: scheduler, não fila e não síncrono

| Caminho | Por que não |
|---|---|
| Síncrono na requisição | `max_execution_time = 60` nos pools PHP-FPM |
| Job em fila (Redis) | `retry_after = 180` re-reservaria um backup longo, fazendo-o **rodar duas vezes em paralelo**; e o Supervisor só consome `documents,default` — fila nova exigiria mexer na configuração como root |

A API grava uma linha `pendente`. `backup:executar --pendente`, agendado a cada
minuto com `runInBackground()`, destaca o processo. É o mesmo idioma que
`file-manager:sync --pending` já usava, com uma linha durável no lugar da flag de
cache — o que permite ao painel mostrar progresso e histórico.

Custo: até 60 s de espera antes de um backup manual começar. A UI mostra
"Na fila".

### 2. Formato: envelope tar com manifesto em texto claro

Os membros já vão comprimidos e cifrados individualmente; o `.tar` externo é só
o envelope. O `manifest.json` fica **fora da cifra** de propósito:

- o painel e a retenção precisam ler tamanho, data e conteúdo **sem a frase**;
- permite restaurar só o banco, ou só uma árvore, sem decifrar 130 MB;
- o `LEIA-ME-RESTAURACAO.txt` precisa ser legível com o sistema fora do ar.

Saber que existe um banco e uma árvore de arquivos não é vazamento. O conteúdo é
o que fica cifrado.

### 3. Cifra: `openssl enc`, não ZIP com senha

`AES-256-CBC + PBKDF2` (600 mil iterações, SHA-512).

O PHP gera ZIP com AES-256 (`ZipArchive::EM_AES_256`), mas o `unzip` padrão do
Linux **não abre** esse formato — exige 7z ou WinZip. Um backup que não abre numa
emergência não é um backup. Com openssl a recuperação é um pipe, em qualquer
máquina, sem PHP.

Além disso openssl trabalha em stream: os 130 MB nunca entram nos 256 MB de
`memory_limit`.

A frase vai por **variável de ambiente**, nunca por `argv` — `-k` e `-pass pass:`
deixam o segredo visível para qualquer usuário da máquina em `ps aux`.

**A fraqueza do CBC é maleabilidade** (texto cifrado pode ser alterado sem
detecção). Fechada pelo **sha256 de cada membro no manifesto**, conferido antes
de qualquer restauração. Isso não é enfeite: é o que substitui um modo
autenticado.

### 4. Raízes por ID lógico — a propriedade de segurança central

`config/backup.php` → `roots` mapeia um ID (`legado_uploads`) para um resolvedor
(`disk:legacy_public` + sufixo `uploads`). O manifesto guarda **apenas o ID**.

`LEGACY_PUBLIC_PATH` difere entre bancada e VPS. Se o manifesto guardasse
caminhos absolutos, restaurar um pacote de produção na bancada escreveria no
caminho de produção. `BackupRootRegistry` relê a raiz da configuração da máquina
onde a restauração roda.

Há teste dedicado a isso: `BackupManifestAndPassphraseTest::
test_manifesto_nao_carrega_nenhum_caminho_absoluto`.

### 5. Ausência é aviso, não falha

Um destino indisponível, um banco inexistente ou um certificado ilegível
degradam a execução para `concluído com avisos`. Só impedem o backup as falhas
que tornariam o pacote inútil ou mentiroso: sem espaço, sem frase secreta, sem
nenhum banco copiável, raiz obrigatória ilegível.

Motivo: na bancada o `sistema_erp_chat` não existe. Exigir sua presença faria o
sistema nunca produzir backup nenhum.

### 6. Download fora do BFF

`ApiClient::download()` no desktop faz `$response->body()` — string inteira em
memória, timeout de 15 s. O backend devolve uma **URL assinada de 10 minutos**
(`URL::temporarySignedRoute`) e o navegador busca o arquivo direto.

A rota `backups.arquivo` fica fora do grupo autenticado: a assinatura temporária
**é** a prova de autorização. `arquivo_caminho` está em `$hidden` no model e
nunca é serializado.

Restaurar por upload é impossível: `client_max_body_size 25M` nos dois sites.

## Modelo de dados

| Tabela | Papel |
|---|---|
| `backups` | uma linha por execução **ou por arquivo descoberto** |
| `backup_destinos_envios` | uma linha por (execução × destino) — Fase 2/3 |
| `backup_restauracoes` | auditoria de restauração — Fase 2 |

Configurações ficam em `configuracoes` (chave/valor), via `BackupSettingsService`,
espelhando `GoogleIntegrationSettingsService` e usando `SecretSettings` para
mascarar segredos na leitura.

Colunas que carregam decisão:

- `gerenciado` — falso para os dumps do cron de root. O painel lê e restaura, mas
  não apaga: o diretório é `root:root` e tentar falharia toda noite.
- `origem` / `conteudo` — o que o painel mostra como "Cron 02:00 / Somente banco".
- `sha256` **nulo** em backups descobertos — calculado uma vez, sob demanda.
- Índice único em `arquivo_caminho` — torna a varredura idempotente.

## Catálogo unificado

O painel é a **única** lista de backups do sistema. `BackupDiscoveryService`
percorre as raízes de `discovery.roots` e cataloga tudo, tenha sido gerado aqui
ou não. Mesmo padrão de `file-manager:sync` com os arquivos legados.

Identidade: `caminho + tamanho + mtime`. Rehashear 440 MB a cada 15 minutos seria
desperdício puro.

Arquivo que some vira `ausente` em vez de perder a linha: saber que um backup
existiu e sumiu vale mais que a linha desaparecer junto.

## Verificação

`gzip -t` **não é suficiente**. Verificado na prática: um `mysqldump` que falha
deixa um arquivo de 20 bytes que passa nele sem reclamar. A prova de que o dump
terminou é o rodapé `-- Dump completed`, que o mysqldump escreve por último.

`BackupVerificationService` confere, conforme o formato:

| Formato | Verificações |
|---|---|
| `.tar` (completo) | sha256 de cada membro contra o manifesto; opcionalmente, que cada `.enc` realmente decifra |
| `.sql.gz` (legado) | `gzip -t`, rodapé `Dump completed`, sha256 estável entre exames |

## Testabilidade — a costura `ProcessRunner`

`backend/phpunit.xml` força `DB_CONNECTION=sqlite :memory:`, então `mysqldump`
**nunca** pode rodar na suíte. Toda a canalização passa por
`Contracts\ProcessRunner`, ligado a `SymfonyProcessRunner` em
`AppServiceProvider::register()` e substituído por `Tests\Support\FakeProcessRunner`
nos testes.

Isso não é preferência de estilo: sem essa indireção o subsistema seria
intestável, e a decisão precisa vir antes do código, não depois.

`SymfonyProcessRunner::runShell()` executa via `bash -o pipefail -c`. Sem
`pipefail`, `mysqldump | gzip | openssl` devolveria o código do **último**
comando, e uma falha do mysqldump produziria um arquivo truncado que passa em
todas as verificações seguintes.

## Permissões

Módulo `backups`, ordem 79 (logo após `arquivos`). Slugs: `visualizar`, `criar`,
`baixar`, `excluir`, `restaurar`, `administrar` — todos já existentes em
`RbacAuthorizationService::DEFAULT_PERMISSIONS`.

`baixar` é separado de `visualizar` de propósito: o pacote carrega todos os
segredos e todos os arquivos de clientes. Ver a lista e levar o arquivo embora
são poderes diferentes.

**Grupos de administração total.** A semeadura casa `LOWER(TRIM(nome))` contra
`['administrador', 'super administrador']`. Ver
[backend-administrativo-rbac.md](backend-administrativo-rbac.md#grupos-de-administração-total)
— o padrão herdado casava só `'Administrador'` e deixava o grupo supremo de fora
de todo módulo novo.

## Fases

| Fase | Conteúdo | Estado |
|---|---|---|
| 1 | Catálogo, motor, cifra, agenda, retenção, painel, download | **entregue** (5.40.0.0) |
| 2 | Restauração (`BACKUP_ALLOW_RESTORE`), HD externo / pasta de rede | pendente |
| 3 | Nuvem via rclone, `backup:importar` | pendente |

A restauração vem antes da nuvem de propósito: **um backup que nunca foi
restaurado não é um backup**.

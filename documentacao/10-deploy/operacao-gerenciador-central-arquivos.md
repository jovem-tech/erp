# Operação do Gerenciador Central de Arquivos

**Ambiente autorizado nesta fase:** desenvolvimento/homologação. Produção exige aprovação explícita, backup conjunto validado e promoção pelo fluxo oficial `develop -> main`.

## Pré-condições

1. Confirmar branch e versão.
2. Gerar backup consistente do banco principal, banco `chat` e storage privado.
3. Confirmar espaço livre e capacidade de restaurar os três componentes no mesmo ponto lógico.
4. Executar migrations aditivas.
5. Manter `FILE_MANAGER_MODE=off` no primeiro deploy.
6. Rodar diagnóstico e regressão antes de habilitar qualquer categoria.

Nunca remover as tabelas centrais nem arquivos anteriores durante rollback de incidente.

## Configuração segura

```dotenv
FILE_MANAGER_MODE=off
FILE_MANAGER_ENABLED_CATEGORIES=
FILE_MANAGER_HYBRID_WRITE_CATEGORIES=company_login_background,company_logo
FILE_MANAGER_ALLOW_WRITES=false
FILE_MANAGER_ALLOW_SCANNER=false
FILE_MANAGER_ALLOW_MUTATING_RECONCILE=false
FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS=false
FILE_MANAGER_LOCK_SECONDS=30
FILE_MANAGER_LOCK_WAIT_SECONDS=5
FILE_MANAGER_SCAN_LIMIT=1000
FILE_MANAGER_SCAN_MAX_DEPTH=12
FILE_MANAGER_SCAN_TIMEOUT_SECONDS=60
FILE_MANAGER_AUTOMATIC_SYNC_ENABLED=false
FILE_MANAGER_AUTOMATIC_SYNC_INTERVAL_MINUTES=5
FILE_MANAGER_AUTOMATIC_SYNC_ROOTS=branding,equipment_photos,order_files,budget_documents,signatures,chat
FILE_MANAGER_AUTOMATIC_SYNC_SCAN_LIMIT=10000
FILE_MANAGER_AUTOMATIC_SYNC_CATALOG_LIMIT=10000
FILE_MANAGER_AUTOMATIC_SYNC_DOMAIN_LINK_LIMIT=10000
FILE_MANAGER_AUTOMATIC_SYNC_MAX_DEPTH=12
FILE_MANAGER_AUTOMATIC_SYNC_LOCK_SECONDS=3600
FILE_MANAGER_PDF_THUMBNAILS_ENABLED=false
FILE_MANAGER_PDF_THUMBNAIL_RENDERER=/usr/bin/pdftocairo
FILE_MANAGER_PDF_THUMBNAIL_DISK=local
FILE_MANAGER_PDF_THUMBNAIL_MAX_DIMENSION=480
FILE_MANAGER_PDF_THUMBNAIL_MAX_BYTES=2097152
FILE_MANAGER_PDF_THUMBNAIL_TIMEOUT_SECONDS=10
FILE_MANAGER_PDF_THUMBNAIL_LOCK_SECONDS=20
FILE_MANAGER_PDF_THUMBNAIL_LOCK_WAIT_SECONDS=5
FILE_MANAGER_PDF_THUMBNAIL_CACHE_SECONDS=86400
```

Depois de alterar `.env`, limpar o cache de configuração no processo normal do ambiente e executar `php artisan file-manager:diagnose --json`. Uma configuração híbrida sem escrita e allowlist é recusada.

## Comandos

```bash
php artisan file-manager:diagnose --json

FILE_MANAGER_ALLOW_SCANNER=true php artisan file-manager:scan --root=managed
FILE_MANAGER_ALLOW_SCANNER=true php artisan file-manager:scan --root=branding --limit=1000 --max-depth=12

# Somente valida candidatos já encontrados pelo scanner; não grava catálogo.
FILE_MANAGER_ALLOW_SCANNER=true php artisan file-manager:catalog-legacy --limit=500

# Registra metadados e aliases após revisar o dry-run. Não move nem altera binários.
FILE_MANAGER_ALLOW_SCANNER=true FILE_MANAGER_ALLOW_MUTATING_RECONCILE=true \
  php artisan file-manager:catalog-legacy --apply --limit=500

php artisan file-manager:check-integrity --limit=500
php artisan file-manager:reconcile --limit=500
```

## Sincronização automática

O modo recomendado para catalogar arquivos sem assumir a escrita dos módulos é `shadow`. Nesse modo, os adapters já integrados catalogam o arquivo durante o fluxo normal e o comando agendado cobre gravações legadas ou feitas fora desses adapters.

```dotenv
FILE_MANAGER_MODE=shadow
FILE_MANAGER_ENABLED_CATEGORIES=company_login_background,company_logo,equipment_photo,order_photo,order_pdf,budget_pdf,user_signature,user_profile_photo,chat_attachment
FILE_MANAGER_ALLOW_WRITES=false
FILE_MANAGER_ALLOW_SCANNER=true
FILE_MANAGER_ALLOW_MUTATING_RECONCILE=true
FILE_MANAGER_AUTOMATIC_SYNC_ENABLED=true
FILE_MANAGER_AUTOMATIC_SYNC_INTERVAL_MINUTES=5
```

O scheduler executa `php artisan file-manager:sync` a cada cinco minutos. Cada ciclo percorre somente roots definidas em `FILE_MANAGER_AUTOMATIC_SYNC_ROOTS`, registra metadados e SHA-256 e mantém o binário no path original. O comando é idempotente e protegido por lock global de uma hora; `withoutOverlapping` impede sobreposição do agendador. Roots ainda inexistentes são ignoradas, e falha em uma root não interrompe as demais.

O botão **Sincronizar agora** exige `arquivos:administrar`. A chamada HTTP apenas grava uma solicitação deduplicada no cache por até dez minutos; `file-manager:sync --pending`, executado a cada minuto pelo scheduler, consome a solicitação. Isso evita varreduras longas dentro do PHP-FPM e funciona mesmo sem queue worker. Se a rotina automática concluir depois do clique, a solicitação é considerada atendida sem repetir a mesma varredura.

Arquivos recusados por extensão, MIME real, decoder ou limite da categoria permanecem fora do catálogo. O finding fica `acknowledged` e só volta a ser candidato quando tamanho ou `mtime` mudarem. O checkpoint do processo `automatic_sync` contém apenas aliases de roots e contadores, sem paths físicos.

Validação operacional:

```bash
php artisan schedule:list
php artisan file-manager:sync --status
php artisan file-manager:sync --root=equipment_photos
php artisan file-manager:diagnose --json
```

Para rollback imediato, definir `FILE_MANAGER_AUTOMATIC_SYNC_ENABLED=false` e limpar o cache de configuração. Isso interrompe novas descobertas sem excluir registros já catalogados nem alterar os arquivos de origem.

## Miniaturas e visualização

Miniaturas de imagem reutilizam o preview autenticado. Para PDF, o servidor
precisa do Poppler e do binário configurado:

```bash
sudo apt-get update
sudo apt-get install -y --no-install-recommends poppler-utils

command -v pdftocairo
/usr/bin/pdftocairo -v
sudo -u www-data test -x /usr/bin/pdftocairo
```

O pacote `poppler-utils` é uma dependência operacional obrigatória sempre que
`FILE_MANAGER_PDF_THUMBNAILS_ENABLED=true`. O sistema executa o binário
`pdftocairo` por lista de argumentos, sem shell, para converter somente a
primeira página do PDF em PNG. Mantenha a funcionalidade desabilitada até que o
binário exista e seja executável pelo usuário do PHP-FPM.

Depois da validação, habilitar:

```dotenv
FILE_MANAGER_PDF_THUMBNAILS_ENABLED=true
FILE_MANAGER_PDF_THUMBNAIL_RENDERER=/usr/bin/pdftocairo
```

Reconstruir o cache de configuração com o mesmo usuário do PHP-FPM:

```bash
cd /var/www/sistema-erp/backend
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
```

A primeira abertura gera PNG da página 1 e as seguintes reutilizam o cache por
SHA-256/dimensão. O cache fica em `storage/app/private/file-thumbnails/pdf` e
deve ter owner/grupo compatíveis com PHP-FPM, sem permissão pública. Não expor
essa pasta pelo Nginx.

O visualizador usa a rota autenticada do desktop dentro de modal. PDFs são
carregados em iframe same-origin e imagens recebem controles locais de
zoom/rotação. Se a miniatura falhar, o ícone de fallback deve permanecer e o
download/preview individual deve ser testado separadamente.

Diagnóstico de miniatura:

1. confirmar `FILE_MANAGER_PDF_THUMBNAILS_ENABLED=true` no config efetivo;
2. confirmar que `poppler-utils` está instalado e que `pdftocairo` é executável
   pelo usuário do PHP-FPM;
3. confirmar arquivo `active + valid + clean`;
4. confirmar `arquivos:baixar` e o vínculo de domínio;
5. verificar espaço/permissão do cache;
6. consultar logs pelo UUID/correlation ID, sem registrar path ou nome sensível;
7. limpar somente a miniatura comprovadamente inválida; nunca remover o PDF.

Quando o pacote estiver ausente ou a funcionalidade permanecer desabilitada, a
rota autenticada de miniatura PDF responde `503 Service Unavailable`; isso não
indica indisponibilidade do arquivo original. O preview e o download devem ser
testados separadamente antes de qualquer ação sobre o documento.

O scanner é sempre dry-run. `catalog-legacy` também é dry-run sem `--apply` e trabalha apenas sobre findings `orphan` de roots allowlisted. A aplicação registra metadados, SHA-256 e alias no path atual, sem mover, renomear ou excluir o arquivo. `reconcile` também é dry-run sem `--apply`. Qualquer aplicação exige `FILE_MANAGER_ALLOW_MUTATING_RECONCILE=true` e uma janela aprovada.

Para a conexão separada do chat:

```bash
php artisan migrate --database=chat --path=database/migrations/chat --force
php artisan file-manager:reconcile-chat
```

Se a credencial não possuir grant no schema `chat`, interromper e solicitar o privilégio mínimo ao DBA. Não reutilizar credenciais privilegiadas da produção nem alterar a conexão para contornar a separação.

## Painel e ações sensíveis

O painel pode permanecer disponível para consulta com `FILE_MANAGER_MODE=off`. As ações archive/restore/quarantine/release somente ficam disponíveis quando `FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS=true`, depois de validação em homologação.

Cada ação exige a permissão RBAC própria, motivo, credenciais de um administrador ativo e vínculo de negócio autorizado. Tentativas inválidas retornam `422` e sofrem rate limit; o operador não deve ser deslogado. Nunca registrar `admin_password` em log, old input ou sessão.

O switch permite apenas transições lógicas auditadas. Exclusão física, empty trash, retenção destrutiva e deduplicação continuam desabilitados.

## Rollout por categoria

Exemplo do piloto de fundo de login:

```dotenv
FILE_MANAGER_ENABLED_CATEGORIES=company_login_background
```

1. `off`: validar que o legado permanece funcional.
2. `observe`: acompanhar eventos sem catálogo de blobs.
3. `shadow`: catalogar paths compatíveis; falhas centrais não afetam o usuário.
4. comparar quantidade, hashes, divergências e latência por período aprovado;
5. `hybrid` + `FILE_MANAGER_ALLOW_WRITES=true`: ativar somente a categoria aprovada;
6. criar um arquivo, voltar para `off` e confirmar leitura pelo endpoint legado;
7. só então avaliar a próxima categoria.

Nunca habilitar todas as categorias de uma vez em `hybrid`. A sincronização `shadow` pode cobrir roots revisadas em conjunto porque não assume a escrita nem altera os binários de origem; ainda assim, métricas e findings devem ser acompanhados após o rollout.

## Rollback

1. definir `FILE_MANAGER_MODE=off`;
2. definir `FILE_MANAGER_ALLOW_WRITES=false`;
3. limpar/recarregar configuração da aplicação;
4. validar endpoint legado e hashes dos arquivos afetados;
5. preservar catálogo, aliases, eventos, blobs centrais e versões anteriores;
6. abrir incidente e executar scanner/integridade em dry-run;
7. restaurar banco e storage juntos somente se houver perda comprovada e após decisão operacional.

O piloto de branding grava no path já compreendido pelo leitor legado, portanto voltar para `off` não exige cópia. Nos demais módulos esse teste é gate obrigatório.

## Falhas e resposta

| Sintoma | Resposta segura |
|---|---|
| configuração inválida | manter `off`; corrigir allowlist/switch e repetir diagnóstico |
| VPS mostra “catalogação automática desativada” e zero arquivos após o deploy | conferir o `.env` real, pois `.env.example` e `git pull` não alteram a configuração persistente; habilitar `shadow` e os três switches necessários, reconstruir `config:cache`, validar o cron de `www-data` e executar a primeira sincronização somente após backup |
| disco sem espaço ou permissão negada | bloquear escrita antes da troca; corrigir owner/group/ACL mínimo |
| falha confirmada antes do commit do catálogo | compensar somente o candidato conhecido |
| resultado de commit ambíguo | preservar blob; localizar por `operation_key`, scanner e reconciliação |
| registro sem blob | marcar integridade `missing` apenas em janela mutável aprovada |
| blob sem registro | manter evidência; catalogar/reconciliar idempotentemente, sem exclusão em massa |
| divergência de hash | bloquear uso sensível, preservar ambos e investigar |
| symlink/arquivo especial | não seguir nem abrir; registrar finding |
| permissão do scanner | ajustar grupo/ACL; nunca aplicar `0777` |
| step-up inválido | retornar `422`, preservar sessão do operador e observar rate limit |
| ação administrativa inesperada | desligar `FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS` e revisar eventos |
| miniatura PDF retorna `503` | validar Poppler, timeout, cache privado e permissões; preservar PDF e usar fallback |
| modal abre sem conteúdo | validar rota proxy, `arquivos:baixar`, estados e headers; não expor URL física |

## Backup e restauração

Um backup válido contém:

- dump transacional do banco principal com catálogo, links, aliases, eventos e findings;
- dump do banco `chat` no mesmo marco operacional;
- snapshot/cópia consistente de `backend/storage/app/private`, `managed-files` e namespaces legados autorizados;
- versão/commit da aplicação e checksum do manifesto do backup.

O teste de restauração deve ocorrer em ambiente isolado, executar migrations, verificar amostra de hashes, resolver links legados e centrais e rodar as regressões de download. Backup sem teste de restore não libera produção.

Arquivos de dump devem usar permissão `0600` e diretório restrito. A senha do
banco não pode aparecer no nome do processo, log, histórico ou saída de CI; se
o cliente exigir `-p`, restringir a execução ao operador e descartar a variável
do processo imediatamente depois do dump.

## Observabilidade e privacidade

Monitorar falhas de catálogo, fallback, divergência, `missing`, `corrupted`, `permission_denied`, duração do scanner e atraso de heartbeat. Logs comuns devem conter identificadores técnicos e hashes, nunca bytes, caminho absoluto, token, nome de cliente ou filename sensível. O path restrito dos findings deve ser acessível somente ao perfil técnico autorizado.

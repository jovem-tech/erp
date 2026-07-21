# Gerenciador Central de Arquivos

**Status em 2026-07-20:** núcleo, adapters de domínio, painel administrativo e sincronização automática foram implementados no ambiente alvo. O modo efetivo está em `shadow`, com descoberta agendada a cada cinco minutos e escrita central desligada. O catálogo registra paths existentes sem mover, renomear ou excluir binários. `off` permanece o default seguro do código e o rollback imediato.

## Objetivo e limites

O componente introduz catálogo, integridade, auditoria, compatibilidade legada e operação segura sem substituir os serviços de negócio. Controllers continuam responsáveis pelos contratos HTTP e cada domínio continua responsável por autorização, versão documental e regras de remoção.

Não existe endpoint genérico de upload. Cada categoria tem policy própria e entra no gerenciador por adapter do domínio. Essa decisão reduz confused deputy, IDOR, upload de conteúdo ativo e acoplamento entre módulos.

## Componentes

| Componente | Responsabilidade |
|---|---|
| `FileManagerConfiguration` | valida modos, discos, roots, categories e kill switches |
| `FilePolicyRegistry` | valida tamanho, extensão, MIME real e decoder específico |
| `LocalFileStorage` | staging, chave imutável, promoção sem overwrite e SHA-256 por stream |
| `EloquentFileCatalog` | registro idempotente, vínculo atual e eventos na mesma transação |
| `FileManagerFacade` | orquestra policy, storage, catálogo e compensação segura |
| `LegacyFileResolver` | registra e resolve aliases compatíveis sem aceitar path arbitrário |
| `FileAuthorizationRegistry` | resolve apenas `subject_type` allowlisted |
| `FileStateMachine` | controla transições de lifecycle, integridade e segurança |
| `FileScanService` | inventário dry-run, sem seguir symlink e limitado por root/lote/tempo |
| `AutomaticFileSyncService` | orquestra scanner e catálogo por root, com lock, isolamento de falhas e checkpoint agregado |
| `FileIntegrityService` | compara existência, tamanho e SHA-256 em lote |
| `FileReconciliationService` | detecta missing; mutação exige kill switch separado |
| adapters de domínio | preservam contrato, path e rollback do módulo migrado |
| `FileManagerController` | catálogo paginado, dashboard, findings, entrega e ações administrativas |
| `ManagedFileDeliveryService` | aplica estados, policy inline, contenção de path e headers seguros |
| `PdfThumbnailService` | gera/cacheia a primeira página do PDF por SHA-256, com lock e validação da imagem |
| `PopplerPdfThumbnailRenderer` | invoca `pdftocairo` sem shell, com timeout e argumentos isolados |

## Modelo de dados

A migration `2026_07_19_000007_create_managed_file_infrastructure.php` cria somente estruturas aditivas:

- `managed_files`: metadados, hash, localização e quatro estados independentes;
- `managed_file_links`: vínculo allowlisted com a entidade de negócio e versão atual;
- `managed_file_legacy_aliases`: compatibilidade verificável com paths existentes;
- `managed_file_events`: trilha append-only com contexto allowlisted;
- `file_scan_runs` e `file_scan_findings`: execução, checkpoint e achados operacionais.

O banco `chat` permanece separado e não recebe foreign key cruzada. UUID é o identificador público futuro; ID numérico é interno. Os índices cobrem operação idempotente, path, hash/tamanho, categoria/data, estados, vínculo e paginação de eventos/findings. O plano crítico do catálogo foi validado com `EXPLAIN` no MySQL 8.4 de desenvolvimento.

As migrations seguintes adicionam `managed_file_uuid` aos arquivos documentais de OS, preparam a mesma referência na conexão `chat` e criam o módulo RBAC `arquivos` com permissões independentes: `listar`, `metadados`, `baixar`, `quarentenar`, `restaurar` e `administrar`. Nenhuma coluna legada foi removida.

## Máquina de estados

Lifecycle, integridade, segurança e migração não são condensados em um único status:

- lifecycle: `active`, `archived`, `trashed`, `purged`;
- integridade: `unknown`, `valid`, `missing`, `corrupted`;
- segurança: `pending`, `clean`, `quarantined`, `rejected`;
- migração: `native`, `legacy`, `cataloged`, `migrating`, `migrated`, `failed`.

Transições inválidas são recusadas. `trashed` mantém o binário recuperável e permite
preview autenticado, mas nunca download. `purged` é terminal: o binário e as
miniaturas derivadas foram removidos, enquanto metadados, vínculos e eventos ficam
preservados como registro-túmulo para auditoria.

## Escrita e compensação

```text
arquivo temporário
  -> validação por categoria
  -> staging privado
  -> hash/tamanho por stream
  -> promoção para chave imutável
  -> catálogo idempotente e vínculo transacional
  -> publicação da referência do domínio
```

O filesystem e o MySQL não participam de uma transação única. Se o catálogo falhar e uma consulta de confirmação provar ausência do registro, o candidato é removido. Em erro de commit ambíguo, o candidato é preservado e o log contém apenas hashes; scanner e reconciliação resolvem o estado posteriormente.

No piloto de branding, o domínio primeiro grava e valida o novo arquivo, publica a configuração e sincroniza o catálogo. Falha central no modo híbrido restaura a configuração anterior antes de remover o candidato. A versão anterior fica retida para rollback. `observe` e `shadow` nunca interrompem o fluxo legado.

## Segurança

- `FILE_MANAGER_MODE=off` é o default seguro;
- escrita, scanner e reconciliação mutável são switches independentes e desligados por padrão;
- ações administrativas de estado usam switch próprio, RBAC específico, motivo obrigatório e step-up com credenciais de administrador;
- discos e roots são allowlists em código, nunca parâmetros físicos enviados pelo cliente;
- path traversal, byte NUL, caminho absoluto, separador inconsistente e filename malicioso são rejeitados/normalizados;
- MIME é detectado com `finfo`; imagens passam pelo decoder e PDFs precisam de assinatura `%PDF-`;
- SVG não é aceito no branding; tipos ativos do chat são sempre download;
- paths completos não entram em logs comuns; findings guardam path restrito e hash para agrupamento;
- aliases polimórficos não carregam FQCN arbitrário;
- o scanner não segue symlinks e marca arquivos especiais;
- o renderizador PDF usa binário allowlisted e `Process` sem shell, reduzindo risco de command injection;
- `NullMalwareScanner` é uma integração explícita e não deve ser confundida com antivírus ativo.

## Adapters de domínio e compatibilidade

- branding mantém o path lido pelo contrato público e pode ser o primeiro piloto híbrido;
- fotos de equipamento e OS são catalogadas em `shadow`, mas não entram na allowlist híbrida antes dos gates operacionais;
- PDFs de OS mantêm `os_documentos`/`os_documento_arquivos` como donos da versão e registram divergência de SHA-256 sem sobrescrever evidência;
- assinaturas de usuário e cliente preservam senha, reautenticação, consentimento e revisão documental existentes;
- chat registra saga `pending_link -> linked`, reconcilia pendências e mantém autorização por conta/conversa; a mídia recebida possui proteção SSRF por IP efetivo e origem privada explicitamente confiável.

## Painel administrativo

O desktop acessa apenas a API central por `FileManagerService`; não consulta tabelas do catálogo. A listagem carrega somente metadados paginados e agregados. O detalhe limita vínculos, eventos e findings a 100 itens e mascara referências de path.

Download e preview exigem simultaneamente a permissão `arquivos:baixar`, um vínculo de domínio autorizado e estados `active + valid + clean`. Preview ainda exige MIME allowlisted pela policy. Arquivos em quarentena, ausentes, corrompidos ou arquivados não são entregues. Toda resposta usa `nosniff`, `no-store`, nome seguro e CSP sandbox no inline.

Archive, restore, quarantine e release exigem permissão específica, motivo de 10 a 500 caracteres, credenciais válidas de um administrador e rate limit por e-mail/IP. O evento guarda separadamente o ator da sessão e o administrador que autorizou. Falha de step-up retorna `422`, nunca `401`, para não encerrar a sessão do operador. `FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS=false` mantém todas essas ações bloqueadas por padrão.

## Performance e escalabilidade

- fingerprints usam blocos de 1 MiB e memória O(1);
- catálogo usa paginação/indexação e não carrega blobs;
- cliente vinculado é resolvido em lote por tipo de domínio, sem N+1;
- miniaturas PDF são lazy, cacheadas por hash/dimensão e protegidas por lock;
- operação e sujeito usam locks distribuíveis via cache;
- scanner limita quantidade, profundidade e tempo, atualizando heartbeat/checkpoint;
- sincronização automática é idempotente, tem lock global e isola falhas por root;
- armazenamento depende do contrato `FileStorage`, permitindo adoção futura de object storage sem alterar os domínios;
- deduplicação física foi adiada: compartilhar blobs entre níveis de confidencialidade aumentaria risco de autorização e retenção.

## Evidências de desenvolvimento

- migration aplicada com sucesso no MySQL 8.4 de desenvolvimento;
- `file-manager:diagnose --json` confirmou configuração, tabelas e uso de índice;
- testes exercitaram provider fake e filesystem Linux real;
- scanner dry-run encontrou dois JPG de branding (110.024 bytes) e um PDF de chat (880.220 bytes), sem alterar conteúdo;
- um subdiretório de login com modo `0700` e owner do PHP-FPM não pôde ser lido pelo usuário CLI; o finding `permission_denied` foi registrado e a correção recomendada é grupo/ACL mínimo, nunca `0777`;
- piloto híbrido comprovou leitura do arquivo novo após retornar o modo para `off`;
- adapters shadow cobrem fotos, documentos, assinaturas e anexos de chat sem remover os campos legados;
- painel backend/desktop possui cobertura de paginação, ausência de path, IDOR, headers, step-up, auditoria e kill switch;
- falhas de catálogo, resize, MIME falso, concorrência idempotente, symlink e ausência de blob possuem cobertura automatizada.

## Riscos residuais e próximos gates

- a sincronização está em `shadow`; qualquer promoção para `hybrid` ainda exige uma janela operacional por categoria;
- backup/restore conjunto precisa ser ensaiado antes de qualquer rollout produtivo;
- métricas já existem como eventos/findings, mas alertas externos ainda precisam de integração operacional;
- antivírus real, deduplicação e S3 permanecem projetos futuros;
- a retenção destrutiva está disponível somente para a lixeira, protegida por kill switch,
  step-up administrativo, confirmação explícita, allowlist de disco/path e retenção legal;
- miniaturas PDF estão disponíveis sob demanda; uma evolução assíncrona só se justifica após teste de carga;
- cada novo domínio exige authorizer e regressão próprios antes de entrar na allowlist.
- o banco `sistema_erp_chat` precisa receber o grant mínimo de migration antes de aplicar a coluna aditiva; credenciais não foram contornadas.

Detalhes de inventário estão em `inventario-arquivos-funcionais.md`; compatibilidade e rollback estão em `specs/022-gerenciador-central-arquivos/FILE_MANAGER_COMPATIBILITY_AND_ROLLBACK.md`.

## Biblioteca visual e operações em lote

A listagem administrativa também funciona como biblioteca de arquivos: pastas por categoria, busca, filtros, visualização em grade/lista, miniaturas lazy e seleção da página. O desktop continua consumindo exclusivamente a API central e nunca recebe `storage_key` ou path absoluto.

Download individual e preview exigem `arquivos:baixar`, autorização de domínio e estados
de integridade/segurança `valid + clean`. Download exige lifecycle `active`; preview
também aceita `trashed` para que o operador consiga confirmar o conteúdo antes de
restaurar ou excluir definitivamente. Arquivos legados ainda sem vínculo inferido
exigem adicionalmente `arquivos:administrar`. Downloads múltiplos geram um ZIP
temporário de até 50 arquivos e 100 MiB, removido após a resposta.

“Excluir” exige `arquivos:excluir`, motivo, step-up de administrador e kill switch de
mutação ativo. A operação move o registro para `trashed` e preserva o binário.
“Excluir definitivamente” é uma operação distinta: exige o kill switch independente
`FILE_MANAGER_ALLOW_PERMANENT_DELETION`, a confirmação exata `EXCLUIR`, limita o lote
a 50 UUIDs e valida disco, namespace, caminho físico, symlink e retenção legal antes
de remover o binário. A rotina automática usa a mesma implementação e aceita somente
0, 7, 30 ou 90 dias; 0 desativa o expurgo. Locks por UUID, `lockForUpdate`, lote
limitado e scheduler `onOneServer/withoutOverlapping` evitam corridas e picos de carga.

### Miniaturas, contexto e modal

Imagens utilizam o preview autenticado. PDFs usam uma miniatura PNG da primeira
página gerada por Poppler e cacheada por SHA-256. O cache é privado, possui
ETag, limites de dimensão/bytes/tempo e validação de PNG antes da entrega.

Cards e linhas usam `document_created_at` obtido do vínculo de domínio e exibem
`linked_client` somente quando o usuário também pode consultar a OS,
equipamento ou cliente que autoriza a associação. A resolução é feita em lote.

O botão de olho abre um modal interno. Imagens recebem zoom, ajuste, rotação,
pan e tela cheia; PDFs são carregados em iframe same-origin com os controles
nativos. URLs só são atribuídas após a ação do usuário e são removidas ao fechar
o modal, evitando preload do catálogo inteiro.

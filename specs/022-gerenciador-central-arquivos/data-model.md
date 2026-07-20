# Data Model: Gerenciador Central de Arquivos

## Objetivos do modelo

- representar blobs imutáveis sem acoplar regras de domínio;
- permitir vários vínculos e aliases legados;
- separar lifecycle, integridade, segurança e migração;
- suportar idempotência e reconciliação;
- manter queries administrativas paginadas e indexáveis;
- não presumir FK entre bancos diferentes;
- permitir evolução do disco local para outro provider sem mudar contratos de negócio.

## 1. `managed_files`

Representa uma unidade física imutável de conteúdo. Nova versão ou substituição cria outra linha; o “arquivo atual” é decidido pelo vínculo ou pelo registro de domínio.

| Coluna | Tipo sugerido | Regra |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK interna, nunca exposta como autorização |
| `uuid` | CHAR(36) | UUID v4 público, unique |
| `operation_key` | VARCHAR(120) nullable | idempotência da operação de criação |
| `original_name` | VARCHAR(255) | nome recebido, normalizado para exibição |
| `safe_download_name` | VARCHAR(255) | nome usado no header |
| `extension` | VARCHAR(20) | extensão normalizada sem ponto |
| `declared_mime_type` | VARCHAR(120) nullable | MIME informado pela origem |
| `detected_mime_type` | VARCHAR(120) | MIME determinado pelo backend |
| `size_bytes` | BIGINT UNSIGNED | maior que zero |
| `sha256` | CHAR(64) | lowercase hexadecimal |
| `storage_disk` | VARCHAR(40) | disk allowlisted |
| `storage_key` | VARCHAR(500) | chave relativa, sem caminho absoluto |
| `category` | VARCHAR(80) | policy registrada |
| `origin` | VARCHAR(40) | upload, generated, legacy, integration |
| `lifecycle_status` | VARCHAR(30) | active, archived, trashed |
| `integrity_status` | VARCHAR(30) | unknown, valid, missing, corrupted |
| `security_status` | VARCHAR(30) | pending, clean, quarantined, rejected |
| `migration_status` | VARCHAR(30) | native, legacy, cataloged, migrating, migrated, failed |
| `visibility` | VARCHAR(30) | private, controlled_public |
| `confidentiality` | VARCHAR(40) | internal, confidential, personal_data, sensitive_personal_data, legal_retention |
| `created_by` | BIGINT nullable | usuário quando houver ator humano |
| `created_at` | DATETIME(6) | obrigatório |
| `updated_at` | DATETIME(6) | apenas estados/metadados, nunca bytes |
| `archived_at` | DATETIME(6) nullable | lifecycle |
| `trashed_at` | DATETIME(6) nullable | lifecycle |
| `quarantined_at` | DATETIME(6) nullable | security |
| `metadata_json` | JSON nullable | metadata allowlisted e não pesquisada frequentemente |

### Restrições e índices

- unique `uuid`;
- unique nullable `operation_key` quando a origem fornecer idempotência;
- unique `(storage_disk, storage_key)`;
- index `(sha256, size_bytes)` apenas para candidatos a duplicidade;
- index `(category, created_at)`;
- index `(lifecycle_status, security_status, created_at)`;
- index `(migration_status, created_at)`;
- FK `created_by` para `usuarios.id` com `nullOnDelete`, se compatível com o schema real.

### Invariantes

- `storage_key` não começa com `/`, drive letter ou protocolo;
- não contém byte nulo ou segmentos `..`;
- `security_status=clean` é necessário para preview inline;
- `security_status=quarantined` impede download por usuário comum;
- `lifecycle_status=trashed` não implica remoção física;
- `integrity_status=missing|corrupted` impede entrega sem fallback seguro;
- bytes de `(disk,key)` nunca são sobrescritos após confirmação do registro.

### Decisão sobre UUID

O projeto começa com `CHAR(36)` pela simplicidade operacional, compatibilidade com Laravel e legibilidade em incidentes. Antes da migration definitiva, benchmark pode justificar `BINARY(16)`; isso não deve ser decidido sem medir o volume do inventário.

## 2. `managed_file_links`

Vincula o arquivo a um registro de negócio. A autorização é resolvida pelo tipo de vínculo.

| Coluna | Tipo sugerido | Regra |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `file_id` | BIGINT UNSIGNED | FK para managed_files |
| `subject_type` | VARCHAR(80) | alias registrado, nunca FQCN recebido do usuário |
| `subject_id` | BIGINT UNSIGNED | ID do registro no domínio |
| `relation` | VARCHAR(80) | ex.: login_background, equipment_photo, a4, attachment |
| `is_current` | BOOLEAN | vínculo atual quando a relação for substituível |
| `created_by` | BIGINT nullable | ator |
| `created_at` | DATETIME(6) | obrigatório |
| `unlinked_at` | DATETIME(6) nullable | remoção lógica do vínculo |
| `metadata_json` | JSON nullable | contexto mínimo |

### Restrições e índices

- FK `file_id` com `restrictOnDelete`;
- unique `(file_id, subject_type, subject_id, relation)` para retry idempotente;
- index `(subject_type, subject_id, relation, unlinked_at)`;
- index `(file_id, unlinked_at)`;
- index `(subject_type, subject_id, is_current)`.

### Polimorfismo controlado

`subject_type` usa aliases estáveis registrados em configuração/morph map:

```text
configuration
equipment
order
order_document
user_signature
chat_message
chat_attachment
```

Nenhum valor arbitrário vira nome de classe. Para domínios no banco principal, o reconciliador valida existência. Para `chat`, a validação usa adapter da conexão correspondente e não tenta criar FK cruzada.

## 3. `managed_file_legacy_aliases`

Permite localizar o mesmo arquivo a partir de mais de um caminho/campo legado e preservar rollback.

| Coluna | Tipo sugerido | Regra |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `file_id` | BIGINT UNSIGNED | FK |
| `legacy_disk` | VARCHAR(40) | disk allowlisted |
| `legacy_path` | VARCHAR(500) | caminho relativo normalizado; acesso administrativo restrito |
| `path_hash` | CHAR(64) | SHA-256 de disk + caminho normalizado |
| `source_table` | VARCHAR(80) nullable | tabela de origem allowlisted |
| `source_column` | VARCHAR(80) nullable | coluna de origem allowlisted |
| `source_record_id` | VARCHAR(120) nullable | suporta chaves legadas não uniformes |
| `verified_at` | DATETIME(6) nullable | última verificação |
| `created_at` | DATETIME(6) | obrigatório |
| `retired_at` | DATETIME(6) nullable | alias não usado, preservado historicamente |

### Restrições e índices

- unique `(legacy_disk, path_hash)`;
- index `(file_id, retired_at)`;
- index `(source_table, source_record_id)`.

O caminho não aparece em logs comuns nem em respostas públicas. O painel pode mostrar versão mascarada apenas para perfil técnico autorizado.

## 4. `managed_file_events`

Trilha append-only de ações e resultados. Eventos não são usados como única fonte para estado transacional do arquivo.

| Coluna | Tipo sugerido | Regra |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `event_uuid` | CHAR(36) | unique/idempotência |
| `file_id` | BIGINT UNSIGNED nullable | null para falha antes do catálogo |
| `actor_id` | BIGINT nullable | usuário, se aplicável |
| `action` | VARCHAR(60) | catálogo fechado |
| `result` | VARCHAR(20) | success, denied, failed |
| `module` | VARCHAR(80) nullable | origem lógica |
| `ip_fingerprint` | CHAR(64) nullable | HMAC/sha conforme política existente |
| `user_agent_fingerprint` | CHAR(64) nullable | fingerprint, não texto integral |
| `correlation_id` | VARCHAR(100) nullable | correlação request/job |
| `context_json` | JSON nullable | chaves allowlisted |
| `created_at` | DATETIME(6) | append-only |

### Ações iniciais

```text
UPLOAD_STAGED
UPLOAD_REJECTED
REGISTERED
LINKED
UNLINKED
PREVIEWED
DOWNLOADED
ARCHIVED
RESTORED
TRASHED
QUARANTINED
RELEASED_FROM_QUARANTINE
INTEGRITY_CHECKED
LEGACY_FALLBACK_USED
MIGRATION_STARTED
MIGRATION_COMPLETED
MIGRATION_FAILED
RECONCILIATION_COMPLETED
ROLLBACK_STARTED
ROLLBACK_COMPLETED
```

### Índices e retenção

- unique `event_uuid`;
- index `(file_id, created_at)`;
- index `(action, created_at)`;
- index `(module, created_at)`;
- index `(correlation_id)`.

Retenção de auditoria deve ser definida juridicamente. Não se deve usar a mesma política destrutiva dos arquivos funcionais.

## 5. `file_scan_runs`

Controla inventários e migrações CLI.

| Coluna | Tipo sugerido | Regra |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `uuid` | CHAR(36) | unique |
| `process_name` | VARCHAR(80) | scan, catalog, integrity, reconcile |
| `mode` | VARCHAR(20) | dry_run, catalog, migrate |
| `roots_fingerprint` | CHAR(64) | versão da allowlist usada |
| `status` | VARCHAR(30) | pending, running, interrupted, completed, failed |
| `checkpoint_json` | JSON nullable | cursor opaco; nunca executado |
| `processed_count` | BIGINT UNSIGNED | default 0 |
| `skipped_count` | BIGINT UNSIGNED | default 0 |
| `finding_count` | BIGINT UNSIGNED | default 0 |
| `failed_count` | BIGINT UNSIGNED | default 0 |
| `started_by` | BIGINT nullable | ator técnico |
| `started_at` | DATETIME(6) nullable |  |
| `heartbeat_at` | DATETIME(6) nullable | detecção de job órfão |
| `completed_at` | DATETIME(6) nullable |  |
| `created_at` | DATETIME(6) |  |
| `updated_at` | DATETIME(6) |  |

### Índices

- unique `uuid`;
- index `(status, created_at)`;
- index `(process_name, created_at)`.

Somente uma execução mutável por raiz/processo poderá obter lock distribuído. Dry-runs podem ser serializados inicialmente para reduzir I/O.

## 6. `file_scan_findings`

Registra achados; não dispara correção automática.

| Coluna | Tipo sugerido | Regra |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `scan_run_id` | BIGINT UNSIGNED | FK |
| `finding_type` | VARCHAR(50) | orphan, missing, broken_reference, duplicate_candidate, permission_denied, symlink, changed_during_scan |
| `severity` | VARCHAR(20) | info, low, medium, high, critical |
| `path_hash` | CHAR(64) nullable | agrupamento sem expor path em log |
| `restricted_path` | VARCHAR(500) nullable | visível apenas ao perfil técnico |
| `file_id` | BIGINT UNSIGNED nullable | FK quando conhecido |
| `source_reference_json` | JSON nullable | origem allowlisted |
| `evidence_json` | JSON nullable | tamanho/MIME/hash, sem conteúdo |
| `resolution_status` | VARCHAR(30) | open, acknowledged, resolved, false_positive |
| `resolved_by` | BIGINT nullable |  |
| `resolved_at` | DATETIME(6) nullable |  |
| `created_at` | DATETIME(6) |  |
| `updated_at` | DATETIME(6) |  |

### Índices

- index `(scan_run_id, severity)`;
- index `(finding_type, resolution_status, created_at)`;
- index `(file_id)`;
- index `(path_hash)`.

## 7. Outbox

Preferência: reutilizar um mecanismo outbox existente, se confirmado no inventário. Se não houver, criar `managed_file_outbox` apenas na Release B.

Campos mínimos:

```text
id, event_uuid, event_type, aggregate_uuid, payload_json,
attempts, available_at, processed_at, last_error_code, created_at
```

O payload não contém bytes nem caminhos absolutos. Um unique em `event_uuid` evita duplicação. Após o período operacional definido, eventos processados podem ser arquivados conforme política separada.

## 8. Política de categoria

As policies são código/configuração versionada na primeira entrega, não CRUD dinâmico em banco.

Exemplo conceitual:

| Categoria | Extensões | MIME detectado | Limite inicial | Inline |
|---|---|---|---:|---|
| `company_login_background` | jpg, jpeg, png, webp | image/jpeg, image/png, image/webp | 4 MiB | raster validado |
| `company_logo` | jpg, jpeg, png, webp | image/jpeg, image/png, image/webp | 4 MiB | raster validado |
| `equipment_photo` | jpg, jpeg, png, webp | image/jpeg, image/png, image/webp | 2 MiB | raster validado |
| `user_signature` | jpg, jpeg, png, webp | raster conhecido | 2 MiB | somente após normalização PNG |
| `order_pdf` | pdf | application/pdf | definido pelo gerador | conforme rota controlada |
| `chat_attachment` | decisão por produto | allowlist correspondente | 25 MiB | somente raster/PDF seguro; demais attachment |

SVG fica fora do MVP. Se for indispensável, deve ser sanitizado por biblioteca especializada, rasterizado quando possível e nunca servido inline sem análise adicional.

## 9. Estados e transições

### Lifecycle

```text
active -> archived -> active
active|archived -> trashed -> active|archived
```

Não existe `deleted` físico no MVP.

### Integridade

```text
unknown -> valid
unknown|valid -> missing|corrupted
missing|corrupted -> valid (após reparo comprovado)
```

### Segurança

```text
pending -> clean
pending -> quarantined|rejected
quarantined -> clean (aprovação e nova análise)
```

### Migração

```text
legacy -> cataloged -> migrating -> migrated
cataloged|migrating -> failed -> migrating
native (arquivo criado diretamente pelo núcleo)
```

Transições serão centralizadas em métodos explícitos e auditadas. Update genérico de status via controller é proibido.

## 10. Consistência com registros de domínio

### Configuração/branding

- configuração atual continua armazenando caminho legível pelo resolver legado durante o piloto;
- link central identifica `configuration/{key}`;
- troca ocorre somente após arquivo central confirmado;
- arquivo anterior permanece até expirar período de rollback.

### Fotos

- colunas/tabelas atuais permanecem;
- UUID central será adicionado de forma nullable apenas na onda do módulo;
- caminho legado continua preenchido durante a transição.

### Documentos de OS

- `os_documentos` e `os_documento_arquivos` permanecem donos de versão/formato;
- cada linha de arquivo ganha vínculo/UUID central sem mudar `arquivo` inicialmente;
- hash existente deve ser comparado, não recalculado silenciosamente e sobrescrito sem relatório.

### Chat

- `mensagem_anexos` permanece no banco `chat`;
- adicionar `managed_file_uuid` nullable em migration própria da conexão;
- nenhum FK cruzado;
- reconciliador confirma: central existe, chat referencia e link central corresponde.

## 11. Deduplicação futura

`sha256 + size` identifica apenas candidato. Reutilização física futura exigirá:

- mesmo contexto organizacional confirmado;
- MIME e categoria compatíveis;
- mesma confidencialidade e política de retenção;
- ausência de canal lateral que revele existência;
- reference counting transacional e reconciliável;
- restauração e backup compatíveis.

O MVP não reutiliza blob com base apenas no hash.

## 12. Migrations

Ordem sugerida:

1. tabelas centrais sem integração com módulos;
2. índices e permissões/RBAC;
3. outbox, se necessária;
4. coluna nullable no módulo piloto somente na Release C;
5. colunas nullable de cada onda em migrations separadas;
6. nenhuma remoção de coluna ou tabela legada neste projeto.

`down()` serve a ambientes de desenvolvimento vazios. Em produção, rollback operacional desativa o modo e preserva tabelas/dados; não se executa `migrate:rollback` destrutivo como resposta primária a incidente.


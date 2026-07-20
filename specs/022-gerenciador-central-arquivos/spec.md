# Feature Specification: Gerenciador Central de Arquivos

**Feature Branch**: `develop`

**Created**: 2026-07-19

**Status**: Implementado incrementalmente no ambiente LAN; operação em `shadow`, promoção externa pendente de aprovação

**Input**: centralizar o catálogo, a proteção, a rastreabilidade e a evolução dos arquivos funcionais do ERP sem interromper nem invalidar os fluxos legados.

## Objetivo

Implantar, de forma incremental e reversível, uma infraestrutura central para arquivos funcionais persistentes do ERP. A primeira entrega não substituirá os serviços de domínio existentes: ela fornecerá armazenamento imutável, metadados, validação por categoria, autorização, auditoria, integridade e compatibilidade para que cada módulo seja migrado separadamente.

O resultado deve reduzir riscos atuais de MIME inconsistente, conteúdo ativo servido inline, referências quebradas, substituição não atômica, caminhos espalhados e ausência de inventário, preservando URLs, payloads, nomes apresentados e registros legados.

## Escopo da iniciativa

### Incluído

- inventário somente leitura dos pontos de gravação, leitura, geração, envio e exclusão;
- testes de caracterização dos fluxos existentes antes de cada migração;
- correções urgentes de segurança independentes da migração;
- núcleo central de catálogo e armazenamento no backend Laravel;
- UUID público, hash SHA-256 por stream, MIME detectado e tamanho verificado;
- políticas de validação específicas por categoria, sem endpoint genérico de upload no MVP;
- referências a registros de negócio e aliases de caminhos legados;
- leitura compatível e fallback legado observável;
- auditoria append-only e métricas operacionais;
- scanner allowlist, dry-run, sem mover ou excluir arquivos;
- migração por módulo com feature flag, reconciliação e rollback;
- painel administrativo após estabilização do núcleo;
- documentação de compatibilidade, segurança, operação e resposta a incidentes.

### Fora do MVP

- mover em massa todos os arquivos existentes;
- desativar o fallback legado;
- exclusão física automática;
- retenção destrutiva;
- deduplicação global ou entre empresas;
- extração automática de ZIP/TAR/GZ;
- armazenamento S3 obrigatório em produção;
- antivírus como requisito bloqueante antes de existir infraestrutura operacional para ele;
- catalogar código-fonte, templates, assets, executáveis distribuídos, logs ou backups;
- persistir CSVs/relatórios que hoje são produzidos apenas por streaming;
- substituir regras de negócio de `OrderDocumentCenterService`, assinaturas, chat ou geradores PDF;
- oferecer um endpoint administrativo para upload arbitrário desvinculado de um módulo.

## User Scenarios & Testing

### User Story 1 - Continuar usando arquivos sem regressão (Priority: P1)

Como operador do ERP, quero continuar visualizando, baixando, enviando e gerando os mesmos arquivos durante a implantação para que a evolução seja invisível ao trabalho diário.

**Why this priority**: continuidade operacional e preservação do acervo são condições obrigatórias para qualquer outra entrega.

**Independent Test**: executar a matriz de caracterização antes/depois em um módulo piloto, incluindo arquivo antigo, arquivo novo, fallback e desativação do novo núcleo.

**Acceptance Scenarios**:

1. **Given** um registro que possui apenas caminho legado válido, **When** o arquivo é solicitado, **Then** o conteúdo, nome e status HTTP permanecem iguais e o uso do fallback é auditado.
2. **Given** o modo `observe` ou `shadow`, **When** o catálogo central falha, **Then** a operação legada termina com o mesmo resultado e a falha é registrada sem bloquear o usuário.
3. **Given** um arquivo criado no modo híbrido, **When** o modo central é desativado, **Then** o contrato legado ainda consegue localizá-lo por caminho ou alias compatível.
4. **Given** uma URL pública controlada já existente, **When** a implementação é ativada, **Then** token, expiração, autorização e resposta permanecem retrocompatíveis.

---

### User Story 2 - Armazenar novos arquivos com segurança e integridade (Priority: P1)

Como responsável técnico, quero que cada novo arquivo persistente seja validado, identificado e armazenado de forma imutável para reduzir XSS, execução indevida, corrupção e perda de referência.

**Independent Test**: armazenar arquivos permitidos e tentar MIME falso, extensão dupla, SVG ativo, HTML, arquivo vazio, grande demais e corrompido.

**Acceptance Scenarios**:

1. **Given** um upload permitido para uma categoria, **When** ele é aceito, **Then** UUID, hash, tamanho, MIME detectado, storage key e ator são registrados.
2. **Given** um conteúdo incompatível com a política da categoria, **When** o upload é processado, **Then** ele é rejeitado ou colocado em quarentena sem substituir o arquivo atual.
3. **Given** falha de disco ou banco durante a troca, **When** a operação termina, **Then** a referência anterior permanece válida e o staging é reconciliável.
4. **Given** um tipo ativo ou não confiável, **When** o download é realizado, **Then** a resposta força `attachment`, usa nome sanitizado e inclui `X-Content-Type-Options: nosniff`.

---

### User Story 3 - Autorizar o arquivo pelo registro de negócio (Priority: P1)

Como usuário autorizado, quero acessar apenas arquivos de registros que posso consultar para impedir IDOR e vazamento entre contas, clientes, OSs ou conversas.

**Independent Test**: autenticar usuários com contextos diferentes e trocar UUIDs, IDs de OS, conversa e documento nas rotas.

**Acceptance Scenarios**:

1. **Given** um usuário sem acesso ao registro vinculado, **When** ele informa um UUID válido, **Then** recebe resposta de acesso negado sem revelar metadados do arquivo.
2. **Given** um arquivo em quarentena, **When** um usuário comum solicita download, **Then** nenhum conteúdo é entregue.
3. **Given** um administrador do gerenciador, **When** ele consulta metadados, **Then** isso não concede automaticamente acesso ao conteúdo confidencial.
4. **Given** um arquivo vinculado ao chat, **When** ele é solicitado, **Then** a autorização continua delegada ao contexto da conversa.

---

### User Story 4 - Inventariar e reconciliar o acervo (Priority: P1)

Como administrador técnico, quero executar inventário somente leitura, retomável e limitado para conhecer arquivos legados, ausentes, duplicados e sem referência antes de qualquer migração.

**Independent Test**: executar o scanner em uma fixture com arquivos válidos, symlink, ausente, duplicado, diretório fora da raiz e interrupção no meio do lote.

**Acceptance Scenarios**:

1. **Given** uma raiz não autorizada, **When** o scanner é iniciado, **Then** ele recusa a execução.
2. **Given** symlink ou caminho cuja canonicalização sai da raiz, **When** ele é encontrado, **Then** não é seguido e um finding é registrado.
3. **Given** uma execução interrompida, **When** ela é retomada, **Then** continua do checkpoint sem duplicar registros.
4. **Given** um dry-run, **When** ele termina, **Then** nenhum arquivo ou referência funcional foi alterado.

---

### User Story 5 - Migrar um módulo com rollback comprovado (Priority: P2)

Como responsável pela implantação, quero ativar o gerenciador por módulo e voltar ao fluxo anterior por configuração para limitar o raio de impacto.

**Independent Test**: migrar o fundo da tela de login em homologação, validar leitura nova/legada, provocar falhas e executar o rollback documentado.

**Acceptance Scenarios**:

1. **Given** o módulo fora da allowlist, **When** um upload ocorre, **Then** somente o fluxo legado é usado.
2. **Given** o módulo em `shadow`, **When** uma divergência é detectada, **Then** o fluxo legado vence e a divergência gera alerta.
3. **Given** o módulo em `hybrid`, **When** o novo fluxo falha antes da troca da referência, **Then** o arquivo anterior continua disponível.
4. **Given** o rollback acionado, **When** as verificações de fumaça são executadas, **Then** upload, leitura e remoção lógica voltam ao comportamento legado sem perda dos arquivos novos.

---

### User Story 6 - Administrar o catálogo e responder a incidentes (Priority: P3)

Como administrador autorizado, quero consultar saúde, uso, quarentena, ausentes e fallback sem expor conteúdo sensível para diagnosticar falhas e planejar migrações.

**Independent Test**: popular o catálogo com estados distintos e validar filtros, paginação, RBAC, auditoria e ausência de caminhos físicos na API.

**Acceptance Scenarios**:

1. **Given** milhares de registros, **When** o catálogo é consultado, **Then** a resposta é paginada e usa filtros indexados.
2. **Given** um finding crítico, **When** o administrador abre o detalhe, **Then** vê evidências e ações seguras, mas não recebe caminho absoluto nem conteúdo por padrão.
3. **Given** uma ação de restauração ou quarentena, **When** ela é executada, **Then** autor, motivo e resultado são auditados.
4. **Given** uma exclusão definitiva futura, **When** não há reautenticação, aprovação e retenção satisfeita, **Then** a ação é recusada.

## Edge Cases

- Banco confirma e o storage falha, ou storage confirma e o banco falha.
- Dois uploads concorrentes tentam substituir o mesmo arquivo lógico.
- Arquivo muda enquanto o scanner calcula hash.
- Hash igual com tamanho, MIME, confidencialidade ou contexto de acesso diferentes.
- Caminho legado contém barra invertida, `..`, byte nulo, Unicode confusável ou case divergente.
- Arquivo existe em disco mas o usuário do PHP-FPM não consegue lê-lo.
- Registro central existe, mas a referência está em outra conexão de banco (`chat`).
- Worker cai depois de gravar o blob e antes de concluir a reconciliação.
- Download solicita range parcial de arquivo grande.
- Original name contém CR/LF, aspas, separadores, caracteres de controle ou tamanho excessivo.
- PDF ou imagem possui conteúdo malformado/decompression bomb.
- Arquivo legado público continua referenciado por URL histórica.
- Rollback ocorre depois que arquivos novos já foram gravados.
- Backup ou executável legítimo do coletor aparece próximo das raízes funcionais.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST manter rotas, payloads, nomes de download e campos legados durante a migração, salvo correção de segurança documentada.
- **FR-002**: O núcleo MUST operar nos modos `off`, `observe`, `shadow`, `hybrid` e `primary`, com ativação adicional por módulo/categoria.
- **FR-003**: Operações destrutivas MUST possuir kill switch independente, desativado por padrão.
- **FR-004**: Cada arquivo central MUST possuir ID interno, UUID público, disk, storage key, nome original, nome seguro, extensão, MIME declarado, MIME detectado, tamanho e SHA-256.
- **FR-005**: O storage key MUST ser relativo ao disco; caminhos absolutos MUST NOT ser persistidos ou expostos em contratos.
- **FR-006**: Novos arquivos MUST ser gravados por stream, em staging, validados e promovidos para chave imutável antes da troca de referência.
- **FR-007**: O sistema MUST preservar a referência anterior se qualquer etapa anterior à promoção falhar.
- **FR-008**: Validação MUST ser definida por categoria; extensão e MIME isolados não são evidência suficiente.
- **FR-009**: HTML, SVG não sanitizado, XML ativo e outros tipos executáveis no navegador MUST NOT ser servidos inline.
- **FR-010**: Downloads MUST autorizar pelo registro de negócio, não apenas pelo conhecimento do UUID.
- **FR-011**: A API MUST aplicar `nosniff`, `Content-Disposition` seguro, MIME controlado e política de cache apropriada.
- **FR-012**: O sistema MUST manter estados separados de ciclo de vida, integridade, segurança e migração.
- **FR-013**: O sistema MUST registrar vínculos idempotentes entre arquivos e registros de negócio por tipos explicitamente permitidos.
- **FR-014**: O sistema MUST suportar múltiplos aliases legados por arquivo sem exigir movimentação física.
- **FR-015**: A leitura compatível MUST registrar `LEGACY_FALLBACK_USED` sem registrar dados pessoais, conteúdo ou caminho absoluto em logs comuns.
- **FR-016**: Auditoria MUST registrar ator, ação, resultado, contexto mínimo e fingerprints de IP/user-agent quando necessários.
- **FR-017**: A falha da auditoria no modo shadow MUST NOT quebrar o fluxo legado; a falha MUST ser reconciliada de forma assíncrona.
- **FR-018**: O scanner MUST aceitar somente raízes configuradas, canonicalizar caminhos, não seguir symlinks e limitar profundidade, lote, tempo e I/O.
- **FR-019**: O scanner MUST oferecer dry-run, checkpoint, retomada e execução idempotente.
- **FR-020**: Scanner e migração MUST NOT executar, incluir, interpretar ou descompactar automaticamente o conteúdo encontrado.
- **FR-021**: Código, configuração, logs, cache, backups, dependências e assets MUST permanecer fora das raízes funcionais por padrão.
- **FR-022**: A aplicação MUST distinguir arquivo persistente de negócio de stream temporário, arquivo de importação e asset confiável de distribuição.
- **FR-023**: A migração do chat MUST considerar a conexão de banco separada e usar reconciliação; atomicidade distribuída MUST NOT ser presumida.
- **FR-024**: Cada módulo MUST possuir testes de caracterização, plano de ativação e rollback antes de entrar em `hybrid`.
- **FR-025**: O fallback legado MUST NOT ser desativado enquanto houver referências não catalogadas, métricas de fallback relevantes ou rollback não validado.
- **FR-026**: Exclusão física MUST exigir ausência de vínculos ativos, ausência de retenção, integridade do backup, aprovação e reautenticação administrativa.
- **FR-027**: O painel MUST ser paginado, filtrável e não carregar binários nem realizar N+1.
- **FR-028**: O painel MUST separar metadados administrativos da autorização para visualizar o conteúdo.
- **FR-029**: O sistema MUST emitir métricas de sucesso/falha, latência, bytes, fallback, divergência, quarentena e reconciliação.
- **FR-030**: Jobs MUST ser idempotentes, possuir retry limitado, backoff, lock e dead-letter operacional ou estado terminal consultável.
- **FR-031**: Alterações de API MUST atualizar `backend/openapi.yaml`; alterações operacionais MUST atualizar `documentacao/`.
- **FR-032**: O módulo piloto MUST usar namespace físico compatível com o leitor legado até que o rollback de arquivos novos esteja comprovado.
- **FR-033**: Deduplicação, quando futuramente ativada, MUST ser invisível ao usuário e nunca revelar a existência de arquivo de outro contexto.
- **FR-034**: `last_accessed_at` MUST NOT ser atualizado sincronicamente na linha principal a cada download; acessos serão derivados da auditoria ou agregados assincronamente.

### Non-Functional Requirements

- **NFR-001**: Upload e download devem usar memória aproximadamente constante em relação ao tamanho do arquivo.
- **NFR-002**: O overhead do catálogo não deve elevar a latência p95 de downloads em mais de 10% no piloto, excluindo transferência do conteúdo.
- **NFR-003**: O modo shadow não deve reduzir a taxa de sucesso do fluxo legado.
- **NFR-004**: O rollback operacional de um módulo deve ser executável em até 15 minutos sem migration destrutiva.
- **NFR-005**: Scanner e migração devem permitir limites de CPU/I/O e não executar durante requisições web.
- **NFR-006**: Toda listagem administrativa deve ser paginada com limite máximo de 100 registros.
- **NFR-007**: Todo texto e contrato deve permanecer em pt-BR e UTF-8 quando voltado ao usuário/documentação.

### Key Entities

- **ManagedFile**: blob funcional imutável e seus metadados técnicos/classificatórios.
- **ManagedFileLink**: vínculo idempotente do arquivo com um registro de negócio permitido.
- **ManagedFileLegacyAlias**: caminho ou identificador legado que continua resolvível.
- **ManagedFileEvent**: evento append-only de segurança, acesso, migração e ciclo de vida.
- **FileScanRun**: execução limitada e retomável de inventário/migração.
- **FileScanFinding**: divergência observada sem ação destrutiva automática.
- **FileCategoryPolicy**: configuração versionada de formatos, limites, comportamento de preview e classificação padrão.

## Security Requirements

- allowlist por categoria e fail-closed fora do modo shadow;
- defesa contra IDOR, traversal, MIME spoofing, dupla extensão, XSS armazenado, CSV Formula Injection, XXE, zip bomb, symlink e decompression bomb;
- quarentena em disco separado e sem rota de serving;
- nomes físicos aleatórios e nomes originais apenas para apresentação;
- autorização central com delegação ao serviço/policy do domínio;
- nenhuma deduplicação global entre contextos de acesso;
- logs sem conteúdo, token, credencial, caminho absoluto ou PII desnecessária;
- ações sensíveis protegidas por RBAC, justificativa, rate limit e reautenticação quando aplicável;
- arquivos nunca executados pelo scanner ou pelo servidor web;
- backups do banco e storage verificados em conjunto antes de mudanças de modo.

## Success Criteria

- **SC-001**: 100% dos fluxos do módulo piloto possuem testes de caracterização aprovados antes/depois.
- **SC-002**: 100% dos arquivos legados da amostra homologada continuam acessíveis com mesmo conteúdo e nome apresentado.
- **SC-003**: MIME falso, HTML, SVG ativo, extensão dupla e traversal são bloqueados nos testes de segurança.
- **SC-004**: Um UUID válido sem autorização do registro nunca entrega metadados sensíveis nem conteúdo.
- **SC-005**: Uma falha induzida de storage/banco não remove a referência anterior nem produz perda silenciosa.
- **SC-006**: Reexecução de scanner/migração não duplica arquivos, aliases, links ou eventos idempotentes.
- **SC-007**: O modo shadow pode ser desativado sem rollback de banco e sem alteração do comportamento visível.
- **SC-008**: O rollback do piloto é concluído em até 15 minutos e os arquivos criados no modo híbrido continuam legíveis.
- **SC-009**: Download de arquivo grande apresenta memória estável e streaming comprovado em teste.
- **SC-010**: Nenhuma exclusão física, retenção destrutiva ou deduplicação está ativa na implantação inicial.
- **SC-011**: Fallback, divergências, falhas, quarentena e reconciliação possuem métricas e alertas verificáveis.
- **SC-012**: Rotas existentes de OS, PDFs, assinaturas, chat, logo, fotos, importações e exportações passam na regressão antes de cada promoção.

## Assumptions

- A instalação atual é tratada como single-company; multi-tenancy não será introduzida especulativamente. Caso seja confirmado, o modelo será evoluído antes da deduplicação.
- O disco `local` atual já possui raiz privada em `storage/app/private`; novas chaves canônicas não repetirão o prefixo `private/` depois da fase de compatibilidade.
- O banco principal e a conexão `chat` podem falhar independentemente; consistência entre eles será eventual e reconciliável.
- Laravel Storage/Flysystem continuará sendo a abstração de provider.
- Redis, filas e scheduler existentes serão reutilizados.
- Os serviços de domínio atuais permanecem responsáveis por regras de OS, documentos, assinaturas, conversas e integrações.
- Uma infraestrutura de antivírus poderá ser acoplada por contrato, mas não será simulada como proteção existente.
- Aprovação para implementar, migrar, versionar, publicar ou executar operação destrutiva será solicitada em etapas separadas.

## Dependências

- inventário dos campos de banco e caminhos existentes;
- baseline de testes dos fluxos de arquivos;
- permissões do usuário do PHP-FPM sobre storage privado;
- backup conjunto de banco e storage;
- workers Redis/Supervisor e scheduler saudáveis;
- definição operacional dos formatos necessários por categoria;
- decisão explícita sobre política de SVG, anexos do chat e arquivos compactados;
- homologação antes de qualquer ativação em produção.

## Risks

- **Crítico**: rollback incapaz de ler arquivos criados no modo híbrido; mitigado por namespace/alias compatível e teste obrigatório.
- **Alto**: stored XSS por arquivo ativo servido inline; mitigado imediatamente por allowlist e headers.
- **Alto**: inconsistência storage/banco; mitigada por staging, promoção, idempotência e reconciliação.
- **Alto**: referência cruzada com banco do chat; mitigada por saga simples e job reconciliador.
- **Alto**: scanner atingir backups, executáveis ou código; mitigado por raízes allowlist e trust zones.
- **Médio**: custo de hash e inventário afetar I/O; mitigado por lotes, prioridade baixa e janela operacional.
- **Médio**: crescimento de auditoria; mitigado por paginação, índices, retenção própria e agregação assíncrona.
- **Médio**: `FileManager` absorver regras de domínio; mitigado por contratos pequenos e ownership mantido nos serviços atuais.

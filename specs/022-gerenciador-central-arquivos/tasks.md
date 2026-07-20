# Tasks: Gerenciador Central de Arquivos

## Convenções

- `[P]`: pode executar em paralelo após dependências da fase.
- `[USx]`: vinculado à user story da spec.
- Cada gate exige evidência de teste, risco residual e rollback.
- Nenhuma fase posterior é autorização implícita para produção.
- Tasks destrutivas permanecem fora do escopo até nova aprovação.

## Phase 0 — Projeto e governança

- [x] T001 Criar `spec.md` com escopo, exclusões, requisitos e critérios mensuráveis.
- [x] T002 Criar `plan.md` com arquitetura, releases, gates, deploy e rollback.
- [x] T003 Criar `data-model.md` separando lifecycle, integridade, segurança e migração.
- [x] T004 Criar `research.md` com baseline, findings e decisões.
- [x] T005 Criar contrato OpenAPI proposto sem alterar ainda o contrato oficial.
- [x] T006 Criar checklist de requisitos/segurança.
- [x] T007 Criar runbook de compatibilidade e rollback.
- [x] T008 Criar quickstart de execução do projeto.
- [ ] T009 Registrar owners técnico, segurança, produto e operação para cada release.
- [ ] T010 Aprovar formalmente escopo do MVP e itens explicitamente adiados.

**Gate G0**: projeto aprovado; implementar apenas após autorização explícita.

## Phase 1 — Inventário somente leitura [US1/US4]

- [x] T011 Criar script reproduzível que liste usos de Storage, UploadedFile e funções de filesystem em `backend/app`, rotas, jobs e comandos.
- [x] T012 Inventariar controllers, services, jobs, commands e integrações por fluxo.
- [x] T013 Inventariar todas as rotas autenticadas, públicas, assinadas e de webhook relacionadas a arquivo.
- [x] T014 Inventariar tabelas/colunas de nome, caminho, disk, MIME, tamanho e hash no banco principal.
- [x] T015 Inventariar tabelas/colunas equivalentes na conexão `chat`.
- [x] T016 Inventariar raízes do storage privado, legado público, temporários, backups e assets.
- [x] T017 Medir quantidade, bytes e distribuição por extensão/tamanho sem registrar conteúdo.
- [x] T018 Mapear permissões POSIX, owner/group e capacidade de leitura do usuário PHP-FPM/worker.
- [x] T019 [P] Mapear fluxo de branding: upload, transformação, config, entrega pública e uso em PDF.
- [x] T020 [P] Mapear fluxo de fotos de equipamentos e OS.
- [x] T021 [P] Mapear fluxo de documentos/PDFs/ZIP/impressão/links/envios de OS.
- [x] T022 [P] Mapear fluxo de assinaturas e previews.
- [x] T023 [P] Mapear fluxo de anexos outbound/inbound do chat e WhatsApp.
- [x] T024 [P] Mapear imports/exports CSV e diferenciar persistentes de streams.
- [x] T025 [P] Mapear executáveis do coletor, assets e backups para exclusão explícita.
- [x] T026 Produzir matriz módulo → rota → controller → service → tabela → path → autorização.
- [x] T027 Classificar cada fluxo em trust zone e risco BAIXO/MÉDIO/ALTO/CRÍTICO.
- [ ] T028 Verificar cobertura e restauração conjunta de backup de banco + storage.
- [x] T029 Registrar inventário em `documentacao/03-arquitetura-tecnica/` sem incluir PII/caminhos sensíveis desnecessários.

**Gate G1**: inventário reproduzível; nenhuma movimentação/exclusão; riscos críticos conhecidos.

## Phase 2 — Testes de caracterização [US1]

- [x] T030 Criar helper de teste para comparar status, headers, nome, tamanho e SHA-256 das respostas.
- [ ] T031 [P] Caracterizar branding público/autenticado e comportamento de cache.
- [ ] T032 [P] Caracterizar criação, visualização e remoção/substituição de fotos.
- [ ] T033 [P] Caracterizar geração de orçamento/abertura A4 e 80mm.
- [ ] T034 [P] Caracterizar catálogo, versão, archive/unarchive, ZIP, impressão e download de OS.
- [ ] T035 [P] Caracterizar links públicos: válido, expirado, revogado e documento não pertencente ao link.
- [ ] T036 [P] Caracterizar assinatura interna, cliente, preview e fingerprint.
- [ ] T037 [P] Caracterizar chat: upload, inbound, autorização por conversa, envio WhatsApp e download.
- [ ] T038 [P] Caracterizar import/export CSV de serviços e estoque.
- [ ] T039 Caracterizar arquivos legados reais anonimizados e paths com variações de separador/case.
- [ ] T040 Definir fixtures pequenas, grandes, corrompidas e ausentes.
- [ ] T041 Executar baseline e arquivar resultados/versionar hashes das fixtures.

**Gate G2**: matriz de caracterização verde e capaz de detectar diferença não planejada.

## Phase 3 — Correções urgentes de segurança (Release A) [US2/US3]

- [x] T042 Bloquear/remover SVG no branding do MVP ou implementar rasterização/sanitização comprovada.
- [x] T043 Alterar resposta de branding para MIME controlado, `nosniff` e headers consistentes.
- [x] T044 Implementar create-before-swap em `CompanyProfileService` e validar retorno do storage/otimização.
- [x] T045 Preservar arquivo anterior quando gravação, resize ou update de configuração falhar.
- [x] T046 Definir allowlist inicial de anexos do chat com produto/operação.
- [x] T047 Forçar `attachment` para HTML, SVG, XML e tipos não aprovados; inline apenas para policy segura.
- [x] T048 Sanitizar nomes de download e testar CR/LF, aspas, Unicode e comprimento.
- [x] T049 Adicionar `nosniff`, cache e CSP/no-store onde aplicável aos downloads/previews.
- [x] T050 Testar stored XSS, MIME spoofing, dupla extensão e IDOR nos fluxos corrigidos.
- [x] T051 Executar regressões de branding, login, chat, PDFs, WhatsApp e autorização.
- [x] T052 Atualizar OpenAPI/documentação e versionar a correção conforme protocolo do repositório.

**Gate G3**: riscos S-001/S-002/S-003 tratados e regressões aprovadas antes do núcleo.

## Phase 4 — Fundação do núcleo (Release B) [US2/US3]

- [x] T053 Criar `config/file-manager.php` com modo, allowlist de módulos, roots e kill switches.
- [x] T054 Validar configuração e recusar combinações inseguras na inicialização/comando de diagnóstico.
- [x] T055 Criar migrations aditivas de `managed_files`, links, aliases, eventos, scan runs/findings e outbox se necessária.
- [x] T056 Criar enums/value objects para estados, actions, categories e origins.
- [x] T057 Criar DTOs imutáveis `FileContext`, `FileDescriptor` e `StoredFileResult`.
- [x] T058 Criar models com casts, scopes pagináveis e relações sem lógica de domínio.
- [x] T059 Criar contratos `FileStorage`, `FileCatalog`, `FileAuthorizer` e integração opcional de malware scan.
- [x] T060 Implementar storage local com staging e read streams.
- [x] T061 Implementar geração de chave imutável e promoção sem overwrite.
- [x] T062 Implementar cálculo SHA-256/tamanho por stream.
- [x] T063 Implementar normalização de filename/storage key e proteção contra traversal.
- [x] T064 Implementar registry de policies por categoria.
- [x] T065 Implementar validação de extensão, MIME finfo, tamanho, empty file e decoder específico.
- [x] T066 Implementar catálogo idempotente com `operation_key`.
- [x] T067 Implementar link/unlink idempotente e aliases legados.
- [x] T068 Implementar máquina de transição de estados e recusar updates inválidos.
- [x] T069 Implementar event recorder/outbox com payload allowlisted.
- [x] T070 Implementar registry de authorizers por `subject_type` allowlisted.
- [x] T071 Implementar `LegacyFileResolver` com canonicalização e métricas.
- [x] T072 Implementar `FileManagerFacade` como orquestrador pequeno.
- [x] T073 Implementar reconciliador de blob sem registro, registro sem blob e vínculo parcial.
- [x] T074 Implementar locks e retry/backoff idempotente.
- [ ] T075 Testar falha de disco, banco, fila e worker em cada ponto da sequência.
- [ ] T076 Testar concorrência de duas substituições e retry da mesma operation key.
- [x] T077 Testar provider com `Storage::fake` e filesystem Linux real.
- [x] T078 Validar índices e planos das queries críticas em MySQL.
- [x] T079 Documentar decisões definitivas e atualizar o modelo se o inventário divergir.

**Gate G4**: núcleo testado isoladamente; nenhuma rota existente depende dele.

## Phase 5 — Observe, shadow e scanner [US1/US4]

- [x] T080 Instrumentar boundaries dos serviços atuais para emitir observações sem alterar paths.
- [x] T081 Garantir que falha do observer no modo `observe/shadow` não interrompa o legado.
- [x] T082 Criar `file-manager:diagnose` para validar configuração, disks, DB, fila e permissões.
- [x] T083 Criar `file-manager:scan --dry-run` com roots allowlist e dry-run default.
- [x] T084 Implementar recusa de root não configurada e canonicalização realpath.
- [x] T085 Implementar não seguimento de symlink e detecção de arquivo especial/socket/pipe.
- [x] T086 Implementar lote, profundidade, timeout, rate de I/O, checkpoint e heartbeat.
- [x] T087 Implementar findings de orphan, missing, broken reference, permission denied e changed-during-scan.
- [x] T088 Implementar `file-manager:check-integrity` em lotes.
- [x] T089 Implementar `file-manager:reconcile` idempotente.
- [ ] T090 Implementar métricas e alertas de shadow/fallback/divergência.
- [x] T091 Executar scanner somente em fixtures e roots de homologação autorizadas.
- [x] T092 Provar por hashes/stat que dry-run não alterou arquivos nem referências.
- [ ] T093 Operar shadow pelo período aprovado e produzir relatório de divergências.

**Gate G5**: shadow estável, sem redução de sucesso e sem finding crítico inexplicado.

## Phase 6 — Piloto fundo de login (Release C) [US1/US2/US5]

- [x] T094 Criar policy `company_login_background` para JPG/PNG/WebP raster.
- [x] T095 Criar adapter de configuração mantendo namespace/path compatível com o leitor legado.
- [x] T096 Integrar store central em modo allowlist sem mudar endpoint/payload atual.
- [x] T097 Implementar leitura central com fallback para valor atual da configuração.
- [x] T098 Garantir create-before-swap e retenção temporária da versão anterior.
- [x] T099 Implementar métricas específicas de central/fallback do piloto.
- [x] T100 Testar upload válido, MIME falso, grande, corrompido e falha durante resize.
- [ ] T101 Testar caching/headers e responsividade da tela de login.
- [ ] T102 Executar rollout `off -> observe -> shadow -> hybrid` em desenvolvimento/homologação.
- [x] T103 Criar arquivo no híbrido, acionar `off` e comprovar leitura pelo contrato legado.
- [x] T104 Documentar resultado, latência, memória, divergências e risco residual.

**Gate G6**: primeiro módulo híbrido e rollback de arquivo novo comprovados.

## Phase 7 — Logo e fotos (Release D) [US1/US2/US3/US5]

- [x] T105 Migrar logo raster preservando uso no login, sidebar e Dompdf.
- [ ] T106 Testar regressão de todos os PDFs que incorporam logo.
- [x] T107 Criar policies de foto de equipamento e OS.
- [x] T108 Integrar adapter em `EquipmentWorkflowService` sem alterar limites/contratos inesperadamente.
- [x] T109 Catalogar paths legados de fotos em shadow antes de escrita híbrida.
- [x] T110 Implementar authorizer por equipamento/OS.
- [ ] T111 Testar IDOR, MIME, imagem malformada, dimensões e consumo de memória.
- [ ] T112 Testar criação/edição de equipamento e OS, preview e visualizador desktop.
- [ ] T113 Exercitar rollback independente de logo e fotos.

**Gate G7**: branding e fotos estáveis, sem regressão visual/PDF.

## Phase 8 — PDFs, documentos e assinaturas (Release E) [US1/US2/US3/US5]

- [ ] T114 Criar adapter para arquivos gerados por `BudgetPdfService`, abertura e fechamento.
- [x] T115 Criar adapter para `OrderDocumentCenterService` sem mover regras de versão/tipo.
- [x] T116 Vincular `os_documento_arquivos` ao UUID central por migration nullable aditiva.
- [x] T117 Preservar `os_documentos.arquivo` e aliases A4/80mm durante toda a transição.
- [x] T118 Comparar SHA-256 existente e central; registrar divergência sem sobrescrever evidência.
- [x] T119 Preservar idempotency key, lock de versão, archive/unarchive e links.
- [x] T120 Integrar arquivos de assinatura e preview sem enfraquecer reautenticação/revisão.
- [ ] T121 Testar geração A4/80mm, ZIP, impressão, download, WhatsApp e e-mail.
- [ ] T122 Testar links públicos válidos/expirados/revogados e tentativa de trocar document ID.
- [ ] T123 Testar alteração de OS/template invalidando revisão de assinatura.
- [ ] T124 Testar fallback e rollback com documentos antigos e novos.
- [ ] T125 Medir tempo/memória de geração e streaming de bundles.

**Gate G8**: paridade documental e de assinatura completa; zero diferença não planejada.

## Phase 9 — Chat e integrações (Release F) [US1/US2/US3/US5]

- [x] T126 Aprovar allowlist final de anexos por canal e política de inline/attachment.
- [x] T127 Adicionar `managed_file_uuid` nullable em `mensagem_anexos` na conexão `chat`.
- [x] T128 Implementar saga `pending_link -> linked` e compensação.
- [x] T129 Implementar adapter/authorizer por conversa e conta de atendimento.
- [x] T130 Preservar limite inbound, trusted origins, timeout e comportamento de webhook.
- [x] T131 Validar redirects/DNS contra SSRF conforme comportamento real do cliente HTTP.
- [x] T132 Migrar outbound upload e inbound base64/URL em shadow, depois hybrid.
- [x] T133 Implementar reconciliador entre catálogo central e banco `chat`.
- [ ] T134 Testar queda de cada banco, storage, provider e worker em passos distintos.
- [ ] T135 Testar IDOR entre conversas/contas e arquivo em quarentena.
- [ ] T136 Testar envio/recebimento de imagem, áudio, vídeo, PDF e rejeitados.
- [ ] T137 Exercitar rollback preservando anexos criados no híbrido.

**Gate G9**: consistência eventual comprovada e fluxo de atendimento sem regressão.

## Phase 10 — Painel administrativo (Release G) [US6]

- [x] T138 Definir permissões independentes de listar, ver metadados, baixar, quarentenar, restaurar e administrar.
- [x] T139 Criar endpoints paginados conforme contrato proposto e atualizar OpenAPI oficial.
- [x] T140 Criar BFF/service/controller desktop sem acesso direto ao banco.
- [x] T141 Criar dashboard de volume, categorias, fallback, integridade e reconciliação.
- [x] T142 Criar catálogo filtrável sem carregar binários nem paths absolutos.
- [x] T143 Criar detalhe com eventos e findings mascarados.
- [x] T144 Implementar archive/restore/quarantine/release com motivo e auditoria.
- [x] T145 Exigir step-up para ações sensíveis definidas pelo projeto.
- [x] T146 Manter trash físico, empty trash, retenção destrutiva e deduplicação desativados.
- [x] T147 Validar paginação, índices, ausência de N+1 e limites máximos.
- [ ] T148 Validar responsividade e Select2 conforme constituição.
- [x] T149 Testar RBAC/IDOR/CSRF e separação metadata/conteúdo.
- [x] T149A Implementar biblioteca em grade/lista, seleção da página, ZIP e lixeira lógica sem purga física.
- [x] T149B Implementar sincronização automática e solicitação manual assíncrona/deduplicada.
- [x] T149C Implementar miniaturas lazy de imagem e primeira página de PDF com cache/lock/limites.
- [x] T149D Resolver data do documento e cliente vinculado em lote, respeitando o RBAC do domínio.
- [x] T149E Substituir nova aba por modal de preview com recursos específicos para imagem e PDF.

**Gate G10**: painel seguro, paginado, observável e não destrutivo.

## Phase 11 — Homologação, rollout e documentação

- [x] T150 Atualizar `backend/openapi.yaml` e contrato humano da API.
- [x] T151 Atualizar `documentacao/01-fundacao/acesso-seguro-a-arquivos.md`.
- [x] T152 Criar documentação técnica do núcleo em `documentacao/03-arquitetura-tecnica/`.
- [x] T153 Criar runbook operacional de scanner, reconciliação e incidentes.
- [ ] T154 Documentar backup/restauração conjunta banco + storage e testar restore.
- [ ] T155 Executar suíte unitária, feature, segurança, migração, rollback e regressão por release.
- [x] T156 Executar análise estática/lint e verificar rotas/OpenAPI.
- [ ] T157 Validar Supervisor, queue, scheduler, Redis e alertas.
- [ ] T158 Executar teste de carga focado em streaming, catálogo e eventos.
- [x] T159 Registrar riscos residuais, limitações e itens adiados.
- [x] T160 Sincronizar documentação viva do repositório.
- [x] T161 Classificar/versionar cada release conforme `VERSIONING.md`.
- [ ] T162 Obter aprovação explícita antes de promover `develop -> main`.
- [ ] T163 Executar backup antes de cada deploy de produção.
- [ ] T164 Fazer rollout por módulo, monitorar e manter rollback por janela aprovada.

## Phase 12 — Pós-estabilização (projetos futuros, não autorizados)

- [ ] T165 Avaliar object storage/S3 com teste de latência, custo e restore.
- [ ] T166 Avaliar antivírus operacional e SLA de quarentena.
- [ ] T167 Definir retenção com jurídico/LGPD e implementar somente dry-run primeiro.
- [ ] T168 Avaliar deduplicação restrita após confirmar single/multiempresa e threat model.
- [ ] T169 Avaliar thumbnails assíncronos apenas para categorias que gerem valor real.
- [ ] T170 Planejar desligamento do fallback somente com fallback próximo de zero, backup e aprovação.

## Definition of Done por task/release

- comportamento atual caracterizado antes da mudança;
- código simples e coberto por testes proporcionais ao risco;
- autorização e threat model revisados;
- performance/índices avaliados;
- logs sem segredos/PII desnecessária;
- migrations aditivas e rollback operacional validado;
- OpenAPI e documentação sincronizados;
- evidência de testes anexada à nota da release;
- nenhuma diferença não planejada conhecida;
- versão registrada quando houver código/entrega funcional.

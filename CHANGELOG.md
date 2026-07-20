# Changelog — Sistema ERP Jovem Tech

## v5.2.2.0 — 2026-07-20 20:30
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** corrige a seleção e a confirmação da lixeira para usuários administrativos definidos pelo RBAC, sem depender do campo legado `perfil=admin`
- **Arquitetura:** mantém dupla autorização: a sessão exige `arquivos:excluir` e a credencial de step-up exige `arquivos:administrar`; o desktop usa POST sem retry para o comando mutável
- **Segurança:** senha e motivo continuam obrigatórios, rate limit e auditoria permanecem ativos, perfil legado sem RBAC não contorna a regra e falha de escrita no log não transforma uma credencial recusada em HTTP 500
- **Experiência:** amplia a área clicável do checkbox em lista, explica as permissões necessárias e preenche o e-mail da sessão quando o próprio usuário pode administrar arquivos
- **Performance/Resiliência:** elimina três chamadas repetidas ao endpoint de lixeira em respostas 5xx e preserva o binário para restauração
- **Operação:** grupo do log atual corrigido para `www-data`; runbook passa a exigir `setgid` em `backend/storage/logs` para arquivos futuros
- **Validação:** 23 testes direcionados aprovados com 194 asserções; fluxo real do usuário supervisor da LAN validado em transação integralmente revertida
- **Arquivos:** backend/app/Services/Auth/AdminCredentialVerifier.php,backend/app/Http/Controllers/Api/V1/FileManagerController.php,backend/tests/Feature/Files/FileManagerApiTest.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/FileManagerService.php,frontends/desktop/resources/views/files/index.blade.php,frontends/desktop/tests/Feature/Desktop/FileManagerTest.php,documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,documentacao/10-deploy/deploy-producao-lan-ubuntu.md,VERSION,shared/version.php,CHANGELOG.md

## v5.2.1.0 — 2026-07-20 07:35
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** abre a miniatura da Central Documental da OS no modal interno com iframe PDF, em vez de navegar para outra aba
- **Arquitetura:** reutiliza o visualizador compartilhado do Gerenciador de Arquivos e mantém o endpoint autenticado da OS como fonte do iframe e do download
- **Segurança:** iframe same-origin com `referrerpolicy=no-referrer`; o `src` só é atribuído após o clique e retorna para `about:blank` ao fechar o modal
- **Performance:** nenhum PDF é carregado antes da interação; a URL e o nome do arquivo acompanham a versão selecionada sem recarregar a página
- **Compatibilidade:** o `href` autenticado permanece como fallback progressivo, enquanto o JavaScript intercepta o clique para abrir o modal
- **Validação:** teste direcionado aprovado com 26 asserções e JavaScript validado pelo parser do Node
- **Arquivos:** frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,VERSION,shared/version.php,CHANGELOG.md

## v5.2.0.0 — 2026-07-20 05:39
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** adiciona à Central Documental da OS a coluna Foto com miniatura da primeira página do PDF mais recente e atualização dinâmica ao selecionar outra versão
- **Arquitetura:** nova rota autenticada da OS reutiliza o serviço central de miniaturas e o cache por SHA-256; o desktop permanece como BFF e não acessa storage ou banco diretamente
- **Segurança:** autorização `os:visualizar`, validação de vínculo documento/OS, estados seguros do arquivo gerenciado, contenção de path e resposta privada com `nosniff`; não exige a permissão administrativa `arquivos:baixar`
- **Performance:** carregamento lazy, cache privado com ETag e geração única por hash; nenhum PDF completo é transportado na listagem
- **Compatibilidade:** mudança aditiva, sem migration e sem alteração das rotas documentais existentes; documentos ausentes exibem fallback visual
- **Validação:** 3 testes direcionados aprovados (31 asserções), sintaxe PHP/JS validada e novas rotas confirmadas no ambiente LAN
- **Arquivos:** backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/routes/api.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,VERSION,shared/version.php,CHANGELOG.md

## v5.1.0.0 — 2026-07-20 03:55
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** evolui o Gerenciador de Arquivos com sincronização automática e manual, biblioteca visual, miniaturas, modal seguro, contexto de cliente/data, controles RBAC em massa e criação idempotente de OS
- **Arquitetura:** catálogo central e vínculos de domínio continuam no backend; o desktop atua como BFF; sincronização e renderização são serviços isolados, configuráveis e protegidos por locks
- **Segurança:** autorização por vínculo e RBAC, proteção contra IDOR/traversal/MIME spoofing/command injection, step-up sem flash de senha, CSRF, rate limits, estados seguros e ausência de purga física
- **Performance:** catálogo paginado, cliente resolvido em lote sem N+1, hashes por stream, miniaturas PDF lazy/cacheadas por SHA-256 e sincronização fora da requisição web
- **Banco:** migrations aditivas do catálogo/RBAC/vínculos e `2026_07_20_000001_add_order_creation_idempotency.php`; nenhum campo/path legado removido
- **Compatibilidade:** URLs e paths legados preservados; falhas pós-commit da OS viram avisos; replay idempotente recupera a mesma OS em vez de duplicá-la
- **Documentação:** consolidado de 20/07/2026, arquitetura, contrato da API, runbook, quickstart, histórico e índices atualizados
- **Validação:** 76 testes direcionados aprovados (430 asserções); suítes amplas ainda contêm falhas preexistentes documentadas no consolidado da release
- **Rollout VPS:** sincronização ativada em `shadow` em 20/07/2026; primeira execução processou 573 arquivos, catalogou 566, criou 366 vínculos ativos e terminou sem falhas; segunda execução confirmou idempotência com zero novos findings
- **Correção operacional LAN:** restaurado o ambiente `192.168.1.100` na branch `develop` após uma promoção interrompida deixá-lo temporariamente em `main`/v4.26.3.0; promoção para `main` passa a usar worktree temporário, sem trocar o código servido na LAN
- **Hardening do deploy:** backups com nomes `.env.*` passam a ser ignorados e bloqueados pelo publicador; o backup de ambiente incluído acidentalmente foi removido do estado ativo da `develop`, e a promoção para `main` ficou suspensa até o tratamento das credenciais e do histórico
- **Arquivos:** backend/app/Services/Files,backend/app/Http/Controllers/Api/V1/FileManagerController.php,backend/config/file-manager.php,backend/routes/api.php,backend/routes/console.php,backend/database/migrations/2026_07_20_000001_add_order_creation_idempotency.php,backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/files,frontends/desktop/public/assets/css/file-preview-modal.css,frontends/desktop/public/assets/js/file-preview-modal.js,frontends/desktop/resources/views/groups/permissions.blade.php,frontends/desktop/public/assets/js/orders-create.js,scripts/php/sync-agent-docs.php,documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md,documentacao/03-arquitetura-tecnica/gerenciador-central-arquivos.md,documentacao/03-arquitetura-tecnica/idempotencia-criacao-os.md,documentacao/10-deploy/operacao-gerenciador-central-arquivos.md,specs/022-gerenciador-central-arquivos

## v5.0.0.0 — 2026-07-19 22:01
- **Tier:** major
- **Autor/Agente:** Codex
- **Descrição:** implementa gerenciador central de arquivos, adapters seguros e painel administrativo
- **Arquivos:** backend/app/Services/Files,backend/app/Http/Controllers/Api/V1/FileManagerController.php,frontends/desktop/resources/views/files,backend/openapi.yaml,specs/022-gerenciador-central-arquivos

## v4.27.0.0 — 2026-07-19 20:20
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Endurece uploads e downloads de branding e chat com validacao por conteudo, allowlist, headers seguros e troca atomica de imagens
- **Arquivos:** backend/app/Services/Chat/ChatAttachmentPolicy.php,backend/config/chat.php,backend/app/Services/Chat/MessageAttachmentService.php,backend/app/Http/Controllers/Api/V1/Chat/AttachmentController.php,backend/app/Http/Controllers/Api/V1/Chat/MessageController.php,backend/app/Http/Controllers/Api/V1/Chat/ConversationController.php,backend/app/Services/Company/CompanyProfileService.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/CompanyProfileImageSecurityTest.php,backend/tests/Feature/Chat/ConversationFlowTest.php,backend/tests/Feature/Chat/WhatsappWebhookTest.php,backend/tests/Unit/Services/Chat/ChatAttachmentPolicyTest.php,frontends/desktop/resources/views/configurations/system.blade.php,documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md,documentacao/07-novas-implementacoes/2026-07-19-hardening-arquivos-branding-chat.md,VERSION,shared/version.php,CHANGELOG.md

## v4.26.3.0 — 2026-07-19 18:10
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** substitui a assinatura direta de documentos pendentes por um fluxo obrigatório de visualização e análise da prévia completa, com confirmação explícita antes de assinar e emitir
- **Segurança:** o backend rejeita assinatura sem revisão recente do mesmo usuário, do mesmo snapshot da OS e da mesma versão/hash do template PDF; prévia sem assinatura, privada, sem cache, com autorização por solicitação e auditoria de data/IP/user-agent em hash
- **Performance:** a prévia é renderizada sob demanda sem persistir versão documental; consulta de pendências permanece limitada e indexada
- **Arquivos:** backend/database/migrations/2026_07_19_000005_require_document_review_before_signature.php,backend/database/migrations/2026_07_19_000006_bind_signature_review_to_pdf_template.php,backend/app/Models/DocumentSignatureRequest.php,backend/app/Services/Pdf/PdfGenerationService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Signatures/DocumentSignatureWorkflowService.php,backend/app/Http/Controllers/Api/V1/DocumentSignatureController.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/DocumentSignatureSecurityTest.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/document-signature-review.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/profile/edit.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-19-assinaturas-digitais-documentos.md,VERSION,CHANGELOG.md

## v4.26.2.0 — 2026-07-19 17:21
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige as caixas de Notificações e Mensagens e documentos para abrirem à direita dos respectivos ícones, sem ficarem ocultas sob a sidebar expandida ou recolhida
- **Segurança:** mantém o posicionamento calculado pelo Bootstrap limitado à viewport, sem introduzir HTML dinâmico ou alterar os controles de autorização das mensagens
- **Performance:** correção declarativa no posicionamento do dropdown, sem listeners, consultas ou processamento adicional no navegador
- **Arquivos:** frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/frontend-desktop-laravel.md,VERSION,CHANGELOG.md

## v4.26.1.0 — 2026-07-19 14:01
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move o registro de acessórios do cadastro permanente do equipamento para a criação e edição da OS, mantém detalhes e PDFs ligados ao snapshot da recepção e migra os valores legados de forma conservadora e auditável
- **Segurança:** backend rejeita acessórios no agregado de equipamento; validação de tamanho na OS; migration não sobrescreve valores existentes e mantém arquivo reversível dos dados legados
- **Performance:** migração processada em lotes de 200 registros e consulta as OS mais recentes em lote, sem N+1
- **Arquivos:** backend/database/migrations/2026_07_19_000004_move_equipment_accessories_to_orders.php,backend/app/Http/Requests/Api/V1/StoreEquipmentRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Services/EquipmentWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/EquipmentCreationTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,backend/tests/Feature/Database/MoveEquipmentAccessoriesToOrdersMigrationTest.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/frontend-desktop-laravel.md,documentacao/07-novas-implementacoes/2026-07-19-acessorios-por-ordem-servico.md

## v4.26.0.0 — 2026-07-19 09:28
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cria a caixa isolada de Mensagens e documentos ao lado do sino e passa a avisar designações de assinatura pelo sistema, e-mail e WhatsApp, com fila assíncrona, auditoria mascarada, retentativas idempotentes e recuperação de pendências anteriores à implantação
- **Segurança:** autorização preservada na abertura do documento; destinatários persistidos somente de forma mascarada e com HMAC; erros externos sanitizados; falhas de canais externos não revertem a designação transacional
- **Arquivos:** backend/app/Services/Notifications/NotificationInboxService.php,backend/app/Http/Controllers/Api/V1/NotificationController.php,backend/app/Notifications/Channels/MobileInboxChannel.php,backend/app/Services/Signatures/DocumentSignatureAssignmentNotifier.php,backend/app/Jobs/DispatchDocumentSignatureAssignmentJob.php,backend/app/Models/DocumentSignatureDelivery.php,backend/database/migrations/2026_07_19_000003_create_document_signature_notification_deliveries.php,backend/routes/console.php,backend/openapi.yaml,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/app/Services/NotificationService.php,frontends/desktop/app/Http/Controllers/NotificationController.php,frontends/desktop/resources/views/notifications/index.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,documentacao/07-novas-implementacoes/2026-07-19-assinaturas-digitais-documentos.md

## v4.25.2.0 — 2026-07-19 04:03
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige prévia da baixa da OS (GET /orders/{id}/closure) mostrando o saldo em aberto de um título já cancelado em vez do título ativo, quando o cancelado é mais recente; a tela dizia 'Saldo em aberto R$0,00' mas a confirmação falhava com 'O valor da baixa não pode ser maior que o saldo em aberto do título' porque close() usa o título ativo de verdade. Mesmo filtro que ensureReceivableTitle() já aplicava, agora também em financialSummary()
- **Arquivos:** backend/app/Services/Orders/OrderClosureService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.25.1.0 — 2026-07-19 03:29
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Fotos de entrada no PDF sempre em paisagem e sem cortes: rotaciona automaticamente fotos em retrato antes de embutir no documento (só nesse bloco; orientação original é mantida em todo o resto do sistema) e troca o recorte (cover) por exibição completa (contain), já que object-fit não é suportado pelo dompdf
- **Arquivos:** backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/resources/views/pdf-engine/document.blade.php,backend/resources/views/pdf-engine/blocks/fotos-entrada.blade.php

## v4.25.0.0 — 2026-07-19 03:29
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Novo bloco 'Galeria de fotos de entrada' no motor de modelos PDF: até 4 fotos de recepção (check-in) da OS lado a lado, adicionável a qualquer tipo de documento (não só abertura); fotos convertidas para base64 sob demanda (só quando o schema usa o bloco), com allowlist de MIME e limite de tamanho
- **Arquivos:** backend/app/Services/Pdf/PdfGenerationService.php,backend/app/Services/Pdf/PdfSchemaValidator.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/resources/views/pdf-engine/blocks/fotos-entrada.blade.php,backend/resources/views/pdf-engine/document.blade.php,frontends/desktop/public/assets/js/pdf-template-editor.js

## v4.24.6.0 — 2026-07-19 03:29
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona teto de segurança de recursão (condicional/colunas) direto no PdfTemplateRenderer: a prévia do editor renderiza rascunhos não publicados sem os limites de profundidade que o publish exige, então um schema fora do padrão podia causar recursão excessiva
- **Arquivos:** backend/app/Services/Pdf/PdfTemplateRenderer.php

## v4.24.5.0 — 2026-07-19 03:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige formatação de data ausente na coluna 'Data' da tabela de recebimentos do comprovante de encerramento (imprimia a string bruta do banco); migration idempotente promove a versão publicada sem afetar customizações
- **Arquivos:** backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/database/migrations/2026_07_18_000014_fix_encerramento_recebimentos_data_format.php

## v4.24.4.1 — 2026-07-19 03:28
- **Tier:** hotfix
- **Autor/Agente:** Claude
- **Descrição:** Corrige encoding quebrado ('Ol?!' -> 'Olá!') na mensagem padrão de envio de documentos quando não há template de WhatsApp configurado
- **Arquivos:** backend/app/Services/Orders/OrderDocumentCenterService.php

## v4.24.4.0 — 2026-07-19 03:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** RBAC granular de publicar/restaurar no motor de modelos PDF do desktop: rotas e botões usavam a permissão genérica 'editar', divergindo do que o backend já exige ('publicar'/'restaurar'); usuário via os botões habilitados e só descobria a falta de permissão após clicar
- **Arquivos:** frontends/desktop/routes/web.php,frontends/desktop/resources/views/knowledge/pdf-templates/engine-edit.blade.php,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php

## v4.24.3.0 — 2026-07-19 03:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Invalida o cache do logo institucional (usado nos PDFs) ao trocar ou remover a logo em Configurações da Empresa; antes o cache de 10min mantinha a logo antiga/removida em qualquer PDF gerado nesse intervalo
- **Arquivos:** backend/app/Services/Company/CompanyProfileService.php

## v4.24.2.0 — 2026-07-19 03:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige botões de ação inertes na central de documentos (ZIP/imprimir/link/enviar): remove poda de seleção que dependia de checkboxes já removidos na reforma da tela de versão-por-linha, reescreve leitura de metadados via dataset da linha, e impede que o polling de 5s reset e a versão selecionada ou feche o menu de Ações aberto
- **Arquivos:** frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.24.1.0 — 2026-07-19 03:22
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Exibe nome, função e data efetiva do signatário e mantém as linhas de assinatura alinhadas nos PDFs
- **Arquivos:** backend/app/Models/User.php,backend/app/Services/Pdf/PdfGenerationService.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/resources/views/pdf-engine/blocks/assinatura.blade.php,backend/resources/views/pdf-engine/document.blade.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Controllers/Api/V1/DocumentSignatureController.php,backend/app/Http/Controllers/Api/V1/PublicDocumentSignatureController.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,documentacao/07-novas-implementacoes/2026-07-19-assinaturas-digitais-documentos.md

## v4.24.0.0 — 2026-07-19 03:00
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Módulo de assinaturas digitais com cadastro por imagem ou tela, Apple Pencil, assinatura própria, reautenticação de outro usuário, pendências e rubrica do cliente por link seguro
- **Segurança:** armazenamento privado, rasterização PNG, confirmação de senha, rate limit, token público armazenado somente como hash, bloqueio de corrida e trilha separada de criador/signatário
- **Documentação:** consolidado executivo/técnico de 18 e 19/07, histórico de versões, índice principal, checklist de deploy e contexto estruturado para agentes atualizados
- **Arquivos:** backend/app/Services/Signatures,backend/app/Http/Controllers/Api/V1/UserSignatureController.php,backend/app/Http/Controllers/Api/V1/DocumentSignatureController.php,backend/app/Http/Controllers/Api/V1/PublicDocumentSignatureController.php,backend/database/migrations/2026_07_19_000002_create_document_signature_infrastructure.php,frontends/desktop/resources/views/profile/edit.blade.php,frontends/desktop/resources/views/signatures/public.blade.php,frontends/desktop/resources/views/orders/documents-center/_signature-modal.blade.php,documentacao/07-novas-implementacoes/2026-07-19-assinaturas-digitais-documentos.md,documentacao/07-novas-implementacoes/2026-07-19-consolidado-implementacoes-18-19-julho.md,documentacao/README.md,documentacao/10-deploy/README.md

## v4.23.0.2 — 2026-07-19 00:36
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Exibe assinaturas do responsável e do cliente lado a lado, substitui o JSON bruto por campos amigáveis no editor e versiona modelos existentes com segurança
- **Arquivos:** backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfSchemaValidator.php,backend/resources/views/pdf-engine/blocks/assinatura.blade.php,backend/database/migrations/2026_07_19_000001_add_client_to_pdf_signature_blocks.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Database/AddClientToPdfSignatureBlocksMigrationTest.php,frontends/desktop/public/assets/js/pdf-template-editor.js,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.23.0.1 — 2026-07-18 21:32
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Promove o Termo de Garantia aprovado para todos os ambientes por migration idempotente, sem sobrescrever personalizações existentes
- **Arquivos:** backend/database/migrations/2026_07_18_000016_seed_termo_garantia_template.php,backend/tests/Feature/Database/SeedTermoGarantiaTemplateMigrationTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.23.0.0 — 2026-07-18 20:57
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Padroniza o cabeçalho institucional em três colunas para todos os modelos PDF atuais, novos e clonados, e fixa o rodapé A4 na margem reservada para evitar páginas geradas apenas pelo rodapé
- **Arquivos:** backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateAdminService.php,backend/app/Services/Pdf/PdfGenerationService.php,backend/resources/views/pdf-engine/document.blade.php,backend/database/migrations/2026_07_18_000015_standardize_pdf_template_headers.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.22.4.0 — 2026-07-18 20:15
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Suporte a cabeçalho PDF em três colunas com foto segura do equipamento
- **Arquivos:** backend/app/Services/Pdf/PdfSchemaValidator.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Pdf/PdfGenerationService.php,backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/app/Services/Pdf/Contexts/BudgetPdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/EquipmentWorkflowService.php,backend/resources/views/pdf-engine/blocks/colunas.blade.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,frontends/desktop/public/assets/js/pdf-template-editor.js,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.22.3.0 — 2026-07-18 18:45
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Amplia a coluna e a área de texto da configuração dos blocos no editor de templates PDF
- **Arquivos:** frontends/desktop/resources/views/knowledge/pdf-templates/engine-edit.blade.php,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php

## v4.22.2.0 — 2026-07-18 18:05
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Audita o contrato de variáveis PDF, aplica fallback seguro ao nome fantasia e separa entrega real da previsão da OS
- **Arquivos:** backend/app/Services/Pdf/Contexts/CompanyContextProvider.php,backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/tests/Feature/Api/V1/PdfEngineGuardTest.php,backend/tests/Feature/Api/V1/PdfEngineDocumentCenterParityTest.php

## v4.22.1.0 — 2026-07-18 17:35
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Preserva quebras de linha em parágrafos PDF e organiza textos colados em títulos, seções, parágrafos e listas editáveis
- **Arquivos:** backend/app/Services/Pdf/PdfVariableResolver.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/resources/views/pdf-engine/blocks/paragrafo.blade.php,backend/tests/Feature/Api/V1/PdfEngineDocumentCenterParityTest.php,backend/tests/Feature/Api/V1/PdfEngineGuardTest.php,frontends/desktop/public/assets/js/pdf-template-editor.js,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php

## v4.22.0.0 — 2026-07-18 17:05
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Permite criar e clonar documentos PDF personalizados, publicá-los e gerá-los manualmente na Central Documental
- **Arquivos:** backend/app/Models/PdfTemplate.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Pdf/PdfTemplateAdminService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Http/Controllers/Api/V1/PdfTemplateEngineController.php,backend/database/migrations/2026_07_18_000014_add_custom_pdf_template_support.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/PdfEngineDocumentCenterParityTest.php,frontends/desktop/app/Services/PdfTemplateEngineService.php,frontends/desktop/app/Http/Controllers/PdfTemplateEngineController.php,frontends/desktop/resources/views/knowledge/pdf-templates/engine-index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.21.0.0 — 2026-07-18 16:07
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Unifica os PDFs no editor versionado e publica tema leve e moderno em A4 e 80mm
- **Arquivos:** backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/resources/views/pdf-engine/document.blade.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderClosurePdfService.php,backend/app/Services/Budgets/BudgetPdfService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/database/migrations/2026_07_18_000013_publish_light_pdf_templates_v2.php,backend/tests/Feature/Api/V1/PdfEngineDocumentCenterParityTest.php,backend/tests/Feature/Api/V1/PdfEngineGuardTest.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/app/Http/Controllers/PdfTemplateEngineController.php,frontends/desktop/resources/views/knowledge/pdf-templates/engine-index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.20.0.2 — 2026-07-18 08:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige collation do extrato e protege erros SQL
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroContaService.php,backend/app/Http/Controllers/Api/V1/FinanceiroContaController.php,backend/tests/Feature/Api/V1/FinanceiroContaTest.php

## v4.20.0.1 — 2026-07-18 08:18
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige conta financeira em lançamento pago
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.20.0.0 — 2026-07-18 04:03
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Consolidado mensal de contas e saldos
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroContaController.php,backend/app/Services/Financeiro/FinanceiroContaService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/FinanceiroContaTest.php,frontends/desktop/app/Http/Controllers/FinanceiroContaController.php,frontends/desktop/app/Services/FinanceiroContaService.php,frontends/desktop/resources/views/financeiro/contas/consolidado.blade.php,frontends/desktop/resources/views/financeiro/contas/index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/FinanceiroContaTest.php,specs/021-gestao-contas-financeiras/contracts/api.md,specs/021-gestao-contas-financeiras/spec.md,specs/021-gestao-contas-financeiras/tasks.md

## v4.19.2.0 — 2026-07-18 03:35
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige validacao da conta financeira no fechamento da OS
- **Arquivos:** frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.19.1.0 — 2026-07-18 03:25
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Contas e Saldos integrado ao RBAC com permissões independentes

## v4.19.0.0 — 2026-07-18 02:26
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Gestão de contas, saldos disponíveis, transferências e conciliação patrimonial

## v4.18.4.0 — 2026-07-17 03:53
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Fluxo da OS: catálogo de transições cresce de 87 para 95 (8 cadastradas manualmente em Conhecimento > Fluxo da OS) — 3 voltas de retrabalho a partir de etapas avançadas da raia CONCLUÍDO (reparo_concluido/reparado_disponivel_loja/garantia_concluida -> retrabalho, roteadas como novas setas tracejadas) e 5 transições inertes com destino de encerramento (garantia_concluida/reparado_disponivel_loja/reparo_concluido/reparo_recusado/irreparavel_disponivel_loja -> entregue_reparado_garantia/entregue_reparado_sem_custo/descartado), formalizadas em REAL_TRANSITIONS só pro diagrama continuar espelhando o banco fielmente (sem seta própria, mesma regra das demais 17 transições de encerramento). Nova migration idempotente (2026_07_17_000002) leva as 8 pra qualquer ambiente via php artisan migrate, testada com ciclo completo de rollback+reaplicação sem duplicar linhas
- **Arquivos:** scripts/python/diagrama_fluxo_os_organizado.py,scripts/python/diagrama_fluxo_os_organizado.svg,scripts/python/diagrama_fluxo_os_organizado.png,scripts/python/README-diagrama-fluxo-os.md,frontends/desktop/resources/views/orders/_flow_map_svg.blade.php,backend/database/migrations/2026_07_17_000002_add_retrabalho_return_and_closure_transitions.php

## v4.18.3.0 — 2026-07-17 03:34
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Mapa da OS: adiciona nº da OS e resumo do equipamento (tipo/marca/modelo) na barra de legenda, dentro da moldura do mapa — visível mesmo em tela cheia, onde o cabeçalho da página e o painel lateral (que ficam fora de .os-map-frame) somem. Sem isso, em tela cheia não tinha como saber de qual OS/equipamento se tratava sem sair do fullscreen
- **Arquivos:** frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.18.2.0 — 2026-07-17 03:27
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Mapa da OS: adiciona cards de contexto no painel lateral com dados do cliente (nome, telefone) e do equipamento (tipo, marca, modelo, defeito relatado pelo cliente), antes do trajeto percorrido — evita precisar voltar pra tela de detalhe da OS só pra lembrar quem é o cliente ou o que foi relatado. Reaproveita os mesmos campos já usados na tela de detalhe ($order['cliente']/$order['equipamento']), sem mudança de backend. Defeito relatado com truncamento em 3 linhas (title com o texto completo)
- **Arquivos:** frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.18.1.0 — 2026-07-17 02:51
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige o submenu de navegação exibido inadvertidamente ao recolher a sidebar: grupos abertos pelo contexto da página agora são fechados no carregamento recolhido e no clique de recolher; popovers deliberadamente abertos continuam fechando por clique externo ou Esc com restauração de foco.
- **Arquivos:** frontends/desktop/public/assets/js/desktop.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.18.0.0 — 2026-07-17 02:51
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona editor de corte às fotos de entrada da OS, com recorte individual, zoom, rotação, reedição e substituição segura do arquivo original antes do envio multipart, mantendo o limite de até quatro imagens e 2 MB por foto.
- **Arquivos:** frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/resources/views/orders/create.blade.php,frontends/desktop/resources/views/orders/edit.blade.php,frontends/desktop/resources/views/orders/_photo_crop_modal.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.17.1.0 — 2026-07-17 02:07
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Adiciona o botão 'Mapa da OS' no dropdown de ações de cada linha da listagem de OS (/os), ao lado de 'Documentos da OS' — antes só existia na tela de detalhe da OS. Mesmo link (rota orders.map) usado em ambos os lugares
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.17.0.0 — 2026-07-17 02:01
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Formaliza como migration as 10 transições que haviam sido cadastradas só pela tela Conhecimento > Fluxo da OS nesta máquina (homologação): testes_operacionais → verificacao_garantia/aguardando_orcamento/reparo_concluido/garantia_concluida/cancelado, e irreparavel → diagnostico/aguardando_orcamento/aguardando_reparo/reparo_execucao/retrabalho. Antes, essas 10 só existiam no banco local — 'php artisan migrate' não sincroniza dados entre ambientes, só executa migrations versionadas, então elas nunca chegariam à VPS de produção sem esse arquivo. Mesmo padrão idempotente e reversível das migrations anteriores de transições (resolve código→id em runtime, down() desativa sem deletar); testada localmente com rollback + reaplicação, confirmando 87 transições ativas, 87 pares distintos, zero duplicata
- **Arquivos:** backend/database/migrations/2026_07_17_000001_add_testes_operacionais_e_irreparavel_transitions.php

## v4.16.0.0 — 2026-07-17 01:36
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Diagrama do fluxo da OS: adiciona as 10 transições que o usuário cadastrou em Conhecimento > Fluxo da OS (77 → 87 na tabela os_status_transicoes), roteadas à mão seguindo as convenções de corredor já usadas no script. 'Testes Operacionais' ganha 5 saídas novas — retorno pra Verificação de Garantia e Aguardando Orçamento (quando o teste revela algo que precisa reavaliar), e atalhos direto pra Reparo Concluído, Garantia Concluída ou Cancelado, pulando Testes Finais. 'Irreparável' deixa de ser (quase) definitivo: ganha volta pra Diagnóstico, Aguardando Orçamento, Aguardando Reparo, Em Execução e Retrabalho, permitindo reavaliar um equipamento antes marcado como sem conserto. Nenhuma das 10 aponta pra um encerramento, então todas viraram seta (60 → 70 setas desenhadas, auto-verificado contra as 70 transições utilizáveis do banco)
- **Arquivos:** scripts/python/diagrama_fluxo_os_organizado.py,scripts/python/diagrama_fluxo_os_organizado.svg,scripts/python/diagrama_fluxo_os_organizado.png,scripts/python/README-diagrama-fluxo-os.md,frontends/desktop/resources/views/orders/_flow_map_svg.blade.php

## v4.15.1.0 — 2026-07-17 00:46
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige o pan (arrastar pra navegar) do Mapa da OS disparando seleção de texto do navegador em vez de mover o mapa: os rótulos dentro do SVG e da legenda são texto selecionável por padrão, e um user-select:none isolado só no viewport não bastava — o navegador 'pulava' a seleção pro texto selecionável mais próximo fora dele (legenda, e até a pílula de status no cabeçalho, que fica bem acima do quadro do mapa). Amplia user-select:none para o quadro inteiro (.os-map-frame: legenda + viewport + toolbar) e para a pílula de status isoladamente, mantendo o número da OS e o painel 'Trajeto percorrido' normalmente selecionáveis. Reforça também no JS com preventDefault() no início do arrasto (pointerdown), para os casos em que o CSS sozinho não é respeitado
- **Arquivos:** frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/public/assets/js/orders-map.js

## v4.15.0.0 — 2026-07-16 23:52
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Mapa da OS: trocar de status agora atualiza o mapa no próprio lugar (novo endpoint JSON orders.map.data + redecoração do mesmo SVG) em vez de recarregar a página inteira — um location.reload() sempre encerrava a tela cheia, já que qualquer navegação sai do modo fullscreen do navegador por padrão de segurança. Depois de confirmar a mudança no modal, o JS busca o estado fresco da OS (status, trajeto completo, próximas etapas), atualiza a pílula de status, o banner de encerrada/cancelada e o painel 'Trajeto percorrido' (HTML já renderizado pelo servidor via partial orders._map_trail, reaproveitado tanto no carregamento normal quanto nesse endpoint), e redecora o mesmo SVG: marcador de posição migra para o novo nó, trajeto/rota provável são recalculados, e as próximas etapas clicáveis são atualizadas — tudo sem sair da tela cheia. Cliques nos nós passam a ser delegados no <svg> (um único listener) em vez de um por nó, então a lista de etapas clicáveis muda com o estado sem precisar desligar/religar handlers a cada atualização
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/resources/views/orders/_map_trail.blade.php,frontends/desktop/public/assets/js/orders-map.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.14.2.0 — 2026-07-16 23:34
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige os modais de mudança de status (confirmação de mover etapa, aviso de encerramento/baixa e toasts) somerem em tela cheia no Mapa da OS: SweetAlert2 anexa seu container a document.body por padrão, que fica fora da 'camada' da Fullscreen API nativa quando .os-map-frame está em tela cheia — só o próprio elemento em fullscreen e seus descendentes são renderizados pelo navegador. Todos os Swal.fire() do mapa agora recebem target dinâmico (document.fullscreenElement || document.body), então o modal passa a ser filho do elemento em tela cheia e continua visível e utilizável; fora de tela cheia o comportamento não muda
- **Arquivos:** frontends/desktop/public/assets/js/orders-map.js

## v4.14.1.0 — 2026-07-16 23:22
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Adiciona modo tela cheia à página Mapa da OS: botão na toolbar do mapa entra em fullscreen (API nativa do navegador, com fallback de overlay fixo quando indisponível); sai com Esc ou pelo X no canto superior direito (visível só em tela cheia). Zoom é reajustado automaticamente ao entrar/sair, e a toolbar desce para não disputar o canto com o X
- **Arquivos:** frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/public/assets/js/orders-map.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.14.0.0 — 2026-07-16 22:57
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona a página Mapa da OS (/os/{id}/mapa): visão GPS do ciclo de vida da ordem de serviço sobre o fluxograma real do catálogo, com trajeto percorrido em verde (reconstruído da trilha completa de eventos category=status, não do histórico limitado a 5), posição atual pulsando, rota provável até 'Entregue — Reparado e Pago' calculada por Dijkstra preferindo o caminho feliz, e próximas etapas clicáveis (confirmação com observação e, quando sai de status com prazo congelado, novo prazo) aplicando a mudança pelo endpoint existente de status; encerramentos nunca são clicáveis — apontam para a tela de baixa. Pan/zoom com roda/arrasto/botões e 'centralizar na posição atual'. Botão 'Mapa da OS' no cabeçalho e no menu Mais ações da tela da OS. Antes disso, a migration add_missing_os_status_transitions fecha as duas lacunas de processo documentadas no README do diagrama (cumprimento_garantia sem saída; teste reprovado sem caminho para retrabalho) e formaliza o fluxo de peça com sinal: 8 transições novas (69→77) — cumprimento_garantia→garantia_concluida/irreparavel, testes_finais→retrabalho, testes_operacionais→irreparavel, retrabalho→aguardando_reparo, aguardando_peca→pagamento_pendente/aguardando_reparo e pagamento_pendente→aguardando_reparo — que aparecem automaticamente também no modal Alterar Status (chips). O gerador do fluxograma (scripts/python) foi atualizado: caminho feliz agora passa por Aguardando Avaliação (caso clássico da bancada), 60 setas auto-verificadas contra as 77 transições, e novo modo --embed que gera o partial SVG endereçável (data-status/data-edge) consumido pela página do mapa
- **Arquivos:** backend/database/migrations/2026_07_16_000001_add_missing_os_status_transitions.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,scripts/python/diagrama_fluxo_os_organizado.py,scripts/python/diagrama_fluxo_os_organizado.svg,scripts/python/diagrama_fluxo_os_organizado.png,scripts/python/README-diagrama-fluxo-os.md,frontends/desktop/routes/web.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/resources/views/orders/_flow_map_svg.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/js/orders-map.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.13.0.0 — 2026-07-16 21:43
- **Tier:** minor
- **Autor/Agente:** jovem-tech
- **Descrição:** Adiciona criação contextual de Nova OS nas páginas de cliente, equipamento e OS, com modal para reutilizar o equipamento atual ou iniciar com equipamento novo e preenchimento seguro do proprietário.
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_new_order_context_modal.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.12.2.0 — 2026-07-16 21:25
- **Tier:** patch
- **Autor/Agente:** jovem-tech
- **Descrição:** Substitui o título Sem resumo técnico no detalhe do equipamento pela identificação formada por tipo, marca e modelo, com fallback para o ID.
- **Arquivos:** frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.12.1.0 — 2026-07-16 21:14
- **Tier:** patch
- **Autor/Agente:** jovem-tech
- **Descrição:** Corrige a busca de equipamentos para exibir foto, tipo, marca, modelo, cliente e número de série, com fallback para cadastros legados e pesquisa pelo tipo.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,frontends/desktop/app/Services/SearchService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/search/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.12.0.0 — 2026-07-16 18:26
- **Tier:** minor
- **Autor/Agente:** jovem-tech
- **Descrição:** Adiciona auditoria completa e paginada da OS, com filtros, autoria, proveniência, resumo atual, endpoint protegido e acesso pelo menu Mais ações.
- **Arquivos:** backend/app/Http/Requests/Api/V1/OrderEventIndexRequest.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/audit.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/eventos-os.md

## v4.11.7.0 — 2026-07-16 12:53
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move o Histórico da OS para abaixo do card Fotos na coluna principal, removendo o layout lateral com rolagem interna e cobrindo a nova ordem com teste de regressão.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.11.6.0 — 2026-07-16 10:48
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Alinha o rodapé do card 'Histórico da OS' (coluna lateral) com o rodapé do último card da coluna principal (Fotos) na tela de detalhe da OS. Antes, a coluna lateral (foto + histórico) tinha altura livre e terminava bem acima da coluna principal em qualquer OS com vários cards, deixando um vão vazio grande abaixo do histórico. Agora .os-detail-layout estica as duas colunas para a mesma altura (align-items:stretch), a coluna lateral vira flex column com o card de foto em tamanho fixo e o card de histórico crescendo (flex:1) até preencher a altura total, com o scroll ficando interno à lista de eventos (não ao card inteiro) — mantendo cabeçalho e chips de filtro sempre visíveis. Layout mobile (<=992px) inalterado, pois a coluna lateral já vira display:contents nesse breakpoint
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v4.11.5.0 — 2026-07-16 10:41
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige alinhamento dos títulos de card na tela de detalhe da OS (Defeito e Solução, Valores e Orçamento, Documentos, Fotos): o ícone aparecia isolado à esquerda e o texto do título isolado à direita, bem afastados um do outro. Causa: .os-info-card-title usa display:flex+justify-content:space-between para separar título de botão de ação quando presente, mas nos títulos sem botão o ícone (elemento) e o texto (nó de texto solto) viravam dois itens flex anônimos distintos, que o space-between empurrava para as bordas opostas do card. Corrigido envolvendo ícone+texto num único <span>, mesmo padrão já usado nos cards Cliente/Equipamento, restando só um item flex (alinhado à esquerda) quando não há botão
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v4.11.4.0 — 2026-07-16 10:24
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** No card 'Valores e Orçamento' (v4.11.1.0), as seções 'Orçamento' e 'Datas e garantia' ficavam lado a lado mas sem separação visual entre si, misturando com o fundo do card externo. Nova classe .os-subcard (fundo levemente diferenciado, borda sutil, sem sombra própria) transforma as duas em caixas distintas lado a lado, mesma altura, deixando claro onde uma seção termina e a outra começa.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/css/desktop.css

## v4.11.3.0 — 2026-07-16 10:17
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a duração da OS no cabeçalho da tela de detalhe ('Aberta há X dias' / 'Concluída em X dias'), que exibia um float fracionário cru (ex.: 'Aberta há 4.170775462963 dias') em vez de um número inteiro de dias — Carbon::diffInDays() nesta versão retorna fração de dia por padrão, não inteiro. O mesmo bug também quebrava silenciosamente os casos-limite 'Aberta hoje' e a pluralização 'X dia'/'X dias', já que as comparações ===0/===1 nunca batiam com um valor fracionário. Corrigido com um cast (int) logo após diffInDays(), truncando para dias completos.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v4.11.2.0 — 2026-07-16 10:11
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Move o campo 'Checklist' do card Equipamento para o card 'Defeito e Solução' (logo após Técnico responsável) na página de detalhe da OS, e adiciona o botão 'Ver checklist', que abre um modal com o resultado completo: todos os itens verificados (com rótulo de status OK/Discrepância/Não verificado), a observação registrada em cada item, o resumo textual e as observações gerais do estado do equipamento — antes só o resumo agregado ("Preenchido · 7 itens") ficava visível, sem acesso ao detalhe item a item pela tela de OS.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_checklist_detail_modal.blade.php

## v4.11.1.0 — 2026-07-16 10:02
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Ajusta o card 'Valores e Orçamento' (v4.11.0.0) para eliminar redundância: a seção 'Resumo financeiro' foi extinta — mão de obra e peças já apareciam detalhadas na tabela de peças e serviços do orçamento, e total/desconto/valor final já apareciam na seção Orçamento. Em seu lugar, na coluna esquerda do card, entra a seção 'Orçamento' (antes um bloco full-width abaixo), ficando lado a lado com 'Datas e garantia' na direita — que agora termina com 'Forma de pagamento' (movida de Resumo financeiro). A nota do título financeiro (recebido/saldo) e o alerta de auditoria de peças, que ficavam soltos sob Resumo financeiro, passam a ficar junto da seção Orçamento, já que são informações sobre ele.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v4.11.0.0 — 2026-07-16 09:24
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Reformula a página de detalhe da OS (/os/{id}): remove as 6 abas (Informações, Orçamento, Diagnóstico, Fotos, Documentos, Valores) — investigação confirmou que nenhuma delas tinha ação interativa própria, todas já duplicadas em "Mais ações" ou eram só exibição — e reorganiza tudo em cards sequenciais de leitura direta, sem clique extra: Cliente e Equipamento (dois cards lado a lado, cada um em tabela label→valor, linha só aparece se o campo não estiver vazio); Defeito e Solução (técnico, relato do cliente, diagnóstico, solução, procedimentos, acessórios, observações); Valores e Orçamento (resumo financeiro + datas/garantia lado a lado, bloco do orçamento vinculado com a nova lista de peças e serviços do orçamento aprovado); Documentos (tabela compacta com botão Visualizar, era cards grandes); e por último Fotos (grade por recepção/diagnóstico/entrega, mantendo a mesma lightbox). Backend (OrderWorkflowService::mapLinkedBudget) passa a expor os itens do orçamento vinculado (antes só o resumo agregado existia); mapEquipment() passa a expor o campo acessórios do equipamento, que existia na tabela mas nunca era mapeado. Nova classe de tabela .os-info-table no CSS (label/valor com linhas separadas por borda sutil), reaproveitando .desktop-grid-two e .table-stack já existentes para o layout de 2 colunas e as tabelas responsivas — sem duplicar padrões.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/css/desktop.css

## v4.10.3.0 — 2026-07-16 01:04
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Causa raiz real da trava de orçamento não aprovado (v4.10.0.0 a v4.10.2.0) nunca funcionar de fato para quem interage pela UI real do Select2: o listener 'change' de #encerrarComo usava só addEventListener nativo, sem o binding paralelo via jQuery que o Select2 exige (Select2 dispara change só via jQuery(el).trigger('change'), que não gera evento nativo — mesmo bug já documentado nesta sessão e corrigido para o select de Classificação e os campos de cartão, mas não para #encerrarComo). Resultado: escolher 'Entregue - Reparado e Pago' clicando na tela nunca desabilitava as abas/botão de verdade. Corrigido adicionando o mesmo binding jQuery paralelo. Validado com teste automatizado em Chrome headless clicando de fato na UI do Select2 (não via .value=): opção fica corretamente desabilitada/não-clicável no dropdown quando o orçamento está pendente, e no cenário de reenvio com valor antigo (old() do Laravel após rejeição do backend) as abas Financeiro/Confirmação e o botão Continuar carregam já desabilitados.
- **Arquivos:** frontends/desktop/public/assets/js/orders-closure.js

## v4.10.2.0 — 2026-07-16 00:46
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** A trava de orçamento não aprovado na tela de baixa (v4.10.1.0) só interceptava o clique no botão 'Continuar' — as abas 'Financeiro' e 'Confirmação' continuavam clicáveis diretamente, deixando o técnico pular a etapa 1 e navegar livre pelo resto do wizard. Agora, ao selecionar 'Entregue - Reparado e Pago' com orçamento vinculado ainda não aprovado, as abas Financeiro e Confirmação ficam de fato desabilitadas (mesmo padrão visual já usado para devolução sem reparo/descarte) e o próprio botão 'Continuar' da etapa 1 fica desabilitado — não é mais possível avançar por nenhum caminho até o orçamento ser aprovado ou outro tipo de encerramento ser escolhido.
- **Arquivos:** frontends/desktop/public/assets/js/orders-closure.js

## v4.10.1.0 — 2026-07-16 00:41
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** A trava de orçamento aprovado no encerramento como 'Entregue - Reparado e Pago' (v4.10.0.0) só desabilitava a opção no <select>, o que não impedia o técnico de avançar pelas etapas Financeiro/Confirmação do wizard de baixa e só descobrir o bloqueio no envio final, depois de preencher tudo. A tela de baixa (orders-closure.js) agora barra a navegação já na etapa 1 (Encerramento), com aviso inline imediato, assim que o orçamento pendente é detectado — e mantém o bloqueio no envio final como defesa adicional. O backend (OrderClosureService::close) continua sendo a barreira definitiva.
- **Arquivos:** frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/orders/closure.blade.php

## v4.10.0.0 — 2026-07-16 00:31
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Bloqueia o encerramento da OS como "Entregue - Reparado e Pago" quando existe um orçamento vinculado ainda não aprovado (aguardando resposta, rejeitado, etc.) — antes o fluxo de baixa ignorava completamente o status do orçamento, permitindo encerrar como entregue e pago mesmo com a OS ainda em "Aguardando Autorização". A trava só age quando HÁ orçamento vinculado à OS; OS sem orçamento nenhum (ex.: serviço rápido cobrado direto) continua fechando normalmente. Vale só para o encerramento pago — sem custo e garantia continuam livres mesmo com orçamento pendente, já que não exigem autorização de cobrança. Tela de baixa (orders/closure.blade.php) desabilita a opção "Entregue - Reparado e Pago" no select com aviso visual quando aplicável, além do bloqueio no backend (OrderClosureService::close, novo resultado delivery_requires_approved_budget → HTTP 422 ORDER_CLOSURE_DELIVERY_REQUIRES_APPROVED_BUDGET). Corrige também duas referências residuais ao nome antigo "Equipamento Entregue" nas mensagens de erro de fechamento.
- **Arquivos:** backend/app/Services/Orders/OrderClosureService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,frontends/desktop/resources/views/orders/closure.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v4.9.0.0 — 2026-07-15 23:22
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Divide o encerramento "Equipamento Entregue" (que era vago) em três status explícitos de reparo entregue, todos grupo_macro=encerrado: entregue_reparado_pago (renome de entregue_reparado — reparado, entregue e pago, único que gera receita/REVENUE_CLOSURE_CODE), entregue_reparado_sem_custo (cortesia, R$0 sem lançamentos) e entregue_reparado_garantia (cumprimento de garantia, R$0 sem lançamentos). Mantém devolvido_sem_reparo e descartado. Os três "entregue_reparado_*" contam como equipamento entregue nos indicadores operacionais (card do dashboard + gráfico mensal de entregues reparadas, via nova const OrderStatus::REPAIRED_DELIVERY_CODES) e geram os documentos de reparo (laudo + comprovante de entrega), mas só o pago entra em faturamento/DRE/fluxo de caixa/margem/comissão — o dashboard passou a separar a contagem operacional de entregas (3 códigos) da soma de receita (só o pago). No fechamento (OrderClosureService::close), cortesia e garantia entram no grupo "sem cobrança" junto com devolvido/descartado: não exigem pagamento, não registram movimento financeiro e não deixam saldo pendente/cobrança agendada — só o pago exige pagamento. Isso também preenche um buraco real: reparo em garantia (fluxo verificacao_garantia→cumprimento_garantia→garantia_concluida) antes não tinha como ser encerrado como entregue sem pagamento. Migração idempotente e reversível renomeia a linha do catálogo os_status e migra os dados existentes (os.status, os.status_final_pendente_pagamento, os_status_historico) de entregue_reparado para entregue_reparado_pago. Documentação (catálogo de status + skill sistema-erp-os-fluxo-fechamento) atualizada.
- **Arquivos:** backend/database/migrations/2026_07_15_000001_split_entregue_reparado_status.php,backend/app/Models/OrderStatus.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Dashboard/DashboardSummaryService.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/app/Http/Controllers/AssistanceModelController.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md,.agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md

## v4.8.2.0 — 2026-07-15 19:31
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a baixa/fechamento de uma OS que já teve um título cancelado por "Erro de cobrança" anteriormente (motivo que reverte a OS para pagamento pendente mas mantém o título cancelado vinculado, ao contrário de "Fechamento inadvertido", que apaga o título). Ao fechar a OS de novo, `OrderClosureService::ensureReceivableTitle()` buscava "o" título da OS sem filtrar por status, encontrava o cancelado (único vinculado) e tentava lançar o novo recebimento nele — `FinanceiroService::registerMovement()` bloqueia baixa em título cancelado, então o fechamento falhava com HTTP 500 ("Não é possível registrar baixa em título cancelado."), travando a OS sem nenhum título ativo para receber. A busca agora ignora títulos cancelados (mesmo filtro que `OrderWorkflowService` já aplicava ao resolver o título "atual" da OS para o resumo/financeiro_titulo_id) e cria um título novo quando só existir um cancelado, preservando o cancelado intocado para auditoria. Bug relatado em produção na OS 26070011 (título #29 cancelado bloqueando a baixa); reproduzido e corrigido primeiro no `develop`.
- **Arquivos:** backend/app/Services/Orders/OrderClosureService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.8.1.0 — 2026-07-15 18:47
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reforça a cobertura de teste do cancelamento de título com motivo "Fechamento inadvertido" (reversão completa da baixa da OS): passa a verificar também que `data_entrega` volta a `null` junto com o status, não só o status em si. Investigação disparada por um relato de produção onde uma OS revertida para "Aguardando Reparo" continuou exibindo a data de entrega antiga na listagem — o teste confirma que o `develop` já corrige isso corretamente (via `OrderClosureService::cancelClosure()`, a mesma lógica reaproveitada de "Cancelar baixa"); a causa mais provável do que foi visto em produção é o deploy daquele fluxo ainda não ter chegado lá, ou o cancelamento ter sido feito antes da correção existir
- **Arquivos:** backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.8.0.0 — 2026-07-15 13:15
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Cancelar um título vinculado a uma OS já encerrada ("Equipamento Entregue") passa a exigir motivo (Reparo sem sucesso entregue ao cliente / Erro de cobrança / Fechamento inadvertido) + confirmação de administrador (reaproveitando o AdminCredentialVerifier já usado em "Cancelar baixa" e na edição de orçamento de OS encerrada), com consequência automática no status da OS conforme o motivo: devolvido_sem_reparo, entregue_pagamento_pendente (cancelando também as cobranças automáticas via WhatsApp que estavam agendadas), ou reversão completa da baixa (mesma lógica de "Cancelar baixa"). UI em duas telas dentro do mesmo modal (motivo → credenciais), com um único POST no final. Exclusão (hard delete) de qualquer lançamento passa a exigir as mesmas credenciais de administrador sempre — antes bastava um confirm() do navegador, mesmo para títulos já pagos, sem checar nada; e fica totalmente bloqueada quando a OS vinculada está encerrada (usar "Cancelar" preserva o histórico e corrige o status da OS, diferente do hard delete que não corrigia nada). Corrige a despesa "Taxa de cartão" gerada pela baixa avulsa de um título em cartão (POST /financeiro/{id}/baixar), que não herdava o os_id do título pago — por isso essas taxas nunca acionavam a trava de motivo+admin mesmo vinculadas a uma OS encerrada, diferente da taxa equivalente gerada pelo fechamento direto da OS. Corrige o bug da listagem de OS que não filtrava títulos cancelados ao somar Recebido/Saldo (diferente do detalhe da OS, que já filtrava corretamente) — OS com título estornado aparecia com saldo em aberto fantasma. Corrige dois bugs pré-existentes descobertos durante a implementação: a constante DELIVERED_STATUS (usada para exigir pagamento ao fechar a OS como "Equipamento Entregue") comparava contra "equipamento_entregue", um código que nunca existiu de fato no catálogo de status — o código real é "entregue_reparado" — tornando essa trava de pagamento morta desde que foi criada; o mesmo código errado também estava em 4 pontos da geração de documentos da OS. Corrige também a ordem de verificação de autorização em OrderClosureService::close(), que rodava depois da validação de negócio, retornando 422 em vez de 403 para um técnico sem acesso à OS.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BaseApiController.php,backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/CancelFinanceiroRequest.php,backend/app/Models/OrderStatus.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/resources/views/financeiro/_cancel_reason_modal.blade.php,frontends/desktop/resources/views/financeiro/_delete_admin_modal.blade.php,frontends/desktop/public/assets/js/financeiro-cancel-reason-modal.js,frontends/desktop/public/assets/js/financeiro-delete-admin-modal.js

## v4.7.15.0 — 2026-07-15 13:15
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona "trilha de origem" na listagem de lançamentos financeiros (GET /financeiro): cada linha passa a mostrar, sob a categoria, de onde aquele título veio — cliente/OS/equipamento para receita de OS, fornecedor para despesa avulsa, e para taxas de cartão (geradas automaticamente na baixa em cartão), o título a receber que originou a taxa. Substitui o antigo subtítulo genérico grupo_dre/subgrupo_dre, que era igual para todo lançamento da mesma categoria e não dizia nada sobre a origem específica daquele registro.
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.7.14.0 — 2026-07-15 01:56
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a Central Documental da OS (`/os/{id}/documentos`): as tabelas "Tipos documentais disponíveis" e "Acervo versionado do cliente" (renomeada para "Todas as versões geradas") passam a viver no mesmo card, uma logo abaixo da outra. Cada linha de "Tipos documentais disponíveis" com documento já gerado ganha um dropdown "Ações" com Visualizar A4/80mm (só aparece o formato realmente disponível), Baixar ZIP, Imprimir, Gerar link, Enviar e Arquivar/Reativar, agindo direto naquele documento — sem precisar marcar checkbox nenhum. Corrige o "Baixar ZIP" que parecia não funcionar: a causa raiz era a seleção da tabela "Tipos documentais" (usada só para gerar em lote) ser um estado desconectado da seleção da tabela do acervo (usada pelos botões de ZIP/imprimir/link/enviar) — confirmado por teste automatizado novo que o endpoint de ZIP do backend sempre funcionou corretamente. A tabela do acervo mantém seleção múltipla para combinar vários documentos num único ZIP/impressão/link/envio
- **Arquivos:** backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.13.0 — 2026-07-15 01:50
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** A listagem de lançamentos financeiros (`/financeiro`) passa a ordenar por data de pagamento/recebimento efetivo (mais recente primeiro), em vez de data de vencimento. Títulos ainda pendentes (sem data de pagamento) vão para o final da lista
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.7.12.0 — 2026-07-14 23:53
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona "Ver lançamentos" e "Novo lançamento" ao topo do dropdown "Mais ações" da tela de detalhes do lançamento financeiro, com divisor separando-os das demais ações. "Ver lançamentos" sempre aparece (exige apenas a permissão de visualizar já necessária para chegar nesta página); "Novo lançamento" só aparece com permissão de criar. Como consequência, o botão "Mais ações" deixa de sumir por completo quando o usuário não tem nenhuma outra ação disponível — passa a mostrar ao menos "Ver lançamentos"
- **Arquivos:** frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.7.11.0 — 2026-07-14 23:30
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Estende o padrão "Mais ações" (já usado em OS, orçamentos, documentos e lançamentos financeiros) para os detalhes de Cliente e de Equipamento. Em Cliente (`/clientes/{id}`): "Voltar" e "Nova OS" continuam como botões visíveis; "Editar cliente", "Ver OS do cliente" e "Ver equipamentos" passam para o dropdown. Em Equipamento (`/equipamentos/{id}`): "Voltar" e "Nova OS" continuam visíveis; "Editar" e "Abrir cliente" passam para o dropdown. Cada item mantém sua checagem de permissão de módulo já existente; o botão "Mais ações" some por completo quando nenhum item está disponível para o perfil do usuário
- **Arquivos:** frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.10.0 — 2026-07-14 22:51
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a tela de detalhes do lançamento financeiro (`/financeiro/{id}`) para reduzir a quantidade de cards e o scroll necessário. "Dados do lançamento" passa a concentrar também os dados de "Quem pagou"/"Para quem pagou" e de "Origem do lançamento" (antes três cards separados), com data/forma de pagamento do recebimento movidos para dentro dele; o card KPI "Recebido em/Pago em" do topo foi substituído por um card "Quem pagou"/"Para quem pagou" (nome, documento, telefone e e-mail da contraparte, no mesmo estilo compacto dos demais KPIs); "Tipo de origem" passa a ser só um campo dentro de "Dados do lançamento" (removida a subseção "Origem do lançamento" e o campo "Lançamento de origem"); "Dados do lançamento" e "OS vinculada" ficam lado a lado numa grade própria com altura igualada (bases alinhadas); "Baixas e formas de pagamento" e "Auditoria" passam a ocupar a largura inteira, abaixo desse par
- **Arquivos:** frontends/desktop/resources/views/financeiro/show.blade.php

## v4.7.9.0 — 2026-07-14 22:00
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o dropdown "Ações" da LISTAGEM de OS (não só da tela de detalhe, já corrigida antes), que não mostrava "Ver lançamento financeiro" mesmo em OS com título vinculado (ex.: OS26070002, com R$ 80,00 recebidos) — o id do título nunca era exposto na resposta da listagem, só na tela de detalhe. Backend passa a incluir `financeiro_titulo_id` em cada linha de `GET /orders` (o dado já era calculado internamente para "Recebido/Saldo", só faltava expor o id); listagem do desktop ganha o mesmo item de menu já usado no detalhe, condicionado a ter lançamento vinculado e permissão de visualizar o módulo financeiro
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.8.0 — 2026-07-14 21:38
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** O dropdown "Mais ações" da tela de detalhe da OS ganha o item "Ver lançamento financeiro" quando a OS tem um título "a receber" vinculado (não cancelado) — mesmo dado já usado no resumo financeiro da aba Valores, agora também como atalho direto para a página de detalhes do lançamento. Item some automaticamente quando a OS não tem lançamento vinculado ou o usuário não tem permissão de visualizar o módulo financeiro
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.7.0 — 2026-07-14 20:53
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Página de detalhes do lançamento financeiro ganha o botão "Mais ações" (mesmo padrão das telas de OS), agrupando todas as ações do lançamento: Editar lançamento; Registrar baixa (modal completo com valor total/parcial, forma de pagamento e taxas de cartão — antes só existia na listagem, agora dá para baixar direto dos detalhes); Ver OS vinculada; Ver orçamento vinculado (novo atalho — backend passa a expor o orçamento mais recente da OS no payload de detalhes); Ver cliente/fornecedor (contraparte, com id agora exposto pelo backend); Cancelar lançamento e Excluir lançamento (com as mesmas confirmações da listagem). O botão "Editar" isolado do cabeçalho foi movido para dentro do dropdown; sem nenhuma ação disponível para o perfil, o dropdown some. Baixa e cancelamento feitos a partir dos detalhes voltam para os detalhes (novo campo voltar_para), em vez de sempre caírem na listagem
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.7.6.0 — 2026-07-14 08:49
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a tela de detalhe da OS: cabeçalho ganha pill de status ao lado do número, linha de metadados (duração 'Aberta há Xd'/'Concluída em Xd', previsão, pill de prazo/SLA com mesma paleta da listagem, técnico responsável) para leitura de relance sem abrir aba; card KPI 'Técnico responsável' (removido em ajuste anterior) segue disponível na aba Diagnóstico; histórico da OS na coluna lateral ganha faixa de filtros rolável (em vez de quebrar em várias linhas) para caber na largura reduzida da coluna; novo campo prazo (SLA) exposto em GET /orders/{id}, reaproveitando o mesmo cálculo já usado na listagem
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/orders/show.blade.php

## v4.7.5.0 — 2026-07-14 07:34
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige de vez o guard "fechar o navegador = deslogar" no Edge, após teste real do usuário mostrar que a v4.7.4 ainda falhava. Quatro falhas encontradas e corrigidas: (1) a detecção dependia só da idade do heartbeat (20s) — fechar e reabrir rápido (teste manual típico) passava despercebido; agora um MARCADOR DE FECHAMENTO é gravado no exato instante em que a aba fecha (evento pagehide, quando a saída não é navegação interna), tornando a detecção instantânea mesmo reabrindo em 2 segundos, não importa o que o Edge restaure; (2) o beforeunload consumia a flag de navegação interna antes de o pagehide lê-la, o que gravaria marcador falso em navegações normais — a flag agora expira sozinha (10s) em vez de ser consumida; (3) o rastreamento de navegação interna ficava dentro do if do aviso de fechamento — com o aviso desligado, toda navegação interna viraria "fechamento"; movido para fora, sempre ativo; (4) recarregar pelo botão do navegador seria tratado como fechamento — agora o tipo de navegação (PerformanceNavigationTiming: reload) isenta qualquer recarregamento com precisão. Fallback por heartbeat mantido (agora 90s, só para crash/kill, evitando falso positivo com abas em segundo plano que têm timers estrangulados pelo navegador). Logs de diagnóstico no console do navegador ([ERP Sessão]) para suporte remoto. Verificado em navegador real (Chromium headless) em 11 cenários, incluindo reabertura em 2s com aba inteira restaurada (caso Edge), reload da barra, multi-abas, crash e anti-laço
- **Arquivos:** frontends/desktop/resources/views/layouts/app.blade.php

## v4.7.4.0 — 2026-07-14 07:08
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o guard de "fechar o navegador = deslogar" para funcionar também no Microsoft Edge (e no Chrome com restauração completa de sessão). A versão anterior dependia do navegador limpar o sessionStorage ao fechar; o Edge, com "Inicialização rápida" + "Continuar de onde parei" (ligados por padrão), restaura a aba inteira — cookie E sessionStorage — deixando o guard cego. Agora o sinal principal é um "heartbeat" gravado no localStorage a cada 3 segundos por cada aba viva: ao fechar o navegador os beats param, mas o relógio continua, então na reabertura o último beat estará velho (> 20s) e a sessão restaurada é detectada e encerrada, independentemente do que o navegador restaure. Inclui verificação de escrita real do localStorage (se não persistir, o guard se desativa em vez de entrar em laço de logout) e mantém o anti-laço via sessionStorage. Verificado em navegador real (Chrome headless, mesmo motor do Edge) nos cenários: reabertura com sessionStorage restaurado → desloga; reload normal → mantém; primeiro login → mantém; nova aba legítima → mantém
- **Arquivos:** frontends/desktop/resources/views/layouts/app.blade.php

## v4.7.3.0 — 2026-07-14 01:23
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona um aviso ao fechar o navegador/aba com sessão ativa sem "Manter-me conectado": ao tentar fechar, o navegador exibe uma confirmação nativa lembrando de encerrar a sessão (útil como lembrete em computadores de clientes). O aviso é suprimido durante a navegação normal dentro do sistema (cliques em links do mesmo host, envio de formulários e recarregar a página) para não incomodar no uso do dia a dia — fica reservado ao fechamento de fato. Novo interruptor "Avisar ao fechar o navegador com sessão ativa" em Configurações do Sistema > Sessão e Segurança (ligado por padrão) controla o recurso. Observação técnica: navegadores modernos mostram a mensagem padrão do próprio navegador (não é possível personalizar o texto) e o diálogo também aparece ao recarregar pelo botão do navegador ou digitar outra URL
- **Arquivos:** frontends/desktop/database/migrations/2026_07_14_000002_add_warn_on_close_to_session_security_settings.php,frontends/desktop/app/Models/SessionSecuritySetting.php,frontends/desktop/app/Support/SessionSecuritySettings.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.2.0 — 2026-07-14 00:48
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reforça o "fechar o navegador = deslogar" para sessões sem "Manter-me conectado", corrigindo duas causas reais: (1) o cookie XSRF-TOKEN nascia com validade de 30 dias (efeito colateral do teto de sessão para o remember-me), aparecendo como um cookie "muito persistente" — agora, sem remember-me, tanto o XSRF-TOKEN quanto o cookie de sessão passam a ter validade curta (igual ao timeout de inatividade configurado), e o cookie de sessão continua morrendo ao fechar o navegador; (2) o recurso "Continuar de onde parei" do Chrome/Edge restaura o cookie de sessão ao reabrir o navegador, mantendo o usuário logado mesmo com o cookie efêmero — adicionado um guard em JS no layout autenticado (só para sessões não-lembradas) que usa sessionStorage (que o navegador limpa ao fechar a aba) mais um heartbeat em localStorage para distinguir "nova aba legítima da mesma sessão" de "navegador reaberto com sessão restaurada"; neste último caso força logout automático e volta para a tela de login. O guard não roda para sessões com "Manter-me conectado" marcado
- **Arquivos:** frontends/desktop/app/Http/Middleware/EnsureBackendToken.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.1.0 — 2026-07-14 00:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Torna configurável, em Configurações do Sistema > Sessão e Segurança, o que antes era fixo por `.env`: tempo de inatividade (minutos) que encerra sessões sem "Manter-me conectado" marcado, duração em dias do "Manter-me conectado", e um interruptor para desativar completamente o recurso (some o campo do login e invalida imediatamente o efeito de qualquer sessão já marcada como "lembrada", sem esperar novo login). Valores ficam guardados numa tabela local nova (`session_security_settings`, banco próprio do desktop) com fallback automático para os padrões de `.env` enquanto a tabela não existir ou estiver vazia
- **Arquivos:** frontends/desktop/database/migrations/2026_07_14_000001_create_session_security_settings_table.php,frontends/desktop/app/Models/SessionSecuritySetting.php,frontends/desktop/app/Support/SessionSecuritySettings.php,frontends/desktop/app/Support/DesktopSession.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.0.0 — 2026-07-13 23:46
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Corrige vulnerabilidade de segurança: o cookie de sessão do desktop sobrevivia ao fechamento do navegador em qualquer login (config `expire_on_close` vinha `false`), permitindo que alguém que reabrisse o navegador (ex.: em um computador de cliente, após o técnico esquecer o logoff) reaproveitasse a sessão autenticada de quem usou o sistema antes. Agora o padrão é seguro: a sessão morre ao fechar o navegador e também expira após 120 minutos de inatividade (configurável). Para quem realmente precisa continuar conectado, foi criado o recurso "Manter-me conectado neste dispositivo" — um checkbox no login (com aviso para não usar em computadores compartilhados) que, quando marcado, mantém a sessão viva por até 30 dias mesmo fechando o navegador, sem enfraquecer o timeout padrão de quem não marcar. A aba "Sessão e Segurança" (Configurações do Sistema) agora mostra os valores realmente aplicados. Também corrigido um erro de digitação histórico na variável de ambiente do cookie seguro (`SESSION_SECURE_COOKIES` → `SESSION_SECURE_COOKIE`, nome que o Laravel realmente lê)
- **Arquivos:** frontends/desktop/config/session.php,frontends/desktop/.env.example,frontends/desktop/app/Support/DesktopSession.php,frontends/desktop/app/Http/Middleware/EnsureBackendToken.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.6.6.0 — 2026-07-13 14:42
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza o acesso administrativo do sistema: o antigo item de menu lateral "Níveis de Acesso" agora é acessado por um botão dentro da aba "Sessão e Segurança" de Configurações do Sistema; a visualização e o gerenciamento de "Usuários" (listagem, filtros, criação, edição, ativar/desativar) foram movidos para uma nova aba "Usuários" dentro de Configurações do Sistema (embutida de verdade, não apenas um link — reaproveitando o mesmo conteúdo/modais/JS da página própria via partials); e uma nova aba "Integrações" concentra o acesso à tela de integrações (WhatsApp, pagamentos, e-mail, Google). O menu lateral "Configurações" agora só tem "Configurações do Sistema". Rotas e páginas próprias (/usuarios, /grupos, /configuracoes/integracoes) continuam existindo e funcionando normalmente — só o ponto de acesso principal mudou. Cada aba nova respeita a permissão do módulo correspondente (usuarios/grupos), com fallback automático para a primeira aba permitida caso alguém force `?tab=` sem permissão
- **Arquivos:** frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/UserController.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/users/index.blade.php,frontends/desktop/resources/views/users/_index-content.blade.php,frontends/desktop/resources/views/users/_index-modals.blade.php,frontends/desktop/resources/views/users/_index-scripts.blade.php,frontends/desktop/tests/Feature/Desktop/ConfigurationSystemTest.php

## v4.6.5.0 — 2026-07-13 09:34
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza o acesso às telas financeiras secundárias: removidos do menu lateral os 4 relatórios (DRE por Competência, DRE de Caixa, Fluxo de Caixa, Margem por OS) e também Cartões e Taxas, Configurações Financeiras e Precificação — a seção "Financeiro" do menu agora só tem "Lançamentos". Em vez disso, a tela de Lançamentos ganhou dois botões dropdown: "Relatórios" (os 4 relatórios) e "Mais ações" (Cartões e Taxas, Configurações Financeiras, Precificação — este último só aparece com permissão própria do módulo `precificacao`, distinta de `financeiro`). Rotas e páginas continuam as mesmas, só o ponto de acesso mudou
- **Arquivos:** frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.6.4.0 — 2026-07-13 07:49
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a notificação do sino "Orçamento aprovado/recusado pelo cliente" (link público), que estava documentada em notificacoes-sino.md mas nunca foi implementada — o evento era gravado normalmente na timeline da OS (os_eventos), porém `BudgetApprovalService::approveByToken()`/`rejectByToken()` nunca chamavam o `NotificationDispatchService`, então nenhuma linha em mobile_notifications era criada e nada era transmitido em tempo real via Reverb. Corrigido injetando o `NotificationDispatchService` no serviço e disparando `orcamento.approved`/`orcamento.rejected` para responsável + criador do orçamento + técnico da OS vinculada (mesma lista de destinatários já documentada), tanto na aprovação quanto na recusa pelo link público
- **Arquivos:** backend/app/Services/Budgets/BudgetApprovalService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v4.6.3.0 — 2026-07-13 07:17
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o gráfico "Tipos de Equipamento" do dashboard, que renderizava quebrado (segmentos empilhados com contorno branco sólido + cantos arredondados em todos os lados, dando aspecto de "pílulas" desconectadas em vez de uma coluna única) e com cores fora do padrão visual do sistema (12 cores arbitrárias, sem validação, com fórmula HSL gerando tons extras a partir do 13º tipo). Trocado por uma paleta categórica de 8 cores fixas e validadas (separação segura para daltonismo, contraste testado contra o fundo real do card, com um tom violeta próximo do roxo primário do sistema); do 9º tipo em diante agora entra no agregado "Outros" com uma cor neutra fixa, nunca mais uma cor gerada por índice. Só o segmento do topo da pilha recebe cantos arredondados (reto embaixo e entre segmentos), com um espaçamento fino na cor da superfície do card em vez de contorno branco chapado. Corrigido também o container do canvas, que só tinha "min-height" (sem altura explícita) — sem uma referência estável de altura o Chart.js (responsive + maintainAspectRatio:false) deixava o gráfico crescer sem limite, nunca cabendo inteiro na tela; agora usa altura explícita nas três faixas responsivas, mesmo padrão já usado no gráfico de rosca "OS por status"
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/dashboard.js

## v4.6.2.0 — 2026-07-12 22:51
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a listagem padrão de OS ocultando indevidamente ordens "Entregue - Pendência Financeira" (o escopo "aberta" filtrava também por status_final_pendente_pagamento; agora usa só os 3 status literais de OrderStatus::closureCodes(), no listing e no card "OS abertas" do dashboard — fixture de teste com grupo_macro divergente do banco real corrigido junto); corrige bug no botão "Editar" de Financeiro > Cartões e Taxas (mismatch camelCase/snake_case entre o JS e os campos do form deixava operadora, bandeira, parcelas, taxa % e taxa fixa em branco ao editar); campo "Parcelas" da baixa da OS agora respeita a faixa (min/max) realmente cadastrada para a operadora/modalidade/bandeira, com aviso da faixa liberada; e reorganiza a tela Financeiro > Cartões e Taxas (abas "Taxa por parcela" e "Taxas online"): tabela cadastrada ocupa a linha inteira e os formulários de cadastro/edição foram movidos para modais, acionados por um botão "Nova taxa"/"Nova taxa online" ou pelo "Editar" de cada linha
- **Arquivos:** .agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md,backend/app/Services/Dashboard/DashboardSummaryService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/public/assets/js/financeiro-cartoes.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/financeiro/cartoes.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartoesTest.php

## v4.6.1.0 — 2026-07-12 20:22
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Correções: cadastro rápido de cliente (erro 500 por CPF/CNPJ duplicado agora vira validação amigável; CPF/CNPJ normalizado para dígitos e com máscara progressiva 000.000.000-00 / 00.000.000/0000-00), correção de encoding mojibake nas mensagens em português do cadastro de equipamento (câmera/galeria/etc.), e regra na baixa da OS: encerrar como "Equipamento Entregue" passa a exigir ao menos algum valor recebido (pagamento parcial aceito, saldo restante segue como pendência financeira), validado no frontend e no backend
- **Arquivos:** backend/app/Http/Controllers/Api/V1/ClientController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderClosureService.php,frontends/desktop/public/assets/js/clients-form.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/orders/closure.blade.php

## v4.6.0.0 — 2026-07-12 20:10
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Implanta a Equipe da Assistência como base operacional separada de Usuários, com cadastro real de membros, vínculo opcional ao usuário do sistema, técnico da OS vindo dessa grade, contagem de documentos gerados no histórico da OS e padronização visual dos cards KPI da baixa
- **Arquivos:** backend/app/Http/Controllers/Api/V1/TeamMemberController.php,backend/app/Http/Requests/Api/V1/StoreTeamMemberRequest.php,backend/app/Http/Requests/Api/V1/UpdateTeamMemberActiveRequest.php,backend/app/Http/Requests/Api/V1/UpdateTeamMemberRequest.php,backend/app/Models/TeamMember.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_12_180000_create_equipe_membros_table.php,backend/routes/api.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Http/Controllers/PeopleController.php,frontends/desktop/app/Services/TeamMemberService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/people/technical-team.blade.php,frontends/desktop/routes/web.php

## v4.5.0.0 — 2026-07-12 19:54
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Reformulação da Central Documental da OS (geração/envio/compartilhamento assíncrono via AJAX, com polling de fila e link público), dropdown "Mais ações" padronizado em todas as telas de OS e orçamento (edição, baixa/encerramento, documentos, orçamento), item "Ver orçamento"/"Gerar orçamento" condicional, e validação guiada de campos obrigatórios na abertura/edição de OS (botão "Próximo" navega até o campo pendente, técnico/data de previsão passam a ser obrigatórios, telefone do cliente exibido no resumo e na seleção)
- **Arquivos:** backend/app/Support/TemplateHtmlSanitizer.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/bootstrap/app.php,frontends/desktop/phpunit.xml,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/resources/views/orders/documents-center/_documents-table.blade.php,frontends/desktop/resources/views/orders/documents-center/_send-history.blade.php,frontends/desktop/resources/views/orders/documents-center/_send-modal.blade.php,frontends/desktop/resources/views/orders/documents-center/_share-links.blade.php,frontends/desktop/resources/views/orders/documents-center/_share-modal.blade.php,frontends/desktop/resources/views/orders/documents-print.blade.php,frontends/desktop/resources/views/orders/edit.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.4.1.0 — 2026-07-11 20:07
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** correção do coletor dois cliques sem comando
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentCollectorController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Services/EquipmentWorkflowService.php,backend/database/migrations/2026_07_11_190000_add_submission_token_to_equipment_collector_pairings_table.php,backend/public/assets/agents/bench-collector/linux-x64/jovemtech-bench-collector.sh,backend/public/assets/agents/bench-collector/linux-x64/README.md,backend/public/assets/agents/bench-collector/win-x64/jovemtech-bench-collector.ps1,backend/public/assets/agents/bench-collector/win-x64/README.md,backend/routes/api.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/routes/web.php

## v4.4.0.0 — 2026-07-11 18:43
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** implantaçãodo coletor de harwares
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/EquipmentWorkflowService.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Support/Knowledge/PlaceholderCatalog.php,backend/config/services.php,backend/.env.production.example,backend/openapi.yaml,backend/public/assets/agents/bench-collector/linux-x64/jovemtech-bench-collector.sh,backend/public/assets/agents/bench-collector/linux-x64/README.md,backend/public/assets/agents/bench-collector/win-x64/jovemtech-bench-collector.ps1,backend/public/assets/agents/bench-collector/win-x64/README.md,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-11-fotos-sem-corte-e-visualizador-modal.md,documentacao/07-novas-implementacoes/2026-07-11-os-abertura-pdf-e-envio-whatsapp.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/layouts/partials/photo-viewer-modal.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,scripts/versionar.sh,VERSIONING.md

## v4.3.0.0 — 2026-07-11 16:07
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** restaura o PDF de abertura da OS, vincula o documento e adiciona envio opcional ao cliente
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Support/Knowledge/PlaceholderCatalog.php,backend/openapi.yaml,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/07-novas-implementacoes/2026-07-11-os-abertura-pdf-e-envio-whatsapp.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.2.0.1 — 2026-07-11 13:03
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** restaura css dos filtros do historico da os
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v4.2.0.0 — 2026-07-11 10:36
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fotos do desktop sem corte, visualizador modal e melhorias no versionar.sh com sincronização automática da documentação de agentes
- **Arquivos:** documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-11-fotos-sem-corte-e-visualizador-modal.md,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/layouts/partials/photo-viewer-modal.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,scripts/versionar.sh,VERSIONING.md

## v4.1.0.1 — 2026-07-10 22:04
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** ajuste da cor do painel administrativo em tema azul
- **Arquivos:** frontends/desktop/public/assets/css/themes/jovem-tech.css

## v4.1.0.0 — 2026-07-10 20:11
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** correção e ajustes das notificações (sino)
- **Arquivos:** backend/app/Console/Commands/NotifyOrderDeadlines.php,backend/app/Events/NotificationCreated.php,backend/app/Notifications/Channels/MobileInboxChannel.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Notifications/NotificationDispatchService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/channels.php,backend/routes/console.php,documentacao/03-arquitetura-tecnica/notificacoes-sino.md,frontends/desktop/app/Http/Controllers/NotificationController.php,frontends/desktop/app/Services/NotificationService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/routes/web.php

## v4.0.2.0 — 2026-07-10 18:01
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move o botao de notificacoes (sino) do lado direito para o lado esquerdo da barra superior, ficando ao lado do botao de inicio (casa)
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v4.0.1.1 — 2026-07-10 17:54
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Adiciona botao de atalho para o Dashboard (icone de casa) ao lado do toggle do menu lateral, e corrige sobreposicao entre a logo e o botao de expandir/recolher quando a sidebar esta recolhida (agora empilham verticalmente no hover)
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v4.0.1.0 — 2026-07-10 15:30
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste de segurança
- **Arquivos:** .agents/skills/sistema-erp-autenticacao-step-up/SKILL.md,backend/app/Http/Controllers/Api/V1/AuthController.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Web/BudgetPublicController.php,backend/app/Http/Requests/Api/V1/RevealEquipmentPasswordRequest.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/EquipmentWorkflowService.php,backend/config/services.php,backend/openapi.yaml,backend/phpunit.xml,backend/routes/api.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/BroadcastAuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Services/ConfigurationService.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/config/session.php,frontends/desktop/public/assets/js/configurations-integrations.js,frontends/desktop/public/assets/js/equipments-reveal-password-modal.js,frontends/desktop/public/assets/js/orders-list.js,frontends/desktop/resources/views/equipments/_reveal_password_modal.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/routes/web.php

## v4.0.0.0 — 2026-07-10 03:38
- **Tier:** major
- **Autor/Agente:** Codex
- **Descrição:** Hardening de seguranca: remove token do frontend, protege secrets de integracoes, mascara senhas de equipamentos, expira orcamentos publicos e endurece sessao/RBAC
- **Arquivos:** backend/app/Http/Controllers/Api/V1/AuthController.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Web/BudgetPublicController.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/EquipmentWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/BroadcastAuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ConfigurationService.php,frontends/desktop/config/session.php,frontends/desktop/public/assets/js/configurations-integrations.js,frontends/desktop/public/assets/js/orders-list.js,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/routes/web.php

## v3.21.0.0 — 2026-07-10 00:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** correções na tela de login, correções no RBCA, correções e ajustes na baixa da os
- **Arquivos:** .agents/skills/sistema-erp-os-fluxo-fechamento/references/regra-fechamento-os.md,.agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md,backend/app/Console/Commands/BackfillOsEventos.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Controllers/Api/V1/UserController.php,backend/app/Http/Requests/Api/V1/CloseOrderRequest.php,backend/app/Http/Requests/Api/V1/StoreUserRequest.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Http/Requests/Api/V1/UpdateUserRequest.php,backend/app/Models/OrderEvent.php,backend/app/Models/Order.php,backend/app/Notifications/FrontendPasswordResetNotification.php,backend/app/Providers/AppServiceProvider.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Company/CompanyProfileService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderEventService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/bootstrap/app.php,backend/database/migrations/2026_07_09_000001_create_os_eventos_table.php,backend/openapi.yaml,backend/routes/api.php,backend/routes/web.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,backend/tests/Feature/Api/V1/PasswordResetFlowTest.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,documentacao/03-arquitetura-tecnica/eventos-os.md,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Http/Controllers/UserController.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/app/Services/UserService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/_event_timeline.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/users/index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Unit/

## v3.20.0.1 — 2026-07-09 20:14
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Aproxima paineis do login em telas grandes
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.20.0.0 — 2026-07-09 08:22
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** modernizaçao e alinhamento do painel de login
- **Arquivos:** backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Services/Company/CompanyProfileService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/layouts/guest.blade.php,frontends/desktop/routes/web.php

## v3.19.1.2 — 2026-07-09 07:46
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta login para azul institucional e layout mobile enxuto
- **Arquivos:** frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.19.1.1 — 2026-07-09 07:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Moderniza tela de login com branding da assistência técnica
- **Arquivos:** backend/app/Services/Company/CompanyProfileService.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/routes/api.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/layouts/guest.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/routes/web.php

## v3.19.1.0 — 2026-07-09 04:23
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** implantação de botão de detalhes em um lançamanto
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php

## v3.19.0.1 — 2026-07-09 04:19
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Adiciona detalhe operacional dos lancamentos financeiros
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php

## v3.19.0.0 — 2026-07-09 03:56
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** recuperação da base de conhecimento, ajustes na visualização da os
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_09_000001_seed_conhecimento_module.php,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/routes/web.php

## v3.18.2.3 — 2026-07-09 03:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Restaura modulo de conhecimento e implementa checklist de entrada operacional na OS
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/routes/api.php,backend/database/migrations/2026_07_09_000001_seed_conhecimento_module.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/css/desktop.css

## v3.18.2.2 — 2026-07-09 03:24
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** ajusta status e diagnostico na tela de detalhe da os
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v3.18.2.1 — 2026-07-09 02:59
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Detalhe da OS passa a exibir tipo, marca e modelo no card Equipamento, mantendo serie e resumo tecnico como complemento.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v3.18.2.0 — 2026-07-09 02:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste e correção no processo de baixa da os
- **Arquivos:** backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/resources/views/orders/show.blade.php

## v3.18.1.3 — 2026-07-09 02:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Aba Valores da OS passa a exibir forma de pagamento resolvida pelos movimentos financeiros e alerta de peca orcada sem baixa de estoque vinculada.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/show.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v3.18.1.2 — 2026-07-09 01:42
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta status da OS conforme status do orçamento
- **Arquivos:** backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v3.18.1.1 — 2026-07-09 01:29
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Garante link publico copiavel e valor da OS em orcamentos
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v3.18.1.0 — 2026-07-09 01:06
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste de layout de elementos de paginas de listagem
- **Arquivos:** frontends/desktop/app/Http/Controllers/ServicoController.php,frontends/desktop/app/Http/Controllers/StockController.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/clients/index.blade.php,frontends/desktop/resources/views/components/,frontends/desktop/resources/views/equipments/index.blade.php,frontends/desktop/resources/views/estoque/index.blade.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/groups/index.blade.php,frontends/desktop/resources/views/orcamentos/index.blade.php,frontends/desktop/resources/views/servicos/index.blade.php,frontends/desktop/resources/views/suppliers/index.blade.php,frontends/desktop/resources/views/users/index.blade.php

## v3.18.0.0 — 2026-07-09 00:11
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** ajuste e correção de layout de graficos do dashboard
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/dashboard.js,frontends/desktop/resources/views/dashboard/index.blade.php

## v3.17.4.3 — 2026-07-09 00:05
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Protege graficos do dashboard no mobile
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.17.4.2 — 2026-07-09 00:02
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta densidade visual dos graficos do dashboard
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.17.4.1 — 2026-07-08 23:54
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Reorganiza graficos do dashboard
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/index.blade.php,frontends/desktop/public/assets/js/dashboard.js,frontends/desktop/public/assets/css/desktop.css

## v3.17.4.0 — 2026-07-08 22:57
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste no grafico de os entregues reparadas mes de março 2026
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.3.1 — 2026-07-08 22:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige serie mensal de entregas reparadas do dashboard para ignorar atualizacoes de importacao legado
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.3.0 — 2026-07-08 22:00
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajustes no dashboard
- **Arquivos:** frontends/desktop/app/Services/DocumentationService.php,frontends/desktop/resources/views/configurations/system.blade.php

## v3.17.2.1 — 2026-07-08 21:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Alinha KPI de OS abertas do dashboard ao escopo operacional da listagem de OS
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.2.0 — 2026-07-08 21:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** mostrar versionamento na documentaçãodo sistema nas configurações
- **Arquivos:** frontends/desktop/app/Services/DocumentationService.php

## v3.17.1.0 — 2026-07-08 21:20
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige permissao de execucao dos scripts (core.fileMode=false ignorava chmod +x) via git update-index --chmod, e corrige deploy-completo.sh lendo VERSION/CHANGELOG de develop (nao do main antigo) para a mensagem do merge
- **Arquivos:** scripts/bash/atualizar-dev.sh,scripts/bash/deploy-completo.sh,scripts/bash/deploy-producao.sh,scripts/bump-version.sh,scripts/classify-change.sh,scripts/versionar.sh

## v3.17.0.0 — 2026-07-08 21:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona scripts/versionar.sh e scripts/bash/deploy-completo.sh para versionar e publicar (dev->main) sem depender de IA
- **Arquivos:** AGENTS.md,.agents/skills/sistema-erp-deploy-producao/SKILL.md,documentacao/10-deploy/workflow-git-multiambiente.md,scripts/bash/deploy-completo.sh,scripts/versionar.sh,VERSIONING.md

## v3.16.0.1 — 2026-07-08 19:42
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige Baixa sumindo para Irreparavel/Reparo Recusado na listagem (is_encerrada ausente em mapSummary) e evita N+1 em OrderStatus::closureCodes()
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/index.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v3.16.0.0 — 2026-07-08 19:42
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Bloqueia mudanca de status em OS encerrada e adiciona Cancelar baixa com gate de administrador (step-up auth)
- **Arquivos:** backend/app/Models/OrderStatus.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/CancelOrderClosureRequest.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/app/Services/Financeiro/OsMargemService.php,backend/routes/api.php,backend/bootstrap/app.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_cancel_closure_modal.blade.php,frontends/desktop/public/assets/js/orders-cancel-closure-modal.js

## v3.15.2.4 — 2026-07-08 12:32
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige botao Limpar da listagem de OS para remover filtros via rota limpa
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php

## v3.15.2.3 — 2026-07-08 12:27
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Indica filtros ativos e trava recolhimento do painel de filtros da listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.2 — 2026-07-08 11:58
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta altura do badge de resultados e botao Filtros na listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.1 — 2026-07-08 11:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Alinha contador de resultados e botao Filtros ao campo de busca na listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.0 — 2026-07-08 11:46
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS: sincronizacao instantanea Status/Macrofase com Select2 e limpeza sem recarregar pagina
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.15.1.0 — 2026-07-08 11:32
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS: Macrofase movida para filtros principais e sincronizada bidirecionalmente com Status
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.15.0.0 — 2026-07-08 11:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS passam a usar catalogo proprio de status autorizado por os:visualizar, restaurando Select2 de status e macrofase
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/DesktopOrderStatusFlowService.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.14.2.0 — 2026-07-08 10:50
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Listagem inicial de OS passa a ocultar encerramentos canonicos e entregas com cobranca pendente, mantendo filtros explicitos para historico
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/index.blade.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.14.1.0 — 2026-07-07 12:54
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Listagem de OS: botao 'Filtrar' ao lado do campo de busca no cabecalho + correcao do campo de busca que estava fora do <form> (nao era submetido ao filtrar). A busca agora submete o form de filtros via atributo HTML5 form=osFilterPanel, carregando status/itens por pagina/filtros avancados junto, mesmo com o painel recolhido.
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.14.0.0 — 2026-07-07 12:45
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Overhaul do modal 'Alterar status da OS': card de equipamento passa a exibir tipo+marca+modelo; switch 'Notificar o cliente' movido para o rodape do modal; nova aba 'Procedimentos' em 2 colunas — registro de procedimentos executados com historico datado por tecnico (nova tabela os_procedimentos_historico) + campos de diagnostico e solucao salvos junto com o status; botao 'Salvar status' sempre habilitado (permite salvar diagnostico/solucao sem trocar o status, sem gerar historico/notificacao espuria); notificacao WhatsApp ao cliente na mudanca de status quando o switch esta ativo, com fallback de envio direto pela Evolution API quando a Central de Atendimento (banco chat) esta indisponivel; corrigido fundo transparente do modal (faltava a classe modal-shell). Novo endpoint POST /api/v1/orders/{order}/procedures; migration aditiva os_procedimentos_historico.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpdateOrderStatusRequest.php,backend/app/Http/Requests/Api/V1/StoreOrderProcedureRequest.php,backend/app/Models/Order.php,backend/app/Models/OrderProcedureHistory.php,backend/database/migrations/2026_07_07_000001_create_os_procedimentos_historico_table.php,backend/routes/api.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/_status_modal.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/js/orders-status-modal.js,frontends/desktop/public/assets/css/desktop.css

## v3.13.1.0 — 2026-07-06 23:59
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige 'Ocorreu um erro inesperado' ao salvar orcamento (INSERT falhava com SQLSTATE 42S22 Unknown column desconto_tipo): a migration 2026_07_03_000001_add_adjustment_modes_to_orcamentos_tables estava marcada como executada no laravel_migrations, mas as 4 colunas de ajuste percentual (desconto_tipo, desconto_percentual, acrescimo_tipo, acrescimo_percentual) nunca existiram de fato nas tabelas orcamentos e orcamento_itens deste banco (drift de schema, mesma classe de problema ja documentada no deploy Contabo). Corrigido com ALTER aditivo direto no banco de dev (192.168.1.100), sem alterar nenhum arquivo de codigo
- **Arquivos:** (nenhum arquivo de codigo — correcao aplicada diretamente no banco sistema_hml de 192.168.1.100)

## v3.13.0.0 — 2026-07-06 23:44
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cadastro rapido de item no orcamento: campo 'Tipo de equipamento' virou Select2 com tags (escolher existente ou digitar novo), reaproveitando o catalogo ja usado em Servicos/Estoque; corrigido bug generico de dropdown do Bootstrap dentro de tabela responsiva (abria para cima sobre a propria linha e ficava cortado em tabelas curtas) — menu agora e movido para o body enquanto aberto, corrigindo Acoes em todas as listagens (OS, equipamentos, financeiro etc.)
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/quick-item-modal.blade.php

## v3.12.0.1 — 2026-07-06 12:04
- **Tier:** hotfix
- **Autor/Agente:** Claude
- **Descrição:** Documenta na armadilha do runbook Contabo o erro 'untracked working tree files would be overwritten by merge' no passo [2/5] do deploy-producao.sh (arquivos nao versionados na VPS colidindo com o commit remoto) e como resolver movendo-os para backup antes de repetir o script
- **Arquivos:** documentacao/10-deploy/deploy-producao-contabo-vps.md

## v3.12.0.0 — 2026-07-06 11:12
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Fluxo de caixa: coluna 'Entrada projetada' (dia em que o dinheiro efetivamente cai na conta para vendas em cartão, podendo cruzar de mês) e coluna 'Saldo líquido em conta' (acumulado já líquido de taxa, corrigindo também um bug pré-existente em que o saldo inicial só somava o dia anterior ao período em vez do histórico completo); botão de Detalhes por dia com modal de lançamentos (pago/recebido e previsto para cair no dia) e submodal de detalhes do cartão (operadora, bandeira, modalidade, parcelas, taxa, prazo); correção de um bug do Bootstrap 5 (modal empilhado perde o scroll-lock do modal externo ao fechar o interno)
- **Arquivos:** backend/app/Models/Financeiro.php,backend/app/Models/FinanceiroMovimento.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/tests/Feature/Api/V1/FinanceiroReportTest.php,frontends/desktop/resources/views/financeiro/relatorios/fluxo-caixa.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js

## v3.11.0.0 — 2026-07-06 11:12
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Cancelamento de lançamento financeiro: botão Cancelar no dropdown de Ações (estorna movimentos do título e, se houver, da despesa de Taxa de cartão vinculada), exclusão de títulos cancelados do DRE por competência, e taxa da operadora passa a ser registrada como despesa separada (Despesas Operacionais / Taxas e impostos) no dia do pagamento, em vez de ficar invisível no fluxo de caixa e no DRE
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/routes/api.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/lang/pt_BR/validation.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v3.10.0.0 — 2026-07-05 23:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Baixa de lancamento financeiro: botoes de valor total/parcial e forma de pagamento com campos de cartao (operadora/bandeira/modalidade/parcelas) e estimativa de taxa, no mesmo padrao da baixa da OS; backend passa a expor valor_aberto por lancamento e o catalogo de cartao, e registra FinanceiroMovimentoCartao quando a baixa e' em cartao. Corrigido tambem um bug critico pre-existente (ja presente antes desta entrega): o modal de baixa era um `<div>` filho direto de `<tbody>` (invalido em HTML), o que faz o navegador aplicar "foster parenting" e esvaziar o `<form>` — o `Confirmar baixa` submetia o formulario sem nenhum campo. Os modais agora sao renderizados num loop separado, fora de `<table>`/`<tbody>`
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCatalogController.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Http/Requests/Api/V1/RegisterFinanceiroMovementRequest.php,backend/app/Services/Financeiro/FinanceiroService.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/public/assets/js/financeiro-pay.js

## v3.9.1.0 — 2026-07-05 23:13
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige TypeError 'destroy is not a function' no Select2 da tela Financeiro (Cliente/Categoria): atributo data-select2="false" colidia com a chave interna do plugin e foi trocado por data-native-select="true"
- **Arquivos:** frontends/desktop/resources/views/financeiro/form.blade.php

## v3.9.0.0 — 2026-07-05 20:30
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Lançamentos financeiros avulsos sem OS, opcionais por cliente, com histórico protegido no cliente e bloqueio no fluxo da OS
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertFinanceiroRequest.php,backend/app/Models/Financeiro.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/database/migrations/2026_07_05_190000_add_avulso_to_financeiro_table.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/resources/views/financeiro/index.blade.php,specs/020-lancamentos-avulsos-financeiro-cliente,documentacao/07-novas-implementacoes/2026-07-05-lancamentos-avulsos-financeiro-cliente.md

## v3.8.0.0 — 2026-07-05 18:04
- **Tier:** minor
- **Autor/Agente:** jovem-tech
- **Descrição:** Sistema de desenvolvimento e deploy profissional: repositorio GitHub (jovem-tech/erp) como fonte unica da verdade, branches develop (dev 192.168.1.100) / main (producao VPS Contabo), deploy keys dedicadas por servidor, scripts de deploy git-based, XAMPP definitivamente descontinuado. AGENTS.md ganha mandato LEIA ISTO PRIMEIRO para qualquer IA.
- **Arquivos:** AGENTS.md,README.md,documentacao/10-deploy/workflow-git-multiambiente.md,scripts/bash/deploy-producao.sh,scripts/bash/atualizar-dev.sh

## v3.7.3.1 — 2026-07-05 16:37
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Documentacao em dia com a producao: notas do deploy Contabo (subdominios, copia de dados reais, fixes de schema/broadcasting/DNS) e da padronizacao de cliente; novo runbook Contabo; historico 3.7.1-3.7.3; AGENTS e skill de deploy com topologia atual

## v3.7.3.0 — 2026-07-05 16:24
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Padroniza nome (Title Case pt-BR, so pessoa fisica) e telefone (mascara (DDD) numero) no cadastro de cliente do desktop (rapido e completo): JS para UX ao vivo + ClientController autoritativo
- **Arquivos:** frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/public/assets/js/clients-form.js

## v3.7.2.0 — 2026-07-05 06:08
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige broadcasting/auth 403 em producao: channels.php passa a ser carregado com require (nao loadRoutesFrom) para sobreviver ao route:cache, registrando os canais de broadcasting (tempo real da Central de Atendimento e OS ao vivo)
- **Arquivos:** backend/app/Providers/AppServiceProvider.php

## v3.7.1.0 — 2026-07-04 22:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige OrderController::jsonFailure ausente (500 na busca de clientes/Select2 da Nova OS) e reconcilia schema de clientes/usuarios/financeiro com colunas que o ERP espera (referencia etc.), aplicado em dev e VPS
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrderController.php

## v3.7.0.0 — 2026-07-04 17:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Ambiente de desenvolvimento oficial migrado para Linux (BANCADA-02); nova topologia de portas (desktop 443, backend 8443); correcoes de auditoria: pools FPM dedicados, upload 25M, MySQL/Redis tuning, UFW+fail2ban, SSH hardening, TLS com SAN, backup diario, cookie Secure; ApiClient Guzzle 8-ready; raiz backend sem welcome page
- **Arquivos:** documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md,backend/routes/web.php,frontends/desktop/app/Services/ApiClient.php,AGENTS.md

## v3.6.0.1 — 2026-07-04 09:20
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Rodape do desktop passa a exibir a versao de 4 posicoes lida do arquivo VERSION (fonte unica), com fallback para shared/version.php
- **Arquivos:** frontends/desktop/app/Providers/DesktopAppServiceProvider.php

## v3.6.0.0 — 2026-07-04 08:35
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Deploy de producao em LAN Ubuntu documentado (runbook 10-deploy), aba Documentacao em Configuracoes>Sistema no desktop, correcao ForceHttps com BinaryFileResponse, adocao do protocolo de versionamento 4 posicoes
- **Arquivos:** documentacao/10-deploy/deploy-producao-lan-ubuntu.md,frontends/desktop/app/Services/DocumentationService.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/configurations/system.blade.php,backend/app/Http/Middleware/ForceHttps.php,VERSIONING.md,VERSION,CHANGELOG.md

## v3.5.3.0 — Baseline
- **Tier:** —
- **Autor/Agente:** Otávio
- **Descrição:** Ponto de partida do novo protocolo de versionamento de 4 posições. Versão anterior era V3.5.3 (3 posições); a partir daqui todo commit deve gerar uma entrada aqui.

# Manifesto do Sistema

Gerado automaticamente por `scripts/php/sync-agent-docs.php`.

- Gerado em: `2026-07-07T15:55:05+00:00`
- Versao do sistema: `3.14.1`
- Versao da API: `1.1.1`
- Ambiente oficial de producao: `Ubuntu VPS`
- Ambiente local de referencia: `Windows/XAMPP`

## Arquitetura resumida

- Backend: Laravel 13.x em backend/ como fonte unica de verdade
- Desktop: Laravel/Blade em frontends/desktop consumindo apenas a API central
- Mobile: Next.js em frontends/mobile consumindo a mesma API central

## Fontes de verdade

### AGENTES

- `AGENTS.md`
- `documentacao/04-governanca-ai/operacao-para-agentes.md`
- `documentacao/04-governanca-ai/manifesto-do-sistema.md`

### ARQUITETURA

- `documentacao/00-visao-geral/arquitetura-alvo.md`
- `documentacao/03-arquitetura-tecnica/README.md`
- `backend/openapi.yaml`

### GOVERNANCA

- `.specify/memory/constitution.md`
- `specs/`
- `documentacao/07-novas-implementacoes/historico-de-versoes.md`

## Categorias documentais

### `documentacao/00-visao-geral`

- `documentacao/00-visao-geral/arquitetura-alvo.md` - Arquitetura Alvo
- `documentacao/00-visao-geral/prd-frontend-sistema-hml-bff.md` - PRD - Frontend sistema-hml como BFF

### `documentacao/01-fundacao`

- `documentacao/01-fundacao/acesso-seguro-a-arquivos.md` - Acesso Seguro a Arquivos
- `documentacao/01-fundacao/contrato-de-ambiente.md` - Contrato de Ambiente
- `documentacao/01-fundacao/estrutura-fisica.md` - Estrutura Fisica Inicial

### `documentacao/02-infraestrutura-ambientes`

- `documentacao/02-infraestrutura-ambientes/README.md` - Fase 2 - Infraestrutura de Desenvolvimento e Produção
- `documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md` - Ambiente Oficial de Desenvolvimento — Linux (BANCADA-02, 192.168.1.100)
- `documentacao/02-infraestrutura-ambientes/cors-urls-logs-filas-scheduler.md` - CORS, URLs, Logs, Filas, Cache/Sessao e Scheduler
- `documentacao/02-infraestrutura-ambientes/linux-vps.md` - Servidor Linux (Ubuntu) — LAN ou VPS
- `documentacao/02-infraestrutura-ambientes/windows-xampp.md` - Windows + XAMPP

### `documentacao/03-arquitetura-tecnica`

- `documentacao/03-arquitetura-tecnica/README.md` - Arquitetura Técnica - Fases 4, 5, 6 e 7
- `documentacao/03-arquitetura-tecnica/backend-administrativo-rbac.md` - Backend Administrativo e RBAC Central
- `documentacao/03-arquitetura-tecnica/backend-central-minimo.md` - Backend Central Minimo
- `documentacao/03-arquitetura-tecnica/catalogo-status-os.md` - Catálogo de status das Ordens de Serviço
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md` - Contrato da API do Backend Central
- `documentacao/03-arquitetura-tecnica/frontend-desktop-laravel.md` - Frontend Desktop Laravel
- `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md` - Frontend sistema-hml como BFF
- `documentacao/03-arquitetura-tecnica/mapa-migracao-legado-frontend-sistema-hml.md` - Mapa Completo de Migração e Limpeza do `frontend/sistema-hml`
- `documentacao/03-arquitetura-tecnica/ordens-mobile.md` - Fluxo de OS Mobile

### `documentacao/04-governanca-ai`

- `documentacao/04-governanca-ai/README.md` - Governança para Agentes
- `documentacao/04-governanca-ai/manifesto-do-sistema.md` - Manifesto do Sistema
- `documentacao/04-governanca-ai/operacao-para-agentes.md` - Operação para Agentes

### `documentacao/07-novas-implementacoes`

- `documentacao/07-novas-implementacoes/2026-06-22-fase-5-pwa-mobile-sessao-seguranca.md` - Fase 5 - PWA Mobile, Sessão e Segurança
- `documentacao/07-novas-implementacoes/2026-06-22-fase-6-backend-administrativo-rbac.md` - Fase 6 - Backend Administrativo e RBAC Central
- `documentacao/07-novas-implementacoes/2026-06-22-fase-7-frontend-desktop-laravel.md` - 2026-06-22-fase-7-frontend-desktop-laravel
- `documentacao/07-novas-implementacoes/2026-06-22-fluxo-os-mobile-detalhe-anexos.md` - Fluxo de OS mobile: detalhe da OS e anexos controlados
- `documentacao/07-novas-implementacoes/2026-06-23-clientes-operacionais-desktop-erp.md` - 2026-06-23 - Clientes operacionais no desktop do sistema-erp
- `documentacao/07-novas-implementacoes/2026-06-23-equipamentos-operacionais-desktop-erp.md` - 2026-06-23 - Equipamentos operacionais no desktop do sistema-erp
- `documentacao/07-novas-implementacoes/2026-06-24-cadastro-completo-equipamentos-desktop-erp.md` - 2026-06-24-cadastro-completo-equipamentos-desktop-erp
- `documentacao/07-novas-implementacoes/2026-06-24-clientes-cadastro-rapido-os-desktop-erp.md` - 2026-06-24 - Cadastro rápido de clientes na OS do desktop ERP
- `documentacao/07-novas-implementacoes/2026-06-24-clientes-formulario-paridade-visual-desktop-erp.md` - 2026-06-24 - Formulário de novo cliente com paridade visual no desktop do sistema-erp
- `documentacao/07-novas-implementacoes/2026-06-24-frontend-sistema-hml-auth-bff.md` - Autenticação BFF do frontend sistema-hml
- `documentacao/07-novas-implementacoes/2026-06-24-frontend-sistema-hml-dashboard-bff.md` - Dashboard BFF do frontend sistema-hml
- `documentacao/07-novas-implementacoes/2026-06-24-frontend-sistema-hml-notificacoes-bff.md` - Notificações BFF do frontend sistema-hml
- `documentacao/07-novas-implementacoes/2026-06-24-governanca-bff-sistema-hml.md` - Governança do frontend sistema-hml como BFF
- `documentacao/07-novas-implementacoes/2026-06-24-menu-pessoas-comercial-sidebar-desktop-erp.md` - 2026-06-24 - Menu `Pessoas` na seção comercial da sidebar do desktop do sistema-erp
- `documentacao/07-novas-implementacoes/2026-06-24-select2-obrigatorio-desktop.md` - 2026-06-24-select2-obrigatorio-desktop
- `documentacao/07-novas-implementacoes/2026-06-25-auditoria-completa-e-correcoes-de-seguranca.md` - Auditoria completa do sistema-erp e correções de segurança (Fase 0 e Fase 1)
- `documentacao/07-novas-implementacoes/2026-06-25-correcoes-ui-equipamentos-e-log-de-erros.md` - Correções de UI no cadastro de equipamentos e padronização de log de erros
- `documentacao/07-novas-implementacoes/2026-06-25-descontinuacao-frontend-sistema-hml.md` - Descontinuação do frontend sistema-hml
- `documentacao/07-novas-implementacoes/2026-06-25-edicao-operacional-equipamentos-desktop-erp.md` - Edicao operacional de equipamentos no desktop ERP
- `documentacao/07-novas-implementacoes/2026-06-25-equipamentos-upload-multipart-header.md` - 2026-06-25 - Correção do upload multipart no cadastro de equipamentos
- `documentacao/07-novas-implementacoes/2026-06-25-fase-2-correcoes-fila-https-csp-mobile.md` - Fase 2 da auditoria: fila assíncrona, HTTPS, CSP e limpeza no mobile
- `documentacao/07-novas-implementacoes/2026-06-25-fase-3-testes-mobile-e-auditoria-independente.md` - Fase 3 da auditoria: testes reais no mobile e skill de auditoria independente
- `documentacao/07-novas-implementacoes/2026-06-25-foto-obrigatoria-destaque-equipamentos.md` - Foto obrigatoria e destaque visual em equipamentos
- `documentacao/07-novas-implementacoes/2026-06-25-modal-cliente-equipamentos-sem-resposta.md` - 2026-06-25 - Modal de cliente sem resposta no cadastro de equipamentos
- `documentacao/07-novas-implementacoes/2026-06-25-vinculo-catalogo-quick-add-equipamentos.md` - 2026-06-25 - Vinculo de catalogo no quick-add de equipamentos
- `documentacao/07-novas-implementacoes/2026-06-26-configuracoes-integracoes-desktop-erp.md` - 2026-06-26 - Configurações > Integrações no desktop ERP
- `documentacao/07-novas-implementacoes/2026-06-26-fornecedores-operacionais-desktop-erp.md` - 2026-06-26 - Fornecedores operacionais no backend central e no desktop
- `documentacao/07-novas-implementacoes/2026-06-26-orcamentos-comerciais-desktop-erp.md` - Orçamentos comerciais no desktop ERP
- `documentacao/07-novas-implementacoes/2026-06-26-paridade-painel-os-desktop.md` - Paridade operacional do painel de Ordens de Serviço no desktop
- `documentacao/07-novas-implementacoes/2026-06-26-release-v3.1.21-servicos-estoque-operacionais.md` - Release v3.1.21 - Serviços e Estoque Operacionais
- `documentacao/07-novas-implementacoes/2026-06-27-acoes-edicao-baixa-os-desktop.md` - Ações de OS no desktop: dropdown, edição e baixa (paridade completa com o legado)
- `documentacao/07-novas-implementacoes/2026-06-27-inbox-whatsapp-tempo-real.md` - Central de Atendimento v1: WhatsApp Web/mobile do ERP
- `documentacao/07-novas-implementacoes/2026-06-28-cartoes-taxas-desktop.md` - Cartões e Taxas no Desktop ERP
- `documentacao/07-novas-implementacoes/2026-06-28-correcao-utf8-painel-os-desktop.md` - Correcao UTF-8 no painel de OS do desktop
- `documentacao/07-novas-implementacoes/2026-06-28-endurecimento-recuperacao-senha-email.md` - Endurecimento da recuperacao de senha por e-mail
- `documentacao/07-novas-implementacoes/2026-06-28-endurecimento-seguranca-chat-integracoes.md` - 2026-06-28 - Endurecimento de seguranca do chat e das integracoes
- `documentacao/07-novas-implementacoes/2026-06-29-configuracoes-sistema-e-precificacao-financeiro-desktop.md` - Configurações do sistema separadas e precificação no financeiro
- `documentacao/07-novas-implementacoes/2026-06-29-fluxo-caixa-calendario-desktop.md` - Fluxo de caixa com visualização em calendário no desktop
- `documentacao/07-novas-implementacoes/2026-06-29-fluxo-trabalho-os-visual-desktop.md` - Fluxo de trabalho visual das OS no desktop
- `documentacao/07-novas-implementacoes/2026-06-29-modelo-assistencia-tecnica-desktop.md` - Modelo ideal da assistência técnica no desktop
- `documentacao/07-novas-implementacoes/2026-06-29-nova-os-dropdown-equipamento-foto-marca-modelo.md` - Nova OS: dropdown de equipamento com foto e fallback por marca/modelo
- `documentacao/07-novas-implementacoes/2026-06-29-nova-os-editar-equipamento-selecionado.md` - Nova OS: edicao do equipamento selecionado
- `documentacao/07-novas-implementacoes/2026-06-29-nova-os-modal-cadastro-equipamento.md` - Nova OS: modal de cadastro completo de equipamento
- `documentacao/07-novas-implementacoes/2026-06-29-nova-os-modal-equipamento-retorno-iframe.md` - Nova OS: retorno do equipamento criado no iframe
- `documentacao/07-novas-implementacoes/2026-06-29-nova-os-resumo-cliente-editar.md` - 2026-06-29 - Resumo do cliente e edicao direta na Nova OS
- `documentacao/07-novas-implementacoes/2026-06-29-select2-manual-init-collision-desktop.md` - 2026-06-29 - Correcao do Select2 manual na criacao de OS do desktop
- `documentacao/07-novas-implementacoes/2026-06-30-otimizacao-performance-backend-desktop.md` - Otimização de performance: backend e desktop
- `documentacao/07-novas-implementacoes/2026-07-01-sidebar-conhecimento-layout.md` - 2026-07-01 - Ajuste de taxonomia e densidade do sidebar desktop
- `documentacao/07-novas-implementacoes/2026-07-02-acoes-rapidas-listagem-os-desktop.md` - Acoes rapidas na listagem de OS do desktop
- `documentacao/07-novas-implementacoes/2026-07-02-jovem-tech-design-system-tema-desktop.md` - Jovem Tech Design System v3.0.0 — Novo tema do desktop
- `documentacao/07-novas-implementacoes/2026-07-03-cadastro-rapido-pecas-servicos-orcamento-desktop.md` - Cadastro rapido de pecas e servicos no orcamento desktop
- `documentacao/07-novas-implementacoes/2026-07-03-orcamentos-ajustes-percentuais.md` - Orçamentos: desconto e acréscimo em valor ou percentual
- `documentacao/07-novas-implementacoes/2026-07-03-orcamentos-aprovacao-publica-desktop.md` - Orcamentos com revisao antes de salvar e aprovacao publica
- `documentacao/07-novas-implementacoes/2026-07-03-orcamentos-botao-cadastro-dinamico-por-tipo.md` - Orcamentos desktop: botao de cadastro dinamico por tipo
- `documentacao/07-novas-implementacoes/2026-07-03-orcamentos-resumo-financeiro-4-colunas.md` - Orcamentos desktop: resumo financeiro em 4 colunas
- `documentacao/07-novas-implementacoes/2026-07-03-orcamentos-valores-monetarios-brl.md` - Orçamentos: campos monetários em BRL com entrada segura
- `documentacao/07-novas-implementacoes/2026-07-04-ambiente-dev-linux-oficial-correcoes-auditoria.md` - Ambiente de desenvolvimento oficial em Linux e correcoes da auditoria de infraestrutura
- `documentacao/07-novas-implementacoes/2026-07-04-deploy-producao-lan-documentacao-integrada-versionamento.md` - Deploy de producao em LAN, documentacao integrada ao desktop e protocolo de versionamento
- `documentacao/07-novas-implementacoes/2026-07-04-orcamentos-modal-compactacao.md` - Compactacao do modal de revisao de orcamento
- `documentacao/07-novas-implementacoes/2026-07-05-baixa-financeiro-cartao-valor-parcial.md` - Baixa de lancamento financeiro: valor total/parcial e forma de pagamento em cartao
- `documentacao/07-novas-implementacoes/2026-07-05-deploy-producao-contabo-subdominios-e-dados-reais.md` - Deploy de producao na VPS Contabo com subdominios e dados reais do legado
- `documentacao/07-novas-implementacoes/2026-07-05-lancamentos-avulsos-financeiro-cliente.md` - Lançamentos avulsos com histórico financeiro do cliente
- `documentacao/07-novas-implementacoes/2026-07-05-padronizacao-nome-telefone-cadastro-cliente-desktop.md` - Padronizacao de nome e telefone no cadastro de cliente (desktop)
- `documentacao/07-novas-implementacoes/2026-07-05-select2-colisao-financeiro-desktop.md` - Correcao de colisao do Select2 no formulario Financeiro (desktop)
- `documentacao/07-novas-implementacoes/2026-07-06-cancelamento-lancamento-taxa-cartao.md` - Cancelamento de lançamento financeiro e taxa de cartão como despesa
- `documentacao/07-novas-implementacoes/2026-07-06-fluxo-caixa-entrada-projetada-saldo-liquido.md` - Fluxo de caixa: entrada projetada, saldo líquido e detalhamento diário
- `documentacao/07-novas-implementacoes/2026-07-06-schema-drift-ajustes-percentuais-orcamento.md` - Correcao de drift de schema: colunas de ajuste percentual ausentes em orcamentos
- `documentacao/07-novas-implementacoes/2026-07-06-tipo-equipamento-select2-e-dropdown-tabela.md` - Tipo de equipamento em Select2 com tags e correcao de dropdown em tabela responsiva
- `documentacao/07-novas-implementacoes/2026-07-07-modal-status-os-procedimentos-notificacao-cliente.md` - Overhaul do modal Alterar status da OS: procedimentos, diagnostico/solucao e notificacao ao cliente
- `documentacao/07-novas-implementacoes/historico-de-versoes.md` - Historico de versoes

### `documentacao/10-deploy`

- `documentacao/10-deploy/README.md` - Deploy e Operacao
- `documentacao/10-deploy/deploy-producao-contabo-vps.md` - Runbook de Producao — VPS Contabo (subdominios + dados reais)
- `documentacao/10-deploy/deploy-producao-lan-ubuntu.md` - Deploy de Produção — Ubuntu Server (LAN ou VPS)
- `documentacao/10-deploy/manual-inicializacao-local-windows-xampp.md` - Manual de Inicializacao Local no Windows com XAMPP
- `documentacao/10-deploy/workflow-git-multiambiente.md` - Fluxo Git Multiambiente — Sistema ERP

## Inventario de specs

- `specs/001-fundacao-fisica` - 001-fundacao-fisica | artefatos: 
- `specs/002-infraestrutura-ambientes` - Spec: Infraestrutura de Desenvolvimento e Produção | artefatos: spec, plan, tasks, research, quickstart, checklists
- `specs/003-backend-central-minimo` - Spec: Backend Central Mínimo | artefatos: spec, plan, tasks, research, quickstart, checklists
- `specs/004-os-mobile-flow` - Spec: Fluxo de OS Mobile | artefatos: spec, plan, tasks, research, quickstart, data_model, contracts
- `specs/005-pwa-mobile-session` - Spec: Sessão e segurança do PWA mobile | artefatos: spec, plan, tasks
- `specs/006-backend-administrativo-rbac` - Spec: Backend administrativo e RBAC central | artefatos: spec, plan, tasks, research, quickstart, analysis, data_model, contracts, checklists
- `specs/007-frontend-desktop-laravel` - Spec 007 - Frontend Desktop Laravel | artefatos: spec, plan, tasks, quickstart, analysis
- `specs/008-cadastro-equipamentos-desktop` - Feature Specification: Cadastro Completo de Equipamentos no Desktop | artefatos: spec, plan, tasks, research, quickstart, analysis, data_model, contracts
- `specs/008-governanca-bff-sistema-hml` - Spec 008 - Governança do BFF sistema-hml | artefatos: spec, plan, contracts, checklists
- `specs/009-paridade-painel-os-desktop` - Feature Specification: Paridade Operacional do Painel de Ordens de Serviço no Desktop | artefatos: spec, plan, tasks
- `specs/010-inbox-whatsapp-tempo-real` - Feature Specification: Central de Atendimento — Inbox de WhatsApp em tempo real | artefatos: spec, plan, tasks
- `specs/011-acoes-edicao-baixa-os-desktop` - Feature Specification: Ações de OS no Desktop — Dropdown, Edição e Baixa (paridade completa com o legado) | artefatos: spec, plan, tasks
- `specs/012-financeiro-cartoes-taxas-desktop` - Feature Specification: Financeiro - Cartões e Taxas no Desktop | artefatos: spec, plan, tasks, research, quickstart, data_model, contracts
- `specs/013-fluxo-caixa-calendario-desktop` - Feature Specification: Fluxo de caixa no desktop com lista e calendário | artefatos: spec, plan, tasks
- `specs/014-configuracoes-sistema-e-precificacao-financeiro-desktop` - Feature Specification: Configuracoes do Sistema e Precificacao no desktop | artefatos: spec, plan, tasks
- `specs/015-nova-os-wizard-desktop` - Feature Specification: Nova OS em modo wizard no desktop | artefatos: spec, plan, tasks
- `specs/016-modelo-assistencia-tecnica-desktop` - Feature Specification: Modelo Ideal da Assistência Técnica no Desktop | artefatos: spec, plan, tasks
- `specs/017-cadastro-rapido-servicos-pecas-orcamento-desktop` - Feature Specification: Cadastro rápido de serviços e peças no orçamento desktop | artefatos: spec, plan, tasks
- `specs/018-orcamentos-ajustes-percentuais-desktop` - Feature Specification: Orcamentos - Ajustes Monetarios ou Percentuais | artefatos: spec, plan, tasks
- `specs/019-aprovacao-orcamento-desktop` - Feature Specification: Orcamentos - Revisao antes de salvar e envio para aprovacao | artefatos: spec, plan, tasks
- `specs/020-lancamentos-avulsos-financeiro-cliente` - Feature Specification: Lançamentos avulsos com histórico financeiro do cliente | artefatos: spec, plan, tasks


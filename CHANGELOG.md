# Changelog — Sistema ERP Jovem Tech

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

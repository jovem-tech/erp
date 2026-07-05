# Changelog — Sistema ERP Jovem Tech

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

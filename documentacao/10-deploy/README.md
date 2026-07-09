# Deploy e Operacao

Este diretorio concentra os guias operacionais para subir, validar e publicar o `sistema-erp`.

## Guias disponiveis

- [Runbook de producao na VPS Contabo (subdominios + dados reais)](deploy-producao-contabo-vps.md) — **producao atual** (`erp.` desktop + `api-erp.` backend), em paralelo ao legado.
- [Deploy de producao em Ubuntu Server (LAN ou VPS)](deploy-producao-lan-ubuntu.md) — fundamentos e tabela geral de problemas do primeiro deploy (2026-07-03/04).
- [Fluxo Git Multiambiente](workflow-git-multiambiente.md) — como o codigo viaja de `develop` ate a VPS (`main`), scripts de versionamento e promocao.
- [Manual de Publicacao — Versionar e Deploy](manual-versionamento-e-deploy.md) — passo a passo, pensado para ser seguido sem IA, dos 3 scripts (`versionar.sh`, `deploy-completo.sh`, `deploy-producao.sh`).
- [Manual de inicializacao local no Windows com XAMPP](manual-inicializacao-local-windows-xampp.md) — *historico/descontinuado para desenvolvimento*.

## Escopo desta pasta

- subida local de backend, desktop, mobile, chat e servicos auxiliares;
- deploy completo de producao em servidor Ubuntu (pacotes, banco, TLS, Nginx, Supervisor, cron, storage);
- verificacoes operacionais antes de abrir o sistema no navegador;
- referencias de deploy e runtime que precisem ficar separadas da arquitetura.

## Ordem recomendada para um deploy novo

1. Ler o [runbook de deploy Ubuntu](deploy-producao-lan-ubuntu.md) por inteiro antes de comecar.
2. Conferir a secao "Tabela de problemas × solucoes" — quase todos os erros de primeira execucao ja estao mapeados la.
3. Executar as fases na ordem do runbook (SSH → pacotes → codigo → banco → .env → permissoes → TLS → Nginx → Supervisor/cron → storage → desktop).
4. Fechar com o "Checklist de verificacao pos-deploy".

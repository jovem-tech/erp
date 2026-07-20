# Deploy e Operacao

Este diretorio concentra os guias operacionais para subir, validar e publicar o `sistema-erp`.

## Guias disponiveis

- [Runbook de producao na VPS Contabo (subdominios + dados reais)](deploy-producao-contabo-vps.md) — **producao atual** (`erp.` desktop + `api-erp.` backend), em paralelo ao legado.
- [Deploy de producao em Ubuntu Server (LAN ou VPS)](deploy-producao-lan-ubuntu.md) — fundamentos e tabela geral de problemas do primeiro deploy (2026-07-03/04).
- [Fluxo Git Multiambiente](workflow-git-multiambiente.md) — como o codigo viaja de `develop` ate a VPS (`main`), scripts de versionamento e promocao.
- [Manual de Publicacao — Versionar e Deploy](manual-versionamento-e-deploy.md) — passo a passo, pensado para ser seguido sem IA, dos 3 scripts (`versionar.sh`, `deploy-completo.sh`, `deploy-producao.sh`).
- [Operação do Gerenciador Central de Arquivos](operacao-gerenciador-central-arquivos.md) — flags, sincronização, miniaturas, diagnóstico, incidentes e rollback.
- [Manual de inicializacao local no Windows com XAMPP](manual-inicializacao-local-windows-xampp.md) — *historico/descontinuado para desenvolvimento*.

## Escopo desta pasta

- subida local de backend, desktop, mobile, chat e servicos auxiliares;
- deploy completo de producao em servidor Ubuntu (pacotes, banco, TLS, Nginx, Supervisor, cron, storage);
- verificacoes operacionais antes de abrir o sistema no navegador;
- referencias de deploy e runtime que precisem ficar separadas da arquitetura.

## Ordem recomendada para um deploy novo

1. Para a release `5.1`, ler o [consolidado de 20/07/2026](../07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md), o [runbook do Gerenciador de Arquivos](operacao-gerenciador-central-arquivos.md) e o [consolidado anterior](../07-novas-implementacoes/2026-07-19-consolidado-implementacoes-18-19-julho.md).
2. Ler o [runbook de deploy Ubuntu](deploy-producao-lan-ubuntu.md) por inteiro antes de comecar.
3. Conferir a secao "Tabela de problemas × solucoes" — quase todos os erros de primeira execucao ja estao mapeados la.
4. Executar as fases na ordem do runbook (SSH → pacotes → codigo → banco → .env → permissoes → TLS → Nginx → Supervisor/cron → storage → desktop).
5. Fechar com o "Checklist de verificacao pos-deploy" e os smoke tests documentais/financeiros da release.

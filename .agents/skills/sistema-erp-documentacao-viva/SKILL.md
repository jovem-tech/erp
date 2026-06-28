---
name: sistema-erp-documentacao-viva
description: Automacao e manutencao da documentacao viva do Sistema ERP. Use quando um agente de IA precisar sincronizar manifests, contexto estruturado, `AGENTS.md`, notas versionadas ou o processo documental que deixa o escopo do sistema legivel para outras IAs, sempre com producao alvo em Ubuntu VPS.
---

# Sistema ERP Documentacao Viva

## Quick start

1. Ler `references/automacao-e-versionamento.md`.
2. Executar `php scripts/php/sync-agent-docs.php` quando houver mudanca estrutural ou de contexto.
3. Criar nota versionada quando a entrega precisar entrar no historico de releases.

## Regras

1. O contexto vivo do projeto deve ficar dentro do proprio repositorio.
2. Artefatos gerados precisam ser reproduziveis via script.
3. Toda automacao deve rodar tanto no Windows local quanto no Ubuntu VPS.
4. O historico versionado nao substitui a documentacao tecnica detalhada; ele a complementa.

## Saida esperada

- manifests sincronizados;
- JSON estruturado atualizado;
- nota versionada criada quando necessario;
- agentes futuros conseguem reconstruir o escopo sem depender de memoria externa.

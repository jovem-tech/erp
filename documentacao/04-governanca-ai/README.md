# Governança para Agentes

Este diretório centraliza o contexto operacional para agentes de IA que trabalham no `sistema-erp`.

## Objetivos

- deixar o escopo do sistema legível para qualquer agente sem adivinhação;
- padronizar a leitura da arquitetura, dos limites de segurança e do fluxo orientado a especificações;
- manter documentação viva e versionada dentro do próprio repositório;
- reforçar que a produção oficial roda em `Ubuntu VPS`, com `Windows/XAMPP` restrito ao desenvolvimento local.

## Arquivos principais

- `operacao-para-agentes.md`: guia humano e operacional do projeto para agentes.
- `manifesto-do-sistema.md`: snapshot gerado automaticamente com escopo, módulos, specs e fontes de verdade.
- `contexto-sistema.json`: equivalente estruturado em JSON para automações, ingestão e validações.
- `playbooks/`: incidentes reais, diagnósticos e correções reutilizáveis para futuras IAs.

## Automação

Sincronizar os artefatos gerados:

- PowerShell: `./scripts/powershell/sync-agent-docs.ps1`
- Bash: `./scripts/bash/sync-agent-docs.sh`
- PHP: `php ./scripts/php/sync-agent-docs.php`

Criar uma nova nota versionada:

- `php ./scripts/php/scaffold-release-note.php --version=3.1.9 --slug=assunto-da-entrega --title="Titulo da entrega"`

## Leitura recomendada

1. `../../AGENTS.md`
2. `operacao-para-agentes.md`
3. `manifesto-do-sistema.md`
4. `../README.md`
5. `../../.specify/memory/constitution.md`

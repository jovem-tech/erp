# Automacao e Versionamento

## Comandos principais

- sincronizar contexto vivo: `php scripts/php/sync-agent-docs.php`
- wrapper PowerShell: `./scripts/powershell/sync-agent-docs.ps1`
- wrapper Bash: `./scripts/bash/sync-agent-docs.sh`
- criar nota versionada: `php scripts/php/scaffold-release-note.php --version=3.1.9 --slug=assunto --title="Titulo"`

## Arquivos gerados

- `documentacao/04-governanca-ai/manifesto-do-sistema.md`
- `documentacao/04-governanca-ai/contexto-sistema.json`

## Quando usar

- depois de mudancas estruturais;
- quando `AGENTS.md`, `specs/`, `documentacao/` ou fontes de verdade mudarem;
- ao preparar entregas que precisam de historico versionado.

# AGENTS.md - Sistema ERP

## Escopo do repositorio

Este repositorio contem a nova plataforma modular `sistema-erp`, com:

- `backend/` como backend central Laravel e fonte unica de verdade de negocio, autenticacao, autorizacao, storage privado e contratos de API.
- `frontends/desktop/` como frontend desktop Laravel/Blade com sessao server-side e consumo exclusivo da API central.
- `frontends/mobile/` como frontend mobile Next.js consumindo a mesma API central.
- `documentacao/` como base oficial de arquitetura, operacao, implantacao e historico de implementacoes.
- `specs/` e `.specify/` como trilha oficial de desenvolvimento orientado a especificacoes.

## Ambiente oficial

- Producao oficial: `Ubuntu VPS`
- Desenvolvimento local de referencia: `Windows/XAMPP`

Toda mudanca deve ser segura para Linux:

- respeitar case-sensitive em nomes de arquivos e classes;
- evitar caminhos hardcoded de Windows fora de documentacao de desenvolvimento ou integracoes legadas explicitamente isoladas;
- assumir separador `/` e filesystem POSIX ao pensar em deploy e automacao;
- publicar apenas diretorios publicos aprovados (`backend/public` e publicos especificos dos frontends).

## Fontes de verdade obrigatorias

Leia sempre nesta ordem quando precisar de contexto amplo:

1. `documentacao/04-governanca-ai/operacao-para-agentes.md`
2. `documentacao/04-governanca-ai/manifesto-do-sistema.md`
3. `.specify/memory/constitution.md`
4. `README.md`
5. `documentacao/README.md`
6. `backend/openapi.yaml`
7. `specs/<feature>/spec.md`, `plan.md` e `tasks.md` da feature ativa

## Guardrails arquiteturais

1. O `backend/` e a unica fonte de verdade para regra de negocio, autenticacao, autorizacao, storage privado e contratos entre canais.
2. Nenhum frontend pode acessar banco diretamente para regra nova ou modulo migrado.
3. Arquivos operacionais devem permanecer em storage privado e sair apenas por endpoint autenticado.
4. Toda alteracao que mude payload, rota, permissao, fluxo operacional, banco, deploy ou comportamento visual deve atualizar a documentacao correspondente.
5. Toda implementacao deve considerar seguranca, integridade, escalabilidade, rastreabilidade e paridade entre ambiente local e Ubuntu VPS.

## Fluxo oficial de entrega

O fluxo padrao do projeto e:

`spec -> plan -> tasks -> implementacao -> validacao -> documentacao -> versionamento`

Regras:

- novas features e mudancas estruturais devem nascer em `specs/`;
- a definicao de pronto inclui codigo, testes, documentacao e nota versionada quando houver impacto de release;
- qualquer agente deve registrar as fontes que consultou e os arquivos documentados atualizados.

## Skills locais do projeto

As skills versionadas do proprio ERP ficam em `.agents/skills/`:

- `$sistema-erp-governanca`: arquitetura, limites, ambiente oficial e guardrails do sistema;
- `$sistema-erp-entrega-especificada`: fluxo de entrega orientado a especificacoes;
- `$sistema-erp-documentacao-viva`: sincronizacao de manifests, AGENTS, historico e artefatos documentais gerados;
- `$sistema-erp-auditoria-independente`: processo de verificacao para auditorias e para qualquer alegacao de "corrigido"/"concluido" — nunca aceitar sem checar contra o codigo real.

## Automacao documental e versionamento

Sincronizar contexto vivo:

- PowerShell: `./scripts/powershell/sync-agent-docs.ps1`
- Bash: `./scripts/bash/sync-agent-docs.sh`
- PHP direto: `php ./scripts/php/sync-agent-docs.php`

Criar nota versionada:

- `php ./scripts/php/scaffold-release-note.php --version=3.1.9 --slug=assunto-da-entrega --title="Titulo da entrega"`

Arquivos gerados pela automacao:

- `documentacao/04-governanca-ai/manifesto-do-sistema.md`
- `documentacao/04-governanca-ai/contexto-sistema.json`

## Validacoes minimas esperadas

- ambiente local ou de producao coerente com a mudanca;
- testes do modulo alterado;
- revisao de impacto em `backend/openapi.yaml` e `documentacao/`;
- sincronizacao do contexto vivo ao concluir mudancas estruturais.

<!-- SPECKIT START -->
Features concluidas mais recentes:
`specs/009-paridade-painel-os-desktop/plan.md` (paridade visual do painel
de OS) e `specs/011-acoes-edicao-baixa-os-desktop/plan.md` (dropdown de
acoes no padrao de equipamentos, edicao completa de OS e baixa MVP com
status final + financeiro + WhatsApp manual).
Entregue em 2026-07-02 (v3.5.0–3.5.1): sistema de temas visuais com 3
opções em `Configuracoes > Sistema > Aparencia`: Padrao (roxo #6f5afc),
Jovem Tech (azul institucional #3868B0, sidebar navy, fundo #F4F8FF) e
Escuro (roxo profundo #7C6EFA, sidebar #1A1035, fundo #0D1117); seleção
via cards visuais, preferência em sessão Laravel, CSS escopo-isolado por
[data-theme], sem migração de banco.
Feature `specs/010-inbox-whatsapp-tempo-real/` em andamento por outra
linha de trabalho — consultar o proprio plan.md dela para o estado atual
antes de presumir o que ja foi entregue.
<!-- SPECKIT END -->

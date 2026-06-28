# Operação para Agentes

## Identidade do sistema

O `sistema-erp` e uma plataforma modular para operacao de assistencia tecnica, atendimento, administracao e evolucao controlada do legado.

Arquitetura atual:

- `backend/`: backend central Laravel 13.x, API `v1`, RBAC, storage privado e contratos oficiais.
- `frontends/desktop/`: frontend desktop Laravel/Blade com sessao server-side.
- `frontends/mobile/`: frontend mobile Next.js consumindo a mesma API.
- `documentacao/`: fonte oficial de arquitetura, operacao, infraestrutura e releases.
- `specs/`: rastreabilidade de features orientadas a especificacoes.

## Premissas de ambiente

- producao oficial: `Ubuntu VPS`;
- proxy esperado: `Nginx`;
- deploy pensado para filesystem Linux e nomes case-sensitive;
- filas e scheduler devem ser planejados para `cron` e `Supervisor` ou equivalente;
- `Windows/XAMPP` serve apenas como base local de desenvolvimento e validacao.

## Decisões que nao podem ser quebradas

1. Nenhuma nova regra de negocio deve nascer fora do `backend/`.
2. Frontends nao podem acessar o banco diretamente para modulo novo ou migrado.
3. Sessao e token do desktop e do BFF permanecem server-side.
4. Storage operacional e privado por padrao.
5. Toda mudanca relevante precisa de documentacao sincronizada.
6. Toda automacao deve ser segura para Ubuntu VPS, mesmo quando criada no Windows.

## Fontes de verdade por assunto

- arquitetura global: `../00-visao-geral/arquitetura-alvo.md`
- ambiente e contratos operacionais: `../01-fundacao/` e `../02-infraestrutura-ambientes/`
- arquitetura tecnica e contratos: `../03-arquitetura-tecnica/`
- historico de implementacoes: `../07-novas-implementacoes/`
- versao exibida do sistema: `../../shared/version.php`
- contrato tecnico de API: `../../backend/openapi.yaml`
- constituicao do fluxo: `../../.specify/memory/constitution.md`

## Workflow padrao para qualquer feature

1. Ler `AGENTS.md`, a constituicao e a documentacao do dominio.
2. Confirmar se a mudanca ja possui `spec` ativa; se nao tiver, abrir trilha em `specs/`.
3. Implementar respeitando os limites entre backend central, frontends e BFF.
4. Validar impacto em API, deploy Ubuntu VPS, seguranca, storage, logs e responsividade.
5. Atualizar a documentacao afetada.
6. Sincronizar os artefatos gerados com `sync-agent-docs`.
7. Registrar nota versionada quando houver entrega relevante.

## Definição de pronto

Uma entrega so esta pronta quando:

- o codigo esta coerente com a arquitetura;
- a documentacao tecnica e operacional foi atualizada;
- a automacao documental foi sincronizada;
- existe rastreabilidade em `specs/` quando o tipo de mudanca exige;
- o impacto em producao Ubuntu VPS foi considerado explicitamente.

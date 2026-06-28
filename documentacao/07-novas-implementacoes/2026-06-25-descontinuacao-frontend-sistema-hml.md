# Descontinuação do frontend sistema-hml

## Contexto

- versao: `3.1.16`
- data: `2026-06-25`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

Decisão do responsável pelo sistema: o `frontend/sistema-hml/` (clone do
legado evoluindo como BFF, conforme `specs/008-governanca-bff-sistema-hml/`)
não será mais implementado. A motivação imediata foi a auditoria completa de
2026-06-25 (`2026-06-25-auditoria-completa-e-correcoes-de-seguranca.md`), que
encontrou nesse app, entre outros problemas:

- 5 scripts administrativos/debug sem autenticação dentro do próprio
  `public/`, diretamente executáveis via HTTP (incluindo um que resetava a
  senha do admin para um valor fixo);
- uma API paralela completa (`app/Controllers/Api/V1/*`) duplicando o
  backend central, violando a regra de "nunca backend paralelo";
- migração real muito menor do que a documentação sugeria — só
  Auth/Dashboard/Notificações tinham de fato migrado para a API central,
  o resto continuava acessando banco direto via ~105 models locais.

Em vez de continuar investindo na migração desse app, a decisão foi
descontinuá-lo por completo.

### O que foi feito

- todo o conteúdo de `frontend/sistema-hml/` (incluindo os 18 scripts que
  haviam sido isolados em quarentena na Fase 0 da auditoria) foi copiado para
  `_arquivo-sistema-hml-removido-2026-06-25/`, **fora** de `sistema-erp/`
  (em `C:\xampp\htdocs\`), preservando o conteúdo para consulta futura sem
  deixá-lo no projeto ativo;
- o diretório `sistema-erp/frontend/` foi esvaziado;
- removidas as referências ativas ao `sistema-hml` como parte da arquitetura
  em `AGENTS.md`, `documentacao/04-governanca-ai/operacao-para-agentes.md`,
  `README.md`, `.agents/skills/sistema-erp-governanca/` e no script
  `scripts/php/sync-agent-docs.php` (que gera o manifesto automático);
- a documentação histórica específica sobre o BFF sistema-hml (PRD, mapa de
  migração, notas de implementação datadas, `specs/008-governanca-bff-sistema-hml/`)
  foi **mantida** como registro do que foi decidido e tentado, sem edição —
  ela não aparece mais nos links "ativos" do `README.md`, mas continua
  disponível para quem precisar entender o histórico.

### Pendência conhecida

Um processo de desenvolvimento (`php -S 127.0.0.1:8081 -t public ...`,
servindo o sistema-hml) estava ativo desde 2026-06-24 e mantinha arquivos de
log abertos, impedindo a remoção completa do diretório original em
`sistema-erp/frontend/sistema-hml/` (ficaram apenas `public/` vazio e dois
arquivos de log). A cópia para o arquivo externo foi concluída com sucesso
antes disso, então nenhum dado foi perdido. A limpeza final desse resíduo
depende de encerrar esse processo.

## Impactos

- Nenhum contrato de API do backend central é afetado — o sistema-hml nunca
  era consumido pelo backend, apenas o contrário.
- `database.default.database=sistema_erp` (configuração própria do
  sistema-hml, banco distinto do `sistema_hml` usado pelo `backend/`) não foi
  alterado nem apagado; só os arquivos de aplicação foram arquivados.
- Reduz a superfície de ataque do projeto: elimina os scripts sem
  autenticação e a API paralela que violava a arquitetura de fonte única de
  verdade.

## Validacao

- Confirmado `du -sh` antes/depois: cópia em
  `_arquivo-sistema-hml-removido-2026-06-25/sistema-hml` com 44M, igual ao
  tamanho original.
- Confirmado que `sistema-erp/frontend/` não referencia mais nenhum app ativo.
- Varredura por `sistema-hml` em `AGENTS.md`, `README.md`,
  `documentacao/04-governanca-ai/`, `.agents/skills/` e `infra/` para
  garantir que nenhuma referência ativa restou fora dos documentos
  históricos.

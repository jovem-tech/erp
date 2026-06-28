# Arquitetura e Escopo

## Leitura obrigatoria

1. `AGENTS.md`
2. `documentacao/04-governanca-ai/operacao-para-agentes.md`
3. `documentacao/00-visao-geral/arquitetura-alvo.md`
4. `documentacao/03-arquitetura-tecnica/README.md`
5. `backend/openapi.yaml`

## Modelo arquitetural

- `backend/` e o backend central Laravel e fonte unica de verdade.
- `frontends/desktop/` e `frontends/mobile/` consomem somente a API central.
- `documentacao/` e `specs/` sao artefatos de primeira classe, nao anexos opcionais.
- o antigo `frontend/sistema-hml/` foi descontinuado e arquivado fora do projeto em 2026-06-25; nao referenciar como parte da arquitetura ativa.

## Fronteiras que nao podem ser quebradas

- nao criar backend paralelo;
- nao mover regra de negocio sensivel para frontends;
- nao expor storage privado por atalho de filesystem;
- nao considerar Windows como ambiente de producao.

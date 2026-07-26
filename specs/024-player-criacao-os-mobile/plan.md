# Plano: player de criação da OS mobile

## Arquitetura

O `AuthenticatedShell` continua proprietário da área fixa inferior. Um provider
local ao shell recebe do `OrderFormWizard` um controlador mínimo, formado
somente por estados derivados e callbacks de navegação/salvamento. O shell
seleciona o player apenas quando `usePathname()` retorna exatamente
`/os/novo`.

As regras de completude ficam centralizadas em `wizard-state.ts` e são
reutilizadas tanto pelas etapas quanto pelo player. O backend permanece como
autoridade final do payload e das permissões.

## Decisões

- usar Context em vez de eventos globais ou acesso imperativo ao DOM;
- separar o contexto de registro do contexto do controlador para evitar
  renderizar novamente o formulário a cada atualização do player;
- manter `Salvar` visível e desabilitado para tornar a pendência previsível;
- confirmar descarte somente quando o estado contém dados do operador;
- manter o mecanismo existente de `idempotency_key` e acrescentar uma trava
  síncrona no cliente contra cliques concorrentes.

## Segurança e integridade

- nenhuma regra de autorização foi movida para o frontend;
- a validação do player é UX, não substitui a validação da API;
- submissões repetidas são bloqueadas no cliente e deduplicadas no backend;
- saídas acidentais são protegidas por confirmação e `beforeunload`;
- não há HTML dinâmico, nova origem de rede nem exposição adicional do token.

## Performance e escalabilidade

O controlador contém apenas booleanos e callbacks. Contextos separados evitam
propagar cada alteração do formulário por todo o shell. Não há consulta,
polling, estado distribuído, cache ou alteração de carga no backend.

## Validação

- testes unitários de completude e detecção de dados preenchidos;
- testes do player, callbacks e confirmação de descarte;
- teste de escopo exato entre criação e edição;
- suíte Vitest, ESLint e build Next.js;
- inspeção visual em viewport `390 x 844`.

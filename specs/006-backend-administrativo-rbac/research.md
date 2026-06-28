# Research: Backend administrativo e RBAC central

## Decisão 1 - Reutilizar o RBAC legado

- **Decisão**: usar `usuarios.grupo_id`, `grupos`, `modulos`, `permissoes` e `grupo_permissoes` como fonte de verdade do acesso.
- **Racional**: evita duplicar cadastros, reduz risco de divergência entre legado e nova plataforma e acelera o consumo pelo desktop.
- **Alternativas consideradas**:
  - criar um RBAC novo em tabelas paralelas no Laravel;
  - mapear permissões fixas apenas em código.

## Decisão 2 - Gate central com cache curto

- **Decisão**: resolver permissões efetivas por serviço central e registrar `Gate::define()` para cada combinação `módulo:ação`.
- **Racional**: mantém o controller fino, padroniza a autorização entre canais e reduz consultas repetidas com cache de 5 minutos.
- **Alternativas consideradas**:
  - autorização inline em cada controller;
  - policies por recurso com matriz duplicada.

## Decisão 3 - Fallback legado `perfil = admin`

- **Decisão**: manter fallback apenas para usuários sem `grupo_id`, registrando warning.
- **Racional**: garante transição segura sem quebrar contas antigas, mas deixa visível a dívida técnica a ser removida.
- **Alternativas consideradas**:
  - remover o fallback imediatamente;
  - manter o fallback para qualquer usuário com `perfil = admin`, mesmo com grupo.

## Decisão 4 - Política de migrations

- **Decisão**: não criar migrations para tabelas legadas nesta fase.
- **Racional**: o banco já existe e a fase é de integração controlada, não de remodelagem do legado.
- **Alternativas consideradas**:
  - recriar todas as tabelas legadas em migrations Laravel;
  - manter models sem inspeção do schema real.

## Decisão 5 - Ordem administrativa dentro do mesmo contrato `/api/v1/orders`

- **Decisão**: preservar os endpoints do mobile e ampliar o mesmo recurso com create, update e filtros administrativos.
- **Racional**: mantém o contrato simples, versionado e compartilhável entre canais.
- **Alternativas consideradas**:
  - criar um namespace `/api/v1/admin/orders`;
  - duplicar recursos para mobile e desktop.

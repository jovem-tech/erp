# Research: Fluxo de OS Mobile

## Decisão 1: validar status em runtime

- **Decision**: a lista de status aceitos vem do catálogo `os_status` no momento da requisição.
- **Rationale**: evita hardcode e permite evolução do catálogo sem novo deploy para liberar um código já cadastrado.
- **Alternatives considered**: lista fixa no código; enum no backend; validação dependente de configuração.

## Decisão 2: atualizar `estado_fluxo` junto com `status`

- **Decision**: toda alteração de status deve atualizar `estado_fluxo` com base no campo `estado_fluxo_padrao` do catálogo.
- **Rationale**: o legado usa os dois campos como parte do mesmo estado operacional.
- **Alternatives considered**: atualizar apenas `status`; calcular `estado_fluxo` no frontend; derivar `estado_fluxo` somente em relatórios.

## Decisão 3: não validar transições nesta fase

- **Decision**: a fase valida apenas se o código existe no catálogo e não restringe a mudança por grafo de transições.
- **Rationale**: evita travar o técnico com regra incompleta e reduz risco operacional durante a migração.
- **Alternatives considered**: aplicar `os_status_transicoes` agora; bloquear transições fora do catálogo; permitir somente avanço linear.

## Decisão 4: retorno 403 para OS de outro técnico

- **Decision**: quando a OS existe, mas não pertence ao técnico autenticado, a API responde `403`.
- **Rationale**: o recurso existe e o bloqueio precisa ser explícito para o cliente e para auditoria.
- **Alternatives considered**: `404` para ocultar existência; `422` para conflito de validação; `401` para sessão ausente.

## Decisão 5: detalhe com histórico curto

- **Decision**: o endpoint de detalhe da OS retorna cliente, equipamento, os últimos 5 registros de histórico e os anexos controlados.
- **Rationale**: o técnico recebe contexto suficiente em uma única chamada sem sobrecarregar a resposta.
- **Alternatives considered**: retornar o histórico completo; buscar cada relacionamento em chamadas separadas; manter o detalhe somente para o desktop.

## Decisão 6: anexos por endpoint controlado

- **Decision**: fotos e PDFs não trafegam como bytes dentro do JSON; o backend entrega apenas URLs/endereços de acesso controlado.
- **Rationale**: reduz o peso da resposta e mantém o acesso aos arquivos sob controle de autorização.
- **Alternatives considered**: embutir base64 no JSON; expor `public/uploads`; entregar arquivos direto pelo frontend.

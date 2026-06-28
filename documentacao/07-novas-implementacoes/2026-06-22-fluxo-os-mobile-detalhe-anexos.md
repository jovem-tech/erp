# Fluxo de OS mobile: detalhe da OS e anexos controlados

**Data**: 22/06/2026

## Resumo

O backend Laravel do `sistema-erp` passou a expor o detalhe completo da OS para o mobile, incluindo cliente, equipamento, histórico recente e anexos vinculados por endpoint controlado.

## O que mudou

- criado `GET /api/v1/orders/{id}` com eager loading de cliente, equipamento, histórico recente, fotos e documentos;
- histórico retornado no detalhe limitado aos últimos 5 registros;
- criado acesso controlado para fotos e PDFs via:
  - `GET /api/v1/orders/{id}/photos/{photo}`
  - `GET /api/v1/orders/{id}/documents/{document}`
- fotos e documentos não são mais enviados como bytes no JSON;
- arquivos do legado são resolvidos por raiz configurável, sem expor o caminho físico ao frontend;
- o retorno `403` continua sendo usado quando a OS existe, mas não pertence ao técnico autenticado.

## Impacto

- o PWA mobile agora consegue abrir o detalhe da OS com contexto suficiente para atendimento em campo;
- o frontend passa a depender apenas do contrato da API, e não da estrutura pública do legado;
- a camada de arquivos fica mais segura e mais fácil de evoluir para outros canais no futuro.

## Validação

- `php artisan test --filter=OrderFlowTest`
- smoke do detalhe da OS com usuário técnico autenticado
- smoke dos endpoints de foto e documento com autorização válida


# Paridade operacional do painel de Ordens de Serviço no desktop

## Contexto

O painel de Ordens de Serviço do legado (`sistema-hml`) era o principal
gerenciador operacional da assistência: foto do equipamento, cliente com
WhatsApp, datas com cor de atraso, status do orçamento vinculado e
breakdown financeiro (Total/Recebido/Saldo) na própria listagem. A
listagem equivalente do `sistema-erp` (`/orders`) só mostrava OS, cliente,
equipamento (resumo técnico bruto), status, prioridade e previsão.

Esta entrega porta o comportamento útil do legado para a arquitetura do
`sistema-erp` (backend Laravel como fonte única, desktop Blade só consome
a API), evitando os problemas do legado (consultas por linha, lógica de
formatação espalhada, modais com iframe). Ver
`specs/009-paridade-painel-os-desktop/`.

## O que foi entregue

- `GET /api/v1/orders` agora retorna, por OS: foto principal do
  equipamento (URL autenticada), telefone do cliente, resumo curto do
  equipamento (tipo + marca + modelo), `data_entrada`/`data_conclusao`/`data_entrega`,
  um objeto `prazo` calculado (estado de atraso + dias), o orçamento mais
  recente vinculado (status/cor/número) e o breakdown financeiro
  (`valor_mao_obra`, `valor_pecas`, `desconto`, `valor_final`,
  `valor_recebido`, `saldo`).
- Orçamento e financeiro são resolvidos em lote por página
  (`OrderWorkflowService::resolveLatestBudgetByOrderId` e
  `resolveReceivableSummaryByOrderId`), sem aumentar o número de consultas
  conforme a quantidade de OS exibidas — cobertura de teste dedicada
  confirma isso (`OrderFlowTest::test_index_query_count_does_not_grow_with_number_of_orders_on_page`).
- Novos filtros em `GET /api/v1/orders`: `grupo_macro`, `data_abertura_de`,
  `data_abertura_ate`, `valor_min`, `valor_max` (o filtro `technician_id`
  já existia no backend e passou a ser exposto no desktop).
- Frontend desktop (`orders/index.blade.php`): colunas de Foto/OS,
  Cliente (com link de WhatsApp), Equipamento (resumo curto com tooltip
  do resumo técnico completo), Datas (com cor de prazo via
  `status-pill`), Status/Orçamento e Valor (Total/Recebido/Saldo).
  Filtros de técnico, macrofase, intervalo de datas e intervalo de valor
  agrupados em um bloco "Filtros avançados" colapsável, expandido
  automaticamente quando algum desses filtros já está em uso.

## Correção relacionada: fallback de foto legada do equipamento

Ao validar a coluna de foto no navegador, equipamentos importados do legado
apareciam com o placeholder de câmera em vez da foto real
(`GET /api/v1/equipments/{id}/photos/{photo}` retornando 404). O registro em
`equipamentos_fotos` existe, mas o arquivo físico nunca foi copiado do
`sistema-hml` para o storage privado novo — `EquipmentWorkflowService::resolvePhotoAccess()`
só procurava em `Storage::disk('local')`, sem o mesmo fallback que
`OrderWorkflowService` já tinha para fotos/documentos de OS. Esse gap é do
módulo de cadastro de equipamentos (`specs/008-cadastro-equipamentos-desktop`),
não desta feature, mas foi corrigido aqui porque afeta diretamente a coluna
de foto recém-criada: `resolvePhotoAccess()` agora cai para
`sistema-hml/public/uploads/equipamentos_perfil/{arquivo}` quando o arquivo
não existe no storage novo, no mesmo padrão já usado para OS.

## Observações técnicas

- Nenhuma migration nova foi necessária: todos os dados já existiam nas
  tabelas `os`, `clientes`, `equipamentos`, `equipamentos_fotos`,
  `orcamentos`, `financeiro` e `financeiro_movimentos` (os dois últimos
  do módulo financeiro entregue em paralelo).
- A foto do equipamento reaproveita a relação e a rota já existentes do
  módulo de cadastro de equipamentos (`specs/008-cadastro-equipamentos-desktop`).
- O filtro de técnico e o catálogo de status no desktop degradam com
  segurança: se o usuário autenticado não tiver permissão para
  `usuarios:visualizar` ou `conhecimento:visualizar`, a listagem de OS
  continua funcionando normalmente, só sem essas opções no filtro.
- Responsividade reaproveita o sistema já existente (`table-stack` +
  `data-label` para a tabela, `desktop-filter-grid` para os filtros),
  sem novos breakpoints.
- Cobertura de testes em `backend/tests/Feature/Api/V1/OrderFlowTest.php`
  (novos campos presentes, estado neutro sem foto/orçamento/financeiro,
  filtros novos, ausência de N+1).

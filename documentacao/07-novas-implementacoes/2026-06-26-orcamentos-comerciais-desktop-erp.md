# Orçamentos comerciais no desktop ERP

## Contexto

O módulo de orçamentos do legado passou a existir também no `sistema-erp`, com backend central em Laravel e frontend desktop em Blade, sem acesso direto ao banco de dados.

## O que foi entregue

- `GET /api/v1/orcamentos` com listagem paginada, filtros e resumo operacional.
- `GET /api/v1/orcamentos/form-data` com clientes, equipamentos, OS, serviços e peças para o formulário.
- `GET /api/v1/orcamentos/{id}` com detalhe completo do orçamento.
- `POST /api/v1/orcamentos`, `PUT/PATCH /api/v1/orcamentos/{id}` e `DELETE /api/v1/orcamentos/{id}` com sincronização dos itens e recalculo financeiro no backend.
- frontend desktop com:
  - listagem comercial no padrão do legado;
  - formulário com abas `Dados do cliente`, `Dados do equipamento`, `Dados operacionais`, `Pacotes de serviço` e `Orçamento e financeiro`;
  - detalhe com cards, tabela de itens, histórico, envios e aprovações;
  - ajuda local dedicada do módulo.
- cada item do formulário passou a usar um layout hierárquico em linhas, com `Tipo + Referência + Descrição` no topo, bloco financeiro (`Qtd`, `Valor unit.`, `Desconto`, `Acréscimo`, `Total`, `Ações`) no centro e `Observações` em largura total, melhorando leitura e conferência sem mudar o contrato do orçamento.
- os breakpoints do grid dos itens foram recalibrados para preservar essa organização em desktop com zoom padrão, evitando empilhamento precoce dos campos financeiros.

## Observações técnicas

- O desktop consome a API central por `OrcamentoService`, sem `Http::` nos controllers.
- O formulário usa catálogo carregado do backend e rascunho local para evitar perda de dados durante o preenchimento.
- A documentação do contrato e do versionamento foi atualizada junto com a entrega.

# 2026-06-26 - Fornecedores operacionais no backend central e no desktop

## Resumo

O modulo de fornecedores deixou de ser um placeholder do desktop e passou a existir de forma operacional no `backend/` e no `frontends/desktop/`, mantendo a regra principal do projeto: o desktop consome a API central e nunca acessa o banco diretamente.

## O que entrou

- backend central com o modelo `Supplier` apontando para a tabela legada `fornecedores`;
- API de fornecedores em `/api/v1/suppliers` com:
  - listagem com busca textual e filtro por ativo;
  - cadastro;
  - detalhamento;
  - edicao completa;
  - encerramento operacional sem exclusao;
  - exclusao;
  - consulta de CNPJ com provedores publicos e envelope padrao da API;
- desktop Laravel com:
  - lista operacional de fornecedores;
  - tela de novo fornecedor;
  - tela de edicao;
  - acao de encerrar;
  - acao de excluir;
  - ajuda local do modulo;
  - auto-preenchimento de CNPJ via endpoint do backend central;
- integracao do modulo com a busca completa do desktop;
- atualizacao do menu `Pessoas` para tratar `Fornecedores` como parte real do fluxo comercial;
- versionamento central atualizado para `v3.1.20`.

## Pontos de seguranca e contrato

- o desktop continua sem acesso direto ao banco;
- a decisao final de permissao permanece no backend central;
- `fornecedores:visualizar`, `fornecedores:criar`, `fornecedores:editar`, `fornecedores:encerrar` e `fornecedores:excluir` continuam sendo validados na API;
- a consulta de CNPJ retorna apenas o payload necessario para preenchimento do formulario.

## Documentacao atualizada

- `backend/README.md`
- `backend/openapi.yaml`
- `frontends/desktop/README.md`
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`
- `documentacao/07-novas-implementacoes/historico-de-versoes.md`

## Validacao

- `php artisan test --filter SupplierFlowTest` no backend;
- `php artisan test --filter "test_supplier_|test_search_suggestions_returns_grouped_json_for_allowed_domains"` no desktop.

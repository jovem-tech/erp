# Select2 paginado de clientes no orçamento

Data: 24/07/2026

## Problema

O formulário de novo orçamento recebia no HTML apenas os 80 primeiros clientes
retornados por `BudgetWorkflowService::formData()`. O Select2 era inicializado
como uma lista local e, por isso, clientes fora desse recorte não apareciam nem
quando o operador digitava o nome.

## Solução

Foi criado um catálogo remoto específico para o contexto de orçamento:

- `GET /api/v1/orcamentos/clientes` no backend central;
- `GET /orcamentos/clientes/buscar` como proxy autenticado do desktop;
- Select2 com busca remota, páginas de 15 registros e carregamento incremental
  ao rolar a lista;
- abertura sem texto carrega a primeira página em ordem alfabética; novas
  páginas são solicitadas até o final do catálogo;
- pesquisa por nome, CPF/CNPJ, telefone principal ou e-mail;
- preservação da opção selecionada em edição, retorno de validação e rascunho
  local.

Os 80 registros já presentes no HTML continuam como fallback progressivo caso
o JavaScript não esteja disponível. Quando o JavaScript está ativo, a fonte
canônica do seletor passa a ser o endpoint paginado.

## Segurança

- o backend exige `orcamentos:visualizar` e também `orcamentos:criar` ou
  `orcamentos:editar`;
- a rota não concede acesso às telas do módulo de clientes;
- a resposta é minimizada para `id`, nome, telefone principal e e-mail;
- CPF/CNPJ pode ser usado para localizar o cliente, mas não é devolvido na
  resposta;
- `q` aceita no máximo 100 caracteres e `per_page` no máximo 20;
- parâmetros são validados e as consultas usam bindings do Eloquent, evitando
  SQL Injection;
- o Select2 usa o escape padrão de texto, sem templates HTML vindos da API,
  reduzindo risco de XSS.

## Performance e escalabilidade

- o navegador deixa de receber a base completa de clientes;
- cada requisição retorna no máximo 20 registros;
- o Select2 aplica debounce de 250 ms e cache do transporte;
- ordenação estável por nome e ID impede duplicação ou salto dentro da mesma
  fotografia de dados;
- a memória usada no desktop passa de O(total de clientes) para O(tamanho da
  página).

Para bases muito maiores, a busca contendo `%termo%` deverá evoluir para índice
FULLTEXT ou mecanismo dedicado de pesquisa. A paginação atual resolve o volume
de resposta, mas buscas parciais ainda podem exigir varredura no banco.

## Rascunhos

Além do ID, o rascunho local guarda o texto, nome, telefone e e-mail da opção
selecionada. Assim, um cliente encontrado em página remota continua visível
depois de recarregar e restaurar o rascunho, sem gravar qualquer dado antes do
salvamento efetivo do orçamento.

## Testes

- API: paginação com mais de 20 clientes, pesquisa, minimização da resposta e
  bloqueio para usuário apenas leitor;
- desktop: contrato do proxy, indicador `pagination.more`, URL no formulário e
  configuração do Select2 remoto;
- sintaxe: PHP e JavaScript validados antes da publicação no servidor de
  desenvolvimento.

## Arquivos principais

- `backend/app/Http/Controllers/Api/V1/BudgetController.php`
- `backend/app/Services/Budgets/BudgetWorkflowService.php`
- `backend/routes/api.php`
- `backend/openapi.yaml`
- `frontends/desktop/app/Http/Controllers/OrcamentoController.php`
- `frontends/desktop/app/Services/OrcamentoService.php`
- `frontends/desktop/public/assets/js/orcamentos-form.js`
- `frontends/desktop/resources/views/orcamentos/create.blade.php`
- `frontends/desktop/resources/views/orcamentos/edit.blade.php`
- `frontends/desktop/resources/views/orcamentos/form.blade.php`
- `frontends/desktop/routes/web.php`

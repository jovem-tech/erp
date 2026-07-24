# Hardening do vínculo de orçamento avulso com Ordem de Serviço

**Data:** 23/07/2026  
**Versão do sistema:** 5.11.0.0  
**Versão da API:** 1.6.0  
**Escopo:** backend central e frontend desktop no ambiente de desenvolvimento `192.168.1.100`

## Objetivo

O campo **Vincular orçamento avulso aprovado** da tela Nova OS deixou de ser
apenas um atalho de interface e passou a representar uma conversão de domínio
protegida. O backend central é a autoridade final e garante que uma aprovação
comercial válida gere no máximo uma Ordem de Serviço.

A criação comum de OS, sem orçamento, permanece inalterada.

## Arquitetura adotada

O fluxo possui quatro controles complementares:

1. O desktop só exibe o seletor para quem possui simultaneamente
   `os:criar` e `orcamentos:converter_os`.
2. O catálogo dedicado
   `GET /api/v1/orcamentos/vinculaveis-os` retorna somente candidatos aptos e
   usa paginação limitada.
3. O `POST /api/v1/orders` repete a autorização e não confia no estado exibido
   anteriormente no navegador.
4. Na transação de criação da OS, o orçamento é relido com
   `SELECT ... FOR UPDATE`, validado e convertido antes do commit.

O endpoint de detalhe
`GET /api/v1/orcamentos/vinculaveis-os/{budget}` fornece apenas o contexto
necessário para pré-preencher a Nova OS. Ele não substitui a validação
transacional executada no salvamento. Para clientes já cadastrados, esse
payload contém somente identificador e nome; CPF/CNPJ, telefone e e-mail do
cadastro não são duplicados nessa resposta. Para equipamentos cadastrados, o
detalhe retorna somente o identificador, sem número de série ou IMEI.

## Regras de negócio

Um orçamento pode ser convertido somente quando todas as condições forem
verdadeiras:

- tipo `previo`;
- status `pendente_abertura_os`, produzido pelo workflow formal de aprovação;
- `os_id` vazio;
- cliente informado na OS compatível com o orçamento;
- equipamento compatível, quando o orçamento já aponta para um equipamento
  cadastrado;
- usuário com as permissões `os:criar` e `orcamentos:converter_os`.

Após o vínculo:

- a OS é criada;
- o orçamento recebe o vínculo da OS e o status `convertido`;
- o orçamento convertido não pode mais ser editado ou excluído pelas rotas
  genéricas;
- uma segunda tentativa, inclusive com outra chave de idempotência, retorna
  conflito `409` e não cria outra OS.

## Vulnerabilidades e falhas corrigidas

### Broken Access Control e IDOR

Antes, possuir apenas a permissão de criação de OS era suficiente para enviar
um `orcamento_id`. Agora existe autorização específica no desktop e no backend.
Alterar manualmente a URL, o HTML ou o payload não contorna o controle.

### Condição de corrida e dupla conversão

Uma verificação feita antes da transação permitia o cenário TOCTOU: duas
requisições poderiam observar o mesmo orçamento ainda livre. A validação agora
ocorre sob bloqueio pessimista na mesma transação que cria a OS e converte o
orçamento.

### Inconsistência de cliente e equipamento

O backend rejeita vínculos em que cliente ou equipamento da OS sejam
incompatíveis com os registros cadastrados no orçamento. A validação do
navegador é apenas conveniência de uso.

### Forja de estados e metadados internos

As rotas genéricas de orçamento não aceitam os estados
`pendente_abertura_os` e `convertido`, nem campos internos de autoria, token
público, datas de aprovação/rejeição/cancelamento ou dados de conversão. Esses
valores pertencem aos workflows específicos.

### Alteração de snapshot convertido

Orçamentos convertidos são snapshots financeiros da OS. Atualização e exclusão
genéricas agora retornam `409`. Exclusão física ficou limitada a rascunhos,
rejeitados e cancelados.

### Falha na criação de OS sem garantia

O esquema canônico exige `garantia_dias` não nulo. A criação passa a persistir
zero quando o campo não é informado, eliminando o erro tardio que podia ocorrer
em determinadas aberturas de OS.

### Perda do PDF A4 quando o layout térmico falhava

O envio de um orçamento podia ser concluído, mas o registro do PDF no acervo da
OS era abandonado caso o template opcional de 80 mm estivesse indisponível.
Agora o PDF A4 canônico é persistido independentemente; a falha térmica continua
registrada nos logs e não apaga nem invalida o documento já emitido.

## Experiência de uso

O seletor usa Select2 com busca remota por número, cliente ou equipamento e
paginação de até 30 registros por requisição. O resultado mostra número do
orçamento, cliente, equipamento, valor e data de aprovação.

Não há mais carregamento antecipado de dezenas de orçamentos completos. Se o
formulário já foi alterado, trocar ou remover o orçamento pede confirmação para
evitar perda silenciosa dos dados digitados. Falhas de consulta são exibidas ao
usuário, em vez de ocultar o recurso silenciosamente.

## Segurança

- Consultas são parametrizadas pelo Eloquent.
- Busca textual é limitada a 120 caracteres.
- Paginação aceita no máximo 30 registros.
- Autorização é aplicada no backend central, não apenas na apresentação.
- Estados críticos são controlados por workflows dedicados.
- A transação preserva atomicidade entre cliente/equipamento diferidos, OS e
  conversão do orçamento.
- O detalhe vinculável não expõe CPF/CNPJ, contatos, número de série ou IMEI.
- Curingas `%` e `_` são tratados como texto na busca, evitando consultas
  involuntariamente amplas.
- As respostas distinguem `403`, `404`, `409` e `422`.
- O índice composto reduz tempo de retenção do lock e custo do catálogo.

## Performance e escalabilidade

O índice `idx_orcamentos_linkable_os` cobre o predicado canônico:

```text
(status, tipo_orcamento, os_id, aprovado_em, id)
```

O catálogo retorna uma projeção enxuta e carrega apenas relações necessárias.
A paginação limita memória e tráfego. Em uma evolução multi-tenant, o
identificador do tenant deverá ser a primeira coluna do índice e um predicado
obrigatório da consulta.

## Migration e permissões

A migration
`2026_07_23_000001_harden_budget_order_linking.php`:

- cria a permissão `converter_os`;
- registra o vínculo no módulo `orcamentos`;
- concede a nova permissão somente aos grupos que já possuíam, ao mesmo tempo,
  `orcamentos:visualizar` e `os:criar`;
- cria o índice de busca;
- limpa o cache RBAC.

Após a migration, o administrador deve revisar a matriz de permissões e remover
`orcamentos:converter_os` dos grupos que não podem executar conversões. Usuários
com sessão aberta podem precisar autenticar novamente caso a aplicação retenha
permissões na sessão.

## Contrato de API

### Buscar candidatos

```http
GET /api/v1/orcamentos/vinculaveis-os?q=cliente&page=1&per_page=15
Authorization: Bearer <token>
```

Permissões cumulativas: `orcamentos:converter_os` e `os:criar`. Assim, catálogo
e detalhe não expõem candidatos para usuários que não podem abrir uma Ordem de
Serviço.

### Carregar um candidato

```http
GET /api/v1/orcamentos/vinculaveis-os/123
Authorization: Bearer <token>
```

Retorna `404 BUDGET_NOT_LINKABLE` quando o orçamento não existe ou deixou de ser
um candidato.

### Criar OS vinculada

```http
POST /api/v1/orders
Authorization: Bearer <token>
Idempotency-Key: <uuid>
Content-Type: application/json

{
  "orcamento_id": 123,
  "cliente_id": 10,
  "equipamento_id": 20
}
```

Além dos erros comuns de criação:

- `403`: usuário sem autorização de conversão;
- `404 ORDER_BUDGET_LINK_NOT_FOUND`: orçamento inexistente;
- `409 ORDER_BUDGET_LINK_CONFLICT`: já convertido ou em estado incompatível;
- `422 ORDER_BUDGET_LINK_INVALID`: cliente/equipamento incompatível.

## Operação e implantação

O fluxo oficial permanece:

```text
192.168.1.100 → Git/develop → revisão → main → VPS
```

Não deve haver edição manual de código na VPS.

Passos de promoção:

1. Fazer backup do banco.
2. Publicar os arquivos versionados pelo fluxo Git.
3. Executar `php artisan migrate --force` no backend.
4. Limpar e reconstruir caches pelo script oficial de deploy.
5. Revisar a nova permissão na matriz de acesso.
6. Validar uma criação sem orçamento, uma conversão autorizada e uma tentativa
   sem permissão.

## Rollback

O rollback de código deve ser acompanhado do rollback da migration somente se a
versão anterior voltar a operar. O `down()` remove o índice e os vínculos da
nova permissão, sem apagar orçamentos ou Ordens de Serviço.

OS já criadas e orçamentos já convertidos não devem ser revertidos
automaticamente; qualquer correção desses registros exige procedimento
auditável de negócio.

## Testes

Foram adicionados testes de:

- autorização do catálogo e do `POST /orders`;
- filtragem canônica e paginação;
- cliente/equipamento incompatível;
- orçamento de tipo ou estado inválido;
- conversão repetida com chaves de idempotência diferentes;
- imutabilidade após conversão;
- proibição de estados e metadados internos;
- contrato Select2 e encaminhamento do vínculo pelo desktop;
- presença/ausência do seletor conforme permissões.
- minimização dos dados pessoais retornados pelo detalhe vinculável;
- persistência do PDF A4 mesmo sem template térmico;
- download público de PDF isolado do motor de geração.

Validação final no ambiente de desenvolvimento:

- backend: 44 testes aprovados, 273 asserções, nas suítes
  `BudgetAvulsoFlowTest` e `BudgetFlowTest`;
- desktop: 4 cenários críticos do vínculo aprovados, 17 asserções;
- PHP Pint aprovado nos arquivos PHP alterados;
- sintaxe JavaScript, OpenAPI e whitespace do diff validados.

## Trade-offs e melhorias futuras

- O bloqueio pessimista prioriza consistência sobre concorrência máxima; ele é
  curto e apoiado por índice.
- O grant inicial preserva compatibilidade para grupos que já reuniam as duas
  capacidades antigas, mas requer revisão administrativa pós-migration.
- Uma futura trilha de auditoria específica pode registrar tentativas negadas
  de conversão com correlação de requisição, sem armazenar dados sensíveis.
- Em volume muito alto, a busca textual pode evoluir para índice FULLTEXT ou
  mecanismo de pesquisa dedicado, preservando o filtro canônico no banco.

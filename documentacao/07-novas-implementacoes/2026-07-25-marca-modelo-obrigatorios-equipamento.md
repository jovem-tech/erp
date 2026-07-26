# Marca e modelo obrigatórios no cadastro de equipamento

Data: 25/07/2026

## Objetivo

Impedir a criação de equipamentos sem marca ou modelo, inclusive no cadastro
rápido aberto durante a criação de uma nova ordem de serviço.

## Implementação

- Os campos `Marca` e `Modelo` exibem o indicador de obrigatoriedade e usam a
  validação nativa `required`.
- O botão do formulário permanece como `Próximo` enquanto tipo, marca, modelo,
  cliente (quando aplicável) ou foto principal estiverem pendentes.
- O controller desktop valida os dois campos antes de encaminhar a requisição.
- A API exige `marca_id` e `modelo_id` tanto no cadastro direto quanto no
  equipamento criado de forma diferida junto com uma nova OS.
- O contrato OpenAPI foi atualizado para refletir a obrigatoriedade.

## Arquitetura e segurança

A validação foi aplicada em camadas:

1. interface, para retorno imediato ao usuário;
2. frontend desktop, para não realizar chamadas inválidas ao backend;
3. API, como fronteira autoritativa contra requisições diretas ou manipuladas.

Os identificadores continuam validados como inteiros positivos e como registros
existentes nos catálogos da API. A alteração não confia apenas no HTML, que pode
ser contornado pelo cliente.

## Banco de dados e compatibilidade

Não foi aplicada migração `NOT NULL` nas colunas legadas. Isso preserva a leitura
de equipamentos históricos que eventualmente estejam incompletos. Novos
cadastros e alterações feitas pelo fluxo atual, porém, só são aceitos com marca e
modelo preenchidos.

## Performance e escalabilidade

A mudança não adiciona consultas no frontend desktop. Na API são mantidas apenas
as validações de existência já executadas no fluxo de escrita; não há impacto em
listagens, paginação ou consultas de leitura.

## Testes

Foram cobertos:

- renderização visual dos campos obrigatórios;
- bloqueio do cadastro direto antes de chamar a API;
- bloqueio do equipamento diferido criado na abertura da OS;
- contrato de validação da API;
- preservação dos fluxos válidos de criação, edição, fotos e coletor.

## Evoluções possíveis

Uma migração futura pode tornar as colunas `marca_id` e `modelo_id` não nulas
também no banco, após auditoria e saneamento de todos os registros legados.

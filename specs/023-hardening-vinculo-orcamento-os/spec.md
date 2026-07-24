# Especificação — Hardening do vínculo orçamento avulso → OS

## Objetivo

Permitir que somente usuários explicitamente autorizados convertam um orçamento
avulso aprovado em Ordem de Serviço, mantendo consistência transacional,
imutabilidade do orçamento convertido e uma seleção paginada e auditável no
desktop.

## Requisitos funcionais

1. A criação de OS sem orçamento permanece inalterada.
2. A conversão exige a permissão `orcamentos:converter_os`, além de `os:criar`.
3. Apenas orçamento:
   - do tipo `previo`;
   - com status `pendente_abertura_os`;
   - sem `os_id`;
   - com cliente compatível;
   - com equipamento compatível, quando já cadastrado;
   pode ser convertido.
4. A mesma aprovação não pode gerar duas OS, mesmo com requisições simultâneas
   ou chaves de idempotência diferentes.
5. Depois da conversão, o orçamento fica imutável nas rotas genéricas de edição
   e exclusão.
6. O seletor do desktop usa busca remota paginada, informa falhas e confirma a
   troca quando o formulário já tiver alterações.
7. O orçamento selecionado exibe número, cliente, equipamento, valor e data de
   aprovação.

## Requisitos de segurança

- O backend central é a autoridade final; ocultar o campo no desktop não é
  controle de acesso.
- A validação e a mudança de estado ocorrem na mesma transação, com
  `SELECT ... FOR UPDATE`.
- Campos de ciclo de vida, autoria, tokens públicos e conversão não podem ser
  definidos pelas rotas genéricas de criação/edição.
- Respostas distinguem validação (`422`), conflito de estado (`409`), ausência
  (`404`) e falta de autorização (`403`).
- Toda busca usa Eloquent/queries parametrizadas e limites explícitos.

## Critérios de aceite

- Um usuário com `os:criar`, mas sem `orcamentos:converter_os`, recebe `403` ao
  enviar `orcamento_id`.
- Um orçamento de assistência, aprovado genérico, já convertido, de outro
  cliente ou de outro equipamento é recusado.
- Uma segunda conversão do mesmo orçamento retorna `409` e não cria outra OS.
- PATCH/DELETE de orçamento convertido retornam `409`.
- A listagem vinculável retorna somente candidatos canônicos e possui
  paginação limitada.
- O desktop não carrega antecipadamente até 50 orçamentos completos.
- Testes automatizados cobrem autorização, invariantes, replay/conflito,
  imutabilidade e busca.

## Fora de escopo

- Multi-tenancy, pois o ERP atual é uma instalação de empresa única.
- Duplicação física dos itens do orçamento na OS. O orçamento convertido é o
  snapshot imutável e continua sendo a fonte dos itens vinculados.
- Deploy ou alteração direta de código na VPS.

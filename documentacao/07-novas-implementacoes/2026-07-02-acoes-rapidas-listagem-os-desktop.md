# Acoes rapidas na listagem de OS do desktop

**Data:** 2026-07-02
**Versao:** 3.5.2
**Modulo:** `frontends/desktop` + `backend`

## Resumo

A listagem de ordens de servico do desktop passou a exibir acoes rapidas para orcamento do equipamento e para mudanca de status seguindo o fluxo de trabalho permitido para cada OS.

## O que foi entregue

- Botao de orcamento na coluna de acoes, com acesso ao cadastro do orcamento quando nao existir documento vinculado.
- Acesso ao orcamento existente quando o usuario possui permissao de visualizacao.
- Acoes de mudanca de status renderizadas no dropdown com base no catologo de transicao do fluxo.
- Payload da listagem enriquecido com `proximas_etapas` para evitar lookup extra no front.
- Row inserida em tempo real pelo broadcast de nova OS alinhada com as mesmas acoes da listagem.
- Tratamento de erro no desktop para mudanca de status com retorno seguro para a listagem.

## Impacto tecnico

- O backend central continua como fonte de verdade para workflow e permissoes.
- A listagem nao faz consultas extras por linha para descobrir proximos estados.
- O front continua sem acesso direto ao banco e usa somente o contrato da API.

## Validacao esperada

- Teste de feature da listagem de OS cobrindo botao de orcamento e alteracao de status.
- Teste de retorno de erro ao tentar atualizar status via desktop.
- Atualizacao do OpenAPI com `proximas_etapas` e `status_disponiveis`.

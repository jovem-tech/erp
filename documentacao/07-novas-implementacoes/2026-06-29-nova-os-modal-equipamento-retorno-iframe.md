# Nova OS: retorno do equipamento criado no iframe

Data: 2026-06-29

## Contexto

Depois que o cadastro de equipamento passou a ser aberto em iframe dentro da Nova OS, faltava devolver o equipamento salvo para a tela pai sem forcar o usuario a recarregar ou procurar o item manualmente na lista.

## O que mudou

- O formulario embutido de equipamento agora envia o cadastro em modo assíncrono quando esta dentro do iframe da Nova OS.
- Em caso de sucesso, o iframe publica uma mensagem `equipment-created` para a pagina pai com o equipamento criado.
- A tela da OS recebe a mensagem, adiciona o novo equipamento ao select, seleciona o item automaticamente e fecha o modal.
- O resumo lateral da OS continua sincronizado com o equipamento recem-criado, inclusive foto principal e cliente vinculado.

## Impacto

- Elimina retrabalho operacional apos cadastrar um equipamento novo durante o atendimento da OS.
- Mantem o fluxo em uma unica tela operacional, sem quebrar a consistencia visual do wizard.
- Preserva a separacao de responsabilidades: o iframe so informa sucesso, e a tela pai decide como atualizar o estado da OS.

## Validacao

- Cobertura adicionada no desktop para a resposta JSON embutida do cadastro de equipamento.
- Lint PHP e validacao de sintaxe dos scripts JS executados com sucesso.

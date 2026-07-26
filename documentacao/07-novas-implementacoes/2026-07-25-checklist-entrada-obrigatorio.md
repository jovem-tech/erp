# Checklist de entrada obrigatório na abertura da OS

## Objetivo

Impedir que o checklist de entrada seja gravado com classificações presumidas.
Quando o tipo de equipamento possui um modelo ativo, cada item precisa ser
classificado conscientemente pelo usuário antes da criação da OS.

## Comportamento da interface

- os status iniciam vazios, com o placeholder `Selecione`;
- as opções válidas são `OK`, `Discrepância`, `Não verificado` e
  `Não se aplica`;
- o botão `Todos OK`, ao lado da quantidade de itens, classifica em lote sem
  apagar observações já digitadas;
- a observação do item passa a ser obrigatória somente quando o status for
  `Discrepância`;
- o resumo e o botão de avanço consideram pendente qualquer item sem status ou
  discrepância sem observação.

## Validação autoritativa

O navegador aplica validação imediata, mas a API é a fonte de verdade:

- exige exatamente uma resposta para cada item ativo do modelo vigente;
- rejeita item ausente, duplicado, desconhecido ou status fora do catálogo;
- não converte item omitido para `Não verificado`;
- exige observação não vazia para cada discrepância;
- exige o checklist na criação quando há modelo ativo com itens para o tipo de
  equipamento.

Essa validação em profundidade impede gravações incompletas por clientes
desatualizados, requisições manuais ou manipulação do HTML.

## Compatibilidade

Não há alteração de schema: `checklist_respostas.status` já é textual. Registros
históricos permanecem válidos, e a edição continua preservando as respostas
existentes.

## Testes

A cobertura funcional verifica:

- persistência e leitura de `nao_se_aplica`;
- bloqueio de checklist omitido ou incompleto;
- obrigatoriedade da observação de discrepância;
- validação equivalente no frontend desktop;
- presença do estado inicial vazio e da ação `Todos OK` no JavaScript.

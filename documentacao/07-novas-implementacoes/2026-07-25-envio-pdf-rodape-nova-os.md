# Envio de PDF no rodapé da Nova OS

## Objetivo

Reposicionar a decisão obrigatória **Enviar PDF ao cliente** para o rodapé do formulário de abertura da OS.

## Comportamento

- A opção ocupa a área esquerda do rodapé.
- As ações **Cancelar** e **Próximo/Criar OS** permanecem agrupadas à direita.
- Em telas menores, a opção e as ações são reorganizadas verticalmente sem perda de acesso.
- O bloco continua disponível apenas na criação da OS.

## Compatibilidade

Foram preservados o campo `enviar_pdf_cliente`, os valores `0` e `1`, a obrigatoriedade, o estado anterior após erro de validação e toda a lógica de geração e envio do PDF.

> Atualização em 2026-07-26: no PWA mobile, a decisão passou para a etapa
> `Atendimento` e deixou de existir uma etapa `Extras`. O backend agora só
> renderiza, persiste e envia o PDF quando o valor é explicitamente verdadeiro.
> A organização de rodapé descrita nesta nota continua referente ao frontend
> desktop.

## Validação

O teste funcional confirma que o bloco está depois de todos os painéis do assistente e dentro do rodapé, antes do grupo de botões.

# PDF no card Extras da revisão da Nova OS

## Objetivo

Concentrar a decisão final de gerar e enviar o PDF no momento em que o operador
revisa a OS, sem recriar uma etapa `Extras`.

## Comportamento

- o checkbox `Gerar e enviar PDF ao cliente` não aparece mais em Atendimento;
- o controle fica dentro do card `Extras` da revisão;
- o orçamento opcional continua vinculado em Atendimento e resumido no mesmo
  card;
- depois de verificar o card, o checkbox fica bloqueado;
- clicar em `Editar` no card invalida somente a verificação de Extras e libera
  novamente a decisão;
- alterar o checkbox mantém o card pendente até uma nova verificação;
- o salvamento continua bloqueado enquanto qualquer card estiver pendente.

## Arquitetura e segurança

A mudança é exclusivamente de composição da interface. O estado
`enviarPdfCliente` continua no agregado local do wizard e só integra o POST
atômico final. Nenhum endpoint adicional é chamado e nenhum PDF é criado antes
do salvamento.

O backend permanece como autoridade: somente `enviar_pdf_cliente=true` permite
renderizar, persistir e enviar o documento. O valor falso evita CPU, I/O,
armazenamento e retenção desnecessária de dados.

## Validação

- teste de Atendimento garante que o checkbox não está mais presente;
- teste da Revisão garante que a opção fica dentro do card Extras;
- TypeScript, Vitest, ESLint e build Next validam a integração.

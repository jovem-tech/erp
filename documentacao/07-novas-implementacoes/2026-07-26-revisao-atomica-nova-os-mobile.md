# Revisão verificável e salvamento atômico da Nova OS mobile

## Objetivo

Tornar a abertura da OS segura contra cadastros parciais e exigir uma revisão
explícita antes da persistência definitiva.

## Experiência do operador

- cliente e equipamento já cadastrados exibem `Editar` ao lado de `Trocar`;
- as edições ficam somente na memória do wizard até o salvamento final;
- o checklist oferece `Marcar tudo OK` e `Desmarcar tudo`;
- `Atendimento` exige um prazo de 1, 3, 7, 15 ou 30 dias corridos e calcula a
  previsão de entrega automaticamente;
- a antiga etapa `Extras` foi removida; o orçamento opcional permanece em
  `Atendimento` e a decisão de gerar/enviar PDF fica no card `Extras` da
  revisão;
- cada card da revisão possui `Editar` e `Verificar`;
- cards confirmados recebem fundo verde;
- editar novamente um item invalida sua confirmação;
- o player só habilita `Salvar` quando os campos obrigatórios e todas as
  confirmações estiverem completos.

## Persistência e arquitetura

O frontend monta um único payload com a OS, novos cadastros e alterações
pendentes. O backend:

1. valida o contrato e as permissões específicas;
2. inicia uma transação;
3. bloqueia cliente e equipamento existentes para atualização;
4. confirma que o equipamento pertence ao cliente;
5. aplica alterações permitidas por listas explícitas;
6. cria a OS e seus dados relacionados;
7. confirma tudo em conjunto ou desfaz tudo em caso de erro.

O prazo em dias é a fonte de verdade. A previsão é recalculada no servidor para
evitar divergências por relógio ou fuso do aparelho.

## PDF sob demanda

Quando `enviar_pdf_cliente` é falso, o PDF de abertura não é:

- renderizado;
- gravado em `os_documentos`;
- armazenado em disco;
- encaminhado ao cliente.

Isso reduz CPU, I/O, armazenamento e risco de reter documentos que o operador
não solicitou.

## Segurança

- `os:criar` continua obrigatória;
- editar cliente requer `clientes:editar`;
- editar equipamento requer `equipamentos:editar`;
- payloads aninhados usam validação estrita e listas permitidas;
- CPF/CNPJ é normalizado e validado contra duplicidade;
- o vínculo equipamento-cliente é verificado dentro da transação;
- a `idempotency_key` e a trava de submissão continuam impedindo criação dupla;
- nenhuma chamada de escrita ocorre antes do POST final.

## Performance e escalabilidade

- a consulta de detalhes só ocorre ao tocar em `Editar`;
- não há geração de PDF quando desmarcado;
- atualizações usam registros indexados por chave primária e bloqueios curtos;
- o processamento permanece stateless e compatível com múltiplas instâncias,
  desde que compartilhem banco, storage e a estratégia de idempotência.

## Validação

- TypeScript sem erros;
- ESLint sem ocorrências;
- build Next.js de produção concluído;
- 113 testes Vitest aprovados;
- auditoria de dependências de produção sem vulnerabilidades conhecidas;
- testes de integração Laravel cobrem transação, RBAC, prazo e ausência de PDF.

## Trade-offs e evolução

Os dados pendentes ficam somente na memória do processo instalado. Fechar o PWA
ou cancelar descarta o formulário, conforme a regra de não persistir rascunho.
Uma evolução futura pode oferecer rascunho criptografado no dispositivo, sem
alterar o backend, caso o produto aceite esse novo comportamento.

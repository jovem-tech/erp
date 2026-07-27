# Plano: revisão e salvamento atômico da Nova OS mobile

## Arquitetura

O wizard mantém um agregado de rascunho no estado React. Consultas de cliente,
equipamento, checklist, técnicos e orçamento permanecem somente leitura. O
POST `/api/v1/orders` recebe, além da OS, alterações opcionais dos registros
selecionados. O backend aplica essas alterações sob autorização, lock e a mesma
transação que cria a ordem.

## Decisões

- reaproveitar o endpoint idempotente de criação em vez de criar endpoints de
  rascunho;
- manter atualização de cliente/equipamento em payloads explicitamente
  permitidos e validados;
- retirar o cadastro rápido de marca/modelo do wizard, pois ele persistia antes
  do salvamento final;
- mover PDF e orçamento para Atendimento, preservando a revisão `Extras` sem
  manter uma etapa própria;
- considerar a verificação como estado de UX local, nunca como autorização;
- invalidar a confirmação do card quando o operador volta para editá-lo.

## Performance e escalabilidade

Não há polling nem persistência de rascunho. As novas leituras são sob demanda
ao clicar em `Editar`. O salvamento acrescenta no máximo dois locks por chave
primária e continua em complexidade constante, sem novas consultas N+1.

## Validação

- testes unitários do estado, prazo, checklist, revisão e edições locais;
- testes de integração da API para autorização, transação, data autoritativa e
  ausência de PDF;
- TypeScript, Vitest, ESLint, build Next e suíte Laravel no Linux oficial;
- validação visual em viewport mobile.


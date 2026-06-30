# Modelo ideal da assistência técnica no desktop

## Contexto

A assistência técnica saudável precisa de uma fila única, responsáveis claros, SLA curto e exceções visíveis. O problema mais comum em operação não é falta de trabalho, e sim trabalho parado sem dono, sem próxima ação e sem prazo de revisão.

## O que foi entregue

- nova página em `Gestão de Conhecimento > Modelo da Assistência Técnica`;
- fluxo natural simulado do caso feliz, usando os status atuais do catálogo para representar a jornada completa da OS;
- diagrama visual com a sequência real do processo:
  - recepção;
  - triagem;
  - aguardando avaliação;
  - diagnóstico técnico;
  - aguardando orçamento;
  - aguardando autorização;
  - aguardando reparo;
  - em execução do serviço;
  - testes operacionais;
  - testes finais;
  - reparo concluído;
  - equipamento entregue;
- ramo financeiro terminal controlado:
  - entregue - pendência financeira;
- regras de fila para evitar procrastinação operacional:
  - fila única;
  - próxima ação obrigatória;
  - SLA timebox;
  - prioridade por aging;
  - WIP limitado;
  - escalonamento automático;
- ramos especiais controlados:
  - verificação de garantia;
  - cumprimento de garantia;
  - aguardando peça;
  - pagamento pendente;
  - retrabalho;
  - irreparável;
  - irreparável, disponível p/ retirada;
  - devolvido sem reparo;
  - reparado, disponível na loja;
  - reparo recusado;
  - equipamento descartado;
  - cancelada / sem reparo.

## Decisões de negócio

O modelo foi desenhado com base em práticas que funcionam em assistência técnica real:

- garantia sai da fila comercial comum e segue por verificação e cumprimento de garantia;
- o orçamento fica explicitamente entre avaliação e autorização, evitando o atalho direto para reparo;
- a execução opera com limite de OS simultâneas por técnico;
- qualidade é etapa obrigatória antes da entrega;
- pagamento pendente não pode travar a produção concluída;
- cancelamento precisa preservar o motivo para análise de perda.

## Segurança e arquitetura

- nenhuma regra de negócio nova foi movida para o desktop;
- a página é apenas informativa e segue o shell visual do ERP;
- o acesso continua protegido por permissão de conhecimento;
- não houve criação de novo contrato de API.

## Impacto operacional

O modelo ajuda a equipe a enxergar:

- onde a fila trava;
- quem é o dono atual da OS;
- qual é a próxima ação;
- quando a pendência precisa escalar;
- quais exceções seguem caminhos controlados.

## Próximo passo natural

Transformar esse modelo em playbook de operação, treinamento de atendimento e referência para revisão de status da OS no backend central.

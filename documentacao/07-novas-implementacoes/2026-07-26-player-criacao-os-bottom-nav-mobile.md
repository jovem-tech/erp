# Player de criação da OS na Bottom Nav mobile

- data: `2026-07-26`;
- versão: `5.20.0.0`;
- módulo: `frontends/mobile`;
- natureza: nova funcionalidade de navegação do wizard.

## Resultado

Na rota `/os/novo`, a Bottom Nav global é substituída por cinco ações próprias
da abertura da OS:

1. `Início`: retorna à área de trabalho;
2. `Voltar`: retorna uma etapa;
3. `Próximo`: avança quando a etapa atual está válida;
4. `Salvar`: cria a OS somente após todas as obrigatoriedades;
5. `Cancelar`: encerra o fluxo e retorna à listagem.

A substituição usa igualdade exata de pathname. Listagem, detalhe, edição e
demais rotas continuam com a navegação original.

## Arquitetura e decisões

- o shell permanece responsável pela região inferior fixa;
- um Context restrito ao shell recebe o controlador do wizard, sem eventos
  globais ou manipulação do DOM;
- registro e leitura do controlador usam contextos separados, reduzindo
  renderizações desnecessárias do formulário;
- as regras de cliente, equipamento, checklist, relato e técnico foram
  centralizadas em `wizard-state.ts` e compartilhadas com as etapas;
- o envio continua utilizando o fluxo existente de `createOrder` e sua
  `idempotency_key`;
- o formulário de edição preserva os botões internos e a Bottom Nav normal.

## Segurança e integridade

- `Salvar` possui bloqueio visual, validação antes da chamada e trava síncrona
  contra submissão concorrente;
- a idempotência do backend continua sendo a segunda barreira contra
  duplicidade;
- sair por `Início`, `Cancelar` ou recarregar a página solicita confirmação
  quando há dados preenchidos;
- validação e autorização definitivas permanecem no backend central;
- não houve alteração de payload, rota, RBAC, banco ou contrato OpenAPI.

## Performance e escalabilidade

O player trabalha somente com estados derivados e callbacks. Não cria chamadas
de rede, polling ou estado persistente adicional. A validação é linear sobre os
itens do checklist aplicável (`O(n)`), já necessários ao wizard, e os demais
campos têm custo constante.

## Testes e validação

- 18 arquivos de teste e 96 testes aprovados;
- cobertura do escopo exclusivo de `/os/novo` e preservação da edição;
- cobertura dos cinco callbacks, estados habilitado/desabilitado, completude e
  confirmação de descarte;
- lint e build Next.js aprovados;
- composição visual aprovada em `390 x 844`;
- rota temporária usada na inspeção visual removida antes do build final.

## Evoluções futuras

- persistência opcional de rascunho local ou no backend;
- indicação no player da etapa atual e do total;
- mensagem de pendências por campo ao tocar em `Salvar` desabilitado.

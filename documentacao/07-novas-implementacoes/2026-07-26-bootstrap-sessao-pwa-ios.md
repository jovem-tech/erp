# Correcao do bootstrap de sessao do PWA no iOS

- data: `2026-07-26`;
- versao: `5.20.3.0`;
- modulo: `frontends/mobile`;
- natureza: correcao de falha de inicializacao no PWA standalone.

## Sintoma

No iPhone 14, o PWA instalado abria com o fundo escuro e permanecia na tela
`Sincronizando sessao`. A instalacao e o mesmo fluxo no desktop funcionavam.

## Causa raiz

O bootstrap possuia dois caminhos capazes de impedir a conclusao:

1. `readStoredSession()` tratava erros de leitura, mas a limpeza seguinte por
   `localStorage.removeItem()` podia lancar uma excecao do WebKit antes do bloco
   `finally`;
2. `auth/me` e o refresh nao tinham deadline, portanto uma requisicao pendente
   mantinha `ready=false` e `booting=true` indefinidamente.

O preflight de producao foi validado separadamente e confirmou CORS correto
para `https://app.jovemtech.eco.br`; o problema nao era bloqueio da API.

## Solucao

- leitura, escrita e remocao do estado persistido passaram a ser tolerantes a
  falhas do armazenamento;
- quando o `localStorage` fica indisponivel, a sessao corrente pode continuar
  apenas em memoria ate o app ser encerrado;
- uma falha ao remover o token suprime o valor persistido antigo no runtime
  atual, evitando sua reutilizacao apos logout;
- `auth/me` e refresh recebem `AbortSignal` e deadline de 8 segundos;
- todo o bootstrap agora esta protegido por `finally`, garantindo a liberacao
  da interface em qualquer caminho.

## Arquitetura e seguranca

O `localStorage` continua sendo o mecanismo persistente ja adotado pelo canal
mobile; nenhum cookie legivel, IndexedDB ou armazenamento alternativo foi
introduzido. O fallback em memoria reduz disponibilidade entre reinicios, mas
nao amplia persistencia do Bearer token. Autorizacao e validade definitivas
continuam sendo verificadas pelo backend em todas as chamadas.

Em timeout sem resposta `401`, uma sessao local ainda nao expirada pode liberar
a interface para tolerar falha transitoria. Isso nao contorna RBAC: qualquer
acao permanece sujeita ao Bearer token e as politicas do backend.

## Performance e escalabilidade

O custo adicional e constante: uma referencia em memoria, um `AbortController`
e um timer apenas durante o bootstrap. Nao ha polling, chamadas extras, banco,
cache distribuido ou estado no servidor.

## Testes e validacao

- 21 arquivos e 105 testes automatizados aprovados;
- regressao para `localStorage` indisponivel no WebKit;
- regressao para validacao remota que nunca responde;
- confirmacao de que o `AbortSignal` e acionado e a UI sai de `booting`;
- lint, tipos, build Next.js e `pnpm audit --prod` aprovados;
- navegacao de `/` para `/login` validada em `390 x 844` sem permanecer na
  tela de sincronizacao.

## Recuperacao de uma instalacao afetada

Depois da publicacao, o usuario deve encerrar completamente o PWA no seletor de
aplicativos do iPhone e abri-lo novamente para o WebKit carregar a nova release.
Se uma sessao antiga estiver inconsistente, a nova versao conduz ao login em
vez de permanecer bloqueada.

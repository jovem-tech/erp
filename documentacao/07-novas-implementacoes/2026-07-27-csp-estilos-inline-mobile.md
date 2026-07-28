# CSP mobile sem estilos inline

## Contexto

- versao: `5.20.6.0`
- data: `2026-07-27`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- removidos os estilos inline de todas as rotas e componentes executados no
  navegador; espaçamento, preview de anexos, login, cards, wizard e bloqueio
  de scroll dos modais agora usam classes do stylesheet local;
- a preferência de tema deixou de escrever em `documentElement.style`; o
  `color-scheme` continua definido por `:root` e `[data-theme='light']`;
- o input de fotos usa o atributo HTML `hidden`, sem CSS inline;
- o ESLint passou a rejeitar a prop React `style` e acessos a
  `element.style`, prevenindo que uma evolução futura volte a conflitar com
  `style-src 'self'`;
- os estilos exigidos por `ImageResponse` permanecem limitados a `/icon` e
  `/apple-icon`, renderizados no servidor e fora do DOM protegido pela CSP.
- `apiListOrders` agora combina `data.orders` com `meta.pagination`, conforme
  o envelope real do backend; antes, as OS eram exibidas, mas a leitura de
  `data.pagination` lançava erro e mostrava um falso aviso de falha.

## Impactos

- segurança: a CSP não foi afrouxada com `unsafe-inline`, hashes frágeis ou
  `unsafe-hashes`; a superfície de XSS permanece restrita;
- mobile: preserva o layout, elimina os bloqueios de estilo observados no
  console em `/os` e remove o falso aviso "Não foi possível carregar as OS";
- API, banco e backend: nenhum contrato, migration ou regra de negócio foi
  alterado;
- performance e escalabilidade: classes reutilizáveis ficam no CSS estático,
  sem custo adicional de rede ou processamento por card.

## Validacao

- `npm run lint`;
- `npx tsc --noEmit`;
- `npm test -- --run`: 23 arquivos e 116 testes passando;
- `npm run build`: build de produção Next.js 15.5.21 concluído;
- busca estática sem `style={{...}}` ou `.style.*` em código cliente;
- HTML de `/login`, `/os?status=triagem` e `/os/novo`: zero atributos
  `style` e zero blocos `<style>`;
- resposta efetiva confirma `style-src 'self'`.
- logs Nginx confirmam `200` para a listagem filtrada e o teste do cliente
  confirma a normalização de `meta.pagination`.

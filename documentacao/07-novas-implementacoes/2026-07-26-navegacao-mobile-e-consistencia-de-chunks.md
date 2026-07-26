# Navegação mobile e consistência de chunks no ambiente de desenvolvimento

## Contexto

- versão: `5.18.0.0`;
- data: `2026-07-26`;
- canal: PWA Next.js em `frontends/mobile`;
- ambiente validado: bancada LAN `192.168.1.100`.

A navegação do PWA possuía apenas os atalhos `OS` e `Nova OS` na barra
inferior. Instalação do PWA e perfil dividiam espaço com notificações no topo.
Além da reorganização solicitada, foi diagnosticada uma falha operacional no
ambiente de desenvolvimento: o processo Next.js continuava executando um build
antigo enquanto `deploy-completo.sh` regravava `.next` com um build novo.

O HTML mantido em memória referenciava
`/_next/static/chunks/977-781875db24a80670.js`, já removido do disco. O Next
respondia HTML com status `400` para essa URL; o navegador recusava executar a
resposta por MIME type incorreto. Limpar o cache do navegador não corrige essa
inconsistência entre processo e artefatos.

## Arquitetura e decisões

### Navegação

- o canto superior esquerdo agora possui um menu hambúrguer;
- `Instalar app` foi movido para esse menu e continua usando o evento nativo
  `beforeinstallprompt`, com instruções de fallback quando o navegador ainda
  não oferece o prompt;
- o perfil foi removido do topo e passou a ser a quinta ação da barra inferior;
- a Bottom Nav possui cinco posições estáveis:
  1. `Início`, direcionando para `/`;
  2. `OS`, direcionando para `/os`;
  3. `Nova OS`, direcionando para `/os/novo`;
  4. `Orçamentos`, visível e desabilitado até a implementação do módulo mobile;
  5. `Perfil`, abrindo tema, edição do nome, troca de senha e logout;
- `/` deixou de ser apenas um redirecionamento para a fila e passou a ser uma
  área de trabalho própria, com atalhos para as rotinas disponíveis;
- a criação de OS continua condicionada à permissão `os:criar`. Para manter as
  cinco posições sem induzir acesso indevido, o atalho permanece visível, mas
  desabilitado, quando a permissão não existe.

O shell autenticado permanece como ponto único para navbar, notificações,
Bottom Nav e diálogos de conta. Não foi criada uma segunda implementação de
perfil ou instalação, evitando duplicação de estado e comportamento.

### Publicação do PWA na bancada

`scripts/bash/deploy-completo.sh` agora reinicia
`sistema-erp-mobile` imediatamente depois do build validado. Após o restart, o
script:

- aguarda o health check HTTPS da porta `8444`;
- extrai do HTML de `/os` todos os assets `/_next/static/*.js` e `*.css`;
- exige status `200` para cada asset;
- exige MIME compatível com JavaScript ou CSS;
- interrompe a publicação se um chunk responder HTML, `400`, `404` ou outro
  tipo incorreto.

Essa verificação transforma o incidente observado em uma falha detectável no
próprio deploy, antes de o operador considerar a publicação concluída.

Como segunda camada de resiliência, `frontends/mobile/scripts/run-next.mjs`
monitora o `.next/BUILD_ID` no modo `start`. Se outro fluxo autorizado concluir
um build sem executar o restart explícito, o wrapper encerra graciosamente o
processo filho; `autorestart=true` do Supervisor sobe o Next novamente usando o
novo conjunto de chunks. Um `BUILD_ID` temporariamente ausente durante a
compilação é ignorado, evitando reciclagem sobre build incompleto.

## Segurança

- o parâmetro `next` do login e as rotas recebidas por notificações agora
  aceitam somente caminhos internos iniciados por `/`;
- URLs absolutas, caminhos iniciados por `//`, barras invertidas e caracteres
  de controle são rejeitados, mitigando open redirect e navegação para origem
  externa;
- o controle visual de permissão não substitui o backend: a tela `/os/novo`
  mantém a validação de `os:criar`, preservando defesa em profundidade;
- o deploy valida status e MIME sem executar conteúdo retornado pelos assets.

Não houve mudança em autenticação, armazenamento de token, contrato REST,
modelo de dados ou permissões do backend.

## Performance e escalabilidade

- a Bottom Nav e a área de trabalho não adicionam chamadas à API;
- ícones continuam como SVG inline, sem novas requisições de imagem;
- o build mantém geração estática das rotas `/`, `/login`, `/os` e `/os/novo`;
- a validação de assets é linear em relação à pequena quantidade de chunks
  referenciada pelo HTML (`O(n)`) e ocorre somente durante publicação;
- notificações preservam sincronização concorrente e atualização periódica já
  existentes.

Para múltiplas instâncias, o passo seguinte recomendado é adotar no ambiente
LAN o mesmo modelo de releases imutáveis e troca atômica de symlink já usado no
deploy da VPS. O restart atual resolve corretamente a topologia de instância
única da bancada.

## Validação

- 78 testes Vitest passando;
- novos testes de regressão para as cinco ações, menu hambúrguer, perfil e
  validação de destinos internos;
- ESLint sem erros ou avisos;
- build de produção Next.js concluído com checagem de tipos;
- inspeção em viewport móvel de `390 x 844`, incluindo menu hambúrguer, perfil,
  notificações e ausência de overflow horizontal;
- reprodução do chunk quebrado por `curl`: HTTP `400` e `text/html`;
- confirmação no servidor de que o processo iniciou antes do timestamp do
  `.next/BUILD_ID`, comprovando a causa do incidente.

## Trade-offs e evolução futura

- `Orçamentos` permanece desabilitado deliberadamente; criar uma rota vazia
  agora aumentaria escopo sem entregar funcionalidade;
- a área de trabalho inicial usa atalhos locais, sem métricas remotas, para não
  adicionar latência nem acoplamento antes de existir um contrato de dashboard
  mobile;
- a bancada ainda compila dentro do checkout corrente. O restart obrigatório
  elimina o estado persistente inconsistente, mas releases imutáveis oferecem
  rollback e disponibilidade superiores e são a evolução recomendada.

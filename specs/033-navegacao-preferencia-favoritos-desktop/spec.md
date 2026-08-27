# Navegação por preferência do usuário + Favoritos na navbar

## Problema

A sidebar do desktop estava sempre presente — 270px expandida, 80px recolhida —
em praticamente todas as telas. Ela consome área útil e disputa atenção com o
conteúdo, num painel cuja rotina é ler tabela operacional.

O sistema já sabia fazer diferente: a listagem de OS (`/os`), a criação de OS e o
PDV rodavam num shell limpo, com a sidebar escondida atrás do botão sanduíche da
navbar e o conteúdo em largura total. Só que a escolha era **por rota, escrita no
layout** — três nomes de rota num `request()->routeIs(...)`. Quem preferia o shell
limpo não tinha como pedi-lo nas outras telas, e quem preferia a sidebar não tinha
como recuperá-la em `/os`.

O segundo problema é consequência do primeiro: sem sidebar sempre visível, é
preciso um caminho curto para as 4 ou 5 telas que cada operador realmente usa. A
sidebar completa tem 39 páginas em 7 seções — é um índice, não um atalho.

## Objetivo

1. Transformar o modo de navegação numa **preferência de cada usuário**, escolhida
   em Configurações do Sistema → Aparência, ao lado do tema.
2. Dar a cada usuário um **menu de favoritos na navbar**: um punhado de páginas
   fixadas por ele, sempre a um clique.

## Decisões

1. **Padrão conservador.** Quem nunca escolheu continua com a sidebar fixa. O
   sanduíche é opt-in.
2. **Só o PDV continua forçado.** `vendas.create` precisa caber na tela sem
   rolagem (specs/027-vendas-balcao-pdv). `orders.index` e `orders.create`
   deixaram de ser forçadas e passaram a obedecer a preferência — mudança de
   comportamento visível para quem já usava o sistema.
3. **Favoritar em dois lugares.** Uma estrela ao lado do **título da página** e um
   item nos "Mais ações" das listagens. A estrela é o caminho principal porque o
   "Mais ações" nem sempre existe: em `/os` ele vive dentro de `@if ($canEditOrder)`
   e em Clientes dentro de `@if ($canViewEquipments || ...)`.

   A estrela nasceu na navbar e desceu para a página em seguida: ao lado do nome da
   página a ação diz sozinha o que ela fixa, enquanto na topbar ficava ambígua — e
   ainda por cima colada ao ícone do próprio menu de favoritos.
4. **Só páginas, não registros.** Favorito é uma tela do sistema, não uma OS ou um
   cliente específico. Mantém a lista curta e evita lidar com registro excluído.
5. **Teto de 12 favoritos.** Acima disso o dropdown deixa de ser atalho e vira uma
   segunda sidebar. Ao estourar, o sistema recusa com mensagem em vez de descartar
   o mais antigo em silêncio — cada item ali foi uma escolha do usuário.
6. **Nada de cadastro novo de páginas.** O que é favoritável sai da própria
   `DesktopNavigation::definition()`, que já descreve as 39 páginas com rótulo,
   ícone e módulo RBAC — incluindo as marcadas `hidden`, que ficam fora da sidebar
   por decisão de navegação mas são páginas legítimas.

## Comportamento

### Modo de navegação

| Modo | Sidebar | Conteúdo | Como abre o menu |
|---|---|---|---|
| `fixed` (padrão) | presente, 270px, recolhível para 80px | com margem | sempre visível |
| `drawer` | fora da tela (`translateX(-100%)`) | largura total | botão ☰ da navbar, com overlay |

No modo `fixed`, a listagem de OS abre **retraída** (80px): é a tabela mais densa
do sistema e a que mais sofreu ao deixar de ser tela cheia. Isso é só um padrão de
tela — assim que o usuário mexe no botão de recolher/expandir, a escolha dele passa
a valer em todas as telas, inclusive nessa. Sem esse segundo sentido, quem
expandisse em `/os` a veria retraída de novo a cada visita.

Abaixo de 992px todo mundo continua em gaveta, como antes — o modo `drawer` é
literalmente "aplicar o comportamento de tela estreita em qualquer largura".

### Favoritos

- A estrela aparece ao lado do título quando a página é favoritável; cheia (âmbar)
  quando já está fixada. Em telas não favoritáveis ela simplesmente não existe, o
  que dispensa condicional página a página.
- Em partials compartilhados por mais de uma tela (`users/_index-content`,
  `financeiro/_lancamentos_table`) a estrela é restrita por `only="<rota>"`: naquele
  outro contexto o mesmo `<h2>` é cabeçalho de seção, não título de página.
- O dropdown lista os favoritos na ordem em que foram fixados, com ícone, rótulo e
  a seção de origem.
- Um favorito cuja permissão o usuário perdeu **some da lista mas continua
  gravado**: devolvida a permissão, ele volta sozinho. O filtro é só de leitura.
- Favoritar uma rota que o usuário não pode ver é recusado no POST — favoritar não
  vira caminho lateral de descoberta de telas.

## Fora de escopo

- Reordenar favoritos por arrastar.
- Favoritar registros individuais (OS, cliente, orçamento).
- Favoritos compartilhados ou definidos por grupo/administrador.

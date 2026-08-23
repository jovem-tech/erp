# Sidebar mais enxuta: equipamentos e ferramentas financeiras viram atalho de listagem

**Data:** 23/08/2026
**Versão:** `5.47.1.0`
**Status:** ativo no ambiente de desenvolvimento LAN; não publicado na VPS de produção

## Objetivo

Três itens da barra lateral não pagavam o espaço que ocupavam:

- **Aparelhos / Equip.** é um cadastro *derivado* — equipamento só existe vinculado
  a um cliente, e quem procura um aparelho quase sempre chega nele pelo cliente ou
  pela OS. Como entrada de primeiro nível, competia visualmente com cadastros que
  são de fato pontos de partida.
- O grupo **Ferramentas** (Financeiro) listava exatamente as mesmas três telas do
  dropdown "Mais ações" de *Financeiro > Lançamentos* — Cartões e Taxas,
  Configurações Financeiras e Precificação. Dois caminhos idênticos para o mesmo
  lugar, sendo que um deles já mora na página onde o operador está.
- **Estoque de Peças** carregava um qualificador que a tela não tem mais: o módulo
  cobre o estoque inteiro, não só peça de reparo.

## O que mudou

### 1. Sidebar (`frontends/desktop/app/Support/DesktopNavigation.php`)

- `Aparelhos / Equip.` e o grupo `Ferramentas` ganharam `'hidden' => true`.
- `Estoque de Peças` virou `Estoque`.

**Por que `hidden` e não remoção do array.** `sections()` filtra `hidden` antes de
renderizar, mas `firstAllowedRouteName()` — a rota de fallback usada pelo middleware
`desktop.permission` quando alguém esbarra numa tela sem permissão — continua
percorrendo a definição inteira. Apagar os itens deixaria sem destino de fallback
justamente quem só tem permissão neles (um usuário só com `equipamentos:visualizar`,
ou só com `precificacao:visualizar`, entraria em redirecionamento sem saída). É o
mesmo padrão já usado por *Nova venda (PDV)*, *Devoluções* e *Caixa*.

Nenhuma rota, permissão, controller ou módulo RBAC mudou. O rótulo "Estoque de
Peças" continua como está no título da página (`StockController`) e nas seções da
busca global (`SearchService`) — a troca foi só na sidebar.

### 2. Novo "Mais ações" em Clientes e Equipamentos

Com a entrada da sidebar fora, as duas listagens passam a se apontar mutuamente
pelo dropdown padrão `<x-list-actions>` do cabeçalho, no mesmo formato de
Fornecedores/Serviços/Estoque:

| Página | "Mais ações" contém |
| --- | --- |
| `clients/index.blade.php` | Aparelhos / Equipamentos, Novo equipamento, Ajuda de equipamentos |
| `equipments/index.blade.php` | Clientes, Novo cliente, Ajuda |

Cada item é filtrado por `DesktopSession::can()` do módulo de destino
(`equipamentos` / `clientes`, ações `visualizar` e `criar`); em Clientes o dropdown
inteiro some se o usuário não tem nenhuma permissão de equipamentos, para não
renderizar um menu vazio. O link `Ajuda de equipamentos` também expõe
`equipments.help`, que até aqui só era alcançável de dentro do formulário de
cadastro de equipamento.

Os dropdowns "Ações" de linha já tinham os atalhos cruzados (*Abrir equipamentos*
na linha do cliente, *Abrir cliente* na linha do equipamento) e continuam iguais —
o que faltava era o caminho para a **listagem** completa.

## Testes

`frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`:

- `test_sidebar_groups_registries_and_moves_team_to_administration` deixou de
  esperar "Ferramentas"/"Precificação" na sidebar e agora afirma o contrário
  (`assertDontSee`).
- `test_clients_list_offers_equipments_shortcut_instead_of_sidebar_entry` e
  `test_equipments_list_offers_clients_shortcut` cobrem os dois novos dropdowns.
- `test_hidden_sidebar_entries_still_serve_as_permission_fallback` prova a razão de
  usar `hidden`: usuário só com `equipamentos` cai em `/equipamentos` e usuário só
  com `precificacao` cai na precificação, em vez de ficar sem destino.

Suíte desktop: 352 passando. As 18 falhas restantes são pré-existentes e alheias a
esta entrega (tabela `user_preferences` ausente no SQLite de teste e
`ClassIsolationTest`) — confirmado rodando a suíte com estas alterações
temporariamente revertidas: mesmas 18 falhas, 349 passando.

## Notas de operação

Depois de alterar `DesktopNavigation`/Blade no servidor de desenvolvimento é preciso
refazer os caches do app desktop (`config:cache`, `route:cache`) e **deixar o cache
de views limpo** (`view:clear`, sem `view:cache`) para que o `www-data` recompile os
Blades e não esbarre em `touch(): Utime failed`. Ver
`documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md`.

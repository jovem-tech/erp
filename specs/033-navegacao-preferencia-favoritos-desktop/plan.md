# Plano — Navegação por preferência + Favoritos

Tudo no `frontends/desktop`. Nenhuma mudança no backend central: preferência de
navegação e favoritos são estado do canal desktop, não regra de negócio.

## Armazenamento

Reaproveita a tabela que já servia ao tema, mesma chave (`api_user_id`, o id do
usuário na API central — o desktop não tem tabela local de usuários).

`database/migrations/2026_08_26_000001_add_navigation_prefs_to_user_preferences_table.php`

| Coluna | Tipo | Default |
|---|---|---|
| `navigation_mode` | varchar(16) | `fixed` |
| `favorites` | text (JSON, cast `array`) | null |

## Camada de suporte

`app/Support/DesktopPreferences.php` concentra as três preferências pessoais
(tema, navegação, favoritos), porque as três moram na mesma linha:

- `hydrateSession()` — uma leitura por sessão, guardada por um sentinel **próprio**
  (`desktop_prefs_loaded`). Não dá para reusar `desktop_theme` como sentinel:
  sessões já abertas em produção têm essa chave, e as preferências novas nunca
  hidratariam até o próximo login.
- `forgetSession()` — chamado por `DesktopSession::forget()` no logout.
- `navigationMode()` / `storeNavigationMode()`
- `favoriteRoutes()` (cru) / `favorites()` (resolvido e filtrado por permissão)
- `toggleFavorite()` — valida favoritável, deduplica, aplica o teto de 12.

`app/Support/DesktopNavigation.php` ganha o lookup público
(`favoritableItems()`, `findFavoritable()`, `currentFavoritableRoute()`), que
reusa o `filterItem()` existente — assim favoritos e sidebar nunca divergem sobre
o que o usuário pode ver.

`DesktopAppServiceProvider::boot()` publica `$desktopNavMode`,
`$desktopFavorites` e `$desktopFavoriteRoute` no `View::composer('*')` já
existente.

## Layout

Duas linhas em `resources/views/layouts/app.blade.php`:

```php
$desktopSidebarHidden = $desktopSidebarHidden
    ?? (request()->routeIs('vendas.create')
        || ($desktopNavMode ?? NAV_MODE_FIXED) === NAV_MODE_DRAWER);

// Padrão de tela, só no modo 'fixed'.
$desktopSidebarCollapsed = $desktopSidebarCollapsed ?? request()->routeIs('orders.index');
```

O CSS quase não muda: `is-hidden`/`is-full`, `is-collapsed`/`is-expanded`, o overlay,
o `Escape` e a exibição do ☰ já reagiam a esses dois booleanos.

A exceção é o botão de recolher (`#sidebarToggle`), que agora sai do DOM no modo
`drawer` via `@unless` no `sidebar.blade.php`. Havia uma regra
`.desktop-sidebar.is-hidden .sidebar-toggle { display: none }` tentando escondê-lo,
mas o `.d-lg-inline-flex` do Bootstrap é `!important` e vencia — o botão aparecia
dentro da gaveta aberta, inclusive em `/os` antes desta entrega. Tirar do HTML também
o tira da árvore de acessibilidade, onde ele anunciaria "Recolher navegação" numa
gaveta. A regra morta foi removida.

No JS, `initSidebar()` passou a aplicar os **dois** sentidos do `localStorage`. Antes
ele só sabia colapsar (`=== '1'`); agora também expande (`=== '0'`), senão o padrão
de tela renderizado pelo servidor venceria a escolha explícita do usuário toda vez
que ele voltasse à listagem de OS.

## Rotas

Ambas sem `desktop.permission` — precedente de `configurations.appearance.update`:
é preferência de quem está logado, não configuração do sistema.

- `POST /configuracoes/navegacao` → `ConfigurationController@updateNavigation`
- `POST /favoritos/alternar` → `FavoriteController@toggle` (JSON, com fallback
  `redirect()->back()` para submit sem JS)

## Interface

- `resources/views/components/favorite-toggle.blade.php` — variantes `star` (ao lado
  do título da página) e `dropdown-item` (dentro de `<x-list-actions>`). Renderiza
  **nada** quando a rota atual não é favoritável, então pode ser solto ao lado de
  qualquer título sem `@if` em volta. A prop `only="<rota>"` cobre os partials
  compartilhados — e evita o `@if` inline, que **quebra a compilação do Blade**
  quando envolve uma tag de componente na mesma linha.
- `resources/views/layouts/partials/favorites.blade.php` — só o dropdown, incluído
  na `.desktop-topbar-left` depois do botão de início. Copia a moldura do dropdown
  de notificações; a lista vem renderizada do servidor (no máximo 12 itens), sem
  carregamento preguiçoso.
- A estrela entrou no título de 30 telas e em 3 casos especiais: dois partials
  compartilhados (via `only=`) e o dashboard, cujo `<h2>` é uma saudação — ali ela
  vai para a `.desktop-hero-actions`, ao lado da Ajuda.
- `components/list-actions.blade.php` — prop `favoritable`, **desligada por
  padrão**: o mesmo componente monta os dropdowns "Ações" de linha das tabelas, e
  favoritar é ação da página, não do registro. Ligada nas 9 listagens.
- `configurations/system.blade.php` — card "Navegação do menu" na aba Aparência,
  espelhando o seletor de tema (`pickNavMode`, `.nav-mode-card`).
- `desktop.js` — `initFavorites()` no bloco de bootstrap, delegação de clique,
  CSRF do `<meta name="csrf-token">`, atualização otimista e reconstrução da lista.
- `desktop.css` — dropdown, links e a estrela (herda a pílula 38x38 de
  `.desktop-icon-button`; só o estado fixado muda de cor).

## Testes

`tests/Feature/Desktop/NavigationPreferenceTest.php` (10) e
`tests/Feature/Desktop/FavoritesTest.php` (9). Dois testes existentes mudaram de
contrato: o da listagem de OS (que afirmava o forçamento por rota, e hoje afirma o
retraído) e o da navbar (que contava dois dropdowns de ícone, agora três).

A suíte do desktop é instável e tem 6 falhas anteriores a esta entrega — sempre
compare com um baseline (`git stash`) antes de atribuir uma falha a este trabalho.

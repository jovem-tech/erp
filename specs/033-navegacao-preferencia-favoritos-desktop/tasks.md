# Tarefas — Navegação por preferência + Favoritos

- [x] Migration `navigation_mode` + `favorites` em `user_preferences`; `$fillable`/`$casts` no model
- [x] `DesktopNavigation`: `favoritableItems()`, `findFavoritable()`, `currentFavoritableRoute()` reusando `filterItem()`
- [x] `DesktopPreferences`: hidratação com sentinel próprio, leitura/escrita de tema, modo e favoritos, teto de 12
- [x] `EnsureBackendToken` passa a chamar `DesktopPreferences::hydrateSession()`
- [x] `DesktopSession::forget()` limpa as chaves novas
- [x] `View::composer('*')` publica `$desktopNavMode`, `$desktopFavorites`, `$desktopFavoriteRoute`
- [x] `layouts/app.blade.php` decide o shell pela preferência; só o PDV segue forçado
- [x] `POST /configuracoes/navegacao` + `ConfigurationController@updateNavigation`
- [x] `POST /favoritos/alternar` + `FavoriteController@toggle`
- [x] Card "Navegação do menu" na aba Aparência
- [x] Componente `favorite-toggle` (star / dropdown-item)
- [x] Partial `layouts/partials/favorites.blade.php` na navbar
- [x] Prop `favoritable` no `list-actions`, ligada nas 9 listagens
- [x] CSS dos favoritos e `initFavorites()` no `desktop.js`
- [x] `NavigationPreferenceTest` (7) e `FavoritesTest` (8)
- [x] Testes existentes afetados: listagem de OS e navbar do dashboard
- [x] README do desktop atualizado
- [x] Versão + CHANGELOG

## Ajustes após a primeira revisão

- [x] `/os` abre com a sidebar retraída no modo fixo (padrão de tela, não trava a escolha do usuário)
- [x] `initSidebar()` passa a aplicar os dois sentidos do `localStorage`
- [x] Estrela sai da navbar e vai para o lado do título da página (30 telas + 3 casos especiais)
- [x] Prop `only=` no componente para partials compartilhados
- [x] Testes novos: retraído em `/os`, expandido nas demais, gaveta vence o retraído, estrela dentro do conteúdo
- [x] Botão de recolher sai do DOM no modo sanduíche (o `!important` do Bootstrap vencia o `display:none`)

## Verificação manual pendente (no navegador)

- [ ] Alternar os dois modos em Configurações → Aparência e recarregar
- [ ] Confirmar que a escolha sobrevive a logout/login e não vaza para outro usuário
- [ ] Modo sanduíche: ☰ abre, overlay fecha, `Esc` fecha, e o chevron de recolher não existe nem com a gaveta aberta
- [ ] Modo fixo: recolher/expandir segue persistindo em `localStorage`
- [ ] Favoritar pela estrela (ao lado do título) e pelo "Mais ações"; navegar pela lista; desfavoritar
- [ ] Conferir a estrela alinhada ao título em telas com título longo e em 1366px
- [ ] `/os`: confirmar que abre retraída e que expandir manualmente persiste na volta
- [ ] Conferir a estrela e o dropdown nos três temas (padrão, jovem-tech, escuro)
- [ ] `/os` em 1366px com sidebar fixa: tabela ganha rolagem horizontal sem estourar

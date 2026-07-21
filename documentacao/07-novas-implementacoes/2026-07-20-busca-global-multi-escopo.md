# Busca global: seleção de múltiplos escopos via checkboxes

## Contexto

- versao: `5.2.3.0`
- data: `2026-07-20`
- ambiente-alvo: `Ubuntu VPS` (validado em `192.168.1.100`, ambiente dev)

Antes desta entrega, o seletor "Busca completa" do topbar (e o campo "Escopo" da
tela `/buscar`) só permitia escolher um domínio por vez (OS, Orçamentos,
Clientes, etc.), obrigando o usuário a repetir a busca trocando de escopo para
cobrir mais de uma categoria.

## Entrega

- dropdown do topbar (`navbar.blade.php`): cada opção de escopo virou um
  checkbox (`<label class="dropdown-item desktop-search-scope-item">` +
  `<input type="checkbox" data-desktop-search-scope-checkbox>`); o toggle
  ganhou `data-bs-auto-close="outside"` para o dropdown não fechar a cada
  clique num item;
- tela `/buscar` (`search/index.blade.php`): o antigo `<select id="searchScope">`
  de escolha única virou uma checklist de checkboxes reais
  (`name="scope[]"`), funcionando via GET nativo sem depender de JS;
- `desktop.js`: `initScopeExclusivity()` — marcar "Busca completa" desmarca
  os escopos específicos (é um superconjunto de qualquer um deles) e
  vice-versa; desmarcar tudo sem deixar nenhuma alternativa marcada volta a
  marcar "Busca completa" sozinha, para a busca nunca ficar sem escopo válido.
  Reaproveitada tanto pelo dropdown do topbar quanto pela checklist de
  `/buscar`. O handler que sincroniza o input hidden `scope` e o rótulo do
  dropdown do topbar guarda uma referência direta ao container dos
  checkboxes (`data-desktop-search-scope-menu`) capturada no `init()`, em vez
  de buscar via `searchForm.querySelectorAll(...)` a cada evento — necessário
  porque `initDropdowns()` já existente reparenta o `.dropdown-menu` para o
  final do `<body>` enquanto ele está aberto (para escapar de containers com
  overflow clipado), o que tira os checkboxes de dentro de `searchForm` e
  quebrava a sincronização (bug pego durante a verificação headless, corrigido
  antes do commit);
- `SearchService`: `normalizeScopes()` aceita tanto array
  (`scope[]=os&scope[]=clientes`, vindo da checklist real de `/buscar`)
  quanto string separada por vírgula (`scope=os,clientes`, vinda do input
  hidden do dropdown do topbar); selecionar "tudo" junto de escopos
  específicos colapsa para só "tudo"; `scopeAllows()` passou a checar
  pertencimento num array de escopos em vez de igualdade com um único valor;
- `SearchController`: `scopeFromRequest()` repassa o parâmetro `scope` como
  array ou string conforme a origem, sem forçar cast para string (que
  quebraria com array vindo de `scope[]`).

## Impactos

- **Contrato:** o campo `scope` retornado por `GET /buscar` e
  `GET /buscar/sugestoes` deixou de ser uma string única e passou a ser
  sempre uma lista (`["tudo"]`, `["os"]`, `["os","clientes"]`, ...). Não há
  consumidor externo desse contrato além do próprio frontend desktop, que foi
  atualizado junto.
- **Módulos:** só `frontends/desktop` (busca global). Nenhuma migration,
  nenhuma rota nova — mesmos endpoints de sempre (`search.index`,
  `search.suggest`).
- **Banco/Deploy:** nenhum.
- **Segurança:** permissão por escopo continua sendo checada individualmente
  (`DesktopSession::can($modulo, 'visualizar')`) para cada domínio
  selecionado, igual ao comportamento anterior — multi-seleção não amplia o
  que o usuário já podia ver um escopo por vez.

## Validacao

- `php artisan test --filter=DesktopFrontendTest` (backend `frontends/desktop`):
  103 passando, incluindo os dois cenários novos de multi-escopo
  (`test_search_accepts_multiple_scopes_selected_via_checkboxes`, cobrindo
  tanto a string separada por vírgula quanto o array `scope[]`). As mesmas 6
  falhas pré-existentes (não relacionadas a busca) foram confirmadas via
  `git stash` do mesmo diff contra `develop` sem as alterações desta entrega —
  já falhavam antes, não é regressão.
- Verificação headless da lógica de exclusividade/sincronização do dropdown
  do topbar com jsdom + os bundles reais (`jquery-3.7.1.min.js`,
  `bootstrap.bundle.min.js`, `desktop.js`): 15/15 checagens (estado inicial,
  exclusividade "tudo" vs. escopos específicos, acumulação de múltiplos
  escopos no input hidden e no rótulo, dropdown permanece aberto entre
  cliques, fallback para "tudo" ao desmarcar tudo). Essa verificação pegou o
  bug de reparentamento do `.dropdown-menu` descrito acima antes do commit.
- `php artisan view:cache` sem erros (compila todos os `.blade.php`,
  incluindo os dois arquivos alterados).
- Rituais de cache do ambiente dev (`route:clear`/`config:clear` antes de
  testar, `route:cache`/`config:cache`/`view:cache` depois).

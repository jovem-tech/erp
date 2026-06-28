# 2026-06-24 - Menu `Pessoas` na seção comercial da sidebar do desktop do sistema-erp

## Resumo

A sidebar do `frontends/desktop` passou a organizar a seção `Comercial` com o grupo `Pessoas`, reproduzindo a lógica de agrupamento visual do legado.

## O que entrou

- grupo `Pessoas` na seção comercial da sidebar;
- submenus:
  - `Clientes`
  - `Fornecedores`
  - `Equipe Técnica`
- suporte visual a itens aninhados na navegação lateral;
- expansão leve do grupo com estado ativo automático quando um dos subitens está em uso;
- rotas reais para `Fornecedores` e `Equipe Técnica`, evitando item morto no menu.

## Ajustes técnicos

- `frontends/desktop/app/Support/DesktopNavigation.php`
  - passou a suportar grupos com filhos e filtragem recursiva por permissão;
- `frontends/desktop/resources/views/layouts/partials/sidebar.blade.php`
  - passou a renderizar o grupo `Pessoas` com subitens;
- `frontends/desktop/public/assets/css/desktop.css`
  - recebeu estilos para submenu, estados ativos e compactação no sidebar recolhido;
- `frontends/desktop/public/assets/js/desktop.js`
  - passou a alternar a abertura do grupo `Pessoas` no clique;
- `frontends/desktop/app/Http/Controllers/PeopleController.php`
  - entrou como ponto de entrada para as páginas de `Fornecedores` e `Equipe Técnica`;
- `frontends/desktop/routes/web.php`
  - ganhou as rotas `suppliers.index` e `technicians.index`.

## Versão

- `shared/version.php` atualizado para `3.1.2`.

## Validação recomendada

- abrir o dashboard e confirmar o grupo `Pessoas` na seção comercial;
- confirmar que `Clientes`, `Fornecedores` e `Equipe Técnica` aparecem como submenus;
- acessar `http://127.0.0.1:8080/fornecedores` e `http://127.0.0.1:8080/equipe-tecnica`;
- verificar que o clique no cabeçalho do grupo alterna a abertura do submenu;
- executar `php artisan test --filter=DesktopFrontendTest --stop-on-failure`.

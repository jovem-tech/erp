# 2026-07-01 - Ajuste de taxonomia e densidade do sidebar desktop

## Resumo

O sidebar do `frontends/desktop` recebeu uma limpeza pontual de rotulos e
um ajuste leve de densidade visual para reduzir ruido cognitivo e deixar
o menu mais escaneavel sem mudar o fluxo de navegacao.

## O que entrou

- secao `Gestao de Conhecimento` renomeada para `Conhecimento`;
- grupo interno renomeado para `Base de Conhecimento`;
- reducao suave de espacamento entre secoes, itens e subitens;
- refinamento de hover/focus no grupo expansivel para melhorar
  affordance;
- scrollbar do menu lateral com aparencia mais discreta e reservando
  espaco estavel quando suportado pelo navegador;
- submenu de grupos abertos no modo recolhido passou a ser posicionado
  por JS ao lado do sidebar, evitando que itens aninhados fiquem fora da
  viewport;
- grupos com submenu passaram a exibir indicador visual e legenda ao
  passar o mouse no botao/icone em modo recolhido;
- submenu aberto no modo recolhido passa a ser fechado ao clicar fora do
  sidebar;
- assercao de teste atualizada para cobrir os novos rotulos exibidos no
  sidebar.

## Ajustes tecnicos

- `frontends/desktop/app/Support/DesktopNavigation.php`
  - rotulos do grupo de conhecimento foram simplificados para remover
    redundancia textual;
- `frontends/desktop/public/assets/css/desktop.css`
  - recebeu refinamentos locais de espacamento, padding, radius, estados
    de hover/focus, barra de rolagem do menu e popover do submenu em modo
    recolhido;
- `frontends/desktop/public/assets/js/desktop.js`
  - passou a recalcular a posicao dos submenus abertos quando o sidebar
    esta recolhido, inclusive ao abrir/fechar grupos, rolar a navegacao
    ou alternar o colapso, e agora fecha os popovers ao clicar fora do
    sidebar;
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`
  - ganhou verificacao dos novos rotulos na pagina de fluxo de trabalho
    de conhecimento.

## Impacto

- sem impacto em contrato de API;
- sem impacto em banco de dados;
- sem mudanca de permissao, rota ou fluxo operacional;
- alteracao visual limitada ao frontend desktop.

## Validacao recomendada

- abrir uma pagina do modulo de conhecimento e confirmar os novos
  rotulos na sidebar;
- navegar com mouse e teclado para validar o foco no grupo expansivel;
- executar `php artisan test --filter=DesktopFrontendTest --stop-on-failure`.

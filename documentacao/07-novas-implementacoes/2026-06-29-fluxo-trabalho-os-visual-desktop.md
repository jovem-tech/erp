# Fluxo de trabalho visual das OS no desktop

## Contexto

- versão: `3.4.10`
- data: `2026-06-29`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- a página `Gestão de Conhecimento > Fluxo de Trabalho OS` passou a exibir um mapa visual por macrofase, com lanes, cards de status, indicadores de saída e legenda operacional;
- o desktop continua consumindo o mesmo contrato da API central para `statuses` e `transitions`, mas agora monta um modelo de apresentação mais rico para leitura humana;
- a matriz de transições permanece editável abaixo do diagrama, e o menu de Gestão de Conhecimento já aponta para essa tela;
- a leitura operacional ficou mais clara para recepção, diagnóstico, execução e encerramento, incluindo o ramo de pendência financeira.

## Impactos

- sem mudança de contrato da API central;
- sem mudança de banco, migrations ou storage;
- impacto restrito ao desktop Blade/CSS, à documentação e ao teste funcional da tela;
- a renderização continua segura porque as cores foram normalizadas no controller antes de entrarem no CSS inline.

## Validação

- `php artisan test --filter=DesktopFrontendTest`
- `php ./scripts/php/sync-agent-docs.php`
- revisão visual da tela `/conhecimento/fluxo-os` no desktop

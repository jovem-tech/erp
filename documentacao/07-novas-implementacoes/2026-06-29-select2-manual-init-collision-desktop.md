# 2026-06-29 - Correcao do Select2 manual na criacao de OS do desktop

## Contexto

- ambiente-alvo: `Ubuntu VPS`
- area afetada: `frontends/desktop/resources/views/orders/create.blade.php`
- scripts envolvidos: `frontends/desktop/public/assets/js/orders-create.js` e `frontends/desktop/public/assets/js/desktop.js`

## Problema observado

Ao abrir `Nova OS`, o console do navegador registrava:

- `Uncaught TypeError: r.GetData(...).destroy is not a function`
- `Script error.` capturado pelo logger global do desktop

O erro ocorria durante a inicializacao manual do Select2 nos campos `Cliente` e `Equipamento`.

## Causa raiz

- o HTML usava `data-select2="false"` apenas para impedir o init global do `desktop.js`;
- esse atributo colide com a chave interna `select2` usada pelo proprio plugin;
- quando o `orders-create.js` chamava `$(select).select2(...)`, o Select2 encontrava o valor anterior associado a `select2` e tentava destrui-lo como se fosse uma instancia valida, resultando em `destroy is not a function`.

## Correcao aplicada

- os selects manuais da tela de criacao passaram a usar `data-native-select="true"`;
- o scanner global do desktop continua respeitando a exclusao;
- o init manual de `orders-create.js` segue responsavel pelos campos de busca de cliente e equipamento, sem colisao de chave interna.

## Validacao

- ajuste coberto por teste funcional no desktop, verificando a presenca de `data-native-select="true"` e a ausencia de `data-select2="false"` na tela `Nova OS`;
- a mudanca nao altera contrato de API, banco ou permissao;
- o fluxo fica mais seguro para futuras reutilizacoes do Select2 manual em outras telas.

## Regra de ouro

Quando um `<select>` sera inicializado manualmente com Select2, use `data-native-select="true"` para excluir o init global. Evite `data-select2="false"` nesses casos, pois o atributo compartilha nome com a chave interna do plugin e pode provocar colisao.

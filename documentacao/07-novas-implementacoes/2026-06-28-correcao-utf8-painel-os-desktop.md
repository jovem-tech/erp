# Correcao UTF-8 no painel de OS do desktop

## Contexto

Durante a validacao visual de `http://127.0.0.1:8080/os`, a listagem de
Ordens de Servico passou a exibir rotulos estaticos com encoding
quebrado, apesar de o restante da interface continuar em pt-BR.

## Causa identificada

O problema estava na view
`frontends/desktop/resources/views/orders/index.blade.php`, que continha
literais ja salvos com acentuacao corrompida. A API central nao precisou
de ajuste para esta correcao.

## Ajuste aplicado

- Normalizados para `UTF-8` os textos visiveis da listagem `/os`.
- Corrigidos rotulos de filtros, tabela, estados vazios e menu de acoes.
- Mantida a mesma estrutura visual, sem alterar contrato de API ou regra
  de negocio.

## Validacao

- Testes do desktop focados na listagem de OS.
- Conferencia do HTML renderizado da view corrigida.

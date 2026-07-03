# Orcamentos desktop: botao de cadastro dinamico por tipo

## Contexto

Na linha de item do formulario de Orcamentos, o botao de cadastro rapido precisava refletir o tipo selecionado no campo `Tipo`, para reduzir ambiguidade visual e deixar a acao mais previsivel para o usuario.

## O que foi ajustado

- quando o tipo da linha e `servico`, o botao passa a exibir `Novo serviço`;
- quando o tipo da linha e `peca`, o botao passa a exibir `Nova peça`;
- a label e o `aria-label` sao atualizados tanto no carregamento inicial quanto quando o tipo e alterado no navegador;
- a aplicacao de um item rapido tambem sincroniza o rótulo da acao com o tipo efetivamente selecionado.

## Observacoes tecnicas

- o modal de cadastro rapido tambem abre no mesmo tipo da linha que disparou a acao, mantendo alinhados botao, modal e cadastro aplicado.
- a mudanca ficou restrita ao frontend desktop do formulario de Orcamentos;
- o comportamento continua dependente das permissoes de cadastro rapido ja existentes;
- a cobertura de teste foi ampliada para validar os dois estados principais do botao.

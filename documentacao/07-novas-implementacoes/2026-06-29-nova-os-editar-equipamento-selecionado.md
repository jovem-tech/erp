# Nova OS: edicao do equipamento selecionado

Data: 2026-06-29

## Contexto

Depois que a Nova OS passou a mostrar miniatura, resumo e retorno imediato do equipamento, ainda faltava um atalho direto para editar o ativo selecionado sem abandonar o fluxo operacional.

## O que mudou

- O card de resumo da OS ganhou a acao `Editar equipamento` quando existe equipamento selecionado e o perfil possui permissao de edicao.
- O link usa a rota autenticada do desktop para `equipments.edit`, mantendo a navegacao dentro do proprio sistema.
- O JavaScript da tela sincroniza o destino do botao com o equipamento atualmente selecionado no Select2.

## Impacto

- Reduz atrito para corrigir dados do ativo durante o atendimento da OS.
- Mantem o operador no contexto da ordem de servico, sem precisar procurar o equipamento em outra tela.
- Nao altera contrato de API nem abre novos caminhos publicos.

## Validacao

- Cobertura de renderizacao atualizada no desktop para garantir a presenca da acao e do link correto quando ha permissao de edicao.
- Validacao de sintaxe e revisao de comportamento continuam amarradas ao fluxo existente da Nova OS.

# Orcamentos desktop: resumo financeiro em 4 colunas

## Contexto

O formulario de Orcamentos do desktop precisava alinhar os quatro campos do resumo financeiro no mesmo eixo visual em telas amplas, reduzindo a sensacao de quebra entre Subtotal, Desconto geral, Acrescimo geral e Total.

## O que foi ajustado

- o bloco `Resumo financeiro` passou a usar `desktop-grid-four` no desktop;
- os campos `Subtotal`, `Desconto geral`, `Acrescimo geral` e `Total` agora ficam alinhados na mesma linha em larguras amplas;
- o comportamento responsivo foi preservado, com reducao natural do grid nos breakpoints menores ja existentes.
- o card de itens do orcamento recebeu ajuste fino de grid para reduzir o vazio entre `Total` e `Acoes`, melhorar o eixo visual da linha financeira e manter os controles compactos no desktop amplo.

## Observacoes tecnicas

- a alteracao foi restrita ao Blade do formulario de Orcamentos;
- nao houve impacto em contrato de API, banco de dados ou regras de negocio;
- foi adicionada cobertura de teste no frontend desktop para garantir a presenca da grid de 4 colunas no render do formulario.

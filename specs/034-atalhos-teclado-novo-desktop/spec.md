# Atalhos de teclado para o botão "+ Novo"

## Problema

Abrir uma OS, um orçamento, uma venda ou um lançamento são as quatro ações que o
operador repete o dia inteiro, e todas custam a mesma coisa: levar a mão ao mouse,
atravessar a tela até o canto superior direito, clicar em "+ Novo", ler o menu e
clicar de novo. Dois cliques e uma travessia para cada atendimento que começa.

O balcão já sabe que isso pode ser melhor: o PDV usa F2, F3 e F4 desde
`specs/027-vendas-balcao-pdv` e o operador não tira a mão do teclado.

## Objetivo

Dar às quatro ações do "+ Novo" um atalho de tecla de função, visível no próprio
menu.

| Tecla | Ação |
|---|---|
| F1 | Nova OS |
| F2 | Novo orçamento |
| F3 | Nova venda |
| F4 | Novo lançamento |

## Decisões

1. **O atalho aciona o próprio link do menu**, não uma URL repetida no JavaScript.
   Assim a permissão já resolvida no Blade continua valendo — sem link, sem atalho —
   e não há duas listas de rotas para manter em sincronia.
2. **Sem permissão, a tecla volta a ser do navegador.** Nada de engolir F1/F3 para
   depois não fazer nada.
3. **A tecla aparece no menu**, num `<kbd>` ao lado do rótulo. Atalho que ninguém vê
   não é usado.
4. **A reivindicação de teclas é por tecla, não por tela.** Uma tela declara quais
   teclas de função são dela em `data-desktop-fkeys-owner="F2 F3 F4"` — mecanismo
   genérico, disponível para qualquer tela futura. Sem valor, reivindica todas.

   O PDV reivindica F2, F3 e F4, que já são dele (confirmar venda, alternar tela
   cheia, abrir o cliente); o F3 de "Nova venda" seria mesmo redundante na tela em
   que a venda está sendo feita. **F1 continua valendo no PDV**: o balcão não usa
   essa tecla, e abrir uma OS de lá é caso real — é o cliente que chegou para deixar
   um aparelho, não para comprar. Bloquear a tela inteira teria sido grosseiro.
5. **Modal aberto bloqueia o atalho.** Modal quase sempre significa trabalho em
   andamento (baixa em lote, cadastro rápido, pagamento); sair da página descartaria
   esse trabalho sem aviso.
6. **Modificadores desligam o atalho.** Ctrl/Alt/Shift/Meta + F1..F4 continuam do
   sistema operacional e do navegador.

## Riscos conhecidos

- **F1 é a tecla de Ajuda do navegador** e **F3 é "localizar próximo"**. Os dois são
  suprimidos com `preventDefault()`, que é o mesmo recurso que o PDV já usa para o F3
  desde 2026. Precisa de confirmação no navegador real — em especial o F1, cujo
  comportamento é o menos consistente entre navegadores.
- O atalho navega para outra página. A navegação passa pelo `.click()` do próprio
  link e avisa o guard de sessão (`erpMarkInternalNavigation`); sem esse aviso a
  página seguinte trataria a saída como "navegador fechado" e deslogaria o usuário.

## Gap que este spec expôs — resolvido em `specs/035`

O PDV não protegia uma venda em andamento contra navegação nenhuma: sem
`beforeunload`, e com o próprio Esc limpando o carrinho sem confirmar. O F1 era mais
um caminho para um risco que já existia, não um risco novo — proteger só o F1 seria
inconsistente com as outras dez formas de perder o carrinho.

`specs/035-trabalho-nao-salvo-desktop` fechou o buraco na origem: um `beforeunload`
único no shell cobre todas as saídas de uma vez, inclusive estes atalhos.

## Fora de escopo

- Atalhos configuráveis pelo usuário.
- Atalhos para outras ações além das quatro do "+ Novo".
- Uma tela de referência listando todos os atalhos do sistema.

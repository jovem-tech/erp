# Proteção de trabalho não salvo

## Problema

Uma venda em andamento no PDV podia sumir sem aviso. O carrinho vive só no DOM, e
qualquer saída da página o descartava em silêncio: clique na sidebar, botão de
início, menu "+ Novo", F5, fechar a aba, botão voltar. Nenhum desses caminhos
perguntava nada.

O Esc era o mais traiçoeiro: a tela anuncia "Esc limpa", mas Esc também é o reflexo
universal de "cancelar o que estou fazendo". Um toque errado apagava uma venda de
quinze itens sem confirmação e sem volta, com o cliente na frente do balcão.

Isso não nasceu com os atalhos F1..F4 (`specs/034`); eles só tornaram o problema
mais visível, por serem mais um caminho de saída — e o mais fácil de acionar sem
querer.

## Objetivo

Que trabalho em andamento não desapareça sem o usuário ser perguntado.

## Decisões

1. **Um único ponto de checagem no shell, não um por caminho de saída.** Um
   `beforeunload` no `desktop.js` cobre todas as saídas de uma vez. A alternativa —
   cada link, atalho e botão lembrar de perguntar — quebra no dia em que alguém
   adiciona o décimo primeiro caminho e esquece.
2. **A tela declara o que é trabalho não salvo, o shell não adivinha.** Páginas
   registram uma sonda em `window.erpRegisterUnsavedWork(fn)`. O shell não sabe o
   que é um carrinho, e a tela não precisa saber por onde o usuário está saindo.
3. **A sonda exclui a saída legítima.** No PDV, `submitLiberado` já marca "a venda
   está sendo enviada". Sem isso, toda venda concluída faria o navegador perguntar
   se o operador quer mesmo sair, justamente no instante de maior pressa do balcão.
4. **Sonda quebrada não prende ninguém.** Exceção dentro de uma sonda é engolida e
   tratada como "nada a perder" — um bug numa tela não pode deixar o usuário sem
   conseguir sair dela.
5. **Esc continua limpando, mas pergunta.** O foco vai para o botão de confirmar,
   então quem quis limpar mesmo resolve com Esc + Enter. Dois toques em vez de um,
   e nenhuma venda perdida por reflexo.

## Detalhe que quase virou bug

O loader de página é armado no **clique** do link, antes do diálogo do navegador. Se
o usuário decide ficar, a navegação não acontece — e nem `pageshow` nem `pagehide`
disparam, que são os dois eventos que escondem o loader. A tela ficaria coberta pelo
loader para sempre. O guard agenda `hidePageLoader` num timeout de 0ms, que só roda
depois que o diálogo é dispensado.

## Escopo

Cobre o PDV. **O wizard de nova OS (`/os/criar`) tem exatamente o mesmo buraco** e
agora só precisa registrar uma sonda — mas definir "sujo" ali não é óbvio: o Select2
dispara `change` programático, e uma sonda ingênua faria a tela perguntar em toda
saída, inclusive sem o usuário ter digitado nada. Nagging na tela de criação mais
usada do sistema é pior que o problema. Fica para uma decisão própria.

## Achado à parte

`session_security_settings.warn_on_close` é uma configuração **morta**: a coluna
existe, o model faz cast, `SessionSecuritySettings::warnOnClose()` lê — e nada na
interface grava nem nada no JavaScript consome. Pelo comentário do guard de sessão em
`layouts/app.blade.php` ("o aviso de fechamento"), era para ser um `beforeunload` de
sessão que nunca chegou. Não foi reaproveitada aqui de propósito: aquilo é sobre
encerrar sessão, isto é sobre não perder trabalho. Vale decidir se ela volta a ter
dono ou se sai do banco.

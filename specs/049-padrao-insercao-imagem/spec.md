# Especificação 049 - Padrão único de inserção de imagem

## Problema

Cada tela que recebia imagem fazia do seu jeito. O detalhe da OS (specs/048) tinha
câmera, colar e arrastar; o cadastro de equipamento tinha webcam própria e recorte
obrigatório; a Nova OS só o seletor de arquivos com recorte obrigatório; perfil,
assinatura, logo, fundo do login e anexos do financeiro, um `<input type="file">`
cru. O técnico precisava aprender cinco comportamentos, e cada tela nova inventava
o sexto.

## Objetivo

Toda inserção de imagem do sistema — atual e futura — com as mesmas origens, o
mesmo recorte e as mesmas regras, vindo de um único componente.

## O padrão

- **Origens:** Câmera (webcam em modal com várias capturas seguidas e troca de
  câmera; em celular/tablet, a câmera nativa), Computador / galeria (seleção
  múltipla quando o campo aceita várias), Colar (botão e Ctrl+V/⌘V) e arrastar.
- **Recorte opcional em toda imagem** (decisão do usuário em 2026-09-26): cada
  imagem escolhida ganha "Recortar" (girar, ampliar/reduzir, restaurar). Enviar sem
  recortar continua a um clique. Arquivo que o navegador não abre (HEIC) segue sem
  recorte e o servidor converte.
- **Ctrl+V:** fora de campo de texto. Com um único campo de imagem na tela, cola de
  qualquer ponto; com vários, vai para o que tem o foco ou foi usado por último;
  com um modal aberto, só para o campo do modal.
- **Arrastar:** soltar no campo; soltar fora dele não abre a imagem no navegador.
- **Redução no navegador** para no máximo 2560 px quando a imagem passa de 1,5 MB
  ou do limite do destino — economia de tráfego; o backend continua autoridade.
  PNG com transparência (logo, assinatura) continua PNG.
- **Validação do destino** (formatos e tamanho) antes de enviar, com mensagem clara.
- **Nada é gravado sem confirmação:** no formulário, só com o "Salvar" da tela; na
  fila, só com o "Enviar".

## Onde se aplica

Detalhe da OS (fila com envio), Nova/Editar OS (fotos de entrada), cadastro e edição
de equipamento (inclusive embutido na Nova OS), foto de perfil, assinatura por
arquivo, logo e fundo do login da empresa e anexos do financeiro (cadastro, detalhe
e listagem — aceitam PDF também). No mobile, o mesmo comportamento no `PhotoPicker`.

## Regra para implementações futuras

Nova tela que recebe imagem usa `<x-image-picker.field>` (formulário) ou
`<x-image-picker.queue>` (envio imediato em fila), nunca `<input type="file">`
próprio para imagem. Um teste de arquitetura falha se aparecer input de imagem fora
do componente. Regra registrada no `AGENTS.md` e na skill
`$sistema-erp-insercao-de-imagem`.

## Fora de escopo

- Rotas e validações do backend não mudam (o campo de formulário continua enviando o
  mesmo nome de campo).
- Inserção de imagem nova no mobile fora dos pontos que já existem.

# "Guardar arquivos do portal": o botão de confirmar deixa de ser implícito (2026-09-02)

**Spec:** `specs/041-emissao-fiscal-nfse/spec.md`
**Tipo:** melhoria de UX (PATCH)

## O problema

No card "Guardar arquivos do portal", o botão ao lado do campo de arquivo só
mostrava o nome do formato — "XML" ou "PDF (DANFSe)". Depois de escolher o
arquivo, nada na tela dizia "clique aqui para enviar": a confirmação estava
implícita no próprio rótulo do campo, que lia como legenda, não como ação.

## A correção

- **Verbo explícito**: "Enviar XML" / "Enviar PDF", com ícone de upload.
- **Desabilitado até um arquivo ser escolhido** — o clique só fica disponível
  depois que há algo para enviar, e a mudança de estado (cinza → clicável) é o
  próprio sinal de "isto aqui confirma o envio".
- O indicador de "já guardado" (antes um `check` dentro do botão) virou uma
  linha própria acima do campo, para não competir com o texto da ação.

## Verificação

Teste novo prende as duas pontas: o botão nasce `disabled` no HTML, e o rótulo
é "Enviar XML"/"Enviar PDF". **40 testes** no desktop.

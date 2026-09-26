# Padrão único de inserção de imagem (2026-09-26)

**Tipo:** padronização transversal — biblioteca, componentes e trava de arquitetura; sem rota nova, sem migration
**Versão:** v6.5.0.0
**Especificação:** [`specs/049-padrao-insercao-imagem`](../../specs/049-padrao-insercao-imagem/spec.md)
**Parte de:** [Fotos direto na visualização da OS](2026-09-25-fotos-na-visualizacao-da-os.md), onde o comportamento nasceu.

## O problema

Cada tela que recebia imagem fazia do seu jeito:

| Tela | Antes |
|------|-------|
| Detalhe da OS | câmera, colar, arrastar (desde a v6.4.0.0), sem recorte |
| Nova / Editar OS | só seletor de arquivos + recorte **obrigatório** por foto |
| Equipamento (e o cadastro embutido na Nova OS) | galeria + webcam própria (uma foto por vez) + recorte **obrigatório** |
| Foto de perfil | só seletor; gravava no ato da escolha |
| Assinatura por arquivo | `<input type="file">` cru |
| Logo e fundo do login | `<input type="file">` cru (a ajuda prometia GIF e SVG, que o backend recusa) |
| Anexos do financeiro (cadastro, detalhe, listagem) | `<input type="file">` cru |
| Mobile (fotos da OS e do equipamento) | galeria + colar, sem câmera direta, sem arrastar, sem recorte |

## O padrão

Em toda tela, as mesmas origens e as mesmas regras:

- **Câmera** — webcam num modal com várias capturas seguidas e troca de câmera
  (lembrada no navegador); em celular/tablet, a câmera nativa. Campo de uma
  imagem só fecha na primeira captura. Capturas acima do limite do campo são
  recusadas e não aparecem como "na fila".
- **Computador / galeria** — seleção múltipla quando o campo aceita várias.
- **Colar** — botão e **Ctrl+V** (⌘V no Mac) fora de campo de texto. Com um campo
  na tela, cola de qualquer ponto; com vários, vai para o que tem o foco ou foi
  usado por último; com modal aberto, só para o campo do modal; se ficar ambíguo,
  avisa "Clique no campo de imagem onde quer colar" em vez de escolher um.
- **Arrastar** — soltar no campo; soltar fora não abre a imagem no navegador.
- **Recorte opcional em toda imagem** — botão "Recortar" em cada miniatura
  (girar, ampliar/reduzir, restaurar). Enviar sem recortar continua a um clique.
  A foto de perfil recorta já em quadrado. HEIC, que o navegador não abre, segue
  sem recorte e o servidor converte.
- **Redução no navegador** para no máximo 2560 px quando a imagem passa de 1,5 MB
  ou do limite do destino; PNG com transparência (logo, assinatura) continua PNG.
- **Validação do destino antes de enviar** — formato e tamanho de cada rota
  (fotos 20 MB com HEIC/AVIF; imagens de cadastro 4 MB; assinatura 2 MB; anexos
  20 MB com PDF), com a mensagem certa.
- **Nada é gravado sem confirmação** — o "Salvar" da tela ou o "Enviar" da fila.
  A foto de perfil agora mostra a prévia no círculo e grava no "Salvar foto".

## Como é feito

**Desktop:** `public/assets/js/image-picker.js` (`window.ErpImagePicker`), carregado
no layout para todas as telas, mais os componentes `<x-image-picker.field>`,
`<x-image-picker.queue>`, `<x-image-picker.buttons>` e `<x-image-picker.dropzone>`.
O campo de formulário escreve as imagens no mesmo `<input type="file" name=...>`
de antes (por `DataTransfer`), então **nenhuma rota do backend mudou**. O
recorte usa o Cropper.js que já estava no projeto, agora carregado só quando
alguém recorta. Saíram: `orders-photo-upload.js`, o modal de recorte da Nova OS,
os modais de câmera e recorte do equipamento e o código de recorte obrigatório.

**Mobile:** o `PhotoPicker` ganhou Câmera (input com `capture="environment"`,
separado da galeria), arrastar e o recorte opcional (`photo-crop-dialog.tsx`), com
o mesmo Cropper.js 1.6.2 (dependência nova via pnpm, em pedaço de 37 KB carregado
só ao recortar).

## Para quem for implementar tela nova

A regra está no `AGENTS.md` (guardrail 6) e na skill
`$sistema-erp-insercao-de-imagem`, com exemplos de uso. O teste
`ImageInsertionStandardTest` **falha** se uma view tiver `<input type="file">` de
imagem fora do padrão ou se um script criar input de arquivo por conta própria —
e diz qual arquivo.

## Decisões

**Recorte opcional, não obrigatório.** Decisão do usuário: o recorte é útil e
fica disponível em toda imagem, mas forçá-lo era o passo que atrasava o técnico.

**`checkOrientation: false` no Cropper.** A CSP das duas aplicações não libera
`blob:` em `connect-src`, e o Cropper faria um XHR no arquivo para ler o EXIF. Os
navegadores atuais já giram a foto pela orientação EXIF sozinhos.

**Selects nativos no padrão.** O desktop transforma todo `select.form-select` em
Select2, que ignorava o `d-none` do seletor de câmera e engolia o `change`
nativo; os selects do padrão usam `data-select2="false"` e uma seta própria (o CSS
global de `.form-select` apaga a do Bootstrap).

## Validação

- Desktop: 690 testes passando, com as mesmas 10 falhas já conhecidas antes da
  entrega; novos `ImageInsertionStandardTest` (trava, comprovada com uma view de
  sonda que ela recusou) e `ImagePickerScreensTest` (componentes, perfil,
  configurações, financeiro); testes de equipamento, Nova OS e anexos atualizados
  para o padrão.
- Mobile: 248 testes passando (novos: câmera, arrastar, recorte e o diálogo),
  `tsc` sem erros e build de produção feito numa cópia isolada.
- Chrome headless dirigindo as páginas reais de cada tela: colar (inclusive o caso
  ambíguo com dois campos), arrastar PDF, câmera simulada com limite de fotos,
  recorte por Cropper carregado sob demanda (quadrado no perfil), PNG com
  transparência preservado na logo, envio em lotes na OS e o editor de recorte
  cabendo inteiro numa tela de 640 px de altura.

# Plano técnico - Padrão único de inserção de imagem

## Desktop

- `public/assets/js/image-picker.js` (`window.ErpImagePicker`), carregado no
  `layouts/app.blade.php` para toda tela:
  - `attach(root, opts)`: liga Câmera (modal de webcam criado sob demanda; câmera
    nativa em ponteiro grosso), Computador/galeria, Colar e arrastar; prepara cada
    arquivo (formato do destino, nome coerente com o MIME, redução para 2560 px) e
    entrega a `onFiles`;
  - `field(root)`: campo de formulário — sincroniza o `<input type="file" name>`
    do form por `DataTransfer` e dispara `change`/`image-picker:change`;
  - `uploader(root)`: fila com Enviar em lotes por XHR, progresso, falha parcial,
    troca da galeria pelo `html` da resposta e sonda de trabalho não salvo;
  - `crop(file, opts)`: editor de recorte opcional (Cropper.js 1.6.2 carregado sob
    demanda de `assets/libs/cropperjs`), com girar/ampliar/restaurar e saída que
    cabe no limite do destino (PNG preservado com `keepPng`);
  - roteamento global de Ctrl+V e guarda de arrastar para a página inteira.
- Componentes Blade anônimos em `resources/views/components/image-picker/`
  (`buttons`, `dropzone`, `queue`, `field`) e perfis de destino em
  `App\Support\ImagePicker`.
- CSS `.image-picker-*` em `desktop.css`, só com tokens de tema.
- Telas migradas: detalhe da OS (uploader), Nova/Editar OS e equipamento
  (`attach` + listas próprias; recorte forçado e modais próprios removidos),
  perfil (foto com "Salvar foto" e recorte quadrado; assinatura PNG até 2 MB),
  logo e fundo do login, anexos do financeiro (3 pontos, com PDF).
- Trava: `ImageInsertionStandardTest` varre as views e os scripts.

## Mobile

- `PhotoPicker` ganha Câmera (`capture="environment"`, input separado da
  galeria), arrastar e recorte opcional em `photo-crop-dialog.tsx` (Cropper.js
  1.6.2 via pnpm, carregado por `React.lazy`).

## Decisões

- **Recorte opcional, nunca forçado** (usuário, 2026-09-26): o recorte obrigatório
  do equipamento e da Nova OS era o passo que atrasava o técnico.
- **Campo de formulário via `DataTransfer`**: nenhuma rota do backend muda; o
  mesmo `name` segue no POST.
- **Nada grava sem confirmação**: a foto de perfil deixou de ser enviada no ato da
  escolha (senão não haveria recorte, e um Ctrl+V trocaria a foto).
- **Ctrl+V nunca chuta**: com vários campos e nenhum em foco, avisa em vez de
  escolher um.
- **`checkOrientation: false` no Cropper**: a CSP não libera `blob:` em
  `connect-src`, e os navegadores atuais já aplicam a orientação EXIF.
- **Selects nativos no padrão** (`data-select2="false"` + seta própria): o Select2
  automático do desktop ignorava `d-none` e o `change` nativo.

# Tarefas - Padrão único de inserção de imagem

- [x] Biblioteca comum (`image-picker.js`): origens, preparo, campo, fila, recorte, câmera.
- [x] Componentes Blade e perfis de destino (`App\Support\ImagePicker`).
- [x] Detalhe da OS migrado para a fila comum (remove `orders-photo-upload.js` e o modal próprio).
- [x] Nova/Editar OS e equipamento: origens do padrão, recorte opcional, modais próprios removidos.
- [x] Perfil (foto e assinatura), logo e fundo do login, anexos do financeiro.
- [x] Mobile: Câmera, arrastar e recorte opcional no `PhotoPicker` (Cropper.js via pnpm).
- [x] Trava de arquitetura (`ImageInsertionStandardTest`) e testes de tela/componente.
- [x] Roteiros no Chrome headless em todas as telas (colar, arrastar, câmera, recorte, lotes).
- [x] Regra no `AGENTS.md` e skill `$sistema-erp-insercao-de-imagem`.
- [ ] Conferir num navegador real: webcam, câmera do celular e Ctrl+V de print do
      Windows com o certificado de dev.

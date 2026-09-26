# Plano técnico - Fotos direto na visualização da OS

> **Atualização 2026-09-26:** `orders-photo-upload.js` e `orders/_photo_camera_modal`
> deram lugar à biblioteca comum do padrão de inserção de imagem
> (`specs/049-padrao-insercao-imagem`); o quadro Fotos agora é um
> `data-image-picker="uploader"` e ganhou recorte opcional por foto.

## Backend

- `OrderController::storePhotos()` + `StoreOrderPhotosRequest` em
  `POST orders/{order}/photos`, com o middleware `photo-upload-throttle` que já
  protege criação e edição de OS.
- `OrderWorkflowService::addOrderPhotos()` reaproveita o caminho da edição:
  `OperationalPhotoOptimizer::optimizeMany()` fora da transação,
  `storeOrderPhotos()` (transação, rollback de blobs, gerenciador de arquivos,
  evento) e limpeza dos temporários em `finally`. Responde com a galeria inteira
  da OS (`mapPhotoCollection`, mesma forma de `fotos` no detalhe) e os ids novos.
- `storeOrderPhotos()` passa a registrar o usuário explicitamente e a categoria no
  texto do evento.
- `OrderPhoto::TIPOS` concentra a lista de categorias usada na validação.

## Desktop

- Rota `POST /os/{order}/fotos` (`desktop.permission:os,editar`) →
  `OrderController::storePhotos()` → `OrderService::addPhotos()` (multipart para o
  backend). Com `Accept: application/json` devolve a mensagem e o HTML da galeria
  renderizado pelo parcial `orders/_photos_gallery`; sem JSON redireciona para o
  detalhe com flash.
- `orders/show.blade.php` usa o parcial da galeria e ganha os controles; o modal da
  câmera fica em `orders/_photo_camera_modal`.
- `orders-photo-upload.js` concentra fila, origens (arquivo, câmera nativa,
  `getUserMedia`, `clipboard.read()`, evento `paste`, arrastar), redução no
  navegador, envio em lotes por XHR (progresso de upload) e troca da galeria,
  reativando o visualizador com `DesktopUi.refreshPhotoViewers()`.

## Decisões

- **Fila com Enviar explícito, não upload imediato:** não existe exclusão de foto de
  OS; colar por engano não pode gravar nada.
- **Sem recorte obrigatório:** era o passo que o técnico queria evitar. A redução
  automática cobre o que o recorte fazia pelo tamanho do arquivo.
- **Lotes de 4 no cliente:** mantém o limite de 4 por requisição do backend
  (`max_files_per_group`, POST de 85 MB) sem limitar o técnico a 4 fotos.
- **Galeria renderizada no servidor:** o mesmo parcial serve a página e a resposta
  JSON, então não há um segundo template de miniaturas em JavaScript.
- **Câmera nativa em ponteiro grosso:** no celular o `capture` entrega a foto da
  câmera do aparelho em resolução total; o quadro de vídeo do `getUserMedia` fica
  para webcams.

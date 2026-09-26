# Fotos direto na visualização da OS (2026-09-25)

**Tipo:** funcionalidade nova — rota nova no backend e no desktop, sem migration
**Versão:** v6.4.0.0
**Especificação:** [`specs/048-fotos-na-visualizacao-da-os`](../../specs/048-fotos-na-visualizacao-da-os/spec.md)
> **Atualização 2026-09-26 (v6.5.0.0):** o comportamento descrito aqui virou o padrão
> de todo o sistema — ver [Padrão único de inserção de imagem](2026-09-26-padrao-insercao-imagem.md).
> O quadro Fotos passou a usar a biblioteca comum (os arquivos `orders-photo-upload.js` e
> `_photo_camera_modal.blade.php` citados abaixo não existem mais) e cada foto da fila ganhou
> **Recortar** (opcional).

**Complementa:** [Fotos operacionais com meta de 400 KB](2026-09-11-otimizacao-fotos-operacionais.md)
(o otimizador central continua sendo quem valida e grava cada foto).

## O problema

Para anexar uma foto a uma OS já aberta, o técnico saía do detalhe (`/os/{id}`),
abria **Editar OS**, ia até a aba Fotos, escolhia o arquivo, passava por um
editor de recorte obrigatório para cada imagem e salvava o formulário inteiro da
OS só para gravar a foto. A única origem era o seletor de arquivos: não dava para
colar um print, fotografar pela webcam ou pelo celular direto na OS, nem arrastar
imagens. E toda foto era gravada como `recepcao` — os grupos Diagnóstico e
Entrega que o detalhe já exibia nunca recebiam nada.

## O que muda

O quadro **Fotos** do detalhe da OS ganha, para quem tem `os:editar`:

| Origem | Como funciona |
|--------|---------------|
| **Câmera** | No computador abre a webcam num modal com prévia ao vivo; cada **Capturar** vai direto para a fila e o modal continua aberto para o próximo ângulo. Havendo mais de uma câmera (webcam + câmera USB de bancada), aparece um seletor e a escolha fica lembrada no navegador. Em celular/tablet abre a câmera nativa do aparelho (resolução total). |
| **Computador / galeria** | Seletor de arquivos com seleção múltipla; no celular, a galeria. |
| **Colar** | Botão que lê a imagem da área de transferência e **Ctrl+V** (⌘V no Mac) em qualquer ponto da página fora de campo de texto — print (Win+Shift+S), "Copiar imagem" do navegador e arquivos copiados no Explorador de Arquivos. Colar dentro de um campo de texto continua sendo texto. |
| **Arrastar** | Soltar imagens em qualquer parte do quadro. Enquanto se arrasta um arquivo pela página o quadro se destaca, e soltar fora dele não abre a imagem no navegador (o que tiraria o técnico da OS). |

Tudo entra numa **fila** com miniatura, origem, tamanho e botão de remover. Nada
é gravado antes do **Enviar** — não existe exclusão de foto de OS no sistema, então
um Ctrl+V acidental não pode virar foto permanente. Não há etapa de recorte.

A fila tem uma **categoria** (Recepção, Diagnóstico ou Entrega) sugerida pela fase
do status atual:

| Fase (`status_grupo_macro`) | Categoria sugerida |
|-----------------------------|--------------------|
| recepção | Recepção |
| diagnóstico, orçamento, espera, execução, qualidade | Diagnóstico |
| concluído, finalizado sem reparo, encerrado, cancelado | Entrega |

O envio sai em lotes de até 4 fotos, um depois do outro, com barra de progresso
("Enviando 1–4 de 6…", depois "Otimizando… no servidor"). Após cada lote a
galeria é redesenhada sem recarregar a página e as fotos novas ficam destacadas.
Se um lote falha (limite de envios, formato recusado, queda de rede), os
seguintes não saem, o que não foi enviado continua na fila e a mensagem diz
quantas já foram gravadas.

Fotos grandes (acima de 1,5 MB) em JPEG/PNG/WebP são reduzidas no navegador para
no máximo 2560 px — o mesmo lado máximo que o backend grava — antes do envio. É só
economia de tráfego (uma foto de celular de 11 MB sai com ~3 MB): HEIC/HEIF e o
que o navegador não decodificar seguem originais para o servidor converter.

Sair da OS com fotos na fila, ou no meio do envio, pede confirmação (mesmo
mecanismo do PDV, `specs/035`). Na busca do topo, "foto", "câmera", "colar
imagem" etc. encontram **Adicionar fotos à OS**.

A OS encerrada também aceita fotos (a de entrega costuma vir depois da baixa).
Quem só tem `os:visualizar` continua vendo a galeria, sem os controles.

## Contrato

**Backend** — `POST /api/v1/orders/{order}/photos` (multipart), documentado no
`openapi.yaml`:

- `fotos[]`: 1 a 4 imagens (JPEG, PNG, WebP, AVIF, HEIC, HEIF), 20 MB cada;
- `tipo`: `recepcao` (padrão), `diagnostico` ou `entrega` — o `enum` de
  `os_fotos.tipo`, sem migration;
- exige `os:editar`, respeita o escopo do técnico (`ORDER_FORBIDDEN` na OS de
  outro), usa o throttle de fotos (8 envios por minuto por usuário e IP);
- `201` com `foto_ids` (as novas) e `fotos` (a galeria inteira, na forma de
  `fotos` do detalhe);
- grava o evento `fotos_adicionadas` com o usuário e a categoria: *"2 foto(s) de
  diagnóstico anexada(s) à OS."* (o texto antigo não dizia a categoria nem
  registrava o usuário explicitamente).

**Desktop** — `POST /os/{order}/fotos` (`desktop.permission:os,editar`). Com
`Accept: application/json` responde `{success, message, added, total, html}`, em
que `html` é a galeria renderizada pelo parcial `orders/_photos_gallery` — o mesmo
que a página usa. Sem JSON, redireciona para `/os/{id}#os-fotos` com flash.
Recusa de validação do backend chega ao técnico com a frase do campo ("Envie uma
foto JPEG… com extensão compatível com o conteúdo"), não com o genérico "Falha na
validação dos dados enviados". Timeout no meio do upload responde 504 avisando
que as fotos **podem** ter sido gravadas — reenviar às cegas duplicaria.

## Decisões

**Fila com Enviar explícito, não upload imediato.** Sem exclusão de foto, o custo
de um upload errado é permanente; o clique a mais compra a revisão e a escolha da
categoria para o lote.

**Galeria renderizada no servidor.** A resposta do upload traz o HTML do mesmo
parcial Blade da página, em vez de o JavaScript manter um segundo template de
miniaturas. O visualizador de fotos é religado com `DesktopUi.refreshPhotoViewers()`.

**Lotes de 4 no navegador.** O backend continua recusando mais de 4 por requisição
(`max_files_per_group`, POST de 85 MB); o técnico não fica limitado a 4.

**Selects nativos.** O desktop transforma todo `select.form-select` em Select2; a
categoria e o seletor de câmera usam `data-select2="false"` — o Select2 ignorava
o `d-none` do seletor de câmera e engolia o `change` nativo.

**Categoria sugerida por fase, não por status.** Os códigos de status são
editáveis no catálogo; as macrofases (`grupo_macro`) são o vocabulário estável
(`App\Support\OrderPhotoTypes::suggestedFor`).

## Validação

- Backend: `tests/Feature/Api/V1/OrderPhotoUploadTest.php` (7 testes) — envio por
  categoria com o arquivo gravado, evento com usuário e categoria e o cadastro da
  OS intocado; categoria padrão e galeria completa; recusa de categoria fora do
  enum, lote vazio, 5 fotos e PDF com extensão de foto; técnico só na OS dele;
  usuário só com visualização; OS inexistente; throttle compartilhado. O filtro
  `Order` da suíte: 218 passando.
- Desktop: `tests/Feature/Desktop/OrderPhotoUploadTest.php` (11 testes) —
  controles por permissão e sugestão de categoria, encaminhamento multipart ao
  backend, galeria devolvida com destaque, validações antes de chamar o backend,
  mensagem de campo do backend, 429, timeout (504) e redirect sem JSON. Suíte
  completa: 682 passando e as 10 falhas já conhecidas antes desta entrega.
- Tela real renderizada pelo Laravel e dirigida no Chrome headless: colar dentro
  de campo de texto é ignorado, PDF colado é recusado, foto de 4000×3000 / 11 MB
  sai como 2560×1920 / 2,9 MB, 5 fotos saem em lotes 4 + 1 com a categoria e o
  token CSRF, falha no segundo lote mantém só a foto restante na fila, webcam
  simulada com duas câmeras gera duas capturas na fila, tema escuro e largura de
  390 px sem transbordo do quadro.

## Fora de escopo

- Excluir ou recategorizar fotos já gravadas.
- O mesmo no frontend mobile (Next.js), que também só anexa fotos na criação da OS.
- Recorte opcional na fila.

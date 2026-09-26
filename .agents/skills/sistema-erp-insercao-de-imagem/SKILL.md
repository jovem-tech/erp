---
name: sistema-erp-insercao-de-imagem
description: Padrao unico de insercao de imagem do ERP (specs/049) — Camera, Computador/galeria, Colar (Ctrl+V), arrastar e recorte opcional, pelo mesmo componente em toda tela. Use SEMPRE que uma tela nova ou alterada receber imagem ou foto (foto de OS/equipamento, avatar, logo, assinatura, anexo que aceita foto, qualquer upload de imagem), no desktop (Blade) ou no mobile (Next.js). Nunca escreva um <input type="file"> proprio para imagem.
---

# Sistema ERP — Padrão único de inserção de imagem

## A regra

Toda tela que recebe imagem — hoje e no futuro — oferece **as mesmas origens**,
com **o mesmo comportamento**, vindo **do mesmo componente**:

| Origem | Comportamento |
|--------|---------------|
| **Câmera** | No computador, webcam num modal (várias capturas seguidas, troca de câmera lembrada). Em celular/tablet (`pointer: coarse`), a câmera nativa do aparelho. Campo de um arquivo só fecha na primeira captura. |
| **Computador / galeria** | Seletor de arquivos (múltiplo quando o campo aceita vários). |
| **Colar** | Botão (Clipboard API) e **Ctrl+V / ⌘V** fora de campo de texto. Um campo na tela → cola de qualquer lugar; vários → o que tem o foco ou foi usado por último; modal aberto → só o campo do modal; ambíguo → avisa, não chuta. |
| **Arrastar** | Soltar no campo; soltar fora não abre a imagem no navegador. |
| **Recorte** | **Opcional em toda imagem** (decisão do usuário, 2026-09-26): botão "Recortar" (girar, ampliar, restaurar). Enviar sem recortar continua a um clique. Nunca force o recorte. |

Além disso, sempre: redução no navegador para no máximo 2560 px quando a imagem
passa de 1,5 MB ou do limite do destino (só economia de tráfego — o backend é a
autoridade), PNG com transparência continua PNG quando o destino pede
(`keep-png`), formato e tamanho do destino conferidos com mensagem clara, e
**nada gravado sem confirmação** (o "Salvar" do form ou o "Enviar" da fila).

## Desktop (Laravel/Blade) — como usar

Biblioteca: `frontends/desktop/public/assets/js/image-picker.js`
(`window.ErpImagePicker`), carregada **globalmente** em `layouts/app.blade.php`.
Perfis de destino: `App\Support\ImagePicker` (`photo`, `image`, `document`).

1. **Campo de formulário** — a rota que recebe o form não muda:

   ```blade
   <form method="post" enctype="multipart/form-data" ...>
       <x-image-picker.field name="empresa_logo" accept="image" keep-png />
   </form>
   ```

   Props: `name`, `accept` (`photo` 20 MB com HEIC/AVIF | `image` 4 MB | `document`
   20 MB + PDF), `multiple`, `max`, `max-bytes`, `keep-png`, `crop-ratio`
   (`1` = quadrado), `paste` (`focus` | `page`), `title`, `help`,
   `input-attributes` (atributos do input que vai no form, ex. um `data-*` que
   outro script escuta). Slot: conteúdo extra; `.image-picker-when-filled` só
   aparece com imagem escolhida (ex.: botão "Salvar foto"). Eventos:
   `change` no input do form e `image-picker:change` na raiz.

2. **Fila com Enviar (envio imediato por XHR)** — ex.: fotos no detalhe da OS:

   ```blade
   <article data-image-picker="uploader"
       data-image-picker-upload-url="{{ route('...') }}"
       data-image-picker-field="fotos[]" data-image-picker-batch="4"
       data-image-picker-gallery="#galeria" data-image-picker-paste="page"
       data-image-picker-noun="foto" data-image-picker-noun-plural="fotos">
       <x-image-picker.buttons accept="photo" />
       <div id="galeria">…</div>
       <x-image-picker.dropzone :empty="$vazio" empty-title="…" title="…" more-title="…" />
       <x-image-picker.queue>
           <x-slot:extras>{{-- campos com data-image-picker-extra + name --}}</x-slot:extras>
       </x-image-picker.queue>
   </article>
   ```

   O endpoint responde JSON `{success: true, message, html}`; `html` substitui a
   galeria. Lotes sequenciais do tamanho que o backend aceita; falha num lote
   mantém o resto na fila; sair da tela com fila pendente pergunta antes.

3. **Só as origens** — tela com regra própria (foto principal do equipamento,
   lista da Nova OS): `ErpImagePicker.attach(root, { accept, maxFiles, paste,
   reveal, onFiles(files, source) })` + `<x-image-picker.buttons>` e
   `<x-image-picker.dropzone>` no markup; recorte opcional com
   `await ErpImagePicker.crop(file, { accept })`. O input que carrega os arquivos
   para o form leva `data-image-picker-input`.

Selects dentro do padrão usam `data-select2="false"` e a classe
`.image-picker-select` (o Select2 automático quebra `d-none` e `change` nativo).

## Mobile (Next.js)

`frontends/mobile/src/components/orders/order-form-wizard/photo-picker.tsx`
(`PhotoPicker`) é o equivalente: Câmera (`capture="environment"`), Galeria,
Colar, arrastar e recorte opcional (`photo-crop-dialog.tsx`, Cropper.js 1.6.2
carregado sob demanda). Tela nova de imagem no mobile reutiliza o `PhotoPicker`.

## Trava automática

`frontends/desktop/tests/Feature/Desktop/ImageInsertionStandardTest.php` falha se
alguma view tiver `<input type="file">` de imagem fora do padrão ou se algum
script criar input de arquivo por conta própria (lista de exceções justificada no
próprio teste). Não "conserte" o teste — adote o componente.

## Checklist de uma tela nova com imagem

- [ ] Usou `<x-image-picker.field>` / `<x-image-picker.queue>` / `attach()` (mobile: `PhotoPicker`).
- [ ] `accept`, `max-bytes` e `keep-png` batem com a validação do backend.
- [ ] Recorte disponível e opcional; nada gravado sem Salvar/Enviar.
- [ ] Teste de página conferindo `data-image-picker…` e o `name` do campo.
- [ ] `ImageInsertionStandardTest` verde.

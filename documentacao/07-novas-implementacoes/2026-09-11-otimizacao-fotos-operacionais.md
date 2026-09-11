# Fotos operacionais com meta de 400 KB

Novas fotos de ordens de serviço e equipamentos agora são validadas e otimizadas em um
serviço central do backend. A política aceita fotos de iPhone em HEIC/HEIF, normaliza
orientação e cor, remove metadados sensíveis e armazena o menor resultado seguro entre
a origem e o AVIF.

A meta normal é 400 KB. Em casos difíceis o sistema admite até 700 KB para preservar
etiquetas, números de série, telas e pequenos defeitos; abaixo desse piso de qualidade a
foto é recusada. Uma imagem pequena, como 90 KB, não é aumentada nem recomprimida sem
benefício.

O processamento ocorre antes das transações de negócio, usa libvips com timeout e
limites de recursos, e remove blobs/temporários em falhas. PDFs recebem uma derivação
JPEG temporária. Desktop e PWA preservam a pré-compressão para economizar tráfego e
conseguem enviar HEIC mesmo quando o navegador não oferece preview.

Não houve migração: as 213 fotos existentes conservam caminhos, bytes e hashes. A
instalação dos codecs e os testes reais de qualidade fazem parte do checklist operacional
da [spec 046](../../specs/046-otimizacao-fotos-operacionais/spec.md).

Para produção, cada VPS precisa receber `libvips-tools`, `libheif-plugin-aomdec`,
`libheif-plugin-aomenc` e `libheif-plugin-libde265`. O script
`scripts/bash/install-operational-photo-dependencies.sh` instala esses pacotes, prepara
`backend/storage/app/private/operational-photo-tmp` como `www-data:www-data` com
permissão `0700` e executa o preflight como `www-data`. Se `vips`, `vipsthumbnail`,
`vipsheader` ou os codecs não estiverem disponíveis, o upload falha fechado com
`PHOTO_PROCESSOR_UNAVAILABLE`.

O deploy de produção chama o instalador com `--no-preflight` depois do `git pull` e roda
`php artisan photos:preflight` apenas após atualizar dependências PHP e caches do backend.
Isso evita o falso erro `There are no commands defined in the "photos" namespace` em VPS
que ainda esteja com código antigo. A aplicação também detecta diferenças de opções entre
`vipsthumbnail` 8.15 e 8.18 antes de gerar AVIF.

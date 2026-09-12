# Especificação 046 - Otimização de fotos operacionais

## Problema

Fotos de OS e equipamentos chegam de navegadores, câmeras e iPhones em formatos,
resoluções e tamanhos diferentes. Armazenar cada origem sem uma política central
torna o crescimento do acervo imprevisível e permite que metadados sensíveis, como
GPS e EXIF, permaneçam no servidor.

O acervo anterior à entrega contém 213 fotos e é imutável para esta especificação:
nenhum caminho, byte ou hash existente pode ser alterado.

## Objetivo

Aplicar a toda nova foto operacional uma política única no backend, independente do
frontend, com meta de 400 KB, limite absoluto de 700 KB e qualidade suficiente para
ler etiquetas, números de série, telas e pequenos defeitos físicos.

## Requisitos funcionais

- Aceitar JPEG, PNG, WebP, AVIF, HEIC e HEIF estáticos.
- Aceitar no máximo quatro fotos por grupo, 20 MB e 60 megapixels por imagem.
- Corrigir orientação, converter para sRGB e remover EXIF, GPS, XMP, comentários e
  miniaturas de todo arquivo que precise ser normalizado.
- Procurar AVIF de até 400 KB com qualidades Q72 a Q60 e lados máximos 2560, 2304 e
  2048 px, sem ampliar imagens menores.
- Se necessário, procurar o melhor AVIF de até 700 KB com qualidade mínima Q50 e
  lado mínimo de 1600 px.
- Rejeitar a imagem com `PHOTO_CANNOT_MEET_POLICY` se o limite absoluto não puder ser
  atendido sem violar o piso de qualidade.
- Preservar uma origem JPEG, PNG, WebP ou AVIF validada quando ela for segura e tiver
  tamanho menor ou igual ao candidato AVIF. Em empate, preservar a origem.
- Nunca armazenar HEIC ou HEIF como origem; normalizá-los para AVIF.
- Persistir MIME verdadeiro, tamanho, SHA-256 e nome físico aleatório.
- Registrar também um nome lógico seguro para exibição/download: equipamento em
  `{tipo}_{marca}{modelo}-{cliente}` e OS em `os_{numero}_{cliente}_{tipo}`, com
  sufixo sequencial apenas quando houver mais de uma foto no mesmo grupo.
- Nunca usar o nome lógico como caminho físico; ele deve passar por sanitização e
  manter a extensão do MIME realmente armazenado.
- Converter AVIF temporariamente para JPEG de até 1920 px/Q85 durante a geração de
  PDF e eliminar a derivação ao terminar.

## Requisitos de segurança

- Rejeitar MIME falso, arquivo corrompido, RAW/DNG, vídeo, animação, HEIF sequencial,
  múltiplos frames e decompression bombs.
- Executar somente o binário fixo do libvips por Symfony Process, com argumentos
  separados, temporários privados e timeout de 12 segundos por foto.
- Falhar fechado com `PHOTO_PROCESSOR_UNAVAILABLE` quando codecs ou processador não
  estiverem disponíveis.
- Limitar uploads com fotos a oito requisições por minuto por usuário e IP.
- Remover temporários, blobs novos e registros parciais em qualquer falha.

## Requisitos de infraestrutura

- Instalar `libvips-tools`, `libheif-plugin-aomdec`, `libheif-plugin-aomenc` e
  `libheif-plugin-libde265` em todo servidor que processe uploads.
- Validar `/usr/bin/vips`, `/usr/bin/vipsthumbnail` e `/usr/bin/vipsheader` no deploy.
- Criar `backend/storage/app/private/operational-photo-tmp` como `www-data:www-data`
  com permissão `0700`.
- Executar `sudo -u www-data php artisan photos:preflight` antes de liberar tráfego.
- Em deploy automatizado, instalar dependências nativas após o `git pull` e executar
  o preflight final somente depois de atualizar dependências PHP/caches do backend.
- Configurar upload de 20 MB por arquivo, POST de 85 MB e timeout total de 75 segundos
  em PHP-FPM/Nginx.

## Compatibilidade

- Manter rotas, autenticação, ordem das fotos, foto principal e campos multipart
  `fotos` e `novo_equipamento_fotos`.
- Manter leitura das fotos antigas JPEG, PNG e WebP e adicionar AVIF ao gerenciador.
- Não migrar, recomprimir ou renomear o acervo existente.
- Fotos novas podem ter nome lógico diferente do nome físico aleatório; a mudança
  não renomeia nem recalcula registros antigos.
- Manter a pré-compressão nos frontends apenas como economia de tráfego; o backend é
  a autoridade de validação e armazenamento.

## Critérios de aceite

- Uma origem segura de aproximadamente 90 KB permanece byte a byte quando o AVIF é
  maior.
- Pelo menos 90% de uma amostra representativa termina com até 400 KB.
- Toda foto aceita termina com até 700 KB.
- HEIC real de iPhone, orientação vertical e foto de 48 MP são cobertos por teste de
  integração no ambiente com codecs instalados.
- As 213 fotos existentes mantêm os mesmos caminhos, tamanhos e SHA-256.
- Criação e atualização de OS/equipamento, equipamento criado na OS, foto principal,
  catálogo e PDF continuam funcionando.

## Fora de escopo

- Migração retroativa do acervo.
- Armazenamento de Live Photo/MOV, RAW/DNG ou imagens animadas.
- Derivadas permanentes e CDN de imagens nesta entrega.

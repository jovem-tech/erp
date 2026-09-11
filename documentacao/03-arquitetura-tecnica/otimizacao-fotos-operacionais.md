# Otimização de fotos operacionais

## Escopo

Novas fotos enviadas em OS e equipamentos passam por uma política obrigatória no
backend. O acervo preexistente não é lido para regravação e não sofre migração.

## Fluxo

1. A requisição é limitada por usuário e IP e validada para quatro fotos por grupo.
2. O inspetor verifica MIME real, dimensões, número de páginas, orientação e metadados.
3. O otimizador cria candidatos AVIF sequencialmente fora da transação de negócio.
4. Uma origem segura pode vencer se não for maior que o AVIF; HEIC/HEIF sempre vira AVIF.
5. O workflow grava o blob com UUID e registra MIME real, bytes e SHA-256.
6. Qualquer exceção desfaz registros, remove blobs novos e limpa temporários.

## Política de tamanho e qualidade

O estágio normal tenta lados máximos de 2560, 2304 e 2048 px, com Q72 a Q60, visando
`409600` bytes. O estágio excepcional admite lados até 1600 px e Q50, limitado a
`716800` bytes. Imagens menores nunca são ampliadas. Se nenhum candidato respeitar o
piso, a requisição falha com `PHOTO_CANNOT_MEET_POLICY`.

Arquivos JPEG, PNG, WebP ou AVIF já seguros podem ser preservados. Isso evita que uma
foto de 90 KB vire um AVIF maior ou sofra nova perda. Orientação pendente, metadados
sensíveis ou origem acima de 700 KB tornam a normalização obrigatória.

## Formatos e erros

Entradas: JPEG, PNG, WebP, AVIF, HEIC e HEIF estáticos, até 20 MB e 60 megapixels.
Saídas: JPEG, PNG, WebP ou AVIF. RAW/DNG, vídeo, animação, sequência, MIME falso e
arquivo corrompido são recusados.

- `PHOTO_FORMAT_UNSUPPORTED` (`422`): conteúdo/formato não permitido.
- `PHOTO_CANNOT_MEET_POLICY` (`422`): não atende 700 KB sem violar o piso.
- `PHOTO_PROCESSOR_UNAVAILABLE` (`503`): libvips ou codecs indisponíveis.
- `413`: limite do servidor HTTP/PHP excedido.
- `429`: mais de oito requisições com fotos por minuto para usuário+IP.

## PDF e compatibilidade

Fotos AVIF são convertidas para JPEG temporário de até 1920 px/Q85 somente durante a
renderização do PDF. A derivação é apagada em seguida. Os endpoints de foto entregam o
MIME armazenado e continuam aceitando o acervo antigo sem alteração.

## Infraestrutura obrigatória

O otimizador depende de libvips disponível no sistema operacional, não de biblioteca PHP
embarcada. Toda VPS que receba uploads precisa instalar `libvips-tools`,
`libheif-plugin-aomdec`, `libheif-plugin-aomenc` e `libheif-plugin-libde265`. Os
binários usados pela aplicação são `/usr/bin/vips`, `/usr/bin/vipsthumbnail` e
`/usr/bin/vipsheader`.

O preflight valida suporte real a HEIC/HEIF de entrada e AVIF de saída. Se essa
verificação falhar, o backend retorna `PHOTO_PROCESSOR_UNAVAILABLE` e rejeita o upload;
não existe fallback que armazene uma origem não validada.

O diretório `backend/storage/app/private/operational-photo-tmp` deve pertencer a
`www-data:www-data`, com permissão `0700`. O preflight operacional deve ser executado
como o mesmo usuário do PHP-FPM:

```bash
cd /var/www/sistema-erp/backend
sudo -u www-data php artisan photos:preflight
```

## Observabilidade

Falhas são registradas com código estável e contexto sem conteúdo sensível. A operação
deve acompanhar duração por foto, taxa de arquivos até 400 KB, bytes economizados,
rejeições e falhas de preflight. Nunca registrar EXIF, GPS ou bytes da imagem.

# Operação da otimização de fotos operacionais

## Pré-requisitos obrigatórios na VPS

Cada VPS ou nó que processe uploads de OS/equipamentos precisa ter as dependências
nativas de imagem instaladas. Sem elas, o backend falha fechado com
`PHOTO_PROCESSOR_UNAVAILABLE` e não grava a foto original como fallback.

Pacotes Ubuntu/Debian exigidos:

- `libvips-tools`
- `libheif-plugin-aomdec`
- `libheif-plugin-aomenc`
- `libheif-plugin-libde265`

Binários esperados:

- `/usr/bin/vips`
- `/usr/bin/vipsthumbnail`
- `/usr/bin/vipsheader`

Instalação padronizada:

```bash
cd /var/www/sistema-erp
sudo /var/www/sistema-erp/scripts/bash/install-operational-photo-dependencies.sh
```

O script instala libvips, suporte HEIC/HEIF e AVIF, prepara o diretório temporário
privado das fotos e termina executando o preflight como `www-data`, o mesmo usuário
dos pools PHP-FPM:

```bash
cd /var/www/sistema-erp/backend
sudo -u www-data php artisan photos:preflight
```

O deploy deve ser interrompido se o preflight retornar
`PHOTO_PROCESSOR_UNAVAILABLE`. Não habilite fallback que grave a origem sem inspeção.

No deploy automatizado da VPS, `deploy-producao.sh` chama esse instalador com
`--no-preflight` logo depois do `git pull`, porque o comando `photos:preflight` só existe
em versões que já contêm a spec 046. O preflight final roda depois de `composer install`
e rebuild dos caches do backend.

## Dependências PHP do deploy

Depois de atualizar código ou dependências PHP, regenere autoload/cache dos dois
projetos Laravel usados na VPS:

```bash
cd /var/www/sistema-erp/backend
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear

cd /var/www/sistema-erp/frontends/desktop
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear
```

Esse passo evita cache de package discovery apontando para dependências antigas ou de
desenvolvimento removidas.

## Permissões de runtime

O código-fonte deve ser legível pelo PHP-FPM, com diretórios `0755` e arquivos `0644`.
O diretório temporário de fotos é a exceção: ele deve ser privado do usuário do serviço.

```bash
sudo install -d -o www-data -g www-data -m 0700 \
  /var/www/sistema-erp/backend/storage/app/private/operational-photo-tmp
sudo chown -R www-data:www-data \
  /var/www/sistema-erp/backend/storage/app/private/operational-photo-tmp
sudo chmod 0700 \
  /var/www/sistema-erp/backend/storage/app/private/operational-photo-tmp
```

Não rode o preflight final como `root`. Use `sudo -u www-data`, pois o processador usa
o mesmo caminho temporário privado do PHP-FPM.

## Limites do servidor

- PHP-FPM: `upload_max_filesize=20M`, `post_max_size=85M`,
  `max_execution_time=75` e `max_input_time=75`.
- Nginx: `client_max_body_size 85m`, `client_body_timeout 75s` e
  `fastcgi_read_timeout 75s`.
- Aplicação: 20 MB, 60 MP, quatro fotos por grupo e 12 segundos por foto.

Use `infra/linux/php-fpm-operational-photo-limits.conf.example` como referência. Na VPS
atual os pools dedicados são `erp-backend` e `erp-desktop`; na migração Ubuntu 26 a base
esperada é PHP-FPM `8.5`. O script de produção detecta automaticamente `php8.5-fpm`,
`php8.4-fpm` ou `php8.3-fpm` ao recarregar serviços.

Após alterar configuração:

```bash
sudo php-fpm8.5 -t
sudo systemctl reload php8.5-fpm
sudo nginx -t
sudo systemctl reload nginx
```

## Compatibilidade de versões do libvips

A VPS atual já apresentou `vipsthumbnail` 8.15, enquanto o ambiente Ubuntu 26 usa linha
8.18. Essas versões usam nomes diferentes para algumas opções de CLI. A aplicação
detecta as opções disponíveis em runtime antes de gerar AVIF, mas o preflight continua
sendo obrigatório para provar que leitura HEIC/HEIF e escrita AVIF funcionam no host.

## Verificação pós-deploy

1. Execute `sudo -u www-data php artisan photos:preflight`.
2. Envie JPEG, PNG, WebP, AVIF e HEIC reais pelos fluxos de OS e equipamento.
3. Confirme MIME, tamanho e SHA-256 no registro criado.
4. Gere um PDF que contenha uma foto armazenada como AVIF.
5. Verifique que temporários não permanecem após sucesso, rejeição ou timeout.
6. Compare o inventário das 213 fotos anteriores por caminho, tamanho e SHA-256.
7. Em uma amostra representativa, confirme pelo menos 90% até 400 KB e todas as
   aceitas até 700 KB, com inspeção lado a lado de detalhes técnicos.

## Nomes exibidos no gerenciador

O caminho físico continua aleatório e imutável para evitar colisões e exposição de
dados no storage. Para novas fotos, o nome lógico exibido e usado no download segue:

- Foto de equipamento: `{tipo}_{marca}{modelo}-{cliente}` e, a partir da segunda foto,
  sufixo sequencial `_02`, `_03` etc.
- Foto anexada à OS: `os_{numero}_{cliente}_{tipo}` e, a partir da segunda foto,
  sufixo sequencial `_02`, `_03` etc.

A extensão é sempre a do MIME realmente armazenado (`.avif`, `.jpg`, `.png` ou
`.webp`). Caracteres de controle, separadores de caminho e pontuação insegura são
sanitizados. Fotos antigas não são renomeadas nem regravadas.

## Rollback

Reverta a aplicação e os arquivos de configuração, valide Nginx/PHP e recarregue os
serviços. Não altere nem reconverta o storage: os leitores continuam autorizando AVIF e
as fotos antigas permanecem compatíveis. Investigue `503` antes de reabrir uploads.

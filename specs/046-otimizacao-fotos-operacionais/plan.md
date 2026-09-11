# Plano técnico - Otimização de fotos operacionais

## Arquitetura

`OperationalPhotoOptimizer` é o ponto central obrigatório antes de qualquer transação
longa. Ele usa `OperationalPhotoInspector` para validar conteúdo real e metadados e
`VipsCommandRunner` para isolar a execução do libvips. Os workflows de OS e equipamento
recebem somente DTOs já validados e persistem o candidato vencedor.

O processamento é sequencial para limitar CPU e memória. A busca de qualidade usa
tentativas delimitadas e escolhe o menor arquivo que respeita o piso definido. A origem
somente compete quando estática, corretamente orientada, sem metadados sensíveis, com
até 2560 px e dentro do limite absoluto.

## Consistência e rollback

- A conversão ocorre antes da transação de negócio.
- Escritas de galeria controlam transação e lista de blobs criados.
- Exceções removem blobs novos e temporários e não deixam registros incompletos.
- Nomes físicos usam UUID; caminhos fornecidos pelo cliente nunca são usados.
- O acervo anterior não participa do pipeline e não sofre escrita.

## Segurança

- `finfo` e inspeção libvips prevalecem sobre nome/extensão enviada.
- Limites de bytes e pixels são validados antes da codificação.
- Symfony Process evita shell e command injection; executável é caminho absoluto
  validado.
- Diretórios temporários privados e limpeza em `finally` reduzem exposição de dados.
- Remoção de metadados impede retenção de GPS e informações do dispositivo.
- Rate limit composto por usuário e IP reduz abuso distribuído por credencial.

## Performance e escalabilidade

- Máximo de quatro fotos e processamento sequencial limitam picos por requisição.
- `VIPS_CONCURRENCY=1`, timeout por imagem e limites HTTP fornecem backpressure local.
- O serviço permanece stateless; storage compartilhado e filas podem ser adotados se
  a concorrência futura ultrapassar a capacidade do nó.
- Métricas recomendadas: duração, bytes de origem/destino, taxa até 400 KB, rejeições
  por código e indisponibilidade do processador.

## Infraestrutura

- Instalar `libvips-tools`, `libheif-plugin-aomdec`, `libheif-plugin-aomenc` e
  `libheif-plugin-libde265` em toda VPS/nó que processe upload de fotos.
- Validar a presença de `/usr/bin/vips`, `/usr/bin/vipsthumbnail` e
  `/usr/bin/vipsheader`.
- Executar `sudo -u www-data php artisan photos:preflight` no deploy antes de liberar
  tráfego, garantindo que o diretório temporário privado funcione com o mesmo usuário do
  PHP-FPM.
- Em `deploy-producao.sh`, instalar esses pacotes com
  `scripts/bash/install-operational-photo-dependencies.sh --no-preflight` depois do
  `git pull` e executar o preflight final somente depois do `composer install` e rebuild
  de caches do backend.
- Configurar PHP com 20 MB por arquivo e 85 MB por POST; Nginx com 85 MB e timeout de
  75 segundos.
- Rollback de código não exige rollback de dados, pois AVIF permanece suportado pelo
  gerenciador e nenhuma foto existente é migrada.

## Alternativas rejeitadas

- Compressão apenas no frontend: clientes da API poderiam contornar a política.
- JPEG universal: maior consumo para qualidade equivalente e perda do benefício AVIF.
- Recompressão obrigatória: aumentaria arquivos pequenos e adicionaria perda sem ganho.
- Conversão dentro da transação: manteria locks de banco durante trabalho intensivo.

## Evoluções futuras

- Executar otimização em fila assíncrona se o volume justificar mudança de contrato.
- Gerar thumbnails persistentes para listagens muito acessadas.
- Armazenar métricas agregadas para recalibrar Q/resolução com fotos reais.

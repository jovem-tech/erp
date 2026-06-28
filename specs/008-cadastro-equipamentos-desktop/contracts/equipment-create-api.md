# Contrato de Interface - Cadastro de Equipamentos

## Backend central

### `GET /api/v1/equipments/form-data`

Retorna:

- `types`
- `brands`
- `models`
- `catalog_relations`
- `desktop_defaults`
- `password_modes`
- `max_photos`
- `collector`

### `POST /api/v1/equipments`

Recebe multipart com:

- campos operacionais do equipamento;
- `collector_pairing_code` opcional;
- `fotos[]` com 1 a 4 arquivos;
- `foto_principal_index`.

Regras obrigatorias:

- o cadastro inicial falha sem pelo menos uma foto;
- a foto principal eh definida pela ordem do preview local e persistida no backend;
- o backend retorna `primary_photo_id` e `primary_photo_url` para consumidores que precisem destacar a imagem principal.

### `GET /api/v1/equipments`

Cada item da listagem retorna, alem do resumo operacional:

- `primary_photo_id`
- `primary_photo_url`

Uso esperado:

- frontends server-side devem reescrever o acesso da foto para uma rota same-origin autenticada do proprio canal;
- a miniatura principal da listagem deve usar essa referencia como primeira imagem operacional do equipamento.

### `GET /api/v1/equipments/{equipment}`

Retorna o detalhe completo com:

- `primary_photo_id`
- `primary_photo_url`
- `photos[]` ordenado com a foto principal primeiro

Uso esperado:

- o detalhe exibe a foto principal no topo do contexto do equipamento;
- fluxos futuros de manutencao podem reutilizar a mesma colecao para atualizacao de imagem sem mudar o identificador do ativo.

### `GET /api/v1/equipments/collector/local-snapshot`

Le o ultimo snapshot local do coletor em `C:\JovemTechBenchCollector` e devolve:

- `source_path`
- `saved_at_utc`
- `collected_at_utc`
- `snapshot`
- `mapped`

### `POST /api/v1/equipments/collector/local-collect`

Tenta executar o coletor local legado e devolve:

- `collector` com resultado da execucao ou aviso de fallback;
- `source_path`
- `snapshot`
- `mapped`

### `POST /api/v1/equipments/brands`

Recebe:

- `tipo_id`
- `nome`

Observacao:

- o backend usa `tipo_id` para manter a marca vinculada ao tipo selecionado;
- quando ainda nao existe modelo real para a marca naquele tipo, o backend persiste uma ancora tecnica compativel com o schema legado sem exibir esse modelo no formulario.

### `POST /api/v1/equipments/models`

Recebe:

- `tipo_id`
- `marca_id`
- `nome`

Observacao:

- o modelo novo fica vinculado a marca e ao tipo selecionados no momento do quick-add.

### `GET /api/v1/equipments/models/suggestions`

Query params:

- `nome`
- `marca_nome`
- `tipo_nome`

### `POST /api/v1/equipments/collector-pairings`

Cria um codigo temporario para o formulario atual quando o modo remoto de apoio for utilizado.

### `GET /api/v1/equipments/collector-pairings/{code}`

Retorna o estado do pareamento e o snapshot normalizado, quando existir.

### `POST /api/v1/collector/snapshots`

Headers:

- `X-Collector-Token`

Body:

- `pairing_code`
- `snapshot`
- `source`
- `agent_version`
- `hostname`

### `GET /api/v1/equipments/{equipment}/photos/{photo}`

Retorna a foto privada do equipamento autenticado.

## Desktop same-origin

O browser nao chama a API central diretamente. O desktop deve expor rotas same-origin para:

- quick-add de cliente;
- quick-add de marca;
- quick-add de modelo;
- leitura e execucao local do coletor;
- criacao e consulta de pareamento remoto de apoio;
- sugestoes externas de modelo;
- proxy autenticado de foto privada.

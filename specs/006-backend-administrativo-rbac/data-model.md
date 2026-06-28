# Data Model: Backend administrativo e RBAC central

## Usuário (`usuarios`)

- `id`
- `nome`
- `email`
- `senha`
- `perfil`
- `grupo_id`
- `ativo`
- `ultimo_acesso`

### Relações

- pertence a `Group` por `grupo_id`
- pode ser técnico responsável em `Order`

## Grupo (`grupos`)

- `id`
- `nome`
- `descricao`
- `sistema`
- `created_at`

### Relações

- possui muitos `User`
- possui muitas linhas em `GroupPermission`

## Módulo (`modulos`)

- `id`
- `nome`
- `slug`
- `icone`
- `ordem_menu`
- `ativo`

## Permissão (`permissoes`)

- `id`
- `nome`
- `slug`

## Matriz de grupo (`grupo_permissoes`)

- `id`
- `grupo_id`
- `modulo_id`
- `permissao_id`

### Regra

- combinação única por `grupo_id`, `modulo_id` e `permissao_id`

## Ordem de serviço (`os`)

- `id`
- `numero_os`
- `cliente_id`
- `equipamento_id`
- `tecnico_id`
- `status`
- `estado_fluxo`
- `prioridade`
- `relato_cliente`
- `diagnostico_tecnico`
- `solucao_aplicada`
- `procedimentos_executados`
- `data_abertura`
- `data_entrada`
- `data_previsao`
- `data_conclusao`
- `data_entrega`
- `garantia_dias`
- `garantia_validade`
- demais campos financeiros e operacionais já existentes

### Relações

- pertence a `Client`
- pertence a `Equipment`
- pertence a `User` como técnico
- possui histórico, fotos e documentos

## Cliente (`clientes`)

- `id`
- `nome_razao`
- `cpf_cnpj`
- `email`
- `telefone1`
- `cidade`
- `uf`
- `status_cadastro`

## Equipamento (`equipamentos`)

- `id`
- `cliente_id`
- `resumo_tecnico`
- `numero_serie`
- `imei`
- `status_operacional`
- `status`

## Estado derivado de autorização

O backend não persiste permissões efetivas em tabela própria. O payload efetivo é derivado em runtime a partir de:

- `User.group`
- `GroupPermission`
- `Module.slug`
- `Permission.slug`

Esse estado derivado é cacheado em `rbac_user_{id}` por 5 minutos.

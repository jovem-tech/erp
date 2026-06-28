# Data Model - Cadastro Completo de Equipamentos no Desktop

## Equipamento

- `cliente_id`
- `tipo_id`
- `marca_id`
- `modelo_id`
- `numero_serie`
- `imei`
- `senha_acesso`
- `cor`
- `cor_hex`
- `cor_rgb`
- `estado_fisico`
- `acessorios`
- `observacoes`
- `desktop_modalidade`
- `gabinete_tipo`
- `gabinete_identificacao_status`
- `gabinete_observacao`
- `placa_mae`
- `chipset`
- `processador`
- `memoria_ram`
- `armazenamento`
- `placa_video`
- `fonte_alimentacao`
- `resumo_tecnico`
- `status_operacional`
- `status`

## Foto de Equipamento

- `equipamento_id`
- `arquivo`
- `is_principal`
- `created_at`

## Catálogo de Equipamentos

### Tipo

- `id`
- `nome`
- `ativo`

### Marca

- `id`
- `nome`
- `ativo`

### Modelo

- `id`
- `marca_id`
- `nome`
- `ativo`

## Pareamento do Coletor

- `user_id`
- `code`
- `snapshot_payload`
- `snapshot_normalized`
- `source`
- `agent_version`
- `hostname`
- `snapshot_received_at`
- `expires_at`
- `consumed_at`

## Snapshot Local do Coletor

- arquivo JSON em `C:\JovemTechBenchCollector`
- pode existir como `last-snapshot.json`, `snapshot.json` ou `inf_*.json`
- o documento pode vir plano ou com `snapshot` aninhado
- o payload mapeado alimenta `numero_serie`, `placa_mae`, `chipset`, `processador`, `memoria_ram`, `armazenamento`, `placa_video`, `gabinete_tipo` e `gabinete_identificacao_status`

## Regras derivadas

- máximo de 4 fotos por equipamento no fluxo de criação;
- `Desktop montado` injeta marca/modelo default quando necessário;
- a leitura local do snapshot é prioritária na UX do formulário em ambiente Windows local;
- `snapshot_normalized` só apoia preenchimento do formulário e não cria equipamento sozinho;
- foto privada deve ser resolvida por rota autenticada vinculada ao equipamento correto.

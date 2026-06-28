# Data Model: Fluxo de OS Mobile

## Entidades

### Order

- **Tabela**: `os`
- **Campos relevantes**: `id`, `numero_os`, `cliente_id`, `equipamento_id`, `tecnico_id`, `status`, `estado_fluxo`, `status_atualizado_em`
- **Responsabilidade**: representar a OS operacional que o técnico acompanha e atualiza
- **Relacionamentos**:
  - pertence a um cliente
  - pertence a um equipamento
  - pertence a um técnico
  - referencia o catálogo de status pelo campo `status`
  - possui histórico recente, fotos e documentos controlados pelo backend

### OrderStatus

- **Tabela**: `os_status`
- **Campos relevantes**: `codigo`, `nome`, `grupo_macro`, `estado_fluxo_padrao`, `ativo`, `ordem_fluxo`
- **Responsabilidade**: ser o catálogo de status aceitos pelo backend
- **Observação**: o catálogo é a fonte de verdade em runtime

### OrderStatusHistory

- **Tabela**: `os_status_historico`
- **Campos relevantes**: `os_id`, `status_anterior`, `status_novo`, `estado_fluxo`, `usuario_id`, `observacao`, `created_at`
- **Responsabilidade**: guardar o trilho de auditoria das mudanças de status

### OrderPhoto

- **Tabela**: `os_fotos`
- **Campos relevantes**: `os_id`, `tipo`, `arquivo`, `created_at`
- **Responsabilidade**: armazenar os metadados das fotos vinculadas à OS

### OrderDocument

- **Tabela**: `os_documentos`
- **Campos relevantes**: `os_id`, `tipo_documento`, `arquivo`, `versao`, `hash_sha1`, `gerado_por`, `created_at`, `updated_at`
- **Responsabilidade**: armazenar os metadados dos PDFs vinculados à OS

## Regras de integração

- A listagem precisa trazer dados suficientes para o técnico identificar a OS rapidamente.
- O detalhe da OS precisa carregar cliente, equipamento, histórico recente e anexos sem expor bytes de arquivo no JSON.
- A atualização deve manter `status` e `estado_fluxo` coerentes.
- O histórico deve registrar quem alterou a OS e quando isso ocorreu.
- Fotos e documentos devem ser acessados por endpoint controlado no backend.

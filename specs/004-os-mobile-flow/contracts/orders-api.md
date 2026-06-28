# Contract: Orders API

## Authentication

- Todas as rotas exigem token Bearer válido.

## GET /api/v1/orders

### Query params

- `q` / `search` - termo opcional de busca
- `status_scope` - escopo opcional da fila operacional; `open` retorna apenas OS cujo `estado_fluxo` nao e `encerrado`, e `all` remove esse recorte
- `status` - filtro opcional por status
- `technician_id` - filtro opcional por técnico responsável (ignorado quando o usuário autenticado já é técnico)
- `client_id` - filtro opcional por cliente
- `equipment_id` - filtro opcional por equipamento
- `grupo_macro` - filtro opcional pela macrofase do status (`os_status.grupo_macro`)
- `data_abertura_de` / `data_abertura_ate` - filtro opcional de intervalo de `data_abertura` (inclusive)
- `valor_min` / `valor_max` - filtro opcional de intervalo de `valor_final`
- `per_page` - quantidade opcional por página

### Response

Cada item de `data.orders[]` (resumo da listagem) inclui, além dos campos
básicos (`id`, `numero_os`, `numero_os_legado`, `status`, `estado_fluxo`,
`prioridade`):

- `cliente_telefone` - telefone do cliente (`telefone1`, com fallback para
  `telefone_contato`) já pronto para link de WhatsApp;
- `equipamento_resumo_curto` - resumo curto (tipo + marca + modelo) para a
  listagem; `equipamento_resumo_tecnico` continua disponível com o texto
  completo;
- `equipamento_foto_id` - id da foto principal do equipamento, nulo/0
  quando não há foto principal; consumidores autenticados via Bearer
  (ex.: mobile) podem usar `equipamento_foto_url` diretamente, mas o
  desktop **não** usa essa URL num `<img>` (ela aponta para o backend, em
  porta diferente da do desktop, sem o token do navegador) — o desktop
  monta sua própria rota proxy autenticada
  (`equipments.photos.show`/`/equipamentos/{id}/fotos/{foto}`) a partir
  de `equipamento_id` + `equipamento_foto_id`;
- `equipamento_foto_url` - URL autenticada da foto principal do
  equipamento (reaproveita a rota de fotos do módulo de equipamentos),
  nula quando não há foto principal;
- `data_entrada`, `data_previsao`, `data_conclusao`, `data_entrega`;
- `prazo` - objeto calculado a partir das datas acima: `{ estado, label, dias }`,
  com `estado` em `sem_previsao | atrasado | vence_hoje | critico | no_prazo | concluido_no_prazo | concluido_atrasado`;
- `orcamento` - orçamento mais recente vinculado à OS (`{ id, numero, status, status_label, status_color }`),
  nulo quando não há orçamento vinculado;
- `valor_mao_obra`, `valor_pecas`, `desconto`, `valor_final`;
- `valor_recebido`, `saldo` - somam os `financeiro_movimentos` do título a
  receber (`financeiro`, `tipo=receber`) mais recente vinculado à OS; ambos
  nulos quando não há título financeiro vinculado.

Orçamento e financeiro são resolvidos em lote por página (no máximo
poucas consultas adicionais, independente da quantidade de OS exibidas) —
nunca uma consulta por linha.

```json
{
  "status": "success",
  "data": {
    "orders": [
      {
        "id": 3578,
        "numero_os": "OS26060006",
        "cliente_nome": "Cliente Exemplo",
        "cliente_telefone": "11999998888",
        "equipamento_resumo_curto": "Notebook Dell Inspiron 15",
        "equipamento_foto_url": "/api/v1/equipments/204/photos/5",
        "prazo": { "estado": "atrasado", "label": "Atrasada", "dias": 2 },
        "orcamento": { "id": 12, "numero": "ORC-2606-000012", "status": "aguardando_resposta", "status_label": "Aguardando resposta", "status_color": "#8b5cf6" },
        "valor_final": "300.00",
        "valor_recebido": "100.00",
        "saldo": "200.00"
      }
    ]
  },
  "error": null,
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 0,
      "last_page": 1,
      "from": null,
      "to": null
    }
  }
}
```

## GET /api/v1/orders/{id}

### Response

```json
{
  "status": "success",
  "data": {
    "order": {
      "id": 3578,
      "numero_os": "OS26060006",
      "cliente": {
        "id": 91,
        "nome_razao": "Cliente Exemplo"
      },
      "equipamento": {
        "id": 204,
        "numero_serie": "ABC123"
      },
      "historico": [
        {
          "id": 10,
          "status_novo": "aguardando_reparo",
          "created_at": "2026-06-22T08:00:00-03:00"
        }
      ],
      "fotos": [
        {
          "id": 5,
          "tipo": "recepcao",
          "url": "/api/v1/orders/3578/photos/5"
        }
      ],
      "documentos": [
        {
          "id": 8,
          "tipo_documento": "abertura",
          "url": "/api/v1/orders/3578/documents/8"
        }
      ]
    }
  },
  "error": null
}
```

### Error codes

- `ORDER_FORBIDDEN` - OS existente, mas não atribuída ao técnico autenticado
- `ORDER_NOT_FOUND` - OS inexistente

## GET /api/v1/orders/{id}/photos/{photo}

### Response

- retorna a foto da OS com acesso controlado pelo backend

## GET /api/v1/orders/{id}/documents/{document}

### Response

- retorna o PDF da OS com acesso controlado pelo backend

## PATCH /api/v1/orders/{id}/status

### Request body

```json
{
  "status": "aguardando_reparo",
  "observacao": "Liberado para execução."
}
```

### Response

```json
{
  "status": "success",
  "data": {
    "order": {
      "id": 3578,
      "numero_os": "OS26060006",
      "status": "aguardando_reparo",
      "estado_fluxo": "em_execucao"
    },
    "status_anterior": "triagem",
    "status_novo": "aguardando_reparo",
    "estado_fluxo": "em_execucao"
  },
  "error": null
}
```

### Error codes

- `VALIDATION_ERROR` - status fora do catálogo ativo ou payload inválido
- `ORDER_FORBIDDEN` - OS existente, mas não atribuída ao técnico autenticado
- `ORDER_NOT_FOUND` - OS inexistente
- `ORDER_ATTACHMENT_NOT_FOUND` - anexo inexistente ou fora da OS solicitada
- `ORDER_ATTACHMENT_FILE_MISSING` - metadado existe, mas o arquivo físico não foi encontrado

## GET /api/v1/orders/{id}/closure

Dados para a tela de baixa (encerramento) da OS: status atual, opções de
encerramento (status com `status_final=1` ativos no catálogo) e resumo
financeiro do título a receber vinculado, se houver.

### Response

```json
{
  "status": "success",
  "data": {
    "order": {
      "id": 3578,
      "numero_os": "OS26060006",
      "status": "triagem",
      "status_nome": "Triagem",
      "estado_fluxo": "em_atendimento",
      "data_entrega": null,
      "valor_final": 300.0
    },
    "cliente_telefone": "11999998888",
    "opcoes_encerramento": [
      { "codigo": "entregue_reparado", "nome": "Equipamento Entregue" },
      { "codigo": "devolvido_sem_reparo", "nome": "Devolvido Sem Reparo" },
      { "codigo": "descartado", "nome": "Equipamento Descartado" }
    ],
    "financeiro": {
      "titulo_id": null,
      "valor_titulo": 300.0,
      "valor_movimentado": 0.0,
      "valor_aberto": 300.0,
      "total_movimentos": 0
    }
  },
  "error": null
}
```

### Error codes

- `ORDER_FORBIDDEN` - OS existente, mas não atribuída ao técnico autenticado
- `ORDER_NOT_FOUND` - OS inexistente

## POST /api/v1/orders/{id}/closure

Encerra a OS (baixa): status final + data de entrega, lançamento
financeiro opcional (reaproveitando o título a receber já vinculado ou
criando um novo a partir de `valor_final`) e notificação WhatsApp manual
opcional. Falha no envio da notificação não desfaz a baixa.

### Request body

```json
{
  "encerrar_como": "entregue_reparado",
  "data_entrega": "2026-06-27",
  "valor_recebido": 300.0,
  "forma_pagamento": "pix",
  "notificar_cliente": true,
  "observacao": "Equipamento testado na entrega."
}
```

- `encerrar_como` - obrigatório, deve ser um código com `status_final=1`
  ativo no catálogo `os_status`.
- `data_entrega` - obrigatório.
- `valor_recebido` - opcional; omitido ou zero encerra a OS com saldo em
  aberto, sem criar movimento financeiro.
- `forma_pagamento` - opcional, um de `dinheiro|cartao_credito|cartao_debito|pix|boleto|transferencia`.
- `notificar_cliente` - opcional; quando `true` e a OS tiver telefone de
  cliente, tenta enviar uma mensagem de encerramento por WhatsApp agora
  (envio único, sem agendamento).

### Response

```json
{
  "status": "success",
  "data": {
    "order": {
      "id": 3578,
      "numero_os": "OS26060006",
      "status": "entregue_reparado",
      "status_nome": "Equipamento Entregue",
      "estado_fluxo": "encerrado",
      "data_entrega": "2026-06-27",
      "valor_final": 300.0
    },
    "notificacao_enviada": true
  },
  "error": null
}
```

`notificacao_enviada` é `null` quando `notificar_cliente` não foi
solicitado, e `false` quando foi solicitado mas o envio falhou (a baixa
continua concluída normalmente).

### Error codes

- `ORDER_FORBIDDEN` - OS existente, mas não atribuída ao técnico autenticado
- `ORDER_NOT_FOUND` - OS inexistente
- `ORDER_CLOSURE_STATUS_INVALID` - `encerrar_como` fora do catálogo de status finais ativos
- `ORDER_CLOSURE_DATE_INVALID` - `data_entrega` inválida
- `VALIDATION_ERROR` - payload inválido (campos fora do formato esperado)

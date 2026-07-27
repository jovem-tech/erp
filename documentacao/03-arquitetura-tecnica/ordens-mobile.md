# Fluxo de OS Mobile

## Objetivo

Este fluxo entrega ao PWA mobile o primeiro trabalho operacional real do técnico: visualizar apenas as OS atribuídas a ele, abrir o detalhe da OS, consultar anexos controlados e alterar o status em campo.

## Contrato da API

### Listagem

- `GET /api/v1/orders`

#### Comportamento atual

- técnico: somente OS atribuídas a ele;
- usuário não técnico com `os:visualizar`: listagem geral com filtros administrativos.

### Detalhe

- `GET /api/v1/orders/{id}`

### Criação administrativa

- `POST /api/v1/orders`

### Edição administrativa

- `PUT/PATCH /api/v1/orders/{id}`

### Atualização de status

- `PATCH /api/v1/orders/{id}/status`

### Anexos

- `GET /api/v1/orders/{id}/photos/{photo}`
- `GET /api/v1/orders/{id}/documents/{document}`

## Regras de negócio

- A listagem mostra somente OS com `tecnico_id` igual ao usuário autenticado.
- A listagem administrativa aceita `status`, `search`, `technician_id`, `client_id`, `page` e `per_page`.
- O detalhe da OS traz cliente, equipamento, os últimos 5 registros de histórico, `status_disponiveis`, fotos e PDFs vinculados.
- As fotos do detalhe são carregadas sob demanda como blobs autenticados,
  sempre por rota interna derivada dos IDs da OS e da foto; a URL absoluta do
  payload não é reutilizada com o Bearer token.
- Miniaturas preservam a imagem inteira e revogam a URL local quando deixam de
  ser utilizadas. Documentos mantêm fallback textual e abertura autenticada.
- O status informado na atualização é validado em runtime contra o catálogo ativo `os_status`.
- O catálogo `os_status` é a fonte de verdade dos códigos aceitos.
- Quando o status muda, o backend atualiza `status` e `estado_fluxo` juntos.
- O `estado_fluxo` é derivado do campo `estado_fluxo_padrao` do catálogo.
- Se a OS existir, mas não pertencer ao técnico autenticado, a resposta é `403`.
- O detalhe da OS e o acesso aos anexos também retornam `403` quando o recurso existir, mas não estiver atribuído ao técnico.
- A criação e edição administrativas respeitam o mesmo RBAC central do restante da API.
- A validação de transições entre status não entra nesta fase.
- Cada alteração grava histórico em `os_status_historico` quando a tabela estiver disponível.

## Navegação durante a criação

- O pathname exato `/os/novo` substitui temporariamente a Bottom Nav global por
  `Início`, `Voltar`, `Próximo`, `Salvar` e `Cancelar`.
- Listagem, detalhe e edição de OS preservam a Bottom Nav global.
- `Próximo` depende da validade da etapa atual.
- O prazo de atendimento, selecionado entre 1, 3, 7, 15 ou 30 dias corridos,
  preenche a previsão de entrega; o backend recalcula a data a partir do prazo
  recebido para não confiar no relógio do dispositivo.
- A etapa `Extras` não existe mais. A decisão de gerar e enviar o PDF e o
  vínculo opcional de orçamento ficam em `Atendimento`.
- Na revisão, cada card precisa ser verificado explicitamente. O card verificado
  recebe fundo verde e qualquer edição posterior invalida a confirmação do item.
- `Salvar` depende da completude conjunta de cliente, equipamento, checklist
  aplicável, relato, técnico, prazo e de todos os cards da revisão confirmados.
- A completude no frontend orienta a experiência; autorização e validação
  definitivas continuam no backend central.
- A criação mantém a `idempotency_key` e acrescenta uma trava síncrona contra
  submissões concorrentes.
- Cadastros novos e edições pendentes de cliente/equipamento permanecem apenas
  em memória até o POST final. O backend aplica tudo na mesma transação da OS,
  com bloqueio pessimista e validação do vínculo equipamento-cliente.
- Quando `enviar_pdf_cliente=false`, o documento de abertura não é renderizado,
  não é persistido e não é enviado.
- Saídas com dados preenchidos exigem confirmação de descarte.

## Estrutura de resposta

### Listagem

```json
{
  "status": "success",
  "data": {
    "orders": []
  },
  "error": null,
  "meta": {
    "timestamp": "2026-06-22T00:00:00-03:00",
    "request_id": "req_...",
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

### Atualização

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
    "status_novo": "aguardando_reparo"
  },
  "error": null
}
```

### Detalhe

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
      "status_disponiveis": [
        {
          "codigo": "triagem",
          "nome": "Triagem"
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

## Segurança

- O frontend nunca acessa o banco diretamente.
- O backend decide se o técnico pode ver ou alterar a OS.
- Edições atômicas exigem, além de `os:criar`, as permissões específicas
  `clientes:editar` e/ou `equipamentos:editar`.
- O serviço usa listas permitidas de campos para impedir mass assignment e
  confirma que o equipamento selecionado pertence ao cliente da operação.
- O retorno `403` evita ambiguidade e mantém o bloqueio explícito.
- O frontend mobile consome a API com token Bearer armazenado localmente e renovado por refresh controlado.

## Observação

As transições permitidas entre status serão tratadas em uma fase posterior, quando o fluxo operacional estiver estabilizado. A Fase 6 apenas ampliou o mesmo recurso `/api/v1/orders` para uso administrativo sem quebrar o canal mobile.

# Contrato de API - Financeiro: Cartões e Taxas

## Consumo principal

O desktop consome a resposta agregada de cartões e taxas a partir de:

- `GET /api/v1/financeiro/cartoes`

## Simulação

- `POST /api/v1/financeiro/cartoes/simular`

## Catálogos

- `POST /api/v1/financeiro/cartoes/operadoras`
- `PATCH /api/v1/financeiro/cartoes/operadoras/{operadora}`
- `DELETE /api/v1/financeiro/cartoes/operadoras/{operadora}`
- `POST /api/v1/financeiro/cartoes/bandeiras`
- `PATCH /api/v1/financeiro/cartoes/bandeiras/{bandeira}`
- `DELETE /api/v1/financeiro/cartoes/bandeiras/{bandeira}`
- `POST /api/v1/financeiro/cartoes/taxas`
- `PATCH /api/v1/financeiro/cartoes/taxas/{taxa}`
- `DELETE /api/v1/financeiro/cartoes/taxas/{taxa}`
- `POST /api/v1/financeiro/cartoes/taxas-online`
- `PATCH /api/v1/financeiro/cartoes/taxas-online/{gatewayTaxa}`
- `DELETE /api/v1/financeiro/cartoes/taxas-online/{gatewayTaxa}`

## Payload esperado para simulação

```json
{
  "valor_bruto": 130,
  "operadora_id": 1,
  "bandeira_id": 1,
  "modalidade": "credito",
  "parcelas": 1
}
```

## Resposta esperada

```json
{
  "success": true,
  "simulation": {
    "valor_taxa": 5.2,
    "valor_liquido": 124.8,
    "taxa_percentual": 3,
    "taxa_fixa": 1.3
  }
}
```

## Regras de frontend

- nunca acessar o banco diretamente;
- tratar `401`, `403` e `422` com UX amigável;
- manter Select2 em todos os dropdowns visíveis;
- atualizar a UI sem refresh completo quando possível.

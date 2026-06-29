# Data Model: Financeiro - Cartões e Taxas no Desktop

## Entidades principais

### Operadora de maquininha

- `id`
- `nome`
- `descricao`
- `ordem_exibicao`
- `prazo_padrao_dias`
- `ativo`

### Bandeira

- `id`
- `nome`
- `ordem_exibicao`
- `ativo`

### Taxa por parcela

- `id`
- `operadora_id`
- `bandeira_id`
- `modalidade`
- `parcelas_inicial`
- `parcelas_final`
- `taxa_percentual`
- `taxa_fixa`
- `prazo_recebimento_dias`
- `observacoes`
- `ativo`

### Taxa online

- `id`
- `provider`
- `modalidade`
- `taxa_percentual`
- `taxa_fixa`
- `ordem_exibicao`
- `observacoes`
- `ativo`

## Observações

- Não há schema novo no desktop.
- O consumo é sempre via API central.
- O simulador usa catálogo ativo e retorna uma estrutura de cálculo pronta para exibição.

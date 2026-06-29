# Cartões e Taxas no Desktop ERP

## Resumo

O `frontends/desktop` passou a entregar o módulo financeiro de cartões e taxas com paridade funcional do legado `sistema-hml/financeiro/cartoes`, mantendo o shell do desktop, a sessão server-side e o consumo exclusivo da API central.

## O que foi entregue

- abas de operadoras, bandeiras, taxas por parcela, simulador e taxas online;
- cadastro, edição e desativação dos catálogos de cartões e taxas;
- simulação de recebimento líquido com retorno de taxa total, valor líquido e previsão de repasse;
- ajuda local dedicada ao módulo;
- Select2 obrigatório em todos os selects visíveis da tela;
- tratamento seguro de `401`, `403` e `422` sem expor token ao navegador;
- documentação, histórico de versão e inventário do Spec Kit atualizados.

## Contrato da API

O desktop consome o contrato do backend central em:

- `GET /api/v1/financeiro/cartoes`
- `POST /api/v1/financeiro/cartoes/simular`
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

## Observações de implementação

- nenhum fluxo acessa o banco diretamente;
- o contrato funciona com dados reais do backend central, sem duplicar regra no desktop;
- a tela segue o padrão Select2-first do canal desktop;
- a versão do sistema foi elevada para refletir a entrega.

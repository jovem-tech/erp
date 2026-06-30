# Catálogo de status das Ordens de Serviço

Atualizado em: `2026-06-29`

Fonte de verdade: catálogo ativo `os_status` do backend central.

## Lista completa

| Ordem | Código | Nome | Macrofase | Final | Pausa | Ativo |
| --- | --- | --- | --- | --- | --- | --- |
| 10 | `triagem` | Triagem | `recepcao` | Não | Não | Sim |
| 20 | `diagnostico` | Diagnóstico Técnico | `diagnostico` | Não | Não | Sim |
| 30 | `aguardando_avaliacao` | Aguardando Avaliação | `diagnostico` | Não | Não | Sim |
| 40 | `verificacao_garantia` | Verificação de Garantia | `diagnostico` | Não | Não | Sim |
| 50 | `aguardando_orcamento` | Aguardando Orçamento | `orcamento` | Não | Não | Sim |
| 60 | `aguardando_autorizacao` | Aguardando Autorização | `orcamento` | Não | Não | Sim |
| 70 | `aguardando_reparo` | Aguardando Reparo | `execucao` | Não | Não | Sim |
| 80 | `reparo_execucao` | Em Execução do Serviço | `execucao` | Não | Não | Sim |
| 90 | `cumprimento_garantia` | Cumprimento de Garantia | `execucao` | Não | Não | Sim |
| 100 | `retrabalho` | Retrabalho | `execucao` | Não | Não | Sim |
| 110 | `testes_operacionais` | Testes Operacionais | `qualidade` | Não | Não | Sim |
| 120 | `aguardando_peca` | Aguardando Peça | `interrupcao` | Não | Sim | Sim |
| 130 | `pagamento_pendente` | Pagamento Pendente | `interrupcao` | Não | Sim | Sim |
| 140 | `entregue_pagamento_pendente` | Entregue - Pendência Financeira | `interrupcao` | Não | Sim | Sim |
| 150 | `testes_finais` | Testes Finais | `qualidade` | Não | Não | Sim |
| 160 | `reparo_concluido` | Reparo Concluído | `concluido` | Sim | Não | Sim |
| 170 | `reparado_disponivel_loja` | Reparado, Disponível na Loja | `concluido` | Sim | Não | Sim |
| 180 | `garantia_concluida` | Garantia Concluída | `concluido` | Sim | Não | Sim |
| 190 | `irreparavel` | Irreparável | `finalizado_sem_reparo` | Sim | Não | Sim |
| 200 | `irreparavel_disponivel_loja` | Irreparável, Disponível para Retirada | `finalizado_sem_reparo` | Sim | Não | Sim |
| 210 | `reparo_recusado` | Reparo Recusado | `finalizado_sem_reparo` | Sim | Não | Sim |
| 220 | `entregue_reparado` | Equipamento Entregue | `encerrado` | Sim | Não | Sim |
| 230 | `devolvido_sem_reparo` | Devolvido Sem Reparo | `encerrado` | Sim | Não | Sim |
| 240 | `descartado` | Equipamento Descartado | `encerrado` | Sim | Não | Sim |
| 250 | `cancelado` | Cancelado | `cancelado` | Sim | Não | Sim |

## Observações operacionais

- `status_final = Sim` indica status terminal.
- `status_pausa = Sim` indica bloqueio ou espera fora da bancada produtiva.
- `grupo_macro` organiza a leitura do fluxo, mas o catálogo real deve continuar sendo validado pelo backend.
- `aguardando_orcamento` fica entre `aguardando_avaliacao` e `aguardando_autorizacao` no fluxo natural da assistência técnica.

## Fluxo real do andamento

### Caminho principal

`triagem -> aguardando_avaliacao -> diagnostico -> aguardando_orcamento -> aguardando_autorizacao -> aguardando_reparo -> reparo_execucao -> testes_operacionais -> testes_finais -> reparo_concluido -> entregue_reparado`

### Ramos reais do diagrama

- `verificacao_garantia -> cumprimento_garantia -> garantia_concluida`
- `pagamento_pendente -> aguardando_peca -> aguardando_reparo`
- `reparo_execucao -> retrabalho -> reparo_execucao`
- `reparo_recusado -> descartado`
- `irreparavel -> irreparavel_disponivel_loja -> devolvido_sem_reparo`
- `reparo_concluido -> entregue_pagamento_pendente -> entregue_reparado`
- `reparo_concluido -> reparado_disponivel_loja`
- `cancelado` como terminal de encerramento administrativo

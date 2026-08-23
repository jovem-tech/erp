# Cobrança indevida em encerramento sem cobrança (2026-08-23)

## O defeito

`OrderClosureService` sempre soube quais encerramentos **não cobram nada** do
cliente (`NON_BILLED_CLOSURE_STATUSES`): devolvido sem reparo, descartado,
entregue reparado sem custo e entregue em garantia. O comentário no código dizia,
com todas as letras:

> Encerramentos sem cobrança … **nunca geram lançamento financeiro**

O código honrava metade disso. Ignorava os **recebimentos** enviados na baixa,
mas seguia chamando `processReceipts()`, que chama `ensureReceivableTitle()` —
e este **cria o título a receber no valor final da OS**, incondicionalmente.

Resultado: um notebook descartado saía da bancada com uma cobrança de R$ 220,00
aberta contra um cliente que não devia nada.

### Alcance

Na base real: **24 títulos, R$ 2.050,00** em contas a receber contra clientes
sem dívida.

| Status da OS | Títulos | Valor |
|---|---:|---:|
| `descartado` | 14 | R$ 1.100,00 |
| `devolvido_sem_reparo` | 8 | R$ 740,00 |
| `entregue_reparado_sem_custo` | 2 | R$ 210,00 |

Não era um problema de exibição. Esse valor contava em **contas a receber, no
fluxo de caixa projetado e no DRE**. A Agenda apenas o tornou visível, ao listar
"Receber: Cobrança da OS" para OS que ninguém iria cobrar — foi assim que
apareceu.

## A correção

`OrderClosureService::settleNonBilledClosure()` substitui `processReceipts()`
nesses encerramentos, com três comportamentos:

| Situação | O que acontece |
|---|---|
| Nenhum título existe | Não cria nenhum |
| Título em aberto, **sem** movimento | Cancela — nada foi recebido, nada é devido |
| Título **com** movimento | **Não toca**, e registra evento na OS |

O terceiro caso é deliberado: movimento significa dinheiro que já entrou no
caixa (adiantamento antes de o equipamento se revelar irreparável). Cancelar
apagaria uma entrada real. Devolver ou reter é decisão humana, tomada no
Financeiro — o sistema registra o evento e sai do caminho.

Cancela, e não apaga: o histórico do título continua auditável, e é por
`status = cancelado` que o DRE por competência e o fluxo de caixa o excluem.

## Limpeza do que já estava gravado

```bash
php artisan os:cancelar-cobrancas-sem-cobranca            # simula
php artisan os:cancelar-cobrancas-sem-cobranca --aplicar  # executa
php artisan agenda:sincronizar-origens                    # reflete na agenda
```

Simula por padrão — mexer em título financeiro sem o operador ver antes o que
será tocado não é aceitável. A saída separa em duas tabelas o que será cancelado
e o que tem valor recebido e ficará intacto.

Efeito na base de desenvolvimento: contas a receber na agenda caíram de **30
para 5**, e o total em aberto de R$ 2.836,00 para **R$ 786,00** — o que sobrou
são OS em `triagem`, `aguardando_reparo` e `entregue_pagamento_pendente`, mais
dois lançamentos avulsos. Cobranças de verdade.

## Testes

`backend/tests/Feature/Api/V1/OrderFlowTest.php`

- os três encerramentos sem cobrança não deixam título em aberto;
- título preexistente sem movimento é cancelado na baixa;
- título com valor já recebido é preservado.

## Nota

`OrderClosureService::batchClosureCodes()` passou a delegar para
`nonBilledClosureStatuses()`, com o mesmo conteúdo. Quem precisa da lista
geralmente quer a regra financeira, não a baixa em lote, e ler
`batchClosureCodes()` num contexto financeiro esconde o motivo.

# Devolução e troca de venda

## Problema

O único jeito de desfazer uma venda hoje é **cancelá-la inteira**
(`specs/027-vendas-balcao-pdv`). Cliente que comprou três películas e quer
devolver uma não tem caminho: o operador cancela tudo e refaz, o que queima o
número da venda original, sujeita o histórico e desalinha a conferência do caixa.

Troca não existe de forma nenhuma — na prática é cancelar e vender de novo, com
o dinheiro voltando e saindo na mesma hora sem registro do que aconteceu.

## Objetivo

Devolver **parte** de uma venda, com o dinheiro voltando pela mesma forma em que
entrou, o produto voltando à prateleira e o caixa do dia refletindo a saída.
Troca é a composição de uma devolução com uma venda nova.

## Escopo

### 1. Devolução parcial, com saldo controlado

`venda_devolucoes` + `venda_devolucao_itens` registram quantidade devolvida
**por item da venda**. Uma venda pode ter várias devoluções ao longo do tempo, e
o sistema controla o **saldo devolvível** de cada linha: não é possível devolver
3 unidades de um item que teve 2 vendidas, nem devolver duas vezes a mesma
unidade.

Numeração `DV-YYMM-NNNNNN`, no mesmo formato de venda e orçamento.

A devolução é **imutável** depois de registrada, pela mesma razão da venda:
corrigir é assunto de uma operação nova, não de edição. Motivo é obrigatório.

### 2. Quanto volta

O valor devolvido de um item **não é** o total da linha: a venda pode ter tido
desconto geral, e o cliente pagou proporcionalmente menos.

```
valor_devolvido_do_item = total_do_item × (venda.total ÷ venda.subtotal)
```

Numa venda de subtotal 100 com desconto geral de 10 (total 90), devolver um item
de 50 devolve **45** — que foi o que o cliente efetivamente pagou por ele.

### 3. Como o dinheiro volta

**Na mesma forma da venda**, rateado proporcionalmente entre os pagamentos
originais. Venda de R$ 100 paga com R$ 40 em dinheiro e R$ 60 no cartão, ao
devolver R$ 50, devolve R$ 20 em dinheiro e R$ 30 no cartão. A sobra de
arredondamento vai para o maior pagamento, para o rateio fechar no centavo.

Cada parcela do reembolso vira um movimento de saída na conta que recebeu o
dinheiro na venda. O cartão não é estornado automaticamente na maquininha — o
sistema registra o estorno e o operador o processa na operadora.

### 4. Taxa de cartão não estornada

A operadora normalmente **não devolve a taxa**: numa venda de R$ 100 no crédito
com taxa de R$ 3,19, devolver os R$ 100 deixa a loja no prejuízo de 3,19.

A implementação disso é o **contrário** do que parece. A taxa já virou despesa
no momento da venda (`FinanceiroService::registerCardFeeExpense`), com
`origem_tipo = 'financeiro_movimento_cartao'`. O cancelamento total cancela essa
despesa junto, porque a venda deixou de existir. **A devolução não pode fazer
isso**: a venda aconteceu e a taxa foi realmente cobrada.

Ou seja: registrar a perda no DRE não exige lançamento novo — exige **não
reverter** o que já está lançado. A devolução apenas guarda
`valor_taxa_nao_estornada` para exibição, de modo que o operador veja o custo
real daquela devolução sem que nada seja contabilizado duas vezes.

### 5. Estoque

Volta à prateleira apenas o que saiu dela: itens de peça com `baixa_estoque`
marcado na venda. Serviço executado e item avulso não geram movimentação — a
tela precisa deixar isso explícito, senão o operador espera um movimento que não
vem.

A entrada é registrada em `movimentacoes` com `venda_id` da venda original e
motivo `"Devolução DV-…"`, preservando a rastreabilidade da saída original.

### 6. Caixa

Devolução em dinheiro **tira dinheiro da gaveta agora**, não do turno em que a
venda aconteceu. Venda de terça devolvida na quinta sai do caixa de quinta.

Consequência: `venda_devolucoes.caixa_sessao_id` aponta para o turno **aberto no
momento da devolução**, e o cálculo do esperado em
`CaixaSessionService::sessionTotals()` passa a subtrair as devoluções em dinheiro
do turno. Sem isso o operador fecharia com falta inexplicada.

Devolução em dinheiro com caixa fechado **abre o turno automaticamente**, pela
mesma razão e com a mesma mecânica da venda (`specs/028-caixa-sessoes`).

### 7. Financeiro

A devolução cria um título **a pagar** "Devolução de venda", vinculado à venda
original, na categoria e subgrupo DRE novos `Devolução de venda`.

Deliberadamente **não** estorna o título de receita da venda: a receita
aconteceu, e apagá-la reescreveria o DRE de um mês possivelmente já fechado.
Devolução é evento próprio, com data própria. O subgrupo fica sob "Despesas
Operacionais" — infla receita e despesa em paralelo, mas fecha certo no
resultado e deixa o volume de devoluções visível, que é o que a gestão precisa
enxergar.

### 8. Prazo

Devolução até **7 dias** da venda é operação normal. Depois disso exige
e-mail e senha de administrador — mesmo step-up do cancelamento de venda fora do
dia corrente. O administrador que liberou fica gravado em `autorizado_por`.

### 9. Troca

Troca é a composição, não uma entidade nova: uma devolução ligada a uma venda
nova por `venda_devolucoes.venda_troca_id`. A diferença entre o que voltou e o
que foi levado é cobrada ou devolvida na venda nova, pelo fluxo normal do PDV.

### 10. Comprovante

Cupom 80 mm da devolução pelo motor de PDF, tipo `venda_devolucao`, com os itens
devolvidos, o valor por forma de pagamento e o rodapé "Documento não fiscal".

## Fora de escopo

- Cancelar uma devolução já registrada.
- Vale-troca / crédito do cliente para uso futuro.
- Estorno automático na maquininha (integração com adquirente).
- Devolução de venda cancelada (não faz sentido: já foi desfeita por inteiro).
- Reposição de estoque em depósito diferente do de saída.
- Relatórios de devolução e curva de motivos — cabem na fase de relatórios.

## Riscos

**Devolução de venda antiga com caixa aberto de outro operador.** O dinheiro sai
do turno de quem está no balcão agora, não de quem vendeu. É o comportamento
correto fisicamente, mas a diferença aparece na conferência de quem está no
turno — por isso a devolução em dinheiro fica visível no relatório de fechamento.

**Devolução em cartão não move dinheiro sozinha.** O sistema registra o estorno;
processar na maquininha continua sendo trabalho manual do operador. Se ele
esquecer, o financeiro mostra a saída e o extrato da operadora não — divergência
que só a conciliação pega.

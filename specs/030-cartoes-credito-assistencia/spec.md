# Cartões de crédito da assistência

## Problema

O cartão de crédito é um concentrador silencioso de despesa. Uma única fatura
mistura conta fixa (plano de internet, mensalidade) com gasto variável (peça para
a OS de um cliente, cola, imprevisto), e o total oscila todo mês sem que ninguém
saiba de onde veio.

No sistema, `forma_pagamento = cartao_credito` existia só como rótulo: não havia
cadastro dos cartões da assistência, nenhum vínculo entre a despesa e o cartão em
que ela foi comprada, e nenhuma visão por fatura. Na prática, quando a fatura
chegava, não dava para reconciliar o valor com o que estava lançado.

Havia ainda uma confusão de nomes herdada: `financeiro.cartoes.*` ("Cartões e
Taxas") já existia, mas trata do lado oposto do balcão — a **maquininha**, com
operadora, bandeira e taxa, para **receber** do cliente. Nada a ver com o cartão
que a assistência usa para **comprar**.

## Objetivo

Saber, a qualquer momento, quanto cada cartão já consumiu no ciclo corrente —
antes de a fatura chegar — e quitar a fatura inteira numa ação só, mantendo cada
despesa como lançamento próprio para o DRE continuar correto.

## Escopo

### 1. Cadastro dos cartões

`financeiro_cartoes_credito`: nome, instituição, últimos 4 dígitos, **dia de
fechamento**, **dia de vencimento**, cor, observações e uma **conta financeira
vinculada** (FK para `financeiro_contas`, cadastrada em Contas e Saldos).

Cartão nunca é excluído, apenas desativado — despesas antigas continuam
apontando para ele. Desativar exige não haver fatura em aberto, senão o gasto do
ciclo corrente sumiria da tela sem ter sido pago.

### 2. Onde a tela vive, e por que não virou uma conta

A UI é uma aba dentro de **Contas e Saldos**, não um item novo no menu.

A ideia inicial era cadastrar o cartão como mais um `tipo` de `financeiro_contas`
(ao lado de caixa/banco/adquirente), para cair no mesmo grid de saldos. **Isso
não funciona.** O saldo daquela tela vem de `FinanceiroContaService::dashboard()`,
que só é atualizado quando uma **baixa** é registrada na conta. Como a despesa de
cartão fica pendente até a fatura ser paga, o "cartão-conta" ficaria zerado o
ciclo inteiro e só apareceria depois de quitado — exatamente o oposto da
visibilidade que a feature existe para dar, além de misturar dívida com dinheiro
em caixa nos três totais do topo.

Por isso o cartão tem cálculo próprio (soma das despesas do ciclo) e **nunca
entra** em "Disponível operacional", "Total em contas" ou "Posição total".

Permissão: reaproveita `contas_saldos` (a tela mora lá dentro).

### 3. Ciclo real da fatura

`FinanceiroCartaoCreditoService::resolveInvoiceCycle()` — método puro, sem I/O,
porque é a parte com mais casos de borda da feature:

1. A compra fecha no mês dela se o dia ainda não passou do fechamento; senão
   entra na fatura que fecha no mês seguinte.
2. O vencimento cai no mesmo mês do fechamento quando o dia de vencer é **maior**
   que o de fechar (fecha 5, vence 15). Quando é menor ou igual, vence no mês
   seguinte (fecha 28, vence 5).

Dias 29/30/31 são reduzidos ao último dia real do mês. Como o mês de vencimento
cresce junto com o de fechamento, dois ciclos do mesmo cartão nunca colidem — é
isso que torna **`(cartao_credito_id, data_vencimento)`** uma chave confiável de
fatura, usada tanto no agrupamento quanto na baixa em lote.

Editar fechamento/vencimento afeta só despesas futuras; as já lançadas mantêm a
fatura em que caíram.

### 4. Vínculo da despesa, e por que `forma_pagamento` não serve

`financeiro` ganhou `cartao_credito_id`, `data_compra`, `cartao_modalidade`,
`cartao_parcela_numero` e `cartao_parcelas_total` (todas aditivas e nullable).

Com cartão vinculado, `data_vencimento` deixa de ser digitada e passa a ser
**calculada pelo servidor** a partir de `data_compra` — se continuasse livre,
editar a data manualmente quebraria o agrupamento por fatura em silêncio. A
`data_competencia` vira a data da compra: o gasto foi incorrido ali, não no
vencimento da fatura.

**`cartao_modalidade` existe por um motivo não óbvio.** A tentação é derivar
crédito/débito de `financeiro.forma_pagamento`, mas essa coluna é sincronizada a
partir das **baixas** (`FinanceiroService::syncFromMovements()`) e volta a `NULL`
enquanto o título está pendente — justamente o estado em que uma despesa de
cartão passa quase toda a vida. Sem coluna própria, o crédito sumiria da fatura.
Pelo mesmo motivo, a tela de detalhe usa a modalidade como forma de pagamento
quando `forma_pagamento` ainda está vazia, em vez de exibir "Não informada".

### 5. Crédito x débito

Ambos vinculam a um cartão cadastrado, mas a lógica diverge:

- **Crédito**: entra na fatura do ciclo, vence com ela, é baixado na baixa em lote.
- **Débito**: o dinheiro sai da conta na hora, então vence no próprio dia da
  compra e **nunca compõe fatura**. Agrupá-lo criaria "faturas" de um dia só e
  marcaria como em aberto algo que já foi pago. A conta vinculada ao cartão é
  pré-selecionada na baixa.

Consequência no formulário de despesa: as opções de cartão são rotuladas
"(cartão da assistência)" quando o tipo é *a pagar*, e a baixa de um título a
pagar **não** oferece operadora/bandeira/taxa — isso é da maquininha e só existe
recebendo do cliente.

### 6. Parcelamento

Compra parcelada (ex.: ar-condicionado em 12x) cria **um título por parcela**,
cada um numa fatura consecutiva, dividindo o valor total. O resto da divisão vai
para a 1ª parcela, de modo que a soma devolva exatamente o total (R$ 100 em 3x →
33,34 + 33,33 + 33,33). A competência de todas é a data da compra.

`installmentDueDates()` anda de mês em mês **sobre o vencimento já resolvido**,
não recalculando o ciclo a partir de "compra + i meses". Com fechamento 28 e
compra no dia 29, a compra + 1 mês cai em 28/fev (dia limite do mês curto) e
voltaria para a **mesma fatura** da 1ª parcela.

Parcelamento é exclusivo de crédito e só vale na criação — reparcelar mudaria
valores e vencimentos de parcelas já em faturas. Também é mutuamente exclusivo
com "repetir nos próximos meses": aquele repete um valor sem fim (mensalidade),
este divide um total que acaba.

### 7. Pagar a fatura

`payInvoice()` reaproveita `FinanceiroService::registerMovement()` — a mesma
rotina da baixa individual — num laço com try/catch por item, seguindo o padrão
de `OrderWorkflowService::updateStatusBatch()`. Uma despesa problemática não pode
impedir a baixa das outras; a tela informa quais falharam e por quê.

**Recibo da baixa em lote.** Ao final de uma chamada que liquidou algo,
`payInvoice()` cria um lançamento próprio (`origem_tipo =
Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO`) representando o pagamento da
fatura em si — a "conta" que agrupa despesas fixas e variáveis. Sem ele, o
dinheiro que de fato saiu para pagar N despesas não aparecia como título
nenhum na tela de Lançamentos: só as despesas individuais.

O recibo vale **o que aquela chamada liquidou**, não o total da fatura — uma
fatura paga aos poucos (retry parcial, ou despesa lançada tarde numa fatura já
fechada) gera um recibo por chamada, e a soma deles reconcilia com a fatura.

Ele não é despesa fixa nem variável, então fica de fora de
`totaisFixoVariavel()`, dos filtros por `dre_fixo_mensal`, do DRE
(`impacta_dre = false`) e do fluxo de caixa (`impacta_fluxo_caixa = false`) —
as despesas que ele agrupa já entram nesses lugares individualmente, e
contá-lo de novo dobraria os totais. `cartao_modalidade` fica NULL para o
recibo nunca entrar na própria fatura que resume.

**Cancelar a baixa.** `cancelInvoicePayment()` desfaz a baixa em lote: apaga os
movimentos das despesas daquela fatura, devolve cada uma para `pendente` e
cancela os recibos. A fatura volta a ficar aberta e pode ser paga de novo — a
nova baixa gera um recibo novo, e o cancelado fica no histórico.

Estorna a despesa em vez de cancelá-la: a compra continua devida, o que deixou
de valer foi o pagamento. Como a baixa individual é bloqueada para despesa de
crédito, todo movimento nesses títulos veio de um `payInvoice()` — por isso
apagar todos é o estorno completo, sem risco de derrubar baixa avulsa legítima.

Exposto na tela da fatura em "Mais ações", só quando há baixa para estornar
(`pode_cancelar_baixa`) e para quem tem `contas_saldos:editar`.

Por mexer em dinheiro já conciliado, exige **confirmação de administrador**
(e-mail + senha) além da permissão de quem opera — mesma regra de excluir/
cancelar um lançamento, via `AdminCredentialVerifier` com rate limit próprio
(`financeiro-cartao-credito-estorno-admin-auth`). O modal da tela leva o
`<form>` dentro de si e submete nativamente: aqui a ação é única da fatura
aberta, então não precisa do `data-target-form` + JS que o modal de excluir
lançamento usa para servir N linhas de uma tabela.

**Compra não entra em fatura paga.** A data da compra tem que cair num ciclo
cuja fatura ainda esteja aberta: lançar numa fatura já paga mudaria um total
que o usuário conferiu e quitou com o banco. `resolveClassification()` recusa
no save (só quando a compra está de fato ENTRANDO na fatura paga — editar uma
despesa que já vive nela continua liberado), e `minimumPurchaseDate()` alimenta
o `min` do calendário.

A data mínima é o dia seguinte ao **fechamento** da última fatura paga, não ao
vencimento dela: é o fechamento que separa um ciclo do outro, e comprar no
próprio dia do fechamento ainda cai naquela fatura. O fechamento é obtido
aplicando `resolveInvoiceCycle()` a uma compra real daquela fatura, em vez de
invertido a partir do vencimento — a conta tem casos de borda demais (meses
curtos, vencimento antes do fechamento) para ser revertida com segurança.

A trava é da fatura **paga**, não da data passada: fatura vencida e ainda em
aberto continua aceitando o lançamento esquecido.

**Despesa esquecida em fatura paga.** Válvula de escape do bloqueio acima, em
"Mais ações" na tela de faturas do cartão: a compra que o banco cobrou naquela
fatura mas que ninguém registrou entra por `registerForgottenExpense()`, **já
quitada**, com movimento na mesma data/forma/conta da baixa da fatura (copiadas
do movimento mais recente dela). O total da fatura se corrige, ela continua
"Paga", e a saída de caixa sobe para o que realmente saiu do banco.

Marcar essa despesa como pendente inventaria uma dívida que não existe e
reabriria uma fatura que o banco já cobrou — por isso o formulário nem pergunta
status ou forma de pagamento. Sem esse caminho, corrigir exigiria cancelar a
baixa, lançar e pagar tudo de novo.

A data da compra fica limitada à **janela em que aquela fatura esteve aberta**:
de `data_abertura` (dia seguinte ao fechamento do ciclo anterior, via
`openingDateForDueDate()`) até `data_fechamento`. Fora dela a compra cairia em
outra fatura, e o título ficaria com vencimento de uma e data de compra de
outra. O save recusa, e o calendário do modal já nasce travado nesse intervalo
— trocar de fatura no select limpa uma data que tenha ficado fora.

**Status trava em pendente.** Despesa comprada no crédito não pode nascer
"paga": quem liquida é a fatura. `FinanceiroService::finalizeAfterSave()`
normaliza `pago`/`parcial` para `pendente` quando o título é de crédito e ainda
não tem movimento — sem isso a baixa automática do status marcaria a despesa
como paga e ela sairia do saldo em aberto da fatura sem a fatura ter sido paga.
Só vale para `tipo=pagar` no crédito: no débito o dinheiro sai na hora, e ao
RECEBER "cartão de crédito" é a maquininha (outro domínio). O formulário trava
o campo Status pela mesma regra — mas nunca num título que já tem baixa real,
senão mostraria "Pendente" para algo já pago pela fatura.

### 8. Listagem das faturas

Ordem **crescente**: a fatura corrente primeiro, seguindo para as futuras — ver a
próxima a vencer é o uso do dia a dia. Filtros por situação (aberta/vencida/paga),
mês e período. O KPI da fatura atual é calculado sem filtro, para não sumir do
topo quando o usuário filtra outro mês.

## Fora de escopo

**Rotativo automático.** Fatura vencida e ainda em aberto é sinalizada como
"Vencida", com aviso de que a operadora normalmente posterga o saldo para a
próxima fatura com juros. O sistema **não** faz esse rolo sozinho: a taxa varia
por cartão e por contrato, e mover valores entre faturas com um juro estimado
produziria números que não batem com a fatura real do banco — que é justamente o
problema que esta feature existe para resolver. Os juros devem ser lançados como
uma despesa do cartão.

Implementar isso depois exige um campo de taxa no cadastro do cartão e uma
decisão de regra: postergar o saldo inteiro, ou permitir pagamento parcial
(pagar o mínimo e rolar o resto).

## Nomenclatura

Rotas, controllers e views usam `cartoes-credito` / `cartoes_credito`,
deliberadamente distintos de `financeiro.cartoes.*` (maquininha/adquirente), que
já existia e trata do lado de receber.

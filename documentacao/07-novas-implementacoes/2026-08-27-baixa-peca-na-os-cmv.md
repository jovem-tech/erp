# Baixa de peça na OS: o CMV deixa de ser zero (2026-08-27)

**Spec:** `specs/038-baixa-peca-na-os/spec.md`
**Tipo:** funcionalidade nova (MINOR)

## O problema, medido

CMV **R$ 0,00 em 2.187 OS entregues e pagas**. Não era erro de cálculo:
**nenhum caminho do sistema criava movimentação de estoque a partir de uma OS.**

O consumo era 100% manual — sair da OS, ir à tela de estoque, achar a peça,
lançar a saída informando o número da OS num campo numérico livre. Ninguém fazia.
E o sistema só reclamava: `orders/show` exibia um alerta dizendo "registre a
movimentação para a margem real ficar correta", sem oferecer onde.

## O que foi entregue

**Botão "Aplicar peças do orçamento"** no lugar do alerta passivo, abrindo um
modal **pré-preenchido com o que falta aplicar**. Esse detalhe decide a adoção:
se o técnico tiver que digitar tudo de novo, ele não usa, e o CMV continua zero.

**A movimentação já é o registro de aplicação** (`os_id` + `saida`). Não se criou
tabela intermediária: `os_itens` tem colunas para isso desde o legado, ninguém as
escreve desde 30/04/2026, e ressuscitá-la seria um segundo lugar para a mesma
verdade.

### O motor único

Havia **duas** implementações de baixa que não se falavam: a do
`EstoqueController` (sem lock, truncando em zero) e a de vendas (correta, cujo
docblock explicava que não reusava a primeira justamente por causa da corrida).
Uma terceira porta tornaria o problema permanente.

`EstoqueMovimentacaoService` generaliza a que já estava certa:

- sempre em transação, participando da que já estiver aberta;
- `lockForUpdate()` **ordenado por id** — é o que evita deadlock entre dois
  caixas com carrinhos que se cruzam;
- saldo alterado só por expressão atômica, nunca read-modify-write;
- agregação por peça com `ksort`: duas linhas da mesma peça viram **um** ajuste,
  ou o lock protege a primeira e a segunda corre solta.

### Decisões de comportamento

**Saldo negativo com confirmação explícita** — mesma decisão do PDV. Recusar
faria o técnico contornar por fora do sistema, e aí some o registro inteiro, não
só o saldo.

**O erro nomeia os ofensores**: "Sem saldo para: Tela LCD (tem 1, pediu 5)".
Mensagem que não diz qual peça faltou obriga a caçar linha por linha.

**Autorização dupla** — `os:editar` **e** `estoque:editar`: aplicar peça mexe no
razão de estoque e no custo da OS.

## Testes

`OsAplicacaoPecaTest` (5), com destaque para **"CMV da OS deixa de ser zero"**:
recalcula a margem antes (custo de peças = R$ 0,00), aplica 2 peças de R$ 90, e
confirma R$ 180,00. É o número que estava zerado em 2.187 OS.

Mais: saldo decrementado atomicamente; saldo insuficiente bloqueia, lista os
ofensores e **não grava nada** (transação inteira volta); confirmação explícita
permite negativo; sem `estoque:editar` recebe 403.

Backend: **890 passando, 0 falhas**. Desktop: **422 passando**, 5
pré-existentes.

## O que isto NÃO faz

**O CMV histórico não muda.** Esta entrega faz o consumo *futuro* existir; as
2.187 OS já encerradas continuam sem movimentação — e o certo é que continuem.
Inventar consumo retroativo seria pior que o zero honesto.

**O custo ainda não é congelado.** Enquanto `movimentacoes.custo_unitario` não
existir (`036` Bloco B), o CMV usa `pecas.preco_custo` **atual**, não o do dia da
baixa. Alterar o custo de uma peça hoje reescreve a margem de OS antigas.

**As outras duas portas do razão continuam abertas.** `SaleStockService` e
`EstoqueController::storeMovement()` ainda não delegam ao motor único — está
registrado no Bloco B da spec.

# Baixa de peça na OS

> **Nota de numeração:** o roteiro original de estoque previa esta entrega como
> `037`. A `037` acabou usada pela precificação integrada, então a baixa na OS
> entra como `038`.

## Problema

O CMV era **R$ 0,00 em 2.187 OS entregues e pagas**. Não por erro de cálculo:
**nenhum caminho do sistema criava movimentação de estoque a partir de uma OS.**
O consumo de peça era 100% manual — o técnico teria de sair da OS, ir à tela de
estoque, achar a peça e lançar a saída informando o número da OS num campo livre.
Ninguém fazia.

A margem de contribuição (v5.57) e o DRE gerencial estavam prontos e famintos. O
encerramento de OS mostrava "Custo estimado: R$ 0,00" (corrigido na `037`, Fase
5) e continuava zerado porque não havia o que somar.

## Objetivo

Fazer o consumo de peça na OS ser um clique dentro da própria OS, e com isso
alimentar CMV, margem e encerramento de uma vez.

## Decisões

- **A movimentação JÁ É o registro de aplicação** (`os_id` + `saida`). Não se
  cria tabela intermediária: `os_itens` tem colunas para isso desde o legado,
  ninguém as escreve desde 30/04/2026, e ressuscitá-la seria um segundo lugar
  para a mesma verdade.
- **Motor único de movimentação.** Havia duas implementações de baixa que não se
  falavam — a do controller (sem lock, truncando em zero) e a de vendas
  (correta). Uma terceira porta tornaria o problema permanente:
  `EstoqueMovimentacaoService` generaliza a que já estava certa.
- **O modal vem pré-preenchido** com o que falta aplicar do orçamento aprovado.
  Esse detalhe decide a adoção: se o técnico tiver que digitar tudo de novo, ele
  não usa, e o CMV continua zero.
- **Saldo negativo é permitido com confirmação explícita** — mesma decisão do
  PDV. Recusar faria o técnico contornar por fora do sistema, e aí some o
  registro inteiro, não só o saldo.
- **O erro nomeia os ofensores.** Mensagem que não diz qual peça faltou obriga o
  técnico a caçar linha por linha.
- **Autorização dupla**: `os:editar` **e** `estoque:editar`. Aplicar peça mexe no
  razão de estoque e no custo da OS.

## Escopo

`EstoqueMovimentacaoService` (motor único) · `OsAplicacaoPecaService` ·
`OrderStockController` com `GET /orders/{id}/estoque` e
`POST /orders/{id}/estoque/aplicar` · botão e modal no detalhe da OS,
substituindo o alerta passivo.

## Fora de escopo

Custo congelado na movimentação, saldo anterior/posterior, `motivo_codigo`,
custo médio ponderado — são o Bloco B da `036`. Enquanto não existirem, o CMV
usa `pecas.preco_custo` **atual**, não o do dia da baixa.

Reserva de peça ao aprovar orçamento (`039` no roteiro).

## Riscos

- **O CMV histórico não muda sozinho.** Esta entrega faz o consumo *futuro*
  existir; as 2.187 OS já encerradas continuam sem movimentação, e o certo é que
  continuem — inventar consumo retroativo seria pior que o zero honesto.
- **`lockForUpdate()` é no-op em SQLite** e a suíte padrão roda SQLite: o teste
  de concorrência do motor único só provaria algo no grupo `mysql`.
- **Cancelar a baixa da OS não estorna o estoque**: a peça já foi fisicamente
  aplicada, e desfazer é decisão humana.

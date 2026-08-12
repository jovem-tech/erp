# Sessões de caixa (abertura, sangria e fechamento)

## Problema

O módulo de vendas (`specs/027-vendas-balcao-pdv`) registra corretamente cada
venda. O que ninguém consegue responder é a pergunta do fim do dia:

> O que está na gaveta bate com o que o sistema diz?

Não existe registro de quem abriu o caixa, com quanto de troco, o que saiu para
o cofre durante o dia, nem quanto foi contado no fechamento. Um erro de troco ou
um desvio só apareceria semanas depois na conciliação bancária, sem dono e sem
data.

Há um sintoma concreto disso hoje: **não existe conta financeira do tipo
`caixa`**. As únicas contas cadastradas são `inter` (banco) e `Ton`
(adquirente). Por isso toda venda em dinheiro obriga o operador a escolher uma
conta manualmente — `FinanceiroContaService::resolveAccountId()` exige conta
explícita quando não há padrão configurado para a forma de pagamento.

## Objetivo

Dar ao dinheiro de balcão um turno com dono: abertura declarada, movimentos
rastreáveis durante o dia, contagem no fechamento e diferença apurada — e, de
quebra, fazer a venda em dinheiro parar de pedir conta ao operador.

## O que já existe e é reaproveitado

A máquina financeira serve quase inteira:

- `financeiro_contas` já tem o tipo `caixa` no enum e no `typeOptions()`;
- `financeiro_conta_movimentos` já registra saldo inicial, ajuste e
  transferência, com natureza entrada/saída;
- `FinanceiroContaService::createTransfer()` já move dinheiro entre contas com
  lock, validação de saldo e data — que é literalmente o que a sangria é
  (caixa → banco);
- `accountBalance()` já calcula saldo unindo movimentos de venda e movimentos
  patrimoniais.

**O que falta não é máquina financeira — é a camada de turno.** Um período com
operador, abertura, fechamento e conferência.

## Escopo

### 1. A sessão

`caixa_sessoes` guarda o turno: conta de caixa, operador, abertura (quem,
quando, quanto), fechamento (quem, quando, quanto foi contado), o esperado
calculado e a diferença. Só pode existir **uma sessão aberta por conta de
caixa** por vez.

A assistência opera com **um único ponto de caixa**. O sistema cria e usa uma
conta "Caixa da loja" automaticamente, e o operador nunca escolhe qual caixa —
uma tela a menos no fluxo. O modelo de dados suporta N pontos (a sessão aponta
para `conta_financeira_id`), então abrir um segundo caixa no futuro é cadastro,
não migration.

### 2. Abertura automática

Se alguém vender em dinheiro com o caixa fechado, **a primeira venda abre o
turno sozinha**, herdando como troco inicial o valor contado no fechamento
anterior (ou zero, na primeira vez de todas). A sessão fica marcada com
`abertura_automatica` e o valor de abertura pode ser corrigido enquanto o turno
estiver aberto.

Decisão consciente: bloquear a venda seria o controle mais rígido, mas travaria
o atendimento toda vez que alguém esquecesse de abrir — e vai esquecer. A
abertura automática mantém o vínculo turno↔venda, que é o que dá sentido à
conferência, sem custo operacional.

Abertura explícita continua existindo, e é o caminho normal: o operador declara
o troco que colocou na gaveta.

### 3. Sangria e suprimento

`caixa_movimentos` registra as duas operações, sempre com motivo obrigatório:

- **Sangria** — dinheiro sai da gaveta. Quando vai para uma conta (banco,
  cofre), gera uma transferência real via `createTransfer()`, e a sessão guarda
  o `transferencia_id`. Sem conta de destino, é só uma saída registrada.
- **Suprimento** — dinheiro entra na gaveta (troco reforçado).

### 4. Fechamento cego

O sistema **não mostra o valor esperado antes da contagem**. O operador conta a
gaveta e digita o que encontrou; só então aparecem esperado, contado e
diferença.

Isso é deliberado: mostrar o esperado antes transforma a conferência em "digitar
o número que o sistema quer" e o controle perde inteiramente o sentido.

O esperado é:

```
valor_abertura
  + vendas em dinheiro do turno (não canceladas)
  + suprimentos
  − sangrias
```

Troco não entra na conta separadamente: numa venda de R$ 50 paga com R$ 100, a
gaveta ganha 100 e devolve 50, então o líquido é exatamente o `valor` do
pagamento. Cartão e Pix não entram na conferência — não passam pela gaveta.

Fechar não bloqueia nada: a diferença é registrada, não impede o fechamento.
Quem precisa agir sobre ela é a gestão, com o número na mão.

### 5. Vínculo com a venda

`vendas.caixa_sessao_id` é preenchido na finalização quando há pagamento em
dinheiro. É o que permite o relatório "fechamento do dia por operador" e o que
faz o cancelamento de uma venda descontar corretamente do esperado.

Efeito colateral desejado: **a venda em dinheiro para de pedir conta ao
operador**. A conta passa a ser a da sessão aberta.

### 6. Permissões

Módulo RBAC novo, slug `caixa` (precisa entrar em
`RbacAuthorizationService::DEFAULT_MODULES`, que hoje não o contém):

- `visualizar` — ver o turno e o histórico;
- `criar` — abrir caixa;
- `editar` — sangria, suprimento e fechamento;
- `excluir` — reabrir sessão fechada (correção, exige credencial de admin).

Herança: quem tem `vendas:visualizar` recebe `caixa:visualizar`; quem tem
`vendas:criar` recebe `caixa:criar` e `caixa:editar`; quem tem
`financeiro:editar` recebe `caixa:excluir`.

### 7. Telas

- **Caixa** — a tela do turno: se fechado, botão de abrir; se aberto, totais
  (abertura, vendas em dinheiro, suprimentos, sangrias), lista de movimentos e
  botões de sangria, suprimento e fechar.
- **Fechamento** — campo de contagem, sem nenhuma pista do esperado. Depois de
  confirmar, mostra o comparativo e oferece o relatório.
- **Histórico** — sessões fechadas com operador, período, esperado, contado e
  diferença em destaque.
- **Relatório de fechamento** em 80 mm, pelo mesmo motor de PDF do cupom.

## Fora de escopo

- Múltiplos caixas simultâneos na UI (o modelo suporta, a tela assume um).
- Conferência por denominação de cédula (contar quantas notas de 50).
- Fechamento parcial / troca de turno sem fechar.
- Sangria automática por limite de valor na gaveta.
- Conciliação de cartão (o repasse da adquirente já tem tratamento próprio em
  `financeiro_movimentos_cartao`).

## Riscos

**A conta "Caixa da loja" nasce com saldo zero.** Se já houver dinheiro físico
na gaveta quando o módulo entrar, a primeira abertura precisa declarar esse
valor — senão a primeira conferência acusa sobra.

**Vendas em dinheiro anteriores a esta entrega** ficaram sem sessão e sem conta
de caixa (foram para a conta que o operador escolheu na hora). Elas não entram
em nenhuma conferência retroativa, e isso é correto: não havia turno.

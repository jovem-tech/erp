# Preço sugerido no cadastro de peça (2026-08-27)

**Spec:** `specs/037-precificacao-integrada-ao-fluxo/spec.md` (Fase 2)
**Tipo:** integração do motor de precificação (MINOR)

## O problema

O formulário de peça **não tinha uma linha de JavaScript**. `preco_venda` era
digitação livre: o operador fazia custo × margem de cabeça e digitava o
resultado. A ironia estava registrada na spec — o campo *código* já trazia
sugestão automática ("Será sugerido se ficar em branco"); o preço, que é a
decisão que dá lucro, não.

## O que foi entregue

**Sugestão que pré-preenche sem nunca destruir digitação.** A regra do "sujo":

| Situação | Comportamento |
|---|---|
| Peça nova, campo vazio | Preenche sozinho e sai de cena |
| Peça nova, já digitada | Dica com botão `[Aplicar]` — não toca no campo |
| Edição de peça existente | Nunca sobrescreve; só dica |
| Erro de validação (`old()`) | Nasce sujo — sobrescrever apagaria o que o operador acabou de digitar |

O campo vira "sujo" de forma permanente na primeira tecla.

**Dispara em `change`/`blur`, nunca em `input`**, com debounce de 400 ms. Em
`input`, a consulta calcularia a sugestão a partir do `1` parcial de quem ainda
está digitando `129,90`.

**A armadilha do `respeitar_preco_venda`.** Quando o preço de tabela é maior que
o calculado, `valor_recomendado` devolve **o próprio preço que já está lá** — e
a dica sugeriria exatamente o número que o operador digitou, lendo como
quebrada. `buildPieceQuote()` passou a expor também `valor_calculado`, e a tela
mostra os dois:

> `Calculado R$ 157,00 · sugerido R$ 250,00 (mantém seu preço de tabela)`

## Autorização: o detalhe que decide adoção

O simulador exigia `financeiro:visualizar`. Quem cadastra peça no dia a dia é
frequentemente um **estoquista sem permissão financeira nenhuma** — manter a
exigência deixaria o preço sugerido inacessível justamente para quem digita o
preço.

Passou a aceitar `financeiro:visualizar` **ou** `precificacao:visualizar`
**ou** `estoque:criar|editar`, com a resposta **redigida por visibilidade**: o
estoquista recebe o valor sugerido e o semáforo; a composição de custo
(`preco_custo_referencia`, `valor_margem`, `percentual_encargos`, `preco_base`)
não existe no payload dele.

A sugestão é **conveniência, não requisito**: falha na consulta esconde a dica e
nunca impede o cadastro da peça.

## Testes

`PrecificacaoSugestaoPecaTest` (4): estoquista recebe sugestão sem composição;
quem tem financeiro recebe completa; quem não tem nenhuma das duas recebe 403
com `PRECIFICACAO_NAO_AUTORIZADO`; `valor_calculado` exposto além do
recomendado.

`EstoqueTest` (desktop): o formulário carrega os ganchos.

**Achado ao escrever os testes:** o número correto é **157**, não 160. Os
componentes de encargo semeados (4+5+3 = 12%) **sobrescrevem** o percentual
manual de 15% quando `usar_componentes` está ligado — comportamento correto de
`buildPieceRules()`, e uma boa ilustração de por que a Fase 6 precisa dar tela
para esses componentes: hoje o motor depende de dados só alcançáveis por SQL
direto.

Backend: **873 passando, 0 falhas**. Desktop: **420 passando**, 5
pré-existentes.

## Armadilha operacional

O cache de rotas do desktop precisa ser reconstruído: uma rota nova não existe
até `route:cache` rodar **naquele app**, e o sintoma é um 500 seco com
`Route [estoque.suggest-price] not defined`.

## Próximo

Fase 3 — custo-hora calculado dos custos fixos reais do DRE + cadastro de
serviço. É a fase que fecha o ciclo: custo fixo → preço → margem → DRE.

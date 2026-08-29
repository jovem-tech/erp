# Orçamento com custo e margem por linha (2026-08-27)

**Spec:** `specs/037-precificacao-integrada-ao-fluxo/spec.md` (Fase 4)
**Tipo:** integração do motor de precificação (MINOR)

## O que estava errado

`orcamento_itens` tinha 9 colunas de precificação. **Oito eram gravadas com
`0` literal** e nenhuma era lida. `modo_precificacao` era a string `'manual'`
hard-coded em cinco lugares — três no JS, uma num campo oculto e uma no service.
A tabela guardava campos que não respondiam nada.

E `orcamentos/show.blade.php` prometia, na legenda: *"Serviços e peças com
**custo, margem** e observações por linha."* A tabela tinha Tipo, Descrição,
Qtd, Valor unit., Desconto, Acréscimo, Total. **A legenda mentia.**

## O que foi entregue

`resolveItemReferenceData()` passou a chamar o motor de precificação nos dois
ramos (peça e serviço). As colunas carregam cotação real.

### A decisão que importa: margem cobrada, não margem-alvo

`percentual_margem` guarda a margem **efetivamente cobrada**
(`valor_unitario − preco_custo_referencia`), não a meta das configurações.
Guardar a meta faria a coluna dizer 45% numa linha que o vendedor descontou para
5% — mentira gravada, e pior que coluna vazia.

A meta mora em `valor_recomendado`, e é contra ela que o semáforo compara.

### `modo_precificacao` resolvido no servidor

Deixou de ser literal e passou a ser resolvido por **comparação**: `sugerido`
quando bate com o recomendado, `tabela` quando bate com o catálogo, `avulso` sem
referência, `manual` no resto. Vindo do cliente, a coluna registraria intenção
declarada, não fato.

### O snapshot que não fazia snapshot

`syncItems()` **apaga e reinsere tudo a cada save**. Enquanto os campos eram
zeros isso era inofensivo; com cotação real, editar a observação de um orçamento
aprovado o **reprecificaria pelas configurações de hoje**.

Agora, em orçamento fechado (aprovado, pacote aprovado, pendente de OS,
rejeitado ou vencido), a cotação das linhas anteriores é preservada em vez de
recalculada. Tem teste: aumenta o custo da peça depois da aprovação e o
orçamento continua com o custo do dia em que foi aprovado.

### A legenda deixou de mentir

A tabela ganhou as colunas **Custo unit.** e **Margem** (com semáforo e aviso de
"abaixo do recomendado"), e a frase virou condicional — quem não tem permissão
financeira lê "Serviços e peças com valores e observações por linha".

O payload também passou a ser redigido: `preco_custo_referencia`,
`valor_margem`, `percentual_margem`, `preco_base` e `valor_encargos` **não
existem** no JSON de quem não pode vê-los. `valor_recomendado` e
`modo_precificacao` continuam para todos — o primeiro é o piso, e quem vende
precisa saber que passou dele.

## Dois achados ao escrever os testes

**A categoria vence os componentes globais.** A peça de teste nasce na categoria
"Insumos", que tem override próprio (`encargos 5%`, `margem 20%`) e vence os
componentes globais de 12%. Comportamento correto de `lookupCategoryOverride()`
— e mais uma evidência de que a Fase 6 precisa dar tela para esses parâmetros:
hoje eles só são alcançáveis por SQL direto.

**`sugerido` vence `tabela` no empate.** Com `respeitar_preco_venda` ligado e o
calculado abaixo do preço de tabela, o recomendado **vira** o próprio preço de
tabela — então os dois coincidem, e o desempate documentado (seguir a
recomendação é a informação mais forte) decide.

## Testes

`OrcamentoPrecificacaoItemTest` (4): cotação real no lugar dos zeros; margem
cobrada e não a meta; modo resolvido por comparação; orçamento aprovado não é
reprecificado ao editar.

Backend: **883 passando, 0 falhas**. Desktop: **421 passando**, 5
pré-existentes. `BudgetFlowTest` intocado.

## Fora desta entrega

`MargemPrevistaService` (margem prevista no orçamento e no PDV) foi adiado — a
Fase 4 já entregou o que muda a decisão do operador, e a previsão depende de
decidir como tratar comissão e taxa antes de haver pagamento. Registrado na
`tasks.md`.

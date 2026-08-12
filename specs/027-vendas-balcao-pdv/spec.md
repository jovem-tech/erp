# Módulo de Vendas (balcão/PDV)

## Problema

A assistência vende película, carregador, cabo USB, capa, fone, celular,
monitor e outros produtos no balcão — vendas que **não têm nada a ver com uma
Ordem de Serviço**. Hoje não existe onde registrar isso.

O único lugar do sistema com estrutura real de itens (quantidade × preço
unitário, desconto, acréscimo, subtotal, total) é o **Orçamento**, e ele existe
para alimentar uma OS. A OS, por sua vez, exige `cliente_id` **e**
`equipamento_id` NOT NULL: não há como "abrir uma OS" para vender uma película
a quem passou na loja.

Na prática, hoje essas vendas seguem um de dois caminhos, ambos ruins:

- ficam fora do sistema (caderno, bloco de anotações, nada);
- entram como lançamento avulso no Financeiro — que registra o dinheiro, mas
  não sabe **o que** foi vendido, não dá baixa no estoque, não calcula margem
  e não emite comprovante.

O efeito colateral é que o estoque nunca se mexe. A tabela `movimentacoes` tem
zero linhas desde sempre: nenhuma peça vendida jamais saiu do estoque no
sistema. Por consequência, `os_margem.custo_pecas` é sempre 0 e todo indicador
de margem do sistema está superestimado.

## Objetivo

Criar um módulo de vendas de balcão que registre a venda com itens, dê baixa no
estoque, lance o recebimento no Financeiro (inclusive cartão com taxa e
parcelas), emita comprovante em cupom 80 mm e permita cancelamento com estorno
— reaproveitando os motores que o sistema já tem.

## Escopo

### 1. A venda

Uma venda é **imutável depois de concluída**. Não há rascunho no servidor: o
carrinho vive no navegador e vai num único POST atômico. Correção se faz
cancelando e revendendo. Essa decisão elimina máquina de estados, reserva de
estoque e locks longos — que é o que costuma tornar um PDV caro e frágil.

Numeração `VD-YYMM-NNNNNN`, com reset mensal, no mesmo formato do orçamento
(`ORC-YYMM-NNNNNN`).

Cada venda registra: cliente (cadastrado **ou** consumidor final, com nome e
CPF/CNPJ opcionais), vendedor, data, itens, desconto e acréscimo (por item e no
total, em R$ ou %), pagamentos, custo total e margem.

O vínculo com OS é **opcional** (`vendas.os_id`), para o caso de o cliente levar
um acessório junto ao aparelho que está em conserto. Não é o fluxo principal.

### 2. Itens

Três tipos, no mesmo desenho já usado em `orcamento_itens` (`tipo_item` +
`referencia_id`):

- **peça** — vinda do cadastro de estoque, com baixa de saldo;
- **serviço** — vinda do cadastro de serviços (instalação da película, por
  exemplo), sem estoque;
- **avulso** — descrição digitada na hora, sem cadastro.

Código e descrição são **congelados** no item (`codigo_snapshot`): renomear ou
encerrar uma peça não pode reescrever o histórico de vendas.

A baixa de estoque é uma flag explícita por item (`baixa_estoque`), não uma
dedução de `tipo_item === 'peca'`. Isso permite vender uma peça cadastrada sem
mexer no saldo — brinde, item consignado, item cujo saldo o operador sabe estar
divergente.

### 3. Estoque

A baixa é transacional, com lock por peça em ordem determinística (para não
travar dois caixas simultâneos) e decremento atômico. A saída fica registrada
em `movimentacoes` com `venda_id`, `venda_item_id` e motivo `"Venda VD-…"` — as
duas colunas de vínculo são novas; a tabela hoje só conhece `os_id`.

**Saldo insuficiente não bloqueia a venda.** O sistema avisa quais itens estão
sem saldo e o operador confirma explicitamente ("Vender assim mesmo"); a venda é
marcada com `estoque_divergente` e o saldo pode ficar negativo. Essa decisão é
deliberada: o cadastro de estoque hoje tem 9 peças e nunca foi movimentado, então
bloquear a venda travaria o balcão por um defeito de cadastro, não de operação.
O saldo negativo é o sinal honesto de que o inventário precisa de acerto.

O cancelamento **estorna** o estoque com uma movimentação de entrada. Nunca
apaga a movimentação de saída — o histórico precisa mostrar saída e entrada,
porque é isso que concilia com a contagem física.

### 4. Financeiro

A venda cria um título a receber (`financeiro`) na categoria **"Venda de
balcão"** — categoria e subgrupo DRE novos, porque a categoria existente "Venda
de peças" está mapeada ao subgrupo "Serviços e peças de OS", semanticamente
errado para balcão.

O pagamento pode ser **misto** (dinheiro + cartão + Pix na mesma venda), com
troco calculado sobre o valor recebido em dinheiro. Cartão passa pelo motor de
taxas existente: operadora, bandeira, modalidade, parcelas, taxa, prazo de
repasse e despesa automática da taxa.

**Venda fiada é permitida.** Se a soma dos pagamentos for menor que o total, a
venda fecha, o estoque baixa e o título fica `pendente`/`parcial`, cobrável como
qualquer outro título. O Financeiro já suporta isso.

O vínculo é uma coluna nova `financeiro.venda_id`, espelhando `os_id`. Não se
usa `origem_tipo`/`origem_id` para isso: apesar do nome, `origem_id` não é um
morph — é um `belongsTo(FinanceiroMovimento)`, e gravar o id da venda ali
carregaria um movimento alheio de mesmo id, em silêncio.

Cancelar a venda cancela o título pelo caminho que já existe
(`FinanceiroService::cancel()`), que estorna os movimentos e as despesas de taxa
de cartão derivadas.

### 5. Comprovante

Cupom **80 mm** pelo motor de PDF central, que já suporta esse formato. Novo
tipo de documento `venda_comprovante`, com template padrão semeado e editável na
tela de Modelos PDF. Rodapé: **"Documento não fiscal"**.

O tipo é independente da Central Documental da OS — não aparece lá e não
dispara gatilho automático de OS.

### 6. Preparação fiscal (sem emissão)

Não há emissão de NFC-e/NF-e/SAT neste escopo. Mas o terreno fica preparado,
para que a integração futura não exija ALTER numa tabela já grande:

- colunas fiscais em `pecas`: `ncm`, `cest`, `cfop_venda`, `origem_mercadoria`,
  `cst_icms`, `csosn`, `unidade_tributavel` — todas nullable, numa aba "Fiscal"
  **recolhida** no formulário de estoque, para não pesar o cadastro do dia a dia;
- dados fiscais da empresa em `configuracoes` (tabela chave/valor já existente),
  sem tabela nova: regime tributário, CRT, inscrição estadual, inscrição
  municipal, CNAE.

Também entram agora `pecas.codigo_barras` e `pecas.unidade`, porque a busca do
PDV já reconhece código de barras.

### 7. Permissões

O slug `vendas` **já existe** em `RbacAuthorizationService::DEFAULT_MODULES` e há
uma linha órfã `modulos` id=15 sem código associado. A migration de seed adota
essa linha (`updateOrInsert` por slug) em vez de duplicar.

Herança de permissões: quem tem `os:visualizar` recebe `vendas:visualizar`;
`os:criar`/`os:editar` recebem `vendas:criar`/`vendas:editar`; `financeiro:editar`
recebe `vendas:excluir` (que é o cancelamento).

### 8. Telas

- **Listagem** com filtros de período, status, vendedor e forma de pagamento, e
  cards de total vendido, número de vendas, ticket médio e margem.
- **PDV** em duas colunas, sem wizard e sem passo obrigatório de cliente: busca
  única com autofoco permanente (nome, código `PC00001` ou código de barras),
  carrinho com saldo disponível visível, e painel de pagamentos com troco em
  destaque. Atalhos F2 finalizar, F4 cliente, Esc limpar.
- **Detalhe** com itens, pagamentos (taxa e líquido), links para o título
  financeiro e para as movimentações de estoque geradas.
- **Cancelamento** com motivo obrigatório; se a venda não é do dia corrente,
  exige credencial de administrador (fluxo de step-up que já existe).

## Fora de escopo

Explicitamente **não** entram nesta entrega:

- **Controle de caixa**: abertura, fechamento, sangria, suprimento, conferência
  cega, relatório por operador. Não existe nada disso no sistema hoje; é um
  módulo próprio (fase 2).
- **Devolução e troca parcial** — só cancelamento total da venda (fase 3).
- **Edição de venda concluída** e rascunho/venda em espera.
- **Emissão fiscal** de qualquer espécie (fase 4, spec própria).
- **Impressão térmica direta** (ESC/POS), gaveta de dinheiro, corte automático —
  o cupom é PDF 80 mm aberto no navegador (fase 5).
- **Comissão de vendedor** — `vendedor_id` fica gravado para quando o cálculo
  existir (fase 3).
- **Parcelamento real** (gerar N títulos a receber). Hoje o sistema só tem
  parcelas como metadado de cartão, e isso não muda aqui.
- Custo médio ponderado, lote/série, multi-depósito, reserva de estoque.
- Multi-filial. O sistema não tem multi-tenancy e não ganha um agora.

## Riscos

**O estoque está vazio na prática.** `pecas` tem 9 registros e `movimentacoes`
tem 0 linhas. O módulo funciona, mas estoque e margem só ganham valor depois que
alguém cadastrar os produtos e fizer a carga inicial de saldo. Isso é trabalho
operacional, não de desenvolvimento, e é o maior risco de o módulo "não pegar".

**Sem nota fiscal**, o comprovante é não fiscal. Se a operação precisar emitir,
é um projeto próprio.

**Sem controle de caixa**, o fechamento do dia continua sendo conferido fora do
sistema até a fase 2.

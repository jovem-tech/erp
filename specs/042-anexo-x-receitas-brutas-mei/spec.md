# Anexo X — Relatório Mensal das Receitas Brutas do MEI

**Norma:** Resolução CGSN nº 140, de 22 de maio de 2018, art. 106 e Anexo X.
**Depende de:** `specs/041-emissao-fiscal-nfse` (tabela `documentos_fiscais`).

## Problema

O MEI é obrigado a preencher o **Relatório Mensal das Receitas Brutas** até o
dia 20 do mês subsequente ao da percepção da receita, anexar a ele os
documentos fiscais de entrada e as notas que tiver emitido, e guardar o
conjunto pelo prazo decadencial. O sistema não produzia isso — o relatório era
preenchido à mão, fora do ERP, a partir de números que o próprio ERP já tem.

O formulário exige duas coisas que nenhum relatório existente fazia:

1. **Segregar a receita por atividade** — revenda de mercadorias (comércio),
   venda de produtos industrializados (indústria) e prestação de serviços.
   O DRE trata tudo como "Receita Operacional".
2. **Dentro de cada atividade, separar** o que teve documento fiscal emitido do
   que foi dispensado de emissão.

Os dados para as duas existem: `os.valor_pecas`/`os.valor_mao_obra` e
`os_itens.tipo` separam peça de serviço; `venda_itens.tipo_item` faz o mesmo no
balcão; `documentos_fiscais` (spec 041) sabe o que foi emitido. O que faltava
era cruzá-los.

## Requisitos

### R1 — O formulário, e só o formulário

O PDF reproduz o Anexo X exatamente como a norma o desenha: identificação
(CNPJ, empreendedor individual, período), os três blocos de atividade com as
linhas I a IX, o total geral X, local/data, assinatura do empresário e as duas
cláusulas de "ENCONTRAM-SE ANEXADOS A ESTE RELATÓRIO".

**Nada é acrescentado.** Acumulado do ano, limite do MEI, lista de receita sem
documento fiscal e relação de notas emitidas são informações de conferência que
vivem na TELA. O formulário é um modelo padronizado pela Receita Federal, e um
formulário com seções extras deixa de ser o formulário. Há teste que falha se
qualquer um desses termos vazar para o Blade.

As linhas IV/V/VI (indústria) saem sempre zeradas nesta base — assistência
técnica não industrializa — mas **continuam impressas**: são linhas do
formulário oficial.

### R2 — Duas invariantes

**O documento fiscal decide a COLUNA, nunca o TOTAL.** `I+II=III`, `IV+V=VI`,
`VII+VIII=IX`, `III+VI+IX=X`, e X não depende de `documentos_fiscais`. Emitir
uma nota não pode mudar quanto se faturou.

**X é igual à receita líquida do DRE do mesmo mês e regime.** Dois números
diferentes para o mesmo faturamento não são detalhe de tela: um deles está
errado e o usuário não tem como saber qual. A garantia é estrutural — os dois
relatórios leem as mesmas linhas, via `ReceitaBrutaSource` — e travada por
teste nos dois regimes.

### R3 — Dois regimes

Competência (entrega da OS / data da venda) e caixa (data do recebimento), com
seletor na tela e padrão competência. O regime usado sai impresso no rodapé do
PDF: um relatório assinado sem dizer por qual critério foi apurado não dá para
conferir depois.

### R4 — Segregação por atividade

| Origem | Comércio | Serviços |
|---|---|---|
| OS | `valor_pecas` | `valor_mao_obra` |
| Venda de balcão | `tipo_item` `peca` e `avulso` | `tipo_item` `servico` |
| Título manual (sem OS e sem venda) | — | integral, com alerta |

`os.desconto` é rateado proporcionalmente entre as duas partes — foi assim que
ele foi concedido, sobre o total. O resíduo de arredondamento fica no serviço,
e a soma fecha no líquido **por construção**: um centavo perdido por operação,
em 200 OS/mês, seria R$ 2,00 de divergência com o DRE.

Item avulso conta como **mercadoria**: numa assistência é cabo, capinha,
película, e mão de obra não é vendida no balcão como avulso. É escolha assumida,
não fato do banco — o total sai separado em `origens.avulsos_da_venda` para
conferência, e errar aqui não move X, só III contra IX.

### R5 — "Com dispensa" × "com documento emitido"

NFS-e cobre a parcela de serviço; NF-e/NFC-e cobrem a de mercadoria. Só
`status = emitido` cobre: cancelado e rascunho devolvem o valor para "com
dispensa", com alerta. A cobertura é limitada por `min()` ao valor da operação —
sem isso, uma nota englobando duas OS deixaria a coluna I negativa.

Documento com `os_id` **e** `venda_id` pertence à venda, sob pena de contar a
mesma receita duas vezes.

### R6 — Devoluções

Deduzem da atividade devolvida, começando pela coluna "com dispensa". A
devolução **não cancela** a nota já emitida — abater de II/VIII faria a coluna
deixar de bater com a relação de documentos que o próprio sistema imprime. O
excedente escorre para "com documento" e depois para a outra atividade; nenhuma
linha sai negativa por distribuição, e X não muda.

### R7 — Fechamento mensal

Encerrar a competência congela os valores. Sem isso, um lançamento retroativo
alteraria em silêncio um relatório já assinado e arquivado. Reabrir exige
`fiscal:encerrar` mais confirmação de administrador e um motivo; a versão
anterior fica guardada como evidência e o próximo encerramento grava `versao+1`.
Competência e caixa fecham separadamente.

O botão **Reconferir** recalcula ao vivo e mostra o diff contra o declarado — é
ele que denuncia dado de origem alterado. O `payload_hash_sha256` cobre o outro
vetor: prova que ninguém editou o JSON direto no banco.

### R7b — Um mês ou o ano inteiro

As ações da tela ficam num menu **Mais ações**. O download do formulário abre um
modal que aceita uma competência ou um ano-calendário; no segundo caso o PDF sai
com uma folha por mês, de janeiro a dezembro.

As duas saídas incluem o mesmo partial Blade do formulário — não são duas
versões dele. Meses em curso ou futuros levam aviso no rodapé para não serem
assinados: declarar R$ 0,00 para um mês que ainda não aconteceu seria declaração
falsa.

### R8 — Extras de tela

- **Acumulado do ano × limite do MEI** (R$ 81.000, proporcional aos meses de
  atividade no ano de abertura), com as faixas de excesso até 20% e acima.
  Oculto fora do MEI, onde o teto não existe. Meses já encerrados entram pelo
  valor **declarado**, não pelo recalculado.
- **Receita sem documento fiscal**, destacando tomador pessoa jurídica — a
  hipótese em que o MEI não é dispensado de emitir.
- **Relação de documentos emitidos**, em **PDF separado**: é o anexo que a
  última cláusula do formulário manda juntar, não uma seção dele. Lista por data
  de emissão, enquanto as colunas classificam a operação — divergência legítima,
  dita na tela e na ajuda.

### R9 — Regime tributário diferente de MEI

Menu e tela continuam. Empresa que migrou de MEI para Simples no meio do ano
ainda precisa do Anexo X dos meses em que era MEI, e sumir com um módulo por
causa de um select vira chamado de suporte. O que muda é um banner explicando
que a obrigação é exclusiva do MEI, e o bloco de limite fica oculto.

## Fora de escopo

- **Módulo de compras / notas de entrada.** O sistema não tem, então a primeira
  cláusula de "encontram-se anexados" fica como texto fixo do formulário, sem
  lista a gerar.
- **Envio automático ao contador.** O relatório é gerado e baixado; distribuir é
  decisão de quem assina.
- **DASN-SIMEI.** Declaração anual, outro documento.

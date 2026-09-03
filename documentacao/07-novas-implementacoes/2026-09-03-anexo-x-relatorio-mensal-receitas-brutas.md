# Anexo X — Relatório Mensal das Receitas Brutas do MEI (2026-09-03)

**Norma:** Resolução CGSN nº 140, de 22/05/2018, art. 106 e Anexo X
**Spec:** `specs/042-anexo-x-receitas-brutas-mei/spec.md`
**Tipo:** funcionalidade nova (MINOR)

## O problema

O MEI tem que preencher o Relatório Mensal das Receitas Brutas até o dia 20 do
mês seguinte, anexar as notas de entrada e as que emitiu, e guardar tudo pelo
prazo decadencial. Isso era feito à mão, fora do sistema, a partir de números
que o próprio sistema já tinha.

O formulário pede duas coisas que nenhum relatório do ERP fazia:

| Exigência do Anexo X | Estado anterior |
|---|---|
| Separar revenda de mercadorias × indústria × prestação de serviços | DRE trata tudo como "Receita Operacional" |
| Dentro de cada uma, separar com documento fiscal × com dispensa | Não existia |
| Linhas I a X na numeração da norma | Não existia |
| Local, data e assinatura do empresário | Não existia |
| Cláusulas de "encontram-se anexados" | Não existia |

Os dados existiam espalhados: `os.valor_pecas`/`os.valor_mao_obra`,
`venda_itens.tipo_item` e `documentos_fiscais` (spec 041). Faltava cruzá-los.

## O que foi feito

Tela em **Fiscal › Anexo X (MEI)**, dois PDFs e fechamento mensal.

### Menu "Mais ações" e escolha do período

As ações da tela ficam num dropdown **Mais ações**, no padrão que
`financeiro/index.blade.php` já usava: *Baixar Anexo X (PDF)*, *Relação de
documentos emitidos* e *Ajuda*.

O item de download abre um modal com duas opções: **um mês** (a competência
escolhida) ou o **ano inteiro**, que gera uma folha por mês de janeiro a
dezembro num PDF só. O formulário do modal é um GET direto para a rota de
download; o campo não escolhido vai `disabled`, porque campo desabilitado não é
serializado pelo navegador — é o que garante que o querystring saia com
exatamente um período em vez de depender da precedência do controller.

**As duas saídas imprimem o MESMO formulário**: `pdf.anexo-x` e
`pdf.anexo-x-anual` incluem `pdf.partials.anexo-x-formulario` e
`pdf.partials.anexo-x-estilo`. Duplicar o Blade produziria, na primeira correção
feita só de um lado, uma folha divergente entregue ao fisco como se fosse o
formulário. Há teste que compara as duas saídas com o partial.

**Meses que ainda não terminaram levam aviso no rodapé** — "competência em
curso" para o mês corrente, "competência futura — não assine esta folha" para os
seguintes. O bloco anual existe para ser conferido, mas assinar uma folha
declarando R$ 0,00 para dezembro em setembro seria declaração falsa. Mês
encerrado não recebe aviso nenhum: é a folha que se assina.

O bloco anual desliga o cálculo do acumulado do ano (que é extra de tela): com
ele ligado, doze relatórios varreriam doze meses cada.

### O PDF é o formulário oficial, e só ele

O Anexo X é um modelo padronizado pela Receita Federal. O PDF sai exatamente
como a norma o desenha — **nada é acrescentado**. Acumulado do ano, limite do
MEI e lista de receita sem documento fiscal são informações de conferência que
existem apenas na tela. A relação de notas emitidas, que a última cláusula do
formulário manda anexar, tem **download próprio**: é anexada ao relatório,
nunca embutida nele.

`AnexoXPdfTest::test_pdf_do_anexo_x_nao_contem_nenhum_dos_extras` falha se
qualquer um desses termos aparecer no Blade do formulário.

As linhas IV/V/VI (indústria) saem sempre R$ 0,00 nesta base, mas continuam
impressas: são linhas do formulário.

### Duas invariantes travadas por teste

**O documento fiscal decide a coluna, nunca o total.** Emitir uma NFS-e move
receita de VII para VIII e não altera III, VI, IX nem X.

**X é igual à receita líquida do DRE do mesmo mês e regime.** A garantia é
estrutural, não coincidência: criou-se
`App\Services\Financeiro\ReceitaBrutaSource`, fonte única das linhas de receita,
e `FinanceiroReportService` passou a delegar a ela nos cinco pontos em que
montava a receita inline. Os dois relatórios leem as mesmas linhas.

A extração preservou sutilezas que uma reescrita "equivalente" não acertaria: o
merge dos títulos `dre_fixo_mensal` vencidos fora da janela, o `excludeOs` que
traz de volta quem tem `venda_id` (porque `SalePaymentService` grava `os_id` no
título da venda), e o fato de `queryMovimentos()` não filtrar `tipo_movimento`.
Portão da extração: `FinanceiroReportTest` e `DocumentoFiscal` verdes **sem
editar nenhum teste**.

### Segregação por atividade

| Origem | Comércio | Serviços |
|---|---|---|
| OS | `valor_pecas` | `valor_mao_obra` |
| Venda de balcão | `tipo_item` `peca` e `avulso` | `tipo_item` `servico` |
| Título manual | — | integral, sinalizado em `origens.sem_classificacao` |

`os.desconto` é rateado proporcionalmente — foi assim que foi concedido, sobre
o total —, com o resíduo de arredondamento no serviço e soma fechando no
líquido por construção. A conta é `App\Support\RateioAtividade`, extraída de
`DocumentoFiscalService::valoresLiquidos()`: tem que ser a MESMA, senão o
documento fiscal e o relatório declarariam repartições diferentes da mesma OS.

Item avulso conta como mercadoria. É escolha assumida, exposta em
`origens.avulsos_da_venda` para conferência; errar aqui não move X, só III
contra IX.

### Com dispensa × com documento emitido

NFS-e cobre serviço, NF-e/NFC-e cobrem mercadoria, limitado por `min()` ao valor
da operação. Cancelado e rascunho não cobrem — o valor volta para "com
dispensa", com alerta. Documento com `os_id` e `venda_id` pertence à venda, sob
pena de contar a receita duas vezes.

Alertas na tela: `peca_sem_nfe`, `servico_sem_nfse`, `documento_cancelado`,
`documento_rascunho`, `documento_parcial`, `documento_excedente`,
`valor_diverge_do_xml`, `tomador_pj_sem_documento`,
`sem_classificacao_de_atividade`.

### Devoluções

Deduzem da atividade devolvida, começando pela coluna "com dispensa": a
devolução não cancela a nota já emitida, e abater de II/VIII faria a coluna
deixar de bater com a relação de documentos. O excedente escorre para "com
documento" e depois para a outra atividade — nenhuma linha sai negativa por
distribuição, e X não muda.

### Fechamento mensal

`anexo_x_fechamentos` guarda as dez linhas como COLUNAS (para o acumulado do ano
ser `SUM(linha_x)`, não desserializar doze payloads), o payload completo e um
sha256. Competência e caixa fecham separadamente; reabrir marca a versão vigente
e o próximo fechamento grava `versao+1`, mantendo a anterior como evidência.

O hash não congela nada — quem congela são as colunas e o payload. Ele é
evidência de adulteração do JSON no banco. Quem denuncia dado de origem alterado
é o botão **Reconferir**, que recalcula ao vivo e mostra o diff.

Reabrir exige `fiscal:encerrar` mais confirmação de administrador, no mesmo
padrão de reabrir caixa: período já declarado ao fisco é da mesma classe de ato.

### Permissão

`fiscal:encerrar`, semeada espelhando `fiscal:criar` — quem já emite nota passa
a poder fechar o mês, ninguém perde acesso ao subir, e apertar depois é uma
linha na tela de Grupos. Reusou o slug `encerrar` do catálogo global em vez de
inventar `fechar_periodo`, que apareceria como coluna vazia em todos os outros
módulos.

## Rotas novas

```
GET   /api/v1/fiscal/anexo-x                        fiscal:visualizar
GET   /api/v1/fiscal/anexo-x/pdf                    fiscal:visualizar
      (?competencia=AAAA-MM  → uma folha
       ?ano=AAAA             → doze folhas, uma por mês; tem precedência)
GET   /api/v1/fiscal/anexo-x/documentos/pdf         fiscal:visualizar
POST  /api/v1/fiscal/anexo-x/fechamento             fiscal:encerrar
POST  /api/v1/fiscal/anexo-x/fechamento/reabertura  fiscal:encerrar + admin
```

Desktop: `/fiscal/anexo-x`, `/fiscal/anexo-x/pdf`,
`/fiscal/anexo-x/documentos/pdf`, `/fiscal/anexo-x/fechamento`,
`/fiscal/anexo-x/fechamento/reabertura`, `/fiscal/anexo-x/ajuda`.

## Configuração nova

`empresa_data_abertura` (Configurações › Sistema › Dados fiscais) — proporciona
o limite do MEI no ano de abertura. Em branco, aplica o limite integral de
R$ 81.000: errar para o lado permissivo é melhor que acusar estouro inexistente.

## Testes

85 testes novos, todos verdes:

| Arquivo | Testes |
|---|---|
| `backend/tests/Feature/Fiscal/AnexoXTest.php` | 32 |
| `backend/tests/Feature/Api/V1/AnexoXApiTest.php` | 13 |
| `backend/tests/Feature/Fiscal/AnexoXFechamentoTest.php` | 12 |
| `backend/tests/Feature/Fiscal/AnexoXPdfTest.php` | 15 |
| `frontends/desktop/tests/Feature/Desktop/AnexoXTest.php` | 18 |

Suíte backend completa: **1126 passando, 0 falhas**. Desktop: 529 passando; as
5 falhas remanescentes são pré-existentes (`ClassIsolationTest` com
`SecurityHeaders.php`, markup dos filtros de OS, calendário de fluxo de caixa),
confirmadas por baseline com `git stash` dos arquivos alterados.

## Limitações registradas

- **A proporção peça/serviço vem do estado atual da origem**, não de um retrato
  da data da baixa. Editar `os.valor_pecas` depois reclassifica receita já
  apurada; o fechamento mensal é a mitigação real.
- **Baixa parcial usa a proporção do todo.** Quem paga R$ 100 de uma OS de
  R$ 500 (200 peça / 300 serviço) entra como 40/60. Não é verdade sobre o que
  ele pagou, mas é a única regra que soma de volta ao total quando todas as
  parcelas caem.
- **Estorno entra na receita de caixa** — imprecisão herdada do DRE. Corrigir só
  aqui quebraria a igualdade entre os dois.
- **Divergência legítima** entre o Anexo X (classifica a operação) e a relação
  de documentos (lista por data de emissão). Dito na tela e na ajuda.

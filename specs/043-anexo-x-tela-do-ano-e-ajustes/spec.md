# Anexo X — tela do ano e ajustes auditados

**Base:** `specs/042-anexo-x-receitas-brutas-mei/spec.md`
**Norma:** Resolução CGSN nº 140/2018, art. 106 e Anexo X.

## Problema

A tela entregue na 042 mostrava **um mês por vez**: escolher a competência, ler
as dez linhas, o acumulado e a conferência — tudo empilhado numa página só.
Comparar dois meses exigia trocar o filtro e perder o anterior, e não havia
lugar nenhum para ver o ano inteiro.

E havia um buraco de fundo: o relatório era o espelho exato do que estava
lançado no ERP, mas o Anexo X tem que declarar **toda** a receita bruta do mês.
Uma venda cobrada em dinheiro e não lançada é receita bruta do mesmo jeito. Sem
um lugar para declará-la, a única saída era preencher o formulário à mão fora do
sistema — perdendo o encerramento, o hash e todo o rastro.

## Requisitos

### R1 — A tela é do ano

Doze linhas, uma por mês:
`MÊS · TOTAL(X) · COMÉRCIO(III) · [INDÚSTRIA(VI)] · SERVIÇOS(IX) · COM DOC · SEM DOC · SITUAÇÃO · AÇÕES`

A coluna de indústria só aparece quando algum mês tem valor — a linha existe no
formulário, mas numa assistência técnica é sempre zero e só poluiria a grade.
Mês futuro entra esmaecido e sem a ação de encerrar: um mês que não aconteceu
não se declara.

Acima da tabela: o card do acumulado × limite e um **gráfico de colunas** com os
doze meses nos dois regimes, barras lado a lado.

### R2 — Cinco ações por mês, mais o fechamento

No menu de cada linha: ver as receitas brutas do mês (modal com alternador de
regime) · ver no padrão da Receita Federal (modal com o PDF em iframe) · editar
o relatório · imprimir o PDF · listar todas as operações com filtro de
documento fiscal. Depois de um separador: encerrar, reabrir e reconferir.

O banner grande de fechamento **sai da tela** — a situação vira coluna.

### R3 — Ajustes auditados por linha

"Editar o relatório" é lançar um acréscimo ou redução numa linha, com motivo
obrigatório. O valor calculado continua visível ao lado do ajuste, e a tela
mostra a tríade **Calculado / Ajuste / Declarado**.

- Só as **seis linhas-folha** (I, II, IV, V, VII, VIII). III, VI, IX e X são
  somas — ajustá-las exigiria repartir o valor de volta entre as folhas, e essa
  repartição é decisão fiscal (a receita teve documento ou não?) que só quem
  lança sabe tomar. A recusa é explícita e diz para ajustar a linha de origem.
- **Imutável.** Corrigir é cancelar e lançar de novo; o cancelado continua
  listado, riscado, com quem cancelou e por quê. O produto é a trilha.
- **Bloqueado com a competência encerrada** — reabra antes.
- Permissão própria (`fiscal:editar`), separada de `fiscal:encerrar`: corrigir o
  relatório e congelá-lo são poderes diferentes.
- O ajuste entra **depois** das deduções na apuração. Uma devolução de venda que
  *está* no sistema não pode consumir um ajuste lançado para uma venda que *não*
  está — se entrasse antes, a devolução comeria o ajuste em silêncio.

### R4 — A invariante do DRE, agora em duas partes

`X_calculado` bate com a receita líquida do DRE do mesmo mês e regime,
**sempre** — as linhas continuam vindo todas de `ReceitaBrutaSource`.
`X_declarado = X_calculado + Σ ajustes`.

O ajuste é a exceção **declarada e auditada**, e nunca toca `ReceitaBrutaSource`
— é parcela somada depois. O DRE é relatório gerencial do que o sistema conhece
e não pode enxergar receita que nunca passou por ele. O rodapé do PDF declara
quanto do total veio de ajuste manual: assinar um formulário cujo número difere
do apurado, sem traço no papel, seria pior que a linha extra.

### R5 — Qual regime conta para o limite

Verificado no *Perguntas e Respostas MEI e Simei* da Receita Federal: o limite é
sobre a **receita bruta AUFERIDA** no ano-calendário — *auferida* é o termo do
**regime de competência**. O regime de caixa é opção do ME/EPP exercida no
PGDAS-D, e o MEI usa DASN-Simei, que não tem esse mecanismo.

O gráfico mostra os dois regimes; o card do acumulado soma **sempre
competência**, e a tela diz isso ao lado do alternador. Trocar para caixa exibe
nota de leitura gerencial.

### R6 — Custo

A tela precisa de 12 meses × 2 regimes. O caminho ingênuo custaria ~1.700
consultas (cada `relatorio()` chamando `acumuladoAnual()`, que varre doze
meses). O resumo compartilha o pipeline com `apurar()` mas descarta o que só o
modal usa, e monta o acumulado a partir dos totais que já tem: **~184 consultas
com o ano todo aberto**, menos ainda com meses encerrados (que não reapuram).

Sem cache: tela fiscal com receita velha é pior que tela lenta.

## Fora de escopo

- Sobrescrever valores de linha direto (descartado — destrói a rastreabilidade
  que o fechamento e o hash existem para garantir).
- Varredura única do ano numa query: `ReceitaBrutaSource` faz merge dos títulos
  `dre_fixo_mensal` com vencimento até o fim do período, e um título fixo de
  janeiro apareceria uma vez só em vez de em todos os meses. O resumo passaria a
  discordar de `apurar()` em silêncio.

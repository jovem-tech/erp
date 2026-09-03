# Anexo X: tela do ano, gráfico dos dois regimes e ajustes auditados (2026-09-03)

**Norma:** Resolução CGSN nº 140/2018, art. 106
**Spec:** `specs/043-anexo-x-tela-do-ano-e-ajustes/spec.md`
**Base:** `specs/042-anexo-x-receitas-brutas-mei` (v5.76.0.0)
**Tipo:** funcionalidade nova (MINOR)

## O problema

A tela do Anexo X mostrava um mês por vez, com tudo empilhado numa página só —
comparar dois meses exigia trocar o filtro e perder o anterior, e não havia como
ver o ano.

E havia um buraco maior: o relatório era o espelho exato do ERP, mas o Anexo X
tem que declarar **toda** a receita bruta. Uma venda cobrada em dinheiro e não
lançada é receita bruta do mesmo jeito, e a única saída era preencher o
formulário à mão fora do sistema — perdendo encerramento, hash e rastro.

## O que foi feito

### A tela virou a grade do ano

Doze linhas, uma por mês, com total, quebra por atividade, quanto teve documento
fiscal e a situação da competência. O menu de cada linha abre o mês: receitas
brutas (modal), formulário no padrão da Receita (PDF em iframe), editar,
imprimir, listar operações com filtro, e — depois de um separador — encerrar,
reabrir e reconferir. O banner grande de fechamento saiu; a situação virou
coluna.

Acima da tabela, o card do acumulado e um gráfico de colunas com os doze meses
nos dois regimes. **Barras lado a lado, nunca empilhadas**: competência e caixa
são duas leituras da MESMA receita, e empilhá-las desenharia o dobro do que foi
faturado. O comentário está no JS para ninguém "corrigir" copiando o dashboard,
onde as despesas *são* empilhadas.

O alternador de regime da tabela troca a leitura **no cliente** — o resumo já
traz os dois regimes de cada mês, inclusive as dez linhas. Por isso trocar de
regime e abrir "Receitas brutas do mês" não fazem requisição nenhuma.

### Ajustes auditados por linha

"Editar o relatório" lança um acréscimo ou redução numa linha, com motivo
obrigatório. O valor calculado continua visível: a tela mostra
**Calculado / Ajuste / Declarado**.

Só as **seis linhas-folha** aceitam ajuste. III, VI, IX e X são somas —
ajustá-las exigiria repartir o valor de volta entre as folhas, e essa repartição
é decisão fiscal (a receita teve documento ou não?) que só quem lança sabe
tomar. A recusa é explícita e manda ajustar a linha de origem.

O lançamento é **imutável**: corrigir é cancelar e lançar de novo, e o cancelado
continua listado, riscado, com quem cancelou e por quê. O produto aqui é a
trilha, e um UPDATE apagaria quem declarou o quê e quando.

Mitigações acumuladas, porque isto é declaração ao fisco feita à mão: motivo de
no mínimo 10 caracteres, imutabilidade, cancelamento também com motivo, autor e
data por lançamento, permissão própria (`fiscal:editar`, separada de
`fiscal:encerrar`) revogável na tela de Grupos, bloqueio total com o mês
encerrado, entrada no `payload_json` assinado por SHA-256, e o rodapé do PDF
declarando que existe.

**O ajuste entra depois das deduções.** Uma devolução de venda que *está* no
sistema não pode consumir um ajuste lançado para uma venda que *não* está — se
entrasse antes, a devolução comeria o ajuste em silêncio e a tríade da tela
deixaria de fechar. Há teste para isso.

### A invariante do DRE virou duas

`X_calculado` bate com a receita líquida do DRE do mesmo mês e regime,
**sempre**. `X_declarado = X_calculado + Σ ajustes`.

O ajuste é a exceção declarada e auditada, e nunca toca `ReceitaBrutaSource` — é
parcela somada depois. O DRE é relatório gerencial do que o sistema conhece e
não pode enxergar receita que nunca passou por ele.

Os dois testes de igualdade da 042 **não mudaram**: o cenário deles não tem
ajuste, então `x.valor == x.calculado`. Ganharam três companheiros que pinam a
exceção em vez de deixá-la implícita — inclusive um que quebra se alguém um dia
implementar o ajuste como um `Financeiro` sintético.

### Qual regime conta para o limite

Verificado no *Perguntas e Respostas MEI e Simei* da Receita Federal: o limite é
sobre a **receita bruta AUFERIDA** no ano-calendário, e *auferida* é o termo do
regime de **competência**. O regime de caixa é opção do ME/EPP exercida no
PGDAS-D, e o MEI usa DASN-Simei, que não tem esse mecanismo.

O gráfico mostra os dois; o card do acumulado soma sempre competência, e a tela
diz isso ao lado do alternador. Em caixa, aparece nota de leitura gerencial.

### Custo

O caminho ingênuo (12 meses × 2 regimes × `relatorio()`) custaria ~1.700
consultas, porque cada `relatorio()` chama `acumuladoAnual()`, que varre doze
meses. O resumo compartilha o pipeline com `apurar()` — via `apurarBlocos()`,
extraído numa etapa isolada e verificada antes de qualquer mudança de
comportamento — mas descarta o que só o modal usa, e monta o acumulado a partir
dos totais que já tem: **~184 consultas** com o ano todo aberto, menos com meses
encerrados, que não reapuram.

Duas armadilhas desviadas: `apresentar()` recalcula o hash do payload inteiro
(24 vezes seria centenas de KB de CPU que não aparecem no contador de queries) →
`apresentarResumido()` sem hash; e o lazy-load dos autores do fechamento → eager
loading numa query só.

**Sem cache.** Tela fiscal com receita velha é pior que tela lenta.

## Rotas novas

```
GET   /api/v1/fiscal/anexo-x/resumo?ano=AAAA              fiscal:visualizar
GET   /api/v1/fiscal/anexo-x/ajustes                      fiscal:visualizar
POST  /api/v1/fiscal/anexo-x/ajustes                      fiscal:editar
POST  /api/v1/fiscal/anexo-x/ajustes/{id}/cancelamento    fiscal:editar
```

Desktop ganhou as rotas JSON espelho para os modais.

## Testes

98 testes no módulo, todos verdes. Backend completo: **1175 passando, 0 falhas**
(era 1126). Desktop: 542 passando (era 529); as 5 falhas remanescentes são
pré-existentes, confirmadas por baseline.

## Verificação ao vivo

Em 2026-07, com ajuste de R$ 90,00 em VII: calculado 930,00 → declarado 1.020,00,
IX e X recompostos para 1.020,00 e 2.076,00. **O DRE do mesmo mês continuou em
R$ 1.986,00** — exatamente o calculado. O PDF imprimiu o declarado e o rodapé
"Inclui R$ 90,00 em ajuste manual declarado (1 lançamento)". Ajuste em linha
calculada recusado. Cancelamento devolveu os valores e manteve o lançamento na
trilha.

## Nota de manutenção

A migration `2026_09_03_000010_add_cadastro_pendente_to_equipamentos_table`
(de outra entrega, do mesmo dia) usava só `Schema::hasColumn()` como guarda.
Numa tabela que **não existe**, `hasColumn` devolve false e o `ALTER` rodava
assim mesmo — nos testes, `equipamentos` é reconstruída depois das migrations, e
isso derrubava a suíte inteira do backend. Corrigido para
`! Schema::hasTable(...) || Schema::hasColumn(...)`, que é o padrão das demais
migrations do módulo fiscal.

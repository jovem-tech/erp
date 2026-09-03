# Tarefas — Anexo X: tela do ano e ajustes auditados

## 1. Banco — CONCLUÍDA
- [x] `2026_09_03_000003_create_anexo_x_ajustes_table` — tabela própria, sem
      unique por linha, `valor` com sinal, sem UPDATE.
- [x] `2026_09_03_000004_add_ajuste_totais_to_anexo_x_fechamentos` — colunas de
      resumo para a tabela do ano não desserializar 24 payloads.
- [x] `2026_09_03_000005_seed_fiscal_editar_permission` — `fiscal:editar`
      espelhando `fiscal:encerrar`.

## 2. Núcleo — CONCLUÍDA
- [x] `AnexoXAjuste` (model) com `LINHAS_AJUSTAVEIS` e `MAPA_DE_BLOCO`.
- [x] `AnexoXAjusteService` — lançar, cancelar, somar por linha, listar do ano.
- [x] **Refatoração isolada primeiro**: `apurarBlocos()` extraído de `apurar()`
      sem mudança de comportamento, com os 72 testes verdes ANTES do ajuste
      entrar. Só então o ajuste foi ligado.
- [x] `aplicarAjustes()` nos blocos (não nas linhas prontas), depois das
      deduções.
- [x] `valoresDeLinha()` — a aritmética de III/VI/IX/X num lugar só.
- [x] `resumoAnual()`, `montarAcumulado()`, `fechamentosDoAno()`, `grafico()`.
- [x] `apresentarResumido()` no fechamento — sem recalcular hash.
- [x] `totalBrutoDoMes()` passa a somar o ajuste.
- [x] Rodapé do PDF declara o ajuste.

## 3. API e desktop — CONCLUÍDA
- [x] `GET /fiscal/anexo-x/resumo`, `GET|POST /fiscal/anexo-x/ajustes`,
      `POST /fiscal/anexo-x/ajustes/{id}/cancelamento`.
- [x] Rotas JSON do BFF: operações, ajustes, lançar, cancelar.
- [x] `openapi.yaml` atualizado.

## 4. Tela — CONCLUÍDA
- [x] Tabela de 12 meses com alternador de regime no cliente.
- [x] Card do acumulado e gráfico acima da tabela.
- [x] Seis modais, renderizados **uma vez** em `@push('modals')`.
- [x] Modais de editar/reabrir/reconferir só entram no DOM com a permissão.
- [x] `anexo-x-chart.js` com `stacked: false` comentado.

## 5. Testes — CONCLUÍDA

| Arquivo | Testes |
|---|---|
| `backend/tests/Feature/Fiscal/AnexoXAjusteTest.php` | 16 |
| `backend/tests/Feature/Fiscal/AnexoXResumoAnualTest.php` | 12 |
| `backend/tests/Feature/Api/V1/AnexoXApiTest.php` | 25 |
| `backend/tests/Feature/Fiscal/AnexoXPdfTest.php` | 18 |
| `frontends/desktop/tests/Feature/Desktop/AnexoXTest.php` | 27 |

Guards centrais:
- `test_resumo_de_cada_mes_e_identico_ao_apurar_do_mesmo_mes` — impede tabela e
  modal de divergirem.
- `test_consultas_do_resumo_nao_crescem_com_o_numero_de_operacoes` — protege
  contra N+1, que é o risco real desta tela.
- `test_x_calculado_continua_batendo_com_o_dre_mesmo_com_ajuste` +
  `test_ajuste_manual_afasta_x_do_dre_exatamente_pelo_valor_ajustado` +
  `test_ajuste_nao_aparece_no_dre` — a invariante em duas partes.
- `test_ajuste_nao_e_alcancado_pela_cascata_de_devolucoes` — guarda a ordem em
  `apurarBlocos()`.

Nenhum teste da 042 foi apagado. Os dois de igualdade com o DRE continuam
intactos, porque o cenário deles não tem ajuste.

## 6. Verificação ao vivo (2026-09-03)

Em `192.168.1.100`, competência 2026-07:

| Conferência | Resultado |
|---|---|
| VII calculado / declarado após ajuste de R$ 90 | 930,00 / 1.020,00 |
| IX e X recompostos | 1.020,00 / 2.076,00 |
| Ajuste em linha calculada | recusado, `ANEXO_X_LINHA_NAO_AJUSTAVEL` |
| **DRE do mesmo mês** | **1.986,00 — inalterado** |
| PDF | imprime o declarado e o rodapé "Inclui R$ 90,00 em ajuste manual declarado (1 lançamento)" |
| Resumo anual | total do mês 2.076,00; acumulado = total da tabela = 7.332,50 |
| Cancelamento | VII volta a 930,00, X a 1.986,00, lançamento continua na trilha |

A tela foi renderizada contra a resposta REAL da API: 12 linhas, cada uma com
`TOTAL = COMÉRCIO + SERVIÇOS` e `= COM DOC + SEM DOC`, futuros marcados, total
do ano 7.242,50 batendo com o acumulado.

## 7. Pendências herdadas (da 042, não resolvidas aqui)

- [ ] Proporção peça/serviço lida do estado atual da origem, não de um retrato
      da data da baixa. O fechamento é a mitigação.
- [ ] Estorno entra na receita de caixa — imprecisão herdada do DRE; consertar
      só aqui quebraria a igualdade.
- [ ] O gráfico não tem eixo secundário para o limite anual: a linha tracejada é
      a média mensal (limite ÷ 12), que responde "estou no ritmo?" e não
      "estourei?". O card acima responde a segunda.

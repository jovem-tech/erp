# Tarefas — Anexo X (Relatório Mensal das Receitas Brutas)

## 1. Fundação compartilhada — CONCLUÍDA

- [x] `backend/app/Support/PeriodoMensal.php` — competência `AAAA-MM` → intervalo.
- [x] `backend/app/Support/RateioAtividade.php` — rateio mercadoria/serviço,
      extraído de `DocumentoFiscalService::valoresLiquidos()`.
- [x] `backend/app/Services/Financeiro/ReceitaBrutaSource.php` — fonte única das
      linhas de receita; `FinanceiroReportService` passa a delegar a ela.
- [x] Portão: `FinanceiroReportTest` (32) e `DocumentoFiscal` (42) verdes **sem
      editar teste nenhum**.

## 2. Data de abertura da empresa — CONCLUÍDA

- [x] `empresa_data_abertura` em `CompanyProfileService::DEFAULTS`,
      `UpdateCompanyProfileRequest` (com `prepareForValidation` para string
      vazia), `CompanyContextProvider`, passthrough do desktop e campo na tela
      de Configurações.

## 3. Persistência do fechamento — CONCLUÍDA

- [x] Migration `2026_09_03_000001_create_anexo_x_fechamentos_table`.
- [x] Migration `2026_09_03_000002_seed_fiscal_encerrar_permission` —
      `fiscal:encerrar` espelhando `fiscal:criar`.
- [x] Model `AnexoXFechamento` com `scopeVigente()`.
- [x] Fábrica `createAnexoXFechamentoRecord()` no trait de testes (a tabela vem
      da migration, não é espelhada no trait).

## 4. Núcleo — CONCLUÍDA

- [x] `AnexoXService` — apuração nos dois regimes, segregação por atividade,
      cobertura por documento, deduções, acumulado anual, relação de documentos.
- [x] `AnexoXFechamentoService` — fechar, reabrir, canonicalizar, hash, diff.
- [x] `AnexoXLayout` — dados formatados para impressão, zero HTML.

## 5. PDFs — CONCLUÍDA

- [x] `AnexoXRenderer` em `app/Services/Pdf/` (exigência do `PdfEngineGuardTest`).
- [x] `resources/views/pdf/anexo-x.blade.php` — o formulário e nada mais.
- [x] `resources/views/pdf/anexo-x-documentos.blade.php` — o anexo, separado.
- [x] Não registrado no `PdfTemplateRegistry`: documento com forma definida em
      norma não pode ser editável na tela de Modelos PDF.

## 6. API — CONCLUÍDA

- [x] `AnexoXController` com as 5 rotas; `fiscal:visualizar` para ler,
      `fiscal:encerrar` + step-up de administrador para fechar/reabrir.
- [x] `backend/openapi.yaml` atualizado.

## 7. Desktop — CONCLUÍDA

- [x] `AnexoXService` (só `ApiClient`), `AnexoXController`, view, ajuda.
- [x] Rotas com `/ajuda` antes das demais do prefixo.
- [x] Item "Anexo X (MEI)" como ÚLTIMO da seção Fiscal, por causa de
      `firstAllowedRouteName()`.

## 8. Testes — CONCLUÍDA

| Arquivo | Testes |
|---|---|
| `backend/tests/Feature/Fiscal/AnexoXTest.php` | 32 |
| `backend/tests/Feature/Fiscal/AnexoXFechamentoTest.php` | 12 |
| `backend/tests/Feature/Fiscal/AnexoXPdfTest.php` | 9 |
| `backend/tests/Feature/Api/V1/AnexoXApiTest.php` | 13 |
| `frontends/desktop/tests/Feature/Desktop/AnexoXTest.php` | 14 |

Travas principais:
- `test_total_do_anexo_x_bate_com_a_receita_liquida_do_dre_de_competencia` e
  `..._de_caixa`.
- `test_linha_x_nao_muda_quando_um_documento_fiscal_e_emitido`.
- `test_pdf_do_anexo_x_nao_contem_nenhum_dos_extras`.
- `test_caixa_usa_a_proporcao_da_venda_quando_o_titulo_tem_venda_id_e_os_id`.
- `test_venda_vinculada_a_os_nao_conta_a_receita_duas_vezes`.
- `test_documento_com_os_id_e_venda_id_cobre_apenas_a_venda`.
- `test_devolucao_maior_que_a_receita_do_mes_deixa_x_negativo_sem_inventar_valor`.

## 10. Verificação contra a base real (2026-09-03)

Medido em `sistema_hml`, sobre as 2.191 OS com receita reconhecida:

| Conferência | Resultado |
|---|---|
| `valor_pecas + valor_mao_obra` ≠ `valor_total` | 6 OS (0,3%) |
| OS sem quebra de valores (cairia inteira em serviço) | 0 |
| **`valor_final` ≠ `valor_total - desconto`** | **2 OS** |

As 2 últimas são a justificativa concreta de ancorar a apuração em
`valor_total - desconto` e não em `valor_final`: com `valor_final`, o Anexo X
divergiria do DRE nesses dois meses. O rateio força
`comercio + servicos = liquido`, então a divergência de 0,3% desloca III contra
IX em centavos e nunca altera X.

Também verificado ao vivo em `192.168.1.100`: os dois PDFs (200, 1 página, zero
extras no formulário), fechar/refechar/reconferir/reabrir com as guardas, e a
view do desktop renderizada contra a resposta REAL da API em três competências
— incluindo um mês sem nenhuma receita.

## 9. Pendências conhecidas

- [ ] **Proporção lida do estado atual da origem.** No regime de caixa, editar
      `os.valor_pecas` depois da baixa reclassifica receita já apurada. O
      fechamento mensal é a mitigação; um snapshot em
      `financeiro.proporcao_atividade` na criação do título seria a correção
      completa, e é feature própria.
- [ ] **Estorno entra na receita de caixa.** `queryMovimentos()` não filtra
      `tipo_movimento`, espelhando o comportamento que o DRE já tinha. Corrigir
      só aqui quebraria a igualdade entre os dois relatórios — se for corrigir,
      corrige no DRE e os dois se movem juntos.
- [ ] **Itens de OS não são lidos linha a linha.** A segregação usa
      `os.valor_pecas`/`os.valor_mao_obra`, que é o que o DRE e o documento
      fiscal usam. `os_itens.tipo` daria o mesmo resultado quando as colunas
      estão consistentes, e um resultado divergente quando não estão — ficar com
      as colunas mantém os três relatórios falando a mesma língua.

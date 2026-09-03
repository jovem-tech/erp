# Changelog — Sistema ERP Jovem Tech

## v5.79.1.0 — 2026-09-03 09:36
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Melhora a UX do Select2 de marca/modelo do equipamento eventual: a sugestao 'criar novo' ganha cor de destaque + icone de inclusao (antes era uma linha igual as demais, sem indicar que o clique cadastra algo no catalogo) — e a criacao de marca/modelo novos deixa de acontecer no instante do clique/digitacao e passa a ser adiada para o clique em Salvar orcamento (gate no submit do form: resolve os pendentes, so entao deixa o POST/PATCH nativo seguir)
- **Arquivos:** frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.79.0.0 — 2026-09-03 08:51
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Equipamento eventual do orcamento (sem cadastro) passa a listar marca e modelo do catalogo real (EquipmentBrand/EquipmentModel), filtrado em cascata pelo tipo, em vez de aceitar so texto solto: Select2 com o mesmo padrao do campo Tipo, e marca/modelo digitados que nao existirem no catalogo sao cadastrados de verdade (equipments.brands/models.quick.store), vinculados corretamente ao tipo/marca — melhorando a base de equipamentos em vez de so gravar texto no orcamento
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.78.0.0 — 2026-09-03 07:56
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Anexo X: tela passa a ser do ano-calendario com tabela de doze meses, grafico dos dois regimes lado a lado e acumulado acima; cinco acoes por mes em modais; ajustes manuais auditados por linha (motivo obrigatorio, imutaveis, permissao fiscal:editar, bloqueados com a competencia encerrada) que declaram receita bruta fora do ERP sem quebrar a igualdade do valor apurado com o DRE
- **Arquivos:** backend/app/Services/Fiscal/AnexoXService.php,backend/app/Services/Fiscal/AnexoXAjusteService.php,backend/app/Services/Fiscal/AnexoXFechamentoService.php,backend/app/Services/Fiscal/AnexoXLayout.php,backend/app/Models/AnexoXAjuste.php,backend/app/Http/Controllers/Api/V1/AnexoXController.php,backend/database/migrations/2026_09_03_000003_create_anexo_x_ajustes_table.php,backend/database/migrations/2026_09_03_000004_add_ajuste_totais_to_anexo_x_fechamentos.php,backend/database/migrations/2026_09_03_000005_seed_fiscal_editar_permission.php,backend/routes/api.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/AnexoXController.php,frontends/desktop/app/Services/AnexoXService.php,frontends/desktop/resources/views/fiscal/anexo-x.blade.php,frontends/desktop/resources/views/fiscal/partials/_anexo-x-tabela.blade.php,frontends/desktop/resources/views/fiscal/partials/_anexo-x-grafico.blade.php,frontends/desktop/resources/views/fiscal/partials/_anexo-x-acumulado.blade.php,frontends/desktop/resources/views/fiscal/partials/_anexo-x-modais.blade.php,frontends/desktop/public/assets/js/anexo-x.js,frontends/desktop/public/assets/js/anexo-x-chart.js,frontends/desktop/routes/web.php

## v5.77.0.0 — 2026-09-03 07:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Orcamento sem OS pode cadastrar o equipamento do cliente no mesmo padrao da abertura de OS (formulario embutido, tipo -> marca -> modelo com criacao inline), com a foto de perfil opcional porque o aparelho ainda esta com o cliente: o equipamento nasce com cadastro_pendente, e a OS desse aparelho e' recusada (ORDER_EQUIPMENT_REGISTRATION_PENDING) ate a foto ser anexada — o wizard da OS trava o salvar e reabre o cadastro no proprio modal para completar
- **Arquivos:** backend/database/migrations/2026_09_03_000010_add_cadastro_pendente_to_equipamentos_table.php,backend/app/Models/Equipment.php,backend/app/Http/Requests/Api/V1/StoreEquipmentRequest.php,backend/app/Services/EquipmentWorkflowService.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/openapi.yaml,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/EquipmentCreationTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.76.0.0 — 2026-09-03 01:02
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Anexo X: acoes da tela reunidas num menu Mais acoes (baixar PDF, relacao de documentos, ajuda) e download do formulario passa a aceitar o ano inteiro num PDF so, com uma folha por mes reusando o mesmo partial do formulario mensal e aviso de rodape nas competencias em curso ou futuras
- **Arquivos:** backend/app/Services/Fiscal/AnexoXService.php,backend/app/Services/Fiscal/AnexoXLayout.php,backend/app/Services/Pdf/AnexoXRenderer.php,backend/app/Http/Controllers/Api/V1/AnexoXController.php,backend/resources/views/pdf/anexo-x.blade.php,backend/resources/views/pdf/anexo-x-anual.blade.php,backend/resources/views/pdf/partials/anexo-x-formulario.blade.php,backend/resources/views/pdf/partials/anexo-x-estilo.blade.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/AnexoXController.php,frontends/desktop/app/Services/AnexoXService.php,frontends/desktop/resources/views/fiscal/anexo-x.blade.php,frontends/desktop/public/assets/js/anexo-x.js

## v5.75.0.0 — 2026-09-02 20:39
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Anexo X (Res. CGSN 140/2018, art. 106): relatorio mensal das receitas brutas do MEI com segregacao por atividade e por documento fiscal, nos regimes de competencia e caixa, PDF do formulario oficial mais PDF separado da relacao de documentos emitidos, e fechamento mensal que congela os valores declarados
- **Arquivos:** backend/app/Services/Fiscal/AnexoXService.php,backend/app/Services/Fiscal/AnexoXFechamentoService.php,backend/app/Services/Fiscal/AnexoXLayout.php,backend/app/Services/Pdf/AnexoXRenderer.php,backend/app/Services/Financeiro/ReceitaBrutaSource.php,backend/app/Support/PeriodoMensal.php,backend/app/Support/RateioAtividade.php,backend/app/Models/AnexoXFechamento.php,backend/app/Http/Controllers/Api/V1/AnexoXController.php,backend/resources/views/pdf/anexo-x.blade.php,backend/resources/views/pdf/anexo-x-documentos.blade.php,backend/database/migrations/2026_09_03_000001_create_anexo_x_fechamentos_table.php,backend/database/migrations/2026_09_03_000002_seed_fiscal_encerrar_permission.php,backend/routes/api.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/AnexoXController.php,frontends/desktop/app/Services/AnexoXService.php,frontends/desktop/resources/views/fiscal/anexo-x.blade.php,frontends/desktop/resources/views/fiscal/anexo-x-help.blade.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/routes/web.php

## v5.74.0.0 — 2026-09-02 20:35
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Novo orcamento: botao de cadastro rapido de cliente ao lado do select (reaproveita clients.quick-modal e clients.quick.store) para o orcamento nascer ligado a um cadastro em vez de depender do cliente eventual; a tela do orcamento passa a avisar que orcamento com cliente eventual nao gera OS enquanto nao houver cadastro, e ganha "Sincronizar dados do cliente" (POST orcamentos/{id}/sincronizar-cliente) para trazer telefone/e-mail atualizados do cadastro; a recusa do cadastro rapido passa a dizer o motivo (rotulo do campo + mensagem do backend no alerta, campo marcado e trazido para a tela) em vez de so "Falha na validacao dos dados enviados"
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.73.1.0 — 2026-09-02 20:07
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Botao de anexar XML/PDF na tela da nota fiscal ganha verbo (Enviar XML/PDF) e comeca desabilitado ate um arquivo ser escolhido, em vez de a confirmacao ficar implicita no rotulo do formato
- **Arquivos:** frontends/desktop/resources/views/fiscal/nota.blade.php,frontends/desktop/tests/Feature/Desktop/DocumentoFiscalTest.php

## v5.73.0.0 — 2026-09-02 19:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Reconstroi a conferencia de assinatura digital do XML da NFS-e (perdida em git reset --hard de outra sessao): NfseXmlImporter volta a chamar AssinaturaXml::conferir(), com DOCTYPE/XXE hardening e checagem de tamanho de chave; fiscal.nfse.exigir_assinatura_xml continua controlando se bloqueia ou so registra
- **Arquivos:** backend/app/Services/Fiscal/NfseXmlImporter.php,backend/tests/Feature/Fiscal/NfseXmlImporterTest.php,backend/tests/Feature/Api/V1/DocumentoFiscalTest.php

## v5.72.1.0 — 2026-09-02 19:20
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** deploy-completo.sh: recusa commitar artefato de runtime (foi assim que backend/storage/fonts entrou e travou a promocao para main), separa 'merge recusado antes de comecar' de conflito real com receita de saida, e nao deixa mais a bancada parada em main
- **Arquivos:** scripts/bash/deploy-completo.sh,.gitignore,documentacao/10-deploy/workflow-git-multiambiente.md

## v5.72.0.0 — 2026-09-02 14:43
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Mobile: abrir OS a partir de um orcamento aprovado do cliente selecionado (lista na etapa Cliente, equipamento do orcamento ja selecionado) e OS com orcamento aprovado passa a nascer em Aguardando Reparo
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,frontends/mobile/src/lib/api.ts,frontends/mobile/src/lib/orders.ts,frontends/mobile/src/lib/types.ts,frontends/mobile/src/components/orders/order-form-wizard/index.tsx,frontends/mobile/src/components/orders/order-form-wizard/steps/step-client.tsx,frontends/mobile/src/components/orders/order-form-wizard/steps/step-equipment.tsx,frontends/desktop/resources/views/orders/_wizard.blade.php,documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md,documentacao/07-novas-implementacoes/2026-09-02-os-a-partir-de-orcamento-aprovado-mobile.md

## v5.71.6.0 — 2026-09-02 13:40
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** anexarArquivo() passa a conferir tomador e chave do XML antes de aceita-lo, fechando a brecha que deixou o XML de um cliente ficar anexado na OS de outro no caminho de emissao manual
- **Arquivos:** backend/app/Services/Fiscal/DocumentoFiscalService.php,backend/app/Http/Controllers/Api/V1/DocumentoFiscalController.php,backend/tests/Feature/Api/V1/DocumentoFiscalTest.php

## v5.71.5.0 — 2026-09-02 13:16
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige corrida em rascunhoDeOrdem() que deixava uma OS acumular documento fiscal duplicado (emitido + rascunho orfao) quando a baixa com XML embutido rodava; trava com Cache::lock + SELECT FOR UPDATE, provada com teste real de duas conexoes MySQL
- **Arquivos:** backend/app/Services/Fiscal/DocumentoFiscalService.php,backend/tests/Integration/FiscalRascunhoConcorrenciaMysqlTest.php,backend/tests/Feature/Api/V1/DocumentoFiscalTest.php

## v5.71.4.0 — 2026-09-02 12:55
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Mensagem padrao do envio passa a descrever o servico executado numa cadeia de tres fontes (item da OS, itens do orcamento aprovado, solucao aplicada), porque as duas primeiras cobrem OS quase disjuntas
- **Arquivos:** backend/app/Services/Fiscal/NotaFiscalEnvioService.php,backend/tests/Feature/Api/V1/DocumentoFiscalTest.php

## v5.71.3.0 — 2026-09-02 09:57
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Mensagem padrao do envio da nota passa a identificar o aparelho (tipo, marca, modelo e numero de serie ou IMEI), cada parte so quando existe
- **Arquivos:** backend/app/Services/Fiscal/NotaFiscalEnvioService.php,backend/tests/Feature/Api/V1/DocumentoFiscalTest.php

## v5.71.2.0 — 2026-09-02 09:44
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Envio da nota: DANFSe vai antes do XML e cada anexo leva legenda propria; XML passa a acompanhar so nota de tomador com CNPJ, e a mensagem padrao encurta
- **Arquivos:** backend/app/Services/Fiscal/NotaFiscalEnvioService.php,backend/tests/Feature/Api/V1/DocumentoFiscalTest.php,frontends/desktop/resources/views/fiscal/nota.blade.php,frontends/desktop/tests/Feature/Desktop/DocumentoFiscalTest.php

## v5.71.1.0 — 2026-09-02 09:28
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Envio por WhatsApp: erro da Evolution deixa de chegar na tela como 'Bad Request' e passa a dizer o motivo real (numero sem WhatsApp, erro de validacao), lendo response.message em vez do status do topo
- **Arquivos:** backend/app/Services/Integrations/IntegrationSettingsService.php,backend/tests/Feature/Integrations/EvolutionErrorMessageTest.php

## v5.71.0.0 — 2026-09-02 09:21
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Nota fiscal: envio ao cliente por e-mail ou WhatsApp com XML e DANFSe anexados (destino do cadastro, editavel), botao Emitir nova nota depois de cancelar e menu Mais acoes com baixar/imprimir/copiar chave/consultar no portal
- **Arquivos:** backend/app/Services/Fiscal/NotaFiscalEnvioService.php,backend/app/Services/Fiscal/DocumentoFiscalService.php,backend/app/Http/Controllers/Api/V1/DocumentoFiscalController.php,backend/routes/api.php,backend/tests/Feature/Api/V1/DocumentoFiscalTest.php,frontends/desktop/resources/views/fiscal/nota.blade.php,frontends/desktop/app/Http/Controllers/DocumentoFiscalController.php,frontends/desktop/app/Services/DocumentoFiscalService.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DocumentoFiscalTest.php

## v5.70.3.0 — 2026-09-02 08:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** DANFSe: fontes embutidas com metrica da Arial (Liberation Sans, com precedencia para as fontes da Microsoft quando licenciadas), margem da folha corrigida para 0,20cm conforme o item 2.2.2, e calculo de espaco calibrado no PDF renderizado
- **Arquivos:** backend/resources/views/pdf/nfse-danfse.blade.php,backend/app/Services/Fiscal/DanfseLayout.php,backend/app/Services/Pdf/NfseDanfseRenderer.php,backend/resources/fonts/danfse,backend/tests/Feature/Fiscal/DanfseNt008Test.php

## v5.70.2.0 — 2026-09-02 08:27
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Tela da nota fiscal reorganizada em duas colunas com papeis distintos: a esquerda opera a nota (dados para o portal, nota registrada, guarda de arquivos, cancelamento) e a direita e o visor do DANFSe, em proporcao A4
- **Arquivos:** frontends/desktop/resources/views/fiscal/nota.blade.php,frontends/desktop/tests/Feature/Desktop/DocumentoFiscalTest.php

## v5.70.1.0 — 2026-09-02 07:57
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Suite do desktop deixa de envenenar o cache de views do site (VIEW_COMPILED_PATH proprio, como o backend ja tinha) e DocumentoFiscalTest concede as permissoes do modulo RBAC fiscal
- **Arquivos:** frontends/desktop/phpunit.xml,frontends/desktop/.gitignore,backend/tests/Feature/Api/V1/DocumentoFiscalTest.php,frontends/desktop/tests/Feature/Desktop/DocumentoFiscalTest.php

## v5.70.0.0 — 2026-09-02 07:52
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** DANFSe reconstruido conforme a Nota Tecnica no 008 (SE/CGNFS-e): 13 blocos do Anexo I, QR Code da consulta publica, logomarca oficial, traducao dos codigos do leiaute, supressao de blocos e pagina unica
- **Arquivos:** backend/resources/views/pdf/nfse-danfse.blade.php,backend/resources/views/pdf/partials/danfse-pessoa.blade.php,backend/app/Services/Fiscal/DanfseLayout.php,backend/app/Services/Fiscal/DanfseCodigos.php,backend/app/Services/Fiscal/NfseXmlImporter.php,backend/app/Services/Pdf/NfseDanfseRenderer.php,backend/app/Support/QrCodePng.php,backend/app/Support/MunicipioIbge.php,backend/app/Console/Commands/Fiscal/AtualizarMunicipiosIbge.php,backend/resources/data/municipios-ibge.php,backend/tests/Feature/Fiscal/DanfseNt008Test.php,frontends/desktop/resources/views/fiscal/nota.blade.php

## v5.69.3.0 — 2026-08-30 23:19
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Cadastro de taxas: modalidade débito deixa de exigir parcelas (fixa 1x) no desktop

## v5.69.2.1 — 2026-08-29 03:13
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Filtro 'Mostrar' passa a ficar ao lado da Categoria como campo normal (colunas 2/3 e 1/3) em vez de solto no canto direito, onde lia como se nao pertencesse a nada

## v5.69.2.0 — 2026-08-29 03:00
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Valor e Data de vencimento movidos para a seção Pagamento: o núcleo do formulário responde só o que é (tipo, categoria, descrição) e a seção agrupa quanto, quando e como

## v5.69.1.0 — 2026-08-29 02:45
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Seção de entrada no estoque passa a aparecer só na categoria de compra de peça (grupo Custo Direto (OS)); atalho 'Entrada por compra' já abre com a categoria escolhida; corrigido atributo class duplicado em 5 lugares do form, que fazia o d-none ser ignorado pelo navegador

## v5.69.0.0 — 2026-08-29 02:29
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Formulário de lançamento com revelação progressiva: 'Despesa fixa?' vira filtro honesto de categorias + classificação exibida como resultado, despesa fixa passa a aceitar fornecedor, forma de pagamento só quando faz efeito, e correção de 6 defeitos (repetir inalcançável, dois donos do d-none, perda de OS/cliente ao trocar categoria)

## v5.68.0.0 — 2026-08-28 23:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Entrada de estoque a partir do lançamento financeiro (specs/039): peças da compra viram movimentação de entrada na mesma transação do título, com cadastro rápido inline, sugestão de preço e estorno no cancelamento

## v5.67.0.0 — 2026-08-28 12:04
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Ajuda contextual nos campos de precificação e de lançamento financeiro

## v5.66.0.0 — 2026-08-27 20:33
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Baixa de peca na OS (specs/038): o CMV deixa de ser zero. Botao Aplicar pecas do orcamento no lugar do alerta passivo, com modal pre-preenchido pelo que falta aplicar, e motor unico EstoqueMovimentacaoService generalizando a implementacao de baixa que ja estava correta em vendas - lock ordenado por id, decremento atomico e agregacao por peca. Saldo negativo so com confirmacao explicita, e o erro nomeia as pecas que faltaram

## v5.65.0.0 — 2026-08-27 20:02
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Precificacao Fase 5 (specs/037): a tela de encerramento de OS mostrava Custo estimado R$ 0,00 em TODA OS porque somava os_itens.preco_custo_referencia, uma coluna com 2306 linhas e zero preenchidas. Passa a vir das mesmas fontes da margem: saida de estoque valorizada para pecas e orcamento_itens para servicos. Fecha tambem custo e margem na listagem e no detalhe da venda, que apareciam para qualquer um com vendas:visualizar

## v5.64.0.0 — 2026-08-27 19:55
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Precificacao Fase 4 (specs/037): orcamento passa a gravar cotacao real no lugar dos zeros literais, com percentual_margem guardando a margem COBRADA e nao a meta, e modo_precificacao resolvido por comparacao no servidor. Orcamento fechado deixa de ser reprecificado ao ser editado, porque syncItems apaga e reinsere tudo a cada save. Tela ganha colunas de custo e margem com semaforo, o payload passa a ser redigido por permissao, e a legenda que prometia custo e margem sem ter as colunas deixa de mentir

## v5.63.0.0 — 2026-08-27 18:54
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Precificacao Fase 3 (specs/037): custo-hora deixa de ser numero digitado e passa a ser calculado dos custos fixos reais do DRE, com definicao de custo fixo unica (Financeiro::scopeFixasDre) compartilhada entre os dois. Escada de guardas que nunca devolve zero, janela de meses fechados com limite inferior e capacidade global. Cadastro de servico ganha preco sugerido e a cadeia de custo visivel, e custo_direto_padrao vira Custo de materiais para acabar com a dupla contagem de mao de obra

## v5.62.0.0 — 2026-08-27 18:20
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Precificacao Fase 2 (specs/037): cadastro de peca ganha preco sugerido que pre-preenche o campo vazio e nunca sobrescreve digitacao (regra do sujo, com edicao e old() nascendo sujos). Simulador passa a aceitar estoque:criar|editar com resposta redigida por visibilidade, porque quem cadastra peca costuma nao ter permissao financeira. Expoe valor_calculado alem do recomendado para tratar respeitar_preco_venda, que antes sugeria o proprio numero ja digitado

## v5.61.0.0 — 2026-08-27 17:46
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Precificacao Fases 0 e 1 (specs/037): o motor sai da tela de configuracao e entra no fluxo. PDV passa a exibir custo, margem por linha e aviso de piso ao dar desconto, com o desconto de cabecalho rateado por linha; custo redigido por permissao no DTO e nao na view, para nao chegar ao DOM de quem nao pode ver. Nunca bloqueia a venda. Fecha tambem a brecha do custo_unitario enviado pelo cliente e reduz loadSettings de 16 queries para 1

## v5.60.0.0 — 2026-08-27 17:36
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Elimina gargalos de travamento sob operacao intensa: PDF de abertura e avisos de WhatsApp saem da requisicao para a fila, retry do desktop deixa de reexecutar comandos mutaveis, busca de OS troca 53 LIKE por coluna indexada (244ms para 27ms), listagem de orcamentos usa uma agregacao no lugar de quinze e file_scan_runs ganha retencao

## v5.59.0.0 — 2026-08-27 17:31
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Precificacao Fase 0 (specs/037): fundacao para o motor sair da tela de configuracao e entrar no fluxo. loadSettings deixa de ser N+1 (16 queries para 1), tres catalogos fechados (VisibilidadeCusto, FaixaMargem, ModoPrecificacao) e o DTO PrecoQuote que faz a redacao de custo por permissao no payload, nao na view. Fecha tambem a brecha em que o custo_unitario enviado pelo cliente sobrescrevia o custo de cadastro e zerava a margem gravada da venda

## v5.58.1.0 — 2026-08-27 09:31
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Estoque: fecha a janela de corrupcao aberta na v5.58 (validacao relaxada para numeric sem o alargamento aplicado, com o MySQL arredondando 0,5 para 1 em silencio) e elimina 10 truncamentos de quantidade remanescentes, incluindo o PDF de orcamento que o cliente assina. Migration emendada para preservar nulidade e defaults das colunas legadas em vez de impor NOT NULL DEFAULT 0

## v5.58.0.0 — 2026-08-27 08:03
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Estoque Fase 1a (specs/036): quantidades passam de INT para DECIMAL(14,4) para aceitar insumo fracionado (0,5 m de cabo), enquanto o estoque tem 9 pecas e 1 movimentacao e a mudanca custa nada. Corrige o cast dos models que truncaria ao ler, blinda a interpolacao de float em SQL cru contra locale pt-BR, e faz o CSV de estoque fechar no round-trip export/import. Primeiro teste de estoque do desktop

## v5.57.0.0 — 2026-08-27 02:36
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Regime tributario como conceito de primeira classe (MEI padrao, Simples, Outro): decide se o imposto e custo variavel que desconta da margem de cada OS ou custo fixo que pertence ao ponto de equilibrio. No MEI o DAS e valor fixo mensal e a margem devolve 0 mesmo com aliquota configurada; configuravel em Financeiro > Precificacao para quando a assistencia crescer e mudar de regime

## v5.56.1.0 — 2026-08-27 02:17
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Comando financeiro:recalcular-margem para reprocessar o cache os_margem com a formula nova (taxa de recebimento e imposto): sem ele o historico ficava com a margem antiga inflada convivendo com a nova, e OsMargemService::recalcularEmLote() era codigo sem chamador. Simula por padrao, aplica com --aplicar. Corrige tambem a margem por hora por tecnico, que dividia a margem de todas as OS pelas horas de apenas parte delas

## v5.56.0.0 — 2026-08-26 13:56
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Margem de contribuicao completa e DRE gerencial: margem por OS passa a descontar taxa de recebimento real e imposto, media do periodo vira indice de contribuicao ponderado, novo apontamento de horas de bancada habilita margem por hora, DRE ganha demonstracao por custeio variavel com CMV de estoque e analise custo-volume-lucro (ponto de equilibrio, margem de seguranca, GAO), e taxa de cartao da baixa de OS passa a ser classificada no DRE

## v5.55.0.0 — 2026-08-26 10:06
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Desktop: protecao de trabalho nao salvo. Uma venda em andamento no PDV sumia sem aviso por qualquer saida da pagina — sidebar, inicio, + Novo, F5, fechar a aba — porque o carrinho vive so no DOM. Agora o shell expoe window.erpRegisterUnsavedWork e um unico beforeunload cobre todas as saidas de uma vez, em vez de cada caminho lembrar de perguntar. A sonda do PDV exclui submitLiberado, senao toda venda concluida perguntaria se o operador quer mesmo sair. Sonda que lanca excecao e tratada como nada a perder, para bug de tela nao prender o usuario. O guard solta o loader de pagina num timeout quando o usuario decide ficar: o loader e armado no clique e nem pageshow nem pagehide disparam numa navegacao cancelada, o que deixaria a tela coberta para sempre. E o Esc do PDV passa a confirmar antes de descartar o carrinho, com foco no confirmar para quem quis limpar resolver com Esc + Enter
- **Arquivos:** frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/tests/Unit/UnsavedWorkGuardTest.php

## v5.54.1.0 — 2026-08-26 09:54
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Desktop: a reivindicacao de teclas de funcao vira POR TECLA (data-desktop-fkeys-owner="F2 F3 F4") em vez de por tela. O PDV bloqueava as quatro, mas so' usa tres — F1 volta a abrir Nova OS a partir do balcao, que e' o caso do cliente que chega para deixar aparelho e nao para comprar. Teste novo le o vendas-pdv.js e confere que as teclas reivindicadas no Blade sao as mesmas que o script trata, para a lista nao passar a mentir em silencio se o PDV mudar de tecla
- **Arquivos:** frontends/desktop/public/assets/js/desktop.js,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/tests/Feature/Desktop/QuickCreateShortcutsTest.php

## v5.54.0.0 — 2026-08-26 09:26
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Desktop: atalhos F1..F4 para os itens do botao + Novo (nova OS, orcamento, venda, lancamento), com a tecla visivel no menu. O atalho aciona o proprio link do dropdown em vez de repetir rotas no JS, entao herda o RBAC e o listener de navegacao interna do guard de sessao; sem permissao a tecla volta a ser do navegador. Desligado quando ha modal aberto e em telas que reivindicam as teclas de funcao via data-desktop-fkeys-owner — hoje o PDV, onde F2/F3/F4 ja confirmam venda, alternam tela cheia e abrem o cliente
- **Arquivos:** frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/QuickCreateShortcutsTest.php

## v5.53.0.0 — 2026-08-26 08:47
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fase 5 da integracao Banco Inter: baixa automatica por conciliacao. Novo InterLiquidacaoService onde a ordem das operacoes E a garantia: INSERT em inter_liquidacoes com o e2eid PRIMEIRO, baixa depois — na ordem inversa duas execucoes simultaneas poderiam ambas passar pelo registerMovement antes de qualquer uma gravar a marca. Violacao de UNIQUE e tratada como ja processado, nao como erro. A baixa passa exclusivamente por FinanceiroService::registerMovement, que ja tem transacao, lock e recusa de valor acima do saldo; nao existe caminho paralelo. Pagamento parcial e registrado normalmente; pagamento MAIOR que o saldo em aberto NAO vira baixa automatica e dispara alerta, porque o excedente exige decisao humana — mas a liquidacao fica gravada com movimento nulo, para dinheiro que entrou na conta nunca sumir do radar. Baixa automatica vai para os_eventos com origem=automacao e usuario_id nulo, deixando explicito no historico que quem lancou foi a maquina. Novo comando inter:conciliar agendado a cada 15 minutos, que e o caminho PRINCIPAL de baixa e nao um plano B: funciona sem webhook, sem porta aberta e sem VPS. Job LiquidarCobrancaInterJob com ShouldBeUnique por cobranca, deixando claro em comentario que a unicidade do job e conveniencia e nao a garantia — a garantia e o indice unico no banco

## v5.52.1.1 — 2026-08-26 08:07
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Desktop: botao de recolher a sidebar sai do DOM no modo sanduiche. A regra CSS que tentava esconde-lo perdia para o .d-lg-inline-flex do Bootstrap, que e !important, entao o chevron aparecia dentro da gaveta aberta — inclusive em /os antes desta entrega. Fora do HTML ele tambem sai da arvore de acessibilidade, onde anunciaria Recolher navegacao numa gaveta
- **Arquivos:** frontends/desktop/resources/views/layouts/partials/sidebar.blade.php,frontends/desktop/public/assets/css/desktop.css

## v5.52.1.0 — 2026-08-26 04:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Desktop: listagem de OS abre com a sidebar retraida no modo fixo (padrao de tela que a escolha explicita do usuario vence, via os dois sentidos do localStorage), e a estrela de favoritar sai da navbar para o lado do TITULO da pagina em 30 telas — colada no nome da pagina a acao diz sozinha o que fixa. Partials compartilhados usam a prop only= em vez de @if inline, que quebra a compilacao do Blade quando envolve tag de componente na mesma linha
- **Arquivos:** frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/resources/views/components/favorite-toggle.blade.php,frontends/desktop/resources/views/layouts/partials/favorites.blade.php,frontends/desktop/public/assets/css/desktop.css

## v5.52.0.0 — 2026-08-26 04:18
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Desktop: modo de navegacao (sidebar fixa x menu sanduiche) como preferencia por usuario e menu de Favoritos na navbar
- **Arquivos:** frontends/desktop/app/Support/DesktopPreferences.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/app/Http/Controllers/FavoriteController.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/layouts/partials/favorites.blade.php,frontends/desktop/resources/views/components/favorite-toggle.blade.php,frontends/desktop/database/migrations/2026_08_26_000001_add_navigation_prefs_to_user_preferences_table.php

## v5.51.1.0 — 2026-08-23 22:25
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Prepara a base para a Fase 5: teste de concorrencia real contra MySQL e certificado de teste deterministico. Novo grupo mysql no phpunit (excluido do run padrao) com teste que abre DUAS conexoes PDO e prova que duas gravacoes simultaneas do mesmo e2eid produzem UMA liquidacao — a suite roda SQLite em memoria, onde concorrencia entre conexoes nao existe. Nao cria banco proprio porque erp_app nao tem CREATE DATABASE: usa a base de desenvolvimento tocando apenas linhas com prefixo proprio e limpando no finally, inclusive quando o teste falha. E a geracao de certificado vencido deixa de depender de flags do openssl que nao existem em toda versao (-not_before/-not_after, -days negativo) e passa a avancar o relogio com Carbon::setTestNow, o que tornava o teste de certificado vencido dependente da maquina

## v5.51.0.0 — 2026-08-23 17:56
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fase 4 da integracao Banco Inter: emissao de cobranca Pix. Novo InterCobrancaService com o txid gerado do nosso lado e a linha gravada ANTES da chamada ao banco — emitir e escrita em dois sistemas sem transacao entre eles, e um timeout depois do banco ter criado a cobranca deixaria cobranca viva la fora sem rastro aqui. Falha de emissao marca FALHA_EMISSAO e MANTEM a linha, porque falha nao significa nao criada; na tentativa seguinte o servico pergunta ao banco antes de emitir outra, adota a existente quando ela existe, emite nova so com 404 confirmado e RECUSA quando o banco nao responde (duas cobrancas vivas para o mesmo titulo e pior que nenhuma). Cobra o SALDO e nao o total do titulo. Chave Pix exige configuracao explicita, sem fallback para financeiro_chaves_pix, que serve para exibir no orcamento e pode ser de outro banco. Documento invalido emite sem devedor em vez de derrubar a cobranca, e o CPF vai para o banco mas nao para a trilha local. Tres rotas com authorize e teste de 403. O codigo Pix entra no lembrete D+1/D+3/D+5 que ja existia, com a regra de que falha do Inter nunca impede o lembrete de sair

## v5.50.0.0 — 2026-08-23 17:37
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Taxa de gateway ganha piso e teto, e o Banco Inter entra no catalogo. A tarifa de Pix cobranca do Inter e 0,9 por cento com minimo de R$0,10 e teto de R$1,50; registrar so o percentual faria uma OS de R$1.000 aparecer com R$9,00 de taxa em vez de R$1,50, e esse numero entra no calculo de margem. Quando o limite atua o gross-up deixa de ser proporcional e vira soma direta (cliente paga base mais o limite, e a liquidacao devolve exatamente a base). Campos nulos nao entram no calculo, entao todas as taxas de cartao ja cadastradas seguem com resultado identico — ha teste que fixa isso. Correcao de rota no meio do caminho: cheguei a criar migration adicionando colunas a tabela financeiro_gateway_taxas, mas essa tabela e vestigial e nao e lida por ninguem; as taxas vivem como JSON em configuracoes. Migration revertida e removida

## v5.49.1.0 — 2026-08-23 17:26
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Modelo da Assistencia Tecnica deixa de afirmar o que o sistema nao faz e passa a derivar do catalogo vivo: saem SLAs de 15/30 min e 24 h, limite de WIP 3, prioridade por aging e escalonamento automatico (nenhum existe: os_status nao tem coluna de prazo e a string WIP so aparecia no proprio controller), alem das etapas inexistentes 'Qualidade' e 'Pos-venda' e de cinco status com nome errado. Indicadores e raias agora saem de os_status. A tela Fluxo de Trabalho OS vira 'Status de OS' e vai para Administracao: removidos o diagrama de fluxo e a matriz de transicoes, que desenhavam uma maquina de estados que o backend abandonou em 09/08/2026 por nao refletir o trabalho real. Mantido o cadastro de status, unico lugar que gerencia os_status. Novo App\Support\OrderStatusMacroGroups compartilha o vocabulario das macrofases entre as duas telas
- **Arquivos:** frontends/desktop/app/Support/OrderStatusMacroGroups.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/app/Http/Controllers/AssistanceModelController.php,frontends/desktop/app/Http/Controllers/OrderStatusFlowController.php,frontends/desktop/app/Services/DesktopOrderStatusFlowService.php,frontends/desktop/resources/views/knowledge/assistance-model/index.blade.php,frontends/desktop/resources/views/knowledge/os-flow/index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-08-23-modelo-assistencia-real-e-fim-da-matriz-de-transicoes.md

## v5.49.0.0 — 2026-08-23 17:24
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fase 3 da integracao Banco Inter: modelo de dados da cobranca. Tres tabelas aditivas com papeis distintos — inter_cobrancas (o que emitimos, com txid unico e escopo abertas() que ja exclui expirada, cancelada e concluida), inter_liquidacoes (o que o banco confirmou, com UNIQUE no e2eid: e ali que mora a idempotencia da integracao, resolvida pelo banco de dados e nao pela ordem de execucao do PHP) e inter_eventos (trilha append-only com decisao, motivo, payload recebido e payload da reconsulta, para investigar baixa indevida sem depender de log em arquivo que rotaciona). A separacao entre cobranca e liquidacao e o que permite pagamento parcial e multiplos Pix na mesma cobranca sem gambiarra. A imagem do QR nao e' guardada de proposito: e derivavel do copia-e-cola e engordaria o dump diario. Nao foi semeada linha do Inter em financeiro_gateway_taxas porque a tabela nao expressa teto e registrar 0,9 por cento sem o teto de R,50 superestimaria a taxa acima de R67 e contaminaria o calculo de margem

## v5.48.1.0 — 2026-08-23 17:13
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Painel de Integracoes e tela da Agenda passam a exibir o e-mail da conta Google vinculada, com recuperacao automatica quando a captura falha ao conectar e sem sobrescrever e-mail conhecido por vazio
- **Arquivos:** backend/app/Services/Agenda/Google/GoogleCalendarConnectionService.php,backend/app/Http/Controllers/Api/V1/AgendaGoogleController.php,frontends/desktop/resources/views/configurations/integrations.blade.php,frontends/desktop/resources/views/agenda/index.blade.php

## v5.48.0.0 — 2026-08-23 13:52
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Tela de configuracao do Banco Inter: card em Configuracoes > Integracoes com canal, ambiente, Client ID, Client Secret (mascarado, preserva o salvo quando enviado vazio) e conta corrente, com aviso explicito de que certificado e chave privada NAO ficam no banco e sim em arquivo apontado pelo .env. Conta financeira ganha o campo de vinculo com a integracao bancaria nos formularios de criar e editar, aceito apenas em conta do tipo banco (vincular caixa fisico daria conciliacao que nunca fecha por construcao); FinanceiroContaService passa a persistir e serializar integracao_provider/integracao_conta_ref, que antes eram validados mas descartados na gravacao

## v5.47.1.0 — 2026-08-23 13:36
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Sidebar mais enxuta no desktop: Aparelhos/Equip. e o grupo Ferramentas (Financeiro) viram itens ocultos e Estoque de Pecas passa a se chamar Estoque; em troca, Clientes e Equipamentos ganham dropdown 'Mais acoes' apontando um para o outro (listagem, cadastro novo e ajuda), filtrado por RBAC. Os itens saem do menu como 'hidden' em vez de serem apagados porque firstAllowedRouteName() os usa como destino de fallback do middleware de permissao: quem so tem 'equipamentos' ou so 'precificacao' ficaria em redirecionamento sem saida. Ferramentas duplicava exatamente o 'Mais acoes' de Financeiro > Lancamentos
- **Arquivos:** frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/clients/index.blade.php,frontends/desktop/resources/views/equipments/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-08-23-sidebar-enxuta-atalhos-clientes-equipamentos.md,documentacao/07-novas-implementacoes/2026-07-21-sidebar-reorganizacao-e-atalhos.md

## v5.47.0.0 — 2026-08-23 13:24
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fase 2 da integracao Banco Inter: saldo e extrato bancario (somente leitura). Novo InterBankingService com saldo cacheado por 10 min (a tela nao pode bater no banco a cada carregamento, mas um numero de uma hora atras seria pior que inutil numa conferencia de caixa), extrato com janela maxima validada ANTES da chamada para o erro ser nosso e explicito, e conciliacao que compara o saldo interno (FinanceiroContaService::balanceOf) com o do banco. Divergencia NUNCA vira ajuste automatico: o ajuste e decisao humana com autor registrado, porque um sistema que conserta o proprio saldo apaga a evidencia do erro que causou a diferenca. Migration aditiva liga financeiro_contas ao provedor (integracao_provider/integracao_conta_ref), com validacao que so aceita conta do tipo banco. Quatro rotas GET sob financeiro, todas com authorize(financeiro:visualizar) e teste de 403. InterException passa a distinguir falha local de falha do banco: periodo invalido ou credencial ausente virava 503 INTER_INDISPONIVEL e mandaria o operador investigar o banco quando o problema era o pedido

## v5.46.0.0 — 2026-08-23 13:20
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Encerramento sem cobranca (descartado, devolvido sem reparo, sem custo, garantia) deixa de criar titulo a receber no valor da OS: novo settleNonBilledClosure cancela o titulo sem movimento e preserva o que ja tem valor recebido; comando os:cancelar-cobrancas-sem-cobranca limpa os 24 titulos e R$ 2.050,00 ja gravados
- **Arquivos:** backend/app/Services/Orders/OrderClosureService.php,backend/app/Console/Commands/CancelNonBilledOrderReceivables.php

## v5.45.0.0 — 2026-08-23 13:09
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fase 1 da integracao Banco Inter: fundacao de credenciais e cliente mTLS. Novo config/inter.php (caminhos do par certificado/chave no env, nunca no banco); InterCredentials resolve caminhos por metodo e nao por config() no ponto de uso, lista o que falta em vez de devolver so um booleano, e expoe a validade do certificado via openssl_x509_parse; InterTokenStore cacheia o token OAuth2 com Cache::lock (os 2 workers do Supervisor mais o efemero do scheduler pediriam token simultaneamente sem isso) e guarda por 3000s dos 3600s de validade para nunca usar token nos ultimos minutos de vida, com chave por ambiente+client_id+escopos; InterClient centraliza mTLS, Bearer, timeouts e x-conta-corrente, renova o token uma unica vez em caso de 401 no meio da validade e classifica o erro em credencial invalida versus falha temporaria; comando inter:verificar-certificado alerta em D-30/D-15/D-7/D-1 e trata 'nao consigo ler a validade' como falha, nao como valido; client_id/client_secret entram em configuracoes ja cifrados pela Fase 0.1. Escopos limitados a extrato.read, cob.write e cob.read. 26 testes novos com Http::fake e certificado X509 gerado em tempo de teste

## v5.44.4.0 — 2026-08-23 12:54
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige vazamento de diretorios temporarios na suite de testes: OrderFlowTest criava um legacy-public-<uniqid> por METODO de teste sem nunca remover (cerca de 80 por execucao da suite, que acumularam 14 mil diretorios e 157 MB em storage/framework/testing) e FileManagerCoreTest deixava um file-manager-real-<uuid> por execucao; ambos ganham tearDown que apaga o que criaram. Apos a correcao uma execucao completa deixa 3 diretorios de nome fixo, reusados, em vez de 90 novos

## v5.44.3.0 — 2026-08-23 12:47
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Agenda deixa de exibir cobranca de OS sem saldo: fontes financeiras ignoram titulo de valor zero, tratam saldo zerado como resolvido e passam a descrever o valor em aberto no lugar do valor de face
- **Arquivos:** backend/app/Services/Agenda/Sources/ContasVencimentoSource.php,backend/app/Services/Agenda/Sources/ContasPagarSource.php,backend/app/Services/Agenda/Sources/ContasReceberSource.php

## v5.44.2.0 — 2026-08-23 12:38
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Suite de testes fica verde e deterministica (727 testes, 0 falhas): storage proprio para a suite via LARAVEL_STORAGE_PATH (storage/app/private e 0750 www-data e os testes rodam como o usuario do dev, o que matava todo teste que grava arquivo e ainda deixava lixo no storage real); env do coletor de bancada fixado no phpunit.xml para a suite nao herdar o .env da maquina; DashboardSummaryTest deixa de duplicar o status irreparavel que seedOrderCatalog ja cria; ClientFlowTest passa a dar grupo aos admins (perfil=admin sem grupo nao autoriza nada desde a v4.0.0.0); EquipmentCreationTest resolve o root do coletor por SO e usa o submission_token por pareamento; BuildsLegacyErpSchema espelha a coluna submission_token; FinanceiroMargemTest encerra a OS por OrderClosureService::close(), unico caminho permitido para status de encerramento, e compara valores numericos sem prender o tipo

## v5.44.1.0 — 2026-08-23 12:30
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Retorno pos-servico passa a entrar na agenda no ato da baixa e o horizonte da varredura vai de 180 para 400 dias, corrigindo o corte silencioso no proprio padrao de seis meses
- **Arquivos:** backend/app/Services/Agenda/AgendaSourceReconciler.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Console/Commands/Agenda/ReconcileAgendaSources.php

## v5.44.0.0 — 2026-08-23 10:25
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Alertas operacionais ganham canal de saida real: novo OperationalAlertService com urgente() (WhatsApp + e-mail) e relatorio() (so e-mail), destinos em config/alertas.php lidos do env e nao do banco (alerta precisa funcionar QUANDO o banco e o caminho quebrado), deduplicacao por chave no cache para D-30/D-15/D-7/D-1 nao repetirem a cada minuto do scheduler, e duas garantias para o chamador: nunca lanca excecao e sempre grava no canal de log pagamentos antes de tentar entregar; sem SMTP real o e-mail nao conta como entregue, porque cairia no mailer log

## v5.43.0.0 — 2026-08-23 09:37
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Segredos de integracao passam a ser cifrados em repouso: SecretSettings ganha encrypt/decrypt tolerante a valor legado em texto puro, aplicado no upsert e no loadSettings dos servicos de integracoes, pagamentos, e-mail e Google (antes blank() so mascarava a resposta HTTP e o valor seguia cru em configuracoes, e portanto no dump diario sem cifra); novo comando integracoes:cifrar-segredos migra as linhas ja gravadas; novo canal de log 'pagamentos' com nivel e retencao proprios, para a trilha das integracoes financeiras nao ser descartada pelo LOG_LEVEL=warning de producao

## v5.42.1.0 — 2026-08-23 09:28
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige conexao com o Google Agenda bloqueada por form-action self: rota de consentimento vira GET em aba nova e a pagina de retorno deixa de prometer fechamento automatico
- **Arquivos:** frontends/desktop/routes/web.php,frontends/desktop/resources/views/configurations/integrations.blade.php,backend/app/Http/Controllers/Api/V1/AgendaGoogleController.php

## v5.42.0.0 — 2026-08-23 07:20
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Agenda ganha as visoes dia, semana e ano no padrao do Google Agenda: grade de 24 horas com faixa de dia inteiro, divisao de largura entre compromissos sobrepostos e mapa de densidade anual
- **Arquivos:** frontends/desktop/app/Support/AgendaTimeGrid.php,frontends/desktop/app/Support/CalendarGrid.php,frontends/desktop/app/Http/Controllers/AgendaController.php,frontends/desktop/resources/views/agenda

## v5.41.0.0 — 2026-08-22 20:39
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Modulo Agenda: compromissos, obrigacoes e lembretes num lugar so, com sincronizacao bidirecional com um calendario dedicado do Google
- **Arquivos:** backend/app/Services/Agenda,backend/app/Models/AgendaCompromisso.php,backend/app/Http/Controllers/Api/V1/AgendaController.php,backend/app/Http/Controllers/Api/V1/AgendaGoogleController.php,frontends/desktop/app/Http/Controllers/AgendaController.php,frontends/desktop/app/Support/CalendarGrid.php,frontends/desktop/resources/views/agenda

## v5.40.0.0 — 2026-08-22 13:42
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Backup completo do sistema (bancos, arquivos e configuração) com criptografia AES-256, agendamento diário, retenção escalonada e catálogo unificado de todos os backups do servidor
- **Arquivos:** backend/config/backup.php,backend/app/Services/Backups,backend/app/Http/Controllers/Api/V1/BackupController.php,frontends/desktop/resources/views/configurations/backups

## v5.39.5.0 — 2026-08-21 07:56
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Aba de PDF, download e pagina de erro passam a exibir a logo da empresa: /favicon.ico vira rota servida pela marca cadastrada (desktop e API), no lugar do arquivo estatico generico

## v5.39.4.0 — 2026-08-21 07:40
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Pagamento de fatura de cartao passa a mostrar as despesas que quitou, com a OS e o fornecedor de cada uma (o recibo nao tem os_id proprio e a tela dizia 'Sem OS vinculada'); a fatura tambem exibe a OS de cada despesa

## v5.39.3.0 — 2026-08-20 16:51
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Padroniza o favicon em todas as paginas: partial unico no desktop (login, recuperacao de senha, painel, impressao, pre-visualizacao e assinatura publica) e nas paginas HTML publicas da API (orcamento, documentos compartilhados e telas de erro)

## v5.39.2.1 — 2026-08-20 16:38
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige favicon da tela de login: remove o link 'alternate icon' que fazia o navegador exibir o icone padrao no lugar da logo da empresa

## v5.39.2.0 — 2026-08-20 12:32
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Campo Conta financeira passa a dizer se o dinheiro entra ou sai conforme o lancamento; corrige o modal de baixa que herdava o tipo da ultima linha da listagem e a tela de detalhe que nao informava o tipo, o que exibia campos de maquininha em conta a pagar e podia gerar despesa de taxa inexistente
- **Arquivos:** frontends/desktop/resources/views/financeiro/_account_select.blade.php,frontends/desktop/resources/views/financeiro/_lancamentos_table.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/_pagar_fatura_modal.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v5.39.1.0 — 2026-08-20 11:22
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Recibo de pagamento de fatura deixa de ser editavel/cancelavel/excluivel pelas telas genericas de Lancamentos: salvar por la apagava o vinculo com o cartao e devolvia o titulo para pendente, fazendo a fatura paga parecer nao registrada
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/resources/views/financeiro/_lancamentos_table.blade.php

## v5.39.0.0 — 2026-08-20 04:38
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fluxo de caixa: pagar fatura de cartao vira uma linha so no detalhe do dia, com as despesas cobertas listadas dentro dela, em vez de N saidas separadas. Totais do dia inalterados
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroReportService.php,backend/tests/Feature/Api/V1/FinanceiroReportTest.php,frontends/desktop/resources/views/financeiro/relatorios/fluxo-caixa.blade.php

## v5.38.1.0 — 2026-08-20 04:25
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Titulos financeiros ganham a coluna Baixa com a data em que a despesa foi paga ou a receita recebida, em Lancamentos e Despesas
- **Arquivos:** frontends/desktop/resources/views/financeiro/_lancamentos_table.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v5.38.0.0 — 2026-08-20 04:16
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Step-up de administrador passa a aceitar super administrador por RBAC (grupos:editar), alem do perfil legado admin. Vale para todos os fluxos que pedem senha de admin, com playbook e teste dedicado
- **Arquivos:** backend/app/Services/Auth/AdminCredentialVerifier.php,backend/tests/Feature/Auth/AdminCredentialVerifierTest.php,documentacao/04-governanca-ai/playbooks/step-up-de-administrador.md

## v5.37.3.0 — 2026-08-20 04:02
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Despesa de cartao no credito: edicao passa a ser pela fatura (listagem de Lancamentos leva ate ela), botao Editar em cada despesa da fatura e retorno para a fatura apos salvar
- **Arquivos:** frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/_lancamentos_table.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/fatura-show.blade.php,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php

## v5.37.2.0 — 2026-08-20 03:52
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Fatura paga passa a mostrar a data do pagamento: nova coluna Pagamento na tabela de faturas e Paga em DD/MM/AAAA no card Situacao da fatura
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/resources/views/financeiro/cartoes-credito/fatura-show.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/faturas.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php

## v5.37.1.0 — 2026-08-20 03:45
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Despesa esquecida: calendario da data da compra limitado a janela em que a fatura esteve aberta (abertura ate fechamento do ciclo), com a janela exposta em invoiceList e o hint mostrando o periodo
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/public/assets/js/financeiro-despesa-esquecida.js,frontends/desktop/resources/views/financeiro/cartoes-credito/_despesa_esquecida_modal.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/faturas.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php,specs/030-cartoes-credito-assistencia/spec.md

## v5.37.0.0 — 2026-08-20 03:35
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Mais acoes na tela de faturas do cartao: lancar despesa esquecida numa fatura ja paga. Entra ja quitada junto com a fatura (mesma data/forma/conta da baixa), corrigindo o total sem precisar cancelar a baixa e pagar de novo
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCartaoCreditoController.php,backend/app/Http/Requests/Api/V1/StoreForgottenFinanceiroCartaoCreditoExpenseRequest.php,backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/app/Http/Controllers/FinanceiroCartaoCreditoController.php,frontends/desktop/app/Services/FinanceiroCartaoCreditoService.php,frontends/desktop/resources/views/financeiro/cartoes-credito/_despesa_esquecida_modal.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/faturas.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php,specs/030-cartoes-credito-assistencia/spec.md

## v5.36.1.0 — 2026-08-20 03:27
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Faturas do cartao: coluna Fechamento na tabela, com a data derivada do vencimento pelo ciclo do cartao
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/resources/views/financeiro/cartoes-credito/faturas.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php

## v5.36.0.0 — 2026-08-20 02:58
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Data da compra no cartao so pode cair em fatura ainda aberta: save recusa lancamento em fatura ja paga e o calendario trava no dia seguinte ao fechamento da ultima fatura paga. Fatura vencida em aberto continua aceitando
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCartaoCreditoController.php,backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/public/assets/js/financeiro-form.js,specs/030-cartoes-credito-assistencia/spec.md

## v5.35.1.0 — 2026-08-20 01:55
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Despesa comprada no credito de cartao nao pode nascer paga: status trava em Pendente no formulario e o backend normaliza, pois quem liquida e a fatura. Nao afeta debito nem recebimento na maquininha
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/public/assets/js/financeiro-form.js,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php,specs/030-cartoes-credito-assistencia/spec.md

## v5.35.0.0 — 2026-08-20 01:12
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cancelar baixa de fatura de cartao passa a exigir confirmacao de administrador (e-mail e senha), mesma regra de excluir lancamento, com rate limit proprio e modal dedicado na tela da fatura
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCartaoCreditoController.php,backend/app/Http/Requests/Api/V1/CancelFinanceiroCartaoCreditoInvoiceRequest.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/app/Http/Controllers/FinanceiroCartaoCreditoController.php,frontends/desktop/app/Services/FinanceiroCartaoCreditoService.php,frontends/desktop/resources/views/financeiro/cartoes-credito/_cancelar_baixa_admin_modal.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/fatura-show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php,specs/030-cartoes-credito-assistencia/spec.md

## v5.34.0.0 — 2026-08-20 00:57
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fatura de cartao: acao Cancelar baixa da fatura em Mais acoes — estorna as despesas de volta para pendente, cancela o recibo do pagamento e reabre a fatura para ser paga de novo
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCartaoCreditoController.php,backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/app/Http/Controllers/FinanceiroCartaoCreditoController.php,frontends/desktop/app/Services/FinanceiroCartaoCreditoService.php,frontends/desktop/resources/views/financeiro/cartoes-credito/fatura-show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php,specs/030-cartoes-credito-assistencia/spec.md

## v5.33.0.0 — 2026-08-20 00:40
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Financeiro: pagamento de fatura de cartao passa a gerar um lancamento proprio (recibo da baixa em lote) visivel na listagem de lancamentos e despesas, sem somar ao total de despesas fixas/variaveis nem ao DRE/fluxo de caixa (as despesas que ele agrupa ja entram individualmente)
- **Arquivos:** backend/app/Models/Financeiro.php,backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php,specs/030-cartoes-credito-assistencia/spec.md

## v5.32.3.0 — 2026-08-19 21:55
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Fatura do cartao: botao Marcar fatura como paga na tela de fatura agora só aparece para a fatura corrente ou vencida (mesma regra ja aplicada na listagem de faturas), consistente com a despesa da listagem geral linkando direto para a fatura
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/resources/views/financeiro/cartoes-credito/fatura-show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php

## v5.32.2.0 — 2026-08-19 21:36
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Financeiro: despesa lancada no credito de cartao (fatura) nao pode mais ser baixada, cancelada ou excluida individualmente; baixa redireciona para a fatura de origem (guarda no backend + UI desktop)
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/resources/views/financeiro/_lancamentos_table.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php

## v5.32.1.0 — 2026-08-19 21:18
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Faturas do cartao: acao renomeada para Ver fatura e botao Pagar fatura direto na listagem (so na fatura atual e nas vencidas), com saldo em aberto real descontando baixas parciais
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/app/Http/Controllers/FinanceiroCartaoCreditoController.php,frontends/desktop/resources/views/financeiro/cartoes-credito/_pagar_fatura_modal.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/faturas.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/fatura-show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php

## v5.32.0.1 — 2026-08-19 21:07
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Navbar desktop: acao rapida 'Novo lancamento' no menu + Novo, linkando para financeiro/novo (financeiro:criar)
- **Arquivos:** frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v5.32.0.0 — 2026-08-19 21:00
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cartoes de credito da assistencia: cadastro em Contas e Saldos com conta vinculada, vinculo da despesa ao cartao com ciclo real de fatura (fechamento/vencimento), credito x debito, parcelamento, baixa da fatura em lote, filtros e KPI na listagem de faturas
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCartaoCreditoController.php,backend/app/Http/Controllers/Api/V1/FinanceiroCatalogController.php,backend/app/Http/Requests/Api/V1/PayFinanceiroCartaoCreditoInvoiceRequest.php,backend/app/Http/Requests/Api/V1/UpsertFinanceiroCartaoCreditoRequest.php,backend/app/Http/Requests/Api/V1/UpsertFinanceiroRequest.php,backend/app/Models/FinanceiroCartaoCredito.php,backend/app/Models/Financeiro.php,backend/app/Services/Financeiro/FinanceiroCartaoCreditoService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/database/migrations/2026_08_17_000001_create_financeiro_cartoes_credito_table.php,backend/database/migrations/2026_08_17_000002_add_cartao_credito_to_financeiro_table.php,backend/database/migrations/2026_08_17_000003_add_conta_financeira_to_financeiro_cartoes_credito.php,backend/database/migrations/2026_08_17_000004_add_cartao_modalidade_to_financeiro_table.php,backend/database/migrations/2026_08_17_000005_add_cartao_parcelas_to_financeiro_table.php,backend/routes/api.php,backend/tests/Feature/Api/V1/FinanceiroCartaoCreditoTest.php,frontends/desktop/app/Http/Controllers/FinanceiroCartaoCreditoController.php,frontends/desktop/app/Http/Controllers/FinanceiroContaController.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroCartaoCreditoService.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/public/assets/js/financeiro-form.js,frontends/desktop/public/assets/js/financeiro-pay.js,frontends/desktop/resources/views/financeiro/_cartao_credito_form_modal.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/faturas.blade.php,frontends/desktop/resources/views/financeiro/cartoes-credito/fatura-show.blade.php,frontends/desktop/resources/views/financeiro/contas/index.blade.php,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/resources/views/financeiro/_lancamentos_table.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartaoCreditoTest.php,specs/030-cartoes-credito-assistencia/spec.md

## v5.31.3.0 — 2026-08-15 14:24
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Dashboard: metrica Pendentes do Resumo financeiro agora soma despesas (financeiro) pendentes/parciais com vencimento ate o mes atual, nunca meses futuros; fonte trocada de OS em aberto para o modulo financeiro
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md

## v5.31.2.0 — 2026-08-15 13:40
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Dashboard: metrica Pendentes do Resumo financeiro agora considera apenas mes atual e meses anteriores, nunca meses futuros
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php

## v5.31.1.0 — 2026-08-12 22:34
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** PDV: campo de busca de produto/servico move para a coluna central, acima da lista de itens, no lugar da coluna de cliente/vendedor
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/routes/api.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/financeiro/contas/index.blade.php,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/resources/views/vendas/index.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php

## v5.31.0.1 — 2026-08-12 22:24
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** PDV: erro no console (Failed to execute requestFullscreen, API can only be initiated by a user gesture) ao recarregar a pagina em modo terminal; Fullscreen API real so e tentada dentro de um gesto (tecla F3 ou clique), o recarregamento usa so a classe CSS
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/routes/api.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/financeiro/contas/index.blade.php,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/resources/views/vendas/index.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php

## v5.31.0.0 — 2026-08-12 21:57
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Sidebar: remove entradas de Nova venda, Caixa e Devolucoes; Nova venda passa a ser so pelo botao da listagem de Vendas, Devolucoes entra em Mais acoes, e Caixa passa a ser acessado por Financeiro > Contas e Saldos
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/routes/api.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/financeiro/contas/index.blade.php,frontends/desktop/resources/views/vendas/index.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php

## v5.30.0.0 — 2026-08-12 20:14
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** PDV: modo terminal em tela cheia ganha cabecalho com logo e nome da empresa, e calendario do mes com relogio digital ao vivo abaixo do botao Finalizar
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/routes/api.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php

## v5.29.2.0 — 2026-08-12 18:55
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Revelar senha do equipamento reconhece grupo RBAC (ex.: super administrador), nao so o campo legado perfil=admin
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php

## v5.29.1.0 — 2026-08-12 18:52
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** PDV: dropdown de busca abre para cima quando nao ha espaco suficiente embaixo (campo perto do rodape em janela baixa), com altura maxima calculada dinamicamente; alinhamento do resultado corrigido para nomes longos
- **Arquivos:** backend/routes/api.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php

## v5.29.0.1 — 2026-08-12 18:24
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige rota ausente de revelar senha do equipamento (POST equipments/{equipment}/reveal-password retornava 405)
- **Arquivos:** backend/routes/api.php

## v5.29.0.0 — 2026-08-12 18:20
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** PDV: pagamento passa a ser um passo dentro de modal aberto por 'Finalizar venda' (F2), em vez de coluna sempre visivel; e corrigido o dropdown de busca que ficava escondido pelo overflow da coluna esquerda, agora posicionado via position:fixed
- **Arquivos:** documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php

## v5.28.1.0 — 2026-08-12 17:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** PDV: campos de pagamento (operadora, bandeira, modalidade, parcelas, conta) empilhados com um rotulo cada, no lugar do grid do Bootstrap que os espremia dentro da coluna estreita
- **Arquivos:** frontends/desktop/resources/views/vendas/pdv.blade.php

## v5.28.0.0 — 2026-08-12 11:23
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** PDV: modo terminal em tela cheia pelo F3 (Fullscreen API), escondendo topbar, sidebar e rodape, com fontes e alvos maiores como em caixa de supermercado
- **Arquivos:** backend/app/Http/Controllers/Api/V1/SaleReturnController.php,backend/app/Http/Requests/Api/V1/StoreSaleReturnRequest.php,backend/app/Models/CaixaSessao.php,backend/app/Models/Sale.php,backend/app/Models/SaleReturnItem.php,backend/app/Models/SaleReturnPayment.php,backend/app/Models/SaleReturn.php,backend/app/Services/Caixa/CaixaSessionService.php,backend/app/Services/Pdf/Contexts/SaleReturnPdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Sales/SaleReturnReceiptService.php,backend/app/Services/Sales/SaleReturnService.php,backend/app/Services/Sales/SaleStockService.php,backend/database/migrations/2026_08_14_000001_create_venda_devolucoes_tables.php,backend/database/migrations/2026_08_14_000002_seed_devolucao_categoria.php,backend/database/migrations/2026_08_14_000003_seed_devolucao_receipt_template.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/SaleReturnFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-08-14-devolucao-troca-venda.md,frontends/desktop/app/Http/Controllers/DevolucaoController.php,frontends/desktop/app/Services/DevolucaoService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/devolucoes/create.blade.php,frontends/desktop/resources/views/devolucoes/help.blade.php,frontends/desktop/resources/views/devolucoes/index.blade.php,frontends/desktop/resources/views/devolucoes/show.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/resources/views/vendas/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DevolucaoTest.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php,specs/029-devolucao-troca/spec.md

## v5.27.3.0 — 2026-08-12 09:50
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** PDV: coluna de cliente e vendedor com um campo por linha e mais largura, no lugar do grid do Bootstrap que espremia os campos dentro da coluna estreita
- **Arquivos:** backend/app/Http/Controllers/Api/V1/SaleReturnController.php,backend/app/Http/Requests/Api/V1/StoreSaleReturnRequest.php,backend/app/Models/CaixaSessao.php,backend/app/Models/Sale.php,backend/app/Models/SaleReturnItem.php,backend/app/Models/SaleReturnPayment.php,backend/app/Models/SaleReturn.php,backend/app/Services/Caixa/CaixaSessionService.php,backend/app/Services/Pdf/Contexts/SaleReturnPdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Sales/SaleReturnReceiptService.php,backend/app/Services/Sales/SaleReturnService.php,backend/app/Services/Sales/SaleStockService.php,backend/database/migrations/2026_08_14_000001_create_venda_devolucoes_tables.php,backend/database/migrations/2026_08_14_000002_seed_devolucao_categoria.php,backend/database/migrations/2026_08_14_000003_seed_devolucao_receipt_template.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/SaleReturnFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-08-14-devolucao-troca-venda.md,frontends/desktop/app/Http/Controllers/DevolucaoController.php,frontends/desktop/app/Services/DevolucaoService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/devolucoes/create.blade.php,frontends/desktop/resources/views/devolucoes/help.blade.php,frontends/desktop/resources/views/devolucoes/index.blade.php,frontends/desktop/resources/views/devolucoes/show.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/resources/views/vendas/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DevolucaoTest.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php,specs/029-devolucao-troca/spec.md

## v5.27.2.0 — 2026-08-12 09:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** PDV em tres colunas: cliente e busca a esquerda, carrinho ao centro crescendo conforme os itens entram, pagamento e fechamento a direita
- **Arquivos:** backend/app/Http/Controllers/Api/V1/SaleReturnController.php,backend/app/Http/Requests/Api/V1/StoreSaleReturnRequest.php,backend/app/Models/CaixaSessao.php,backend/app/Models/Sale.php,backend/app/Models/SaleReturnItem.php,backend/app/Models/SaleReturnPayment.php,backend/app/Models/SaleReturn.php,backend/app/Services/Caixa/CaixaSessionService.php,backend/app/Services/Pdf/Contexts/SaleReturnPdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Sales/SaleReturnReceiptService.php,backend/app/Services/Sales/SaleReturnService.php,backend/app/Services/Sales/SaleStockService.php,backend/database/migrations/2026_08_14_000001_create_venda_devolucoes_tables.php,backend/database/migrations/2026_08_14_000002_seed_devolucao_categoria.php,backend/database/migrations/2026_08_14_000003_seed_devolucao_receipt_template.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/SaleReturnFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-08-14-devolucao-troca-venda.md,frontends/desktop/app/Http/Controllers/DevolucaoController.php,frontends/desktop/app/Services/DevolucaoService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/devolucoes/create.blade.php,frontends/desktop/resources/views/devolucoes/help.blade.php,frontends/desktop/resources/views/devolucoes/index.blade.php,frontends/desktop/resources/views/devolucoes/show.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/resources/views/vendas/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DevolucaoTest.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php,specs/029-devolucao-troca/spec.md

## v5.27.1.1 — 2026-08-12 08:32
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** PDV: ponto de quebra do layout de duas colunas baixado de 1400px para 992px (telas de 1366px caiam no fallback empilhado) e altura movida para a grade, sem depender de :has()
- **Arquivos:** backend/app/Http/Controllers/Api/V1/SaleReturnController.php,backend/app/Http/Requests/Api/V1/StoreSaleReturnRequest.php,backend/app/Models/CaixaSessao.php,backend/app/Models/Sale.php,backend/app/Models/SaleReturnItem.php,backend/app/Models/SaleReturnPayment.php,backend/app/Models/SaleReturn.php,backend/app/Services/Caixa/CaixaSessionService.php,backend/app/Services/Pdf/Contexts/SaleReturnPdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Sales/SaleReturnReceiptService.php,backend/app/Services/Sales/SaleReturnService.php,backend/app/Services/Sales/SaleStockService.php,backend/database/migrations/2026_08_14_000001_create_venda_devolucoes_tables.php,backend/database/migrations/2026_08_14_000002_seed_devolucao_categoria.php,backend/database/migrations/2026_08_14_000003_seed_devolucao_receipt_template.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/SaleReturnFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-08-14-devolucao-troca-venda.md,frontends/desktop/app/Http/Controllers/DevolucaoController.php,frontends/desktop/app/Services/DevolucaoService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/devolucoes/create.blade.php,frontends/desktop/resources/views/devolucoes/help.blade.php,frontends/desktop/resources/views/devolucoes/index.blade.php,frontends/desktop/resources/views/devolucoes/show.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/resources/views/vendas/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DevolucaoTest.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php,specs/029-devolucao-troca/spec.md

## v5.27.1.0 — 2026-08-12 07:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** PDV em tela cheia: sidebar em menu sanduiche como na listagem de OS, cliente e vendedor acima da busca, pagamento e fechamento na coluna lateral, sem rolagem de pagina
- **Arquivos:** backend/app/Http/Controllers/Api/V1/SaleReturnController.php,backend/app/Http/Requests/Api/V1/StoreSaleReturnRequest.php,backend/app/Models/CaixaSessao.php,backend/app/Models/Sale.php,backend/app/Models/SaleReturnItem.php,backend/app/Models/SaleReturnPayment.php,backend/app/Models/SaleReturn.php,backend/app/Services/Caixa/CaixaSessionService.php,backend/app/Services/Pdf/Contexts/SaleReturnPdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Sales/SaleReturnReceiptService.php,backend/app/Services/Sales/SaleReturnService.php,backend/app/Services/Sales/SaleStockService.php,backend/database/migrations/2026_08_14_000001_create_venda_devolucoes_tables.php,backend/database/migrations/2026_08_14_000002_seed_devolucao_categoria.php,backend/database/migrations/2026_08_14_000003_seed_devolucao_receipt_template.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/SaleReturnFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-08-14-devolucao-troca-venda.md,frontends/desktop/app/Http/Controllers/DevolucaoController.php,frontends/desktop/app/Services/DevolucaoService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/devolucoes/create.blade.php,frontends/desktop/resources/views/devolucoes/help.blade.php,frontends/desktop/resources/views/devolucoes/index.blade.php,frontends/desktop/resources/views/devolucoes/show.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/resources/views/vendas/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DevolucaoTest.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php,specs/029-devolucao-troca/spec.md

## v5.27.0.0 — 2026-08-12 04:08
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Devolução e troca de venda: devolução parcial por item, reembolso pela forma original, retorno de estoque, abatimento em venda fiada e saída do caixa do turno
- **Arquivos:** backend/app/Http/Controllers/Api/V1/SaleReturnController.php,backend/app/Http/Requests/Api/V1/StoreSaleReturnRequest.php,backend/app/Models/CaixaSessao.php,backend/app/Models/Sale.php,backend/app/Models/SaleReturnItem.php,backend/app/Models/SaleReturnPayment.php,backend/app/Models/SaleReturn.php,backend/app/Services/Caixa/CaixaSessionService.php,backend/app/Services/Pdf/Contexts/SaleReturnPdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Sales/SaleReturnReceiptService.php,backend/app/Services/Sales/SaleReturnService.php,backend/app/Services/Sales/SaleStockService.php,backend/database/migrations/2026_08_14_000001_create_venda_devolucoes_tables.php,backend/database/migrations/2026_08_14_000002_seed_devolucao_categoria.php,backend/database/migrations/2026_08_14_000003_seed_devolucao_receipt_template.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/SaleReturnFlowTest.php,documentacao/07-novas-implementacoes/2026-08-14-devolucao-troca-venda.md,frontends/desktop/app/Http/Controllers/DevolucaoController.php,frontends/desktop/app/Services/DevolucaoService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/devolucoes/create.blade.php,frontends/desktop/resources/views/devolucoes/help.blade.php,frontends/desktop/resources/views/devolucoes/index.blade.php,frontends/desktop/resources/views/devolucoes/show.blade.php,frontends/desktop/resources/views/vendas/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DevolucaoTest.php,specs/029-devolucao-troca/spec.md

## v5.26.0.0 — 2026-08-11 23:38
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Módulo de Caixa: turnos com abertura declarada ou automática, sangria e suprimento, conferência cega no fechamento com diferença apurada e relatório 80mm
- **Arquivos:** backend/app/Http/Controllers/Api/V1/CaixaController.php,backend/app/Http/Controllers/Api/V1/EstoqueController.php,backend/app/Http/Controllers/Api/V1/SaleController.php,backend/app/Http/Requests/Api/V1/CancelSaleRequest.php,backend/app/Http/Requests/Api/V1/CloseCaixaRequest.php,backend/app/Http/Requests/Api/V1/OpenCaixaRequest.php,backend/app/Http/Requests/Api/V1/StoreCaixaMovimentoRequest.php,backend/app/Http/Requests/Api/V1/StoreSaleRequest.php,backend/app/Models/CaixaMovimento.php,backend/app/Models/CaixaSessao.php,backend/app/Models/Financeiro.php,backend/app/Models/SaleItem.php,backend/app/Models/SalePayment.php,backend/app/Models/Sale.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Caixa/CaixaReportService.php,backend/app/Services/Caixa/CaixaSessionService.php,backend/app/Services/Financeiro/FinanceiroContaService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Pdf/Contexts/CaixaPdfContextFactory.php,backend/app/Services/Pdf/Contexts/SalePdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Sales/InsufficientStockException.php,backend/app/Services/Sales/SalePaymentService.php,backend/app/Services/Sales/SaleReceiptService.php,backend/app/Services/Sales/SaleStockService.php,backend/app/Services/Sales/SaleWorkflowService.php,backend/app/Support/CommercialAdjustment.php,backend/database/migrations/2026_08_12_000001_create_vendas_module_tables.php,backend/database/migrations/2026_08_12_000002_add_sales_support_to_legacy_tables.php,backend/database/migrations/2026_08_12_000003_seed_vendas_module.php,backend/database/migrations/2026_08_12_000004_seed_venda_receipt_template.php,backend/database/migrations/2026_08_13_000001_create_caixa_module_tables.php,backend/database/migrations/2026_08_13_000002_seed_caixa_module.php,backend/database/migrations/2026_08_13_000003_seed_caixa_report_template.php,backend/openapi.yaml,backend/phpunit.xml,backend/resources/views/budgets/public/show.blade.php,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetCommercialTermsTest.php,backend/tests/Feature/Api/V1/CaixaFlowTest.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/SaleFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-08-10-condicoes-comerciais-orcamento-garantia-os.md,documentacao/07-novas-implementacoes/2026-08-11-integridade-leitura-pdf-botao-aprovacao-nome-documento.md,documentacao/07-novas-implementacoes/2026-08-12-modulo-vendas-balcao-pdv.md,documentacao/07-novas-implementacoes/2026-08-13-modulo-caixa-sessoes.md,frontends/desktop/app/Http/Controllers/CaixaController.php,frontends/desktop/app/Http/Controllers/StockController.php,frontends/desktop/app/Http/Controllers/VendaController.php,frontends/desktop/app/Services/CaixaService.php,frontends/desktop/app/Services/VendaService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/public/assets/js/pagamentos-cartao.js,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/caixa/_abertura_modal.blade.php,frontends/desktop/resources/views/caixa/_abrir_modal.blade.php,frontends/desktop/resources/views/caixa/_fechar_modal.blade.php,frontends/desktop/resources/views/caixa/help.blade.php,frontends/desktop/resources/views/caixa/historico.blade.php,frontends/desktop/resources/views/caixa/index.blade.php,frontends/desktop/resources/views/caixa/_movimento_modal.blade.php,frontends/desktop/resources/views/caixa/show.blade.php,frontends/desktop/resources/views/estoque/form.blade.php,frontends/desktop/resources/views/estoque/movimentacoes.blade.php,frontends/desktop/resources/views/vendas/_cancel_modal.blade.php,frontends/desktop/resources/views/vendas/help.blade.php,frontends/desktop/resources/views/vendas/index.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/resources/views/vendas/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/CaixaTest.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php,specs/027-vendas-balcao-pdv/spec.md,specs/028-caixa-sessoes/spec.md

## v5.25.0.0 — 2026-08-11 22:38
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Módulo de Vendas (balcão/PDV): venda de produtos e serviços sem OS, com baixa de estoque, título e baixas no financeiro, cupom 80mm e cancelamento com estorno
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EstoqueController.php,backend/app/Http/Controllers/Api/V1/SaleController.php,backend/app/Http/Requests/Api/V1/CancelSaleRequest.php,backend/app/Http/Requests/Api/V1/StoreSaleRequest.php,backend/app/Models/Financeiro.php,backend/app/Models/SaleItem.php,backend/app/Models/SalePayment.php,backend/app/Models/Sale.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Pdf/Contexts/SalePdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Sales/InsufficientStockException.php,backend/app/Services/Sales/SalePaymentService.php,backend/app/Services/Sales/SaleReceiptService.php,backend/app/Services/Sales/SaleStockService.php,backend/app/Services/Sales/SaleWorkflowService.php,backend/app/Support/CommercialAdjustment.php,backend/database/migrations/2026_08_12_000001_create_vendas_module_tables.php,backend/database/migrations/2026_08_12_000002_add_sales_support_to_legacy_tables.php,backend/database/migrations/2026_08_12_000003_seed_vendas_module.php,backend/database/migrations/2026_08_12_000004_seed_venda_receipt_template.php,backend/openapi.yaml,backend/phpunit.xml,backend/resources/views/budgets/public/show.blade.php,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetCommercialTermsTest.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/SaleFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-08-10-condicoes-comerciais-orcamento-garantia-os.md,documentacao/07-novas-implementacoes/2026-08-11-integridade-leitura-pdf-botao-aprovacao-nome-documento.md,documentacao/07-novas-implementacoes/2026-08-12-modulo-vendas-balcao-pdv.md,frontends/desktop/app/Http/Controllers/StockController.php,frontends/desktop/app/Http/Controllers/VendaController.php,frontends/desktop/app/Services/VendaService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/public/assets/js/pagamentos-cartao.js,frontends/desktop/public/assets/js/vendas-pdv.js,frontends/desktop/resources/views/estoque/form.blade.php,frontends/desktop/resources/views/estoque/movimentacoes.blade.php,frontends/desktop/resources/views/vendas/_cancel_modal.blade.php,frontends/desktop/resources/views/vendas/help.blade.php,frontends/desktop/resources/views/vendas/index.blade.php,frontends/desktop/resources/views/vendas/pdv.blade.php,frontends/desktop/resources/views/vendas/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/VendaTest.php,specs/027-vendas-balcao-pdv/spec.md

## v5.24.4.0 — 2026-08-11 16:57
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Pagina publica: Aprovar e Rejeitar na mesma linha acima do campo de motivo (ligados por atributo form) e Baixar PDF ao final; suite de testes passa a compilar Blade em diretorio proprio
- **Arquivos:** backend/resources/views/budgets/public/show.blade.php,backend/phpunit.xml,backend/tests/Feature/Api/V1/BudgetCommercialTermsTest.php

## v5.24.3.0 — 2026-08-11 03:32
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Botao Copiar ao lado de cada chave Pix na pagina publica de aprovacao, com fallback de selecao para origem sem contexto seguro
- **Arquivos:** backend/resources/views/budgets/public/show.blade.php,backend/tests/Feature/Api/V1/BudgetCommercialTermsTest.php

## v5.24.2.0 — 2026-08-11 03:18
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Condicoes comerciais na pagina publica de aprovacao passam a ser blocos estruturados (chips de forma de pagamento, garantia em destaque e chave Pix isolada) em vez de linhas corridas
- **Arquivos:** backend/resources/views/budgets/public/show.blade.php,backend/tests/Feature/Api/V1/BudgetCommercialTermsTest.php

## v5.24.1.0 — 2026-08-11 03:01
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Botao de aprovacao verde que some quando o orcamento vence, e agrupamento de quebra de pagina passa a valer para a secao inteira (nao so o primeiro bloco)
- **Arquivos:** backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/app/Services/Pdf/Contexts/BudgetPdfContextFactory.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/resources/views/pdf-engine/document.blade.php,backend/tests/Unit/Services/Pdf/PdfDocumentLayoutTest.php,backend/tests/Feature/Api/V1/BudgetCommercialTermsTest.php

## v5.24.0.0 — 2026-08-11 02:22
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Integridade de leitura no motor de PDF (cabecalho de secao nunca separa do conteudo, tabela curta indivisivel), link de aprovacao vira botao clicavel com validade e nome do documento editavel no editor
- **Arquivos:** backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/app/Services/Pdf/PdfSchemaValidator.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Pdf/PdfTemplateAdminService.php,backend/resources/views/pdf-engine/document.blade.php,backend/resources/views/pdf-engine/blocks/botao-link.blade.php,backend/resources/views/pdf-engine/blocks/tabela.blade.php,backend/database/migrations/2026_08_11_000001_replace_budget_approval_link_with_button.php,backend/tests/Unit/Services/Pdf/PdfDocumentLayoutTest.php,frontends/desktop/resources/views/knowledge/pdf-templates/engine-edit.blade.php,frontends/desktop/public/assets/js/pdf-template-editor.js

## v5.23.1.0 — 2026-08-11 00:15
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Revisao final do orcamento passa a exibir as condicoes comerciais e a garantia de forma organizada, em vez de so o campo livre
- **Arquivos:** frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/tests/Unit/BudgetCommercialTermsAssetsTest.php

## v5.23.0.0 — 2026-08-10 23:28
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Condicoes comerciais estruturadas no orcamento (formas de pagamento, chaves Pix, parcelamento sem juros) e garantia registrada na OS com data de termino, refletidas nos modelos PDF
- **Arquivos:** backend/database/migrations/2026_08_10_000001_create_financeiro_chaves_pix_table.php,backend/database/migrations/2026_08_10_000002_add_commercial_conditions_to_orcamentos.php,backend/database/migrations/2026_08_10_000003_add_commercial_terms_to_pdf_templates.php,backend/app/Services/Budgets/BudgetCommercialTermsService.php,backend/app/Models/FinanceiroChavePix.php,backend/app/Models/BudgetPaymentMethod.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/financeiro/configuracoes.blade.php,frontends/desktop/resources/views/orders/closure.blade.php

## v5.22.0.0 — 2026-08-10 07:51
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Orcamento vencido passa a ter status Vencido: comando agendado app:expire-budgets, marcacao imediata no acesso ao link publico, historico/evento/aviso no sino e protecao contra reabrir OS ja encerrada
- **Arquivos:** backend/app/Console/Commands/ExpireStaleBudgets.php,backend/app/Models/Budget.php,backend/app/Models/OrderEvent.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/resources/views/errors/404.blade.php,backend/resources/views/errors/410.blade.php,backend/resources/views/errors/419.blade.php,backend/resources/views/errors/429.blade.php,backend/resources/views/errors/500.blade.php,backend/resources/views/errors/layout.blade.php,backend/routes/console.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md

## v5.21.1.0 — 2026-08-10 07:43
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige o link publico de orcamento vencido: paginas de erro proprias em portugues (404/410/419/429/500) e renovacao da validade no reenvio, para o link nao nascer expirado
- **Arquivos:** backend/app/Services/Budgets/BudgetApprovalService.php,backend/resources/views/errors/404.blade.php,backend/resources/views/errors/410.blade.php,backend/resources/views/errors/419.blade.php,backend/resources/views/errors/429.blade.php,backend/resources/views/errors/500.blade.php,backend/resources/views/errors/layout.blade.php

## v5.21.0.0 — 2026-07-30 20:06
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Financeiro: OS e fornecedor por busca select2 no lançamento, com autofill de cliente pela OS
- **Arquivos:** frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/public/assets/js/financeiro-form.js,frontends/desktop/resources/views/financeiro/create.blade.php,frontends/desktop/resources/views/financeiro/edit.blade.php,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/routes/web.php

## v5.20.7.0 — 2026-07-28 07:31
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Preenche automaticamente o relato do cliente / defeito relatado no orçamento com base na OS vinculada selecionada.
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php

## v5.20.6.0 — 2026-07-27 07:25
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige conflitos da CSP no mobile removendo estilos inline, impede regressões no lint e normaliza `meta.pagination` para eliminar o falso erro após carregar as OS.
- **Arquivos:** frontends/mobile,documentacao/03-arquitetura-tecnica/ordens-mobile.md,documentacao/07-novas-implementacoes/2026-07-27-csp-estilos-inline-mobile.md,documentacao/07-novas-implementacoes/historico-de-versoes.md

## v5.20.5.0 — 2026-07-26 22:25
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move a decisão de gerar e enviar o PDF de Atendimento para o card Extras da Revisão da Nova OS mobile.
- **Arquivos:** frontends/mobile/src/components/orders/order-form-wizard,documentacao/03-arquitetura-tecnica/ordens-mobile.md,documentacao/07-novas-implementacoes/2026-07-26-pdf-card-extras-revisao-nova-os.md,specs/025-revisao-atomica-nova-os-mobile

## v5.20.4.0 — 2026-07-26 21:33
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Adiciona revisão verificável, prazo automático, PDF sob demanda e salvamento atômico à Nova OS mobile.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/mobile/src/components/orders/order-form-wizard,frontends/mobile/src/lib,documentacao/03-arquitetura-tecnica/ordens-mobile.md,documentacao/07-novas-implementacoes/2026-07-26-revisao-atomica-nova-os-mobile.md,specs/025-revisao-atomica-nova-os-mobile

## v5.20.3.0 — 2026-07-26 18:52
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige o PWA standalone no iOS para nunca ficar preso na sincronizacao de sessao, com storage resiliente e deadline de validacao.
- **Arquivos:** documentacao/07-novas-implementacoes/2026-07-26-bootstrap-sessao-pwa-ios.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/mobile/README.md,frontends/mobile/src/components/__tests__/session-provider.test.tsx,frontends/mobile/src/components/session-provider.tsx,frontends/mobile/src/lib/__tests__/session.test.ts,frontends/mobile/src/lib/api.ts,frontends/mobile/src/lib/session.ts,specs/005-pwa-mobile-session/spec.md,specs/005-pwa-mobile-session/tasks.md

## v5.20.2.0 — 2026-07-26 16:59
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Atualiza Next e Sharp do PWA para versoes corrigidas e zera vulnerabilidades conhecidas nas dependencias de producao.
- **Arquivos:** documentacao/07-novas-implementacoes/2026-07-26-hardening-dependencias-pwa-next-sharp.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/mobile/package.json,frontends/mobile/pnpm-lock.yaml,frontends/mobile/pnpm-workspace.yaml

## v5.20.1.0 — 2026-07-26 16:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige a instalacao do PWA mobile na VPS com captura antecipada do prompt, manifest compativel e orientacao para iOS e Android.
- **Arquivos:** .agents/skills/sistema-erp-deploy-producao/references/problemas-conhecidos.md,documentacao/07-novas-implementacoes/2026-07-26-instalacao-pwa-mobile-vps.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,documentacao/10-deploy/deploy-producao-contabo-vps.md,frontends/mobile/public/pwa-install-bootstrap.js,frontends/mobile/src/app/__tests__/manifest.test.ts,frontends/mobile/src/app/globals.css,frontends/mobile/src/app/layout.tsx,frontends/mobile/src/app/manifest.ts,frontends/mobile/src/components/__tests__/pwa-install-button.test.tsx,frontends/mobile/src/components/pwa-install-button.tsx

## v5.20.0.0 — 2026-07-26 16:20
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Transforma a Bottom Nav em player exclusivo da criação de OS mobile, com navegação, validação completa, salvamento idempotente e cancelamento protegido.
- **Arquivos:** documentacao/03-arquitetura-tecnica/ordens-mobile.md,documentacao/07-novas-implementacoes/2026-07-26-player-criacao-os-bottom-nav-mobile.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/mobile/src/app/globals.css,frontends/mobile/src/app/os/novo/page.tsx,frontends/mobile/src/components/__tests__/authenticated-shell.test.tsx,frontends/mobile/src/components/authenticated-shell.tsx,frontends/mobile/src/components/orders/__tests__/order-creation-player.test.tsx,frontends/mobile/src/components/orders/order-creation-player.tsx,frontends/mobile/src/components/orders/order-form-wizard/__tests__/wizard-state.test.ts,frontends/mobile/src/components/orders/order-form-wizard/index.tsx,frontends/mobile/src/components/orders/order-form-wizard/steps/step-client.tsx,frontends/mobile/src/components/orders/order-form-wizard/steps/step-details.tsx,frontends/mobile/src/components/orders/order-form-wizard/steps/step-equipment.tsx,frontends/mobile/src/components/orders/order-form-wizard/steps/step-operations.tsx,frontends/mobile/src/components/orders/order-form-wizard/steps/step-review.tsx,frontends/mobile/src/components/orders/order-form-wizard/wizard-state.ts,specs/024-player-criacao-os-mobile/plan.md,specs/024-player-criacao-os-mobile/spec.md,specs/024-player-criacao-os-mobile/tasks.md

## v5.19.2.0 — 2026-07-26 15:37
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Exibe as fotos reais da OS no mobile com miniatura autenticada, sem corte e carregamento seguro.
- **Arquivos:** documentacao/03-arquitetura-tecnica/ordens-mobile.md,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-26-miniaturas-fotos-os-mobile.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/mobile/src/app/globals.css,frontends/mobile/src/components/orders/__tests__/order-attachments.test.tsx,frontends/mobile/src/components/orders/order-attachments.tsx

## v5.19.1.0 — 2026-07-26 14:02
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige o fundo do tema claro e alinha os acentos e a tipografia mobile a identidade azul Jovem Tech.
- **Arquivos:** documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-26-tema-claro-identidade-jovem-tech-mobile.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/mobile/public/favicon.svg,frontends/mobile/public/theme-bootstrap.js,frontends/mobile/src/app/globals.css,frontends/mobile/src/components/orders/order-attachments.tsx,frontends/mobile/src/lib/__tests__/theme.test.ts,frontends/mobile/src/lib/theme.ts

## v5.19.0.0 — 2026-07-26 07:58
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Lista com foto os equipamentos do cliente na Nova OS mobile e inicia o cadastro vinculado quando a lista estiver vazia.
- **Arquivos:** documentacao/07-novas-implementacoes/2026-07-26-selecao-equipamento-cliente-mobile.md,frontends/mobile/src/app/globals.css,frontends/mobile/src/components/orders/order-form-wizard/__tests__/search-select.test.tsx,frontends/mobile/src/components/orders/order-form-wizard/__tests__/step-equipment.test.tsx,frontends/mobile/src/components/orders/order-form-wizard/__tests__/wizard-state.test.ts,frontends/mobile/src/components/orders/order-form-wizard/index.tsx,frontends/mobile/src/components/orders/order-form-wizard/search-select.tsx,frontends/mobile/src/components/orders/order-form-wizard/steps/step-equipment.tsx,frontends/mobile/src/components/orders/order-form-wizard/wizard-state.ts,frontends/mobile/src/lib/__tests__/orders-wizard-api.test.ts,frontends/mobile/src/lib/api.ts,frontends/mobile/src/lib/orders.ts

## v5.18.0.0 — 2026-07-26 01:59
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Reorganiza a navegacao mobile em cinco acoes e impede deploy com chunks Next.js inconsistentes.
- **Arquivos:** documentacao/07-novas-implementacoes/2026-07-26-navegacao-mobile-e-consistencia-de-chunks.md,frontends/mobile/scripts/run-next.mjs,frontends/mobile/src/app/globals.css,frontends/mobile/src/app/login/page.tsx,frontends/mobile/src/app/os/page.tsx,frontends/mobile/src/app/page.tsx,frontends/mobile/src/components/__tests__/authenticated-shell.test.tsx,frontends/mobile/src/components/authenticated-shell.tsx,frontends/mobile/src/components/pwa-install-button.tsx,frontends/mobile/src/lib/__tests__/navigation.test.ts,frontends/mobile/src/lib/navigation.ts,scripts/bash/deploy-completo.sh

## v5.17.2.0 — 2026-07-26 01:40
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Atualiza PostCSS do PWA para versao corrigida contra path traversal em source maps (GHSA-r28c-9q8g-f849).
- **Arquivos:** frontends/mobile/pnpm-lock.yaml,frontends/mobile/pnpm-workspace.yaml

## v5.17.1.0 — 2026-07-26 01:34
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige permissoes do checkout e dos caches Laravel no deploy de producao, preservando leitura pelo PHP-FPM.
- **Arquivos:** scripts/bash/deploy-producao.sh

## v5.17.0.0 — 2026-07-26 01:26
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Integra o PWA mobile ao deploy de producao com validacao, release atomico, cache versionado e rollback automatico.
- **Arquivos:** frontends/mobile/pnpm-lock.yaml,infra/linux/supervisor-mobile-vps.conf,scripts/bash/deploy-completo.sh,scripts/bash/deploy-producao.sh

## v5.16.8.0 — 2026-07-25 22:18
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige a inicializacao do seletor remoto de orcamentos na Nova OS, evitando colisao com o Select2 global.
- **Arquivos:** frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-25-orcamentos-avulsos-vinculaveis-nova-os.md

## v5.16.7.0 — 2026-07-25 21:23
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Amplia o vinculo da Nova OS para todos os orcamentos avulsos ativos, preservando a aprovacao pendente e bloqueando estados terminais.
- **Arquivos:** backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-25-orcamentos-avulsos-vinculaveis-nova-os.md

## v5.16.6.0 — 2026-07-25 20:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Torna marca e modelo obrigatorios no cadastro de equipamentos e na criacao diferida da Nova OS.
- **Arquivos:** frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,backend/app/Http/Requests/Api/V1/StoreEquipmentRequest.php,backend/app/Http/Requests/Api/V1/UpdateEquipmentRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/EquipmentCreationTest.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/07-novas-implementacoes/2026-07-25-marca-modelo-obrigatorios-equipamento.md

## v5.16.5.0 — 2026-07-25 20:24
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move a decisão obrigatória Enviar PDF ao cliente para um rodapé de largura total na abertura da OS, mantendo a opção à esquerda e Cancelar/Próximo agrupados à direita.
- **Arquivos:** frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-25-envio-pdf-rodape-nova-os.md

## v5.16.4.0 — 2026-07-25 20:11
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Restringe a aba Informações técnicas à edição de equipamentos Desktop e Notebook, ocultando-a para Smartphone e demais famílias e atualizando sua visibilidade ao trocar o tipo.
- **Arquivos:** frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-25-aba-informacoes-tecnicas-equipamento.md

## v5.16.3.0 — 2026-07-25 20:04
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige a aba Informações técnicas vazia em equipamentos não computacionais: o Painel técnico permanece visível em toda edição, enquanto o Coletor continua restrito a Desktop e Notebook.
- **Arquivos:** frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-25-aba-informacoes-tecnicas-equipamento.md

## v5.16.2.0 — 2026-07-25 17:35
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Reorganiza o cadastro de equipamentos: Coletor de hardware e Painel técnico passam para a aba Informações técnicas, visível somente na edição, mantendo Estado físico e Observações na aba Informações.
- **Arquivos:** frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-25-aba-informacoes-tecnicas-equipamento.md

## v5.16.1.0 — 2026-07-25 17:16
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Torna o checklist de entrada obrigatório e sem classificação automática na abertura da OS, adiciona Não se aplica e Todos OK, e exige observação para discrepâncias no desktop e na API.
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_checklist_detail_modal.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-25-checklist-entrada-obrigatorio.md

## v5.16.0.0 — 2026-07-25 16:07
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Orcamento avulso ainda aguardando resposta do cliente agora pode ser vinculado na abertura da OS: a OS nasce automaticamente com status 'aguardando_autorizacao' e o orcamento permanece aberto/editavel (nao vira convertido/imutavel) ate o cliente aprovar de fato pelo link publico; orcamento ja aprovado (pendente_abertura_os) continua com o comportamento definitivo de sempre
- **Arquivos:** backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php

## v5.15.1.0 — 2026-07-25 14:56
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Reposiciona o campo 'Vincular orcamento avulso aprovado' na Nova OS: sai do topo da pagina e passa a ficar logo abaixo do campo Cliente, dentro da propria aba Cliente (sem alterar busca/vinculo, so a posicao)
- **Arquivos:** frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.15.0.0 — 2026-07-25 14:35
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Sugestao de orcamento avulso (nome/telefone) no cadastro rapido de cliente da Nova OS: busca contatos avulsos em aberto, autopreenche o formulario e oferece vincular a OS reaproveitando o fluxo existente de conversao orcamento->OS (sem alterar as regras de elegibilidade/hardening do spec 023)
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/clients/quick-modal.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/css/desktop.css

## v5.14.4.0 — 2026-07-25 13:08
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Reduz a latencia do fallback das filas documentais: o worker agendado permanece ativo por ate 55 segundos e consome novos jobs em poucos segundos quando o Supervisor esta indisponivel.
- **Arquivos:** backend/routes/console.php,backend/tests/Feature/Queue/QueueResilienceTest.php,documentacao/02-infraestrutura-ambientes/cors-urls-logs-filas-scheduler.md,documentacao/07-novas-implementacoes/2026-07-11-central-documentos-cliente-os.md

## v5.14.3.0 — 2026-07-25 12:53
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige envios documentais presos na fila: adiciona fallback limitado pelo scheduler quando o Supervisor entra em FATAL, torna a saúde dos workers obrigatória no deploy, registra falha terminal sanitizada, remove a cópia do destino em texto puro dos metadados e mantém `retry_after` acima do timeout do job para impedir processamento concorrente.
- **Arquivos:** backend/app/Jobs/ProcessOrderDocumentSendJob.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/config/queue.php,backend/routes/console.php,backend/database/migrations/2026_07_25_130000_remove_plaintext_destination_from_document_sends.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,backend/tests/Feature/Queue/QueueResilienceTest.php,documentacao/02-infraestrutura-ambientes/cors-urls-logs-filas-scheduler.md,documentacao/07-novas-implementacoes/2026-07-11-central-documentos-cliente-os.md,scripts/bash/atualizar-dev.sh

## v5.14.2.0 — 2026-07-25 09:08
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Campo 'Condições comerciais' movido da aba 'Dados operacionais' para a aba 'Orçamento e financeiro', logo após os itens do orçamento e antes do resumo financeiro — por ser um campo de natureza financeira, não operacional.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.14.1.0 — 2026-07-25 09:02
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Campo 'Prazo de execução' do orçamento passa a ser obrigatório em qualquer orçamento novo (aba 'Dados operacionais'). O assistente 'Próximo' passa a exigi-lo antes de liberar 'Criar orçamento'; validação equivalente adicionada no servidor (controller desktop).
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.14.0.0 — 2026-07-25 08:37
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** OS que já possuem um orçamento vinculado (qualquer status — inclusive rascunho, rejeitado, vencido ou cancelado) deixam de ser listadas em 'OS vinculada' ao criar um novo orçamento avulso, evitando um segundo orçamento na mesma OS. Ao editar um orçamento já vinculado a uma OS, a própria OS continua aparecendo normalmente na lista (a exclusão não se aplica ao orçamento que está sendo editado).
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.13.10.0 — 2026-07-25 08:14
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** No orçamento avulso, escolher uma 'OS vinculada' passa a pré-selecionar automaticamente o equipamento cadastrado vinculado a ela no campo 'Equipamento cadastrado' (uma OS sempre tem um único equipamento). Evita o técnico ter que escolher o mesmo aparelho duas vezes e já aciona a ocultação do card de equipamento eventual.
- **Arquivos:** documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.13.9.0 — 2026-07-25 08:01
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** No orçamento avulso, o card 'Equipamento eventual' (Tipo/Marca/Modelo/Cor) passa a ficar oculto — não só desabilitado — quando o técnico escolhe um equipamento já cadastrado do cliente selecionado. Antes o card continuava visível com os campos travados, dando a impressão de que ainda era possível preenchê-los. Volta a aparecer normalmente se o equipamento cadastrado for removido da seleção.
- **Arquivos:** frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.13.8.0 — 2026-07-25 01:16
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige logoff indevido ao criar OS com envio de PDF ao cliente: essa é a única requisição de abertura de OS lenta o bastante (geração do PDF + até duas tentativas de envio por WhatsApp, cada uma com timeout de até 20s no backend) para estourar a janela de 10s em que o guard de sessão considera a saída da página como 'navegação interna'. Passado esse prazo, o pagehide da navegação real (já sem a flag) marcava a saída como fechamento do navegador, deslogando o usuário na página seguinte. Janela ampliada para 60s, com folga sobre o pior caso, sem enfraquecer a detecção real de fechamento do navegador (verificado com simulação: submissão lenta não desloga; fechamento real, com ou sem navegação cancelada antes, continua deslogando).
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/item-row.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v5.13.7.0 — 2026-07-24 23:07
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Card grande de 'Gerando OS a partir do orçamento X' na criação de OS vira uma linha compacta ('Orçamento') no painel Resumo da OS, com botão de informação que abre um modal com o mesmo texto explicativo e a ação 'Remover ou trocar'. Preserva os atributos data-linked-budget-* e o link data-order-unlink-budget consumidos pelo orders-create.js.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/item-row.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.13.6.0 — 2026-07-24 21:53
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Campo 'Prazo de execução' do orçamento vira select com opções fixas (1, 3, 7, 15 e 30 dias), substituindo o texto livre. Valor legado/vindo da OS (ex.: 'Previsão: dd/mm/aaaa') que não bate com nenhuma opção é preservado como opção extra selecionada, sem perder dados em orçamentos existentes.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/item-row.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.13.5.0 — 2026-07-24 21:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Campo 'Tipo' do equipamento eventual (novo orçamento) passa a ser um Select2 populado com os tipos de equipamento cadastrados no banco (EquipmentType ativos), com opção de digitar um novo tipo. Substitui o campo de texto livre; a validação de obrigatoriedade e a exclusividade registrado × eventual foram adaptadas para o novo controle.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/item-row.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.13.4.0 — 2026-07-24 20:43
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Novo orçamento: Tipo, Marca, Modelo e Cor do equipamento eventual passam a ser obrigatórios (antes só Modelo), e o Relato do cliente/defeito relatado vira obrigatório sempre (inclusive serviço sem aparelho). O assistente 'Próximo' exige esses campos e a aba correspondente antes de liberar 'Criar orçamento'; validação equivalente adicionada no servidor (controller desktop).
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/item-row.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.13.3.0 — 2026-07-24 18:11
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige a trava do botão do novo orçamento: um </div> órfão na aba financeiro fechava o <form> cedo demais, jogando o botão 'Próximo/Criar orçamento' para fora do formulário. Com isso o JS nunca encontrava o botão (form.querySelector) — o rótulo não alternava para 'Criar orçamento', as pendências por aba não apareciam e o clique não avançava entre as abas. Movendo o botão de volta para dentro do form, o assistente passo-a-passo volta a funcionar.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/item-row.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.13.2.0 — 2026-07-24 17:16
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige definitivamente a ação Próximo do novo orçamento, com validação por aba, captura resiliente do clique, erro visível e rótulo final Criar orçamento.
- **Arquivos:** frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,VERSION,shared/version.php,CHANGELOG.md

## v5.13.1.0 — 2026-07-24 16:52
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige o botão Próximo do novo orçamento com avanço sequencial entre abas e navegação independente do recálculo financeiro.
- **Arquivos:** frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,VERSION,shared/version.php,CHANGELOG.md

## v5.13.0.0 — 2026-07-24 08:33
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Orçamento: campo 'OS vinculada' movido para a aba Dados do cliente, listando apenas OS abertas do cliente selecionado (oculto quando não há nenhuma); 'Equipamento cadastrado' passa a listar apenas os equipamentos do cliente escolhido, com miniatura da foto principal ao lado. Novo endpoint de contexto por cliente (OS abertas + equipamentos com foto) consumido ao trocar o cliente no Select2.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/item-row.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.12.2.0 — 2026-07-24 05:23
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Consolida a trava do novo orçamento: o botão permanece como "Próximo" e conduz à primeira pendência até cliente, telefone, contexto de equipamento e financeiro estarem completos; somente então exibe "Salvar orçamento". O controller desktop aplica o mesmo contrato, valida telefone e itens e recalcula os totais para impedir bypass por POST manual ou manipulação do DOM.
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/item-row.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-24-hardening-trava-orcamento-completo.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,VERSION,shared/version.php,CHANGELOG.md

## v5.12.1.0 — 2026-07-24 04:07
- **Tier:** patch
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Cadastro de orçamento novo trava o botão principal (vira 'Próximo') até os campos obrigatórios de todas as abas estarem preenchidos; só libera 'Salvar orçamento' quando completo, com indicador de pendência por aba
- **Arquivos:** frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/css/desktop.css

## v5.12.0.0 — 2026-07-24 03:55
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Corrige o seletor de clientes do novo orcamento com catalogo remoto paginado, busca completa, resposta minimizada e preservacao segura em rascunhos.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md,documentacao/07-novas-implementacoes/2026-07-24-select2-clientes-orcamento-paginado.md

## v5.11.1.0 — 2026-07-24 03:27
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige a perda visual e o risco de descarte silencioso da foto do novo equipamento durante a criação de OS a partir de orçamento avulso. A foto permanece apenas na memória do navegador, aparece no card e na aba Fotos, é normalizada entre iframe e página principal e segue como multipart somente no salvamento final. Desktop e API agora rejeitam novo equipamento sem foto antes de persistir qualquer registro.
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-24-foto-diferida-equipamento-na-criacao-os.md,VERSION,shared/version.php,CHANGELOG.md

## v5.11.0.0 — 2026-07-24 00:17
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Protege a conversão de orçamento avulso aprovado em OS com permissão dedicada, catálogo remoto paginado e minimizado, validação transacional sob lock, bloqueio de conversão duplicada e imutabilidade do orçamento convertido; corrige a criação de OS sem garantia informada e preserva o PDF A4 no acervo quando o layout térmico opcional estiver indisponível.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderBudgetLinkException.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_23_000001_harden_budget_order_linking.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-23-hardening-vinculo-orcamento-os.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/index.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,specs/023-hardening-vinculo-orcamento-os/plan.md,specs/023-hardening-vinculo-orcamento-os/spec.md,specs/023-hardening-vinculo-orcamento-os/tasks.md,VERSION,shared/version.php

## v5.10.0.0 — 2026-07-23 20:57
- **Tier:** minor
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Cadastro de equipamento na abertura de OS agora exige foto obrigatoria (e demais campos): o botao so vira 'Criar equipamento' quando tipo/cliente/foto estao completos, senao mostra 'Proximo' e leva ao campo pendente. As fotos do equipamento novo sao capturadas no navegador e criadas de forma atomica junto com a OS (nada persiste antes do salvamento). Gate vale para o cadastro embarcado (fluxo OS) e o avulso.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_photo_crop_modal.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.9.3.4 — 2026-07-23 20:12
- **Tier:** hotfix
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Corrige logoff ao cancelar o cadastro de equipamento (iframe) na abertura de OS: o guard de sessao rodava dentro do iframe embedded e seu pagehide (ao fechar/about:blank) marcava 'navegador fechado' no localStorage compartilhado, derrubando a sessao. Guard agora nao roda em paginas embedded.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_photo_crop_modal.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.9.3.3 — 2026-07-23 20:08
- **Tier:** hotfix
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Corrige alerta falso 'Imagem invalida' ao reabrir a abertura de OS: o <img src=''> vazio do modal de recorte disparava o evento 'error'. Remove o src vazio (OS e equipamento) e ignora erro de imagem com src vazio.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_photo_crop_modal.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.9.3.2 — 2026-07-23 20:01
- **Tier:** hotfix
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Corrige logout indevido ao cancelar a geracao de OS: o redirect programatico (window.location) nao era reconhecido como navegacao interna pelo guard de sessao (detecta fechamento do navegador) e forcava logout. Agora navega via clique de ancora, marcado como navegacao interna. Mesmo ajuste no seletor de orcamento.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.9.3.1 — 2026-07-23 19:46
- **Tier:** hotfix
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Corrige o cancelamento da geracao de OS que redirecionava para /dashboard e deslogava usuarios sem permissao de dashboard; agora vai para a raiz (/), que roteia cada usuario para a home permitida
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.9.3.0 — 2026-07-23 19:37
- **Tier:** patch
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Na geracao de OS a partir de orcamento, cancelar (X/Cancelar) o modal de cadastro de cliente ou de equipamento aborta toda a abertura da OS e redireciona ao dashboard (nada e persistido). Nao afeta a criacao de OS avulsa.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.9.2.0 — 2026-07-23 19:20
- **Tier:** patch
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** No cadastro de equipamento durante a abertura de OS (cliente novo pendente), exibe o nome do cliente da OS como contexto read-only, ja que o cliente ainda nao existe (criacao diferida)
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.9.1.0 — 2026-07-23 07:57
- **Tier:** patch
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Exibe o aviso 'Novo cliente/equipamento (sera cadastrado ao salvar)' tambem no campo das abas Cliente/Equipamento, com acoes Editar/Remover, alem do resumo da OS (fluxo de criacao diferida)
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.9.0.0 — 2026-07-23 07:43
- **Tier:** minor
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Criacao atomica na abertura de OS: cliente e equipamento novos sao apenas capturados no formulario e so persistidos junto com a OS ao salvar (novo_cliente/novo_equipamento criados na mesma transacao). Cancelar a abertura nao deixa cadastros orfaos nem duplicados.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.8.0.0 — 2026-07-22 20:19
- **Tier:** minor
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Relato do cliente no orcamento (defeito relatado) que pre-preenche o relato da OS ao gerar; corrige checklist de entrada e sugestoes de defeito nao carregarem para equipamento recem-cadastrado (tipo_id ausente no payload)
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/database/migrations/2026_07_22_000002_add_relato_cliente_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.7.2.0 — 2026-07-22 19:56
- **Tier:** patch
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Gerar OS a partir de orcamento avulso abre automaticamente o cadastro rapido de cliente e de equipamento ja pre-preenchidos (cliente/equipamento eventuais), ajustaveis antes de salvar
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.7.1.0 — 2026-07-22 19:40
- **Tier:** patch
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Exibe marca e modelo do equipamento no card e nos dados do orcamento (equipamento eventual ou da OS) no detalhe do orcamento
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.7.0.0 — 2026-07-22 19:01
- **Tier:** minor
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Equipamento eventual no orcamento avulso: campos livres tipo/marca/modelo/cor (com envolve_equipamento), exclusividade cliente/equipamento cadastrado x eventual e pre-preenchimento do cadastro de equipamento ao gerar a OS
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertBudgetRequest.php,backend/app/Models/Budget.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/database/migrations/2026_07_22_000001_add_equipamento_eventual_to_orcamentos.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php

## v5.6.0.0 — 2026-07-22 11:37
- **Tier:** minor
- **Autor/Agente:** Claude (Opus 4.8)
- **Descrição:** Divisao clara orcamento avulso x OS: tipo derivado da presenca de OS, acoes do tecnico (aprovar/rejeitar/cancelar por outros meios) e geracao de OS a partir de orcamento avulso aprovado com cadastro rapido de cliente e vinculo (convertido)
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Models/OrderEvent.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php,documentacao/07-novas-implementacoes/revisao-seguranca-orcamento-assistencia.md,frontends/desktop/app/Http/Controllers/OrcamentoController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrcamentoService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/index.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/routes/web.php

## v5.5.1.0 — 2026-07-21 22:44
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige a auditoria do gerenciador para exibir exclusões definitivas preservadas como tombstones imutáveis.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FileManagerController.php,backend/tests/Feature/Files/FileManagerApiTest.php,frontends/desktop/resources/views/files/index.blade.php,frontends/desktop/tests/Feature/Desktop/FileManagerTest.php

## v5.5.0.2 — 2026-07-21 04:29
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige propriedade do cache Blade nos deploys e evita erro 500 por touch negado
- **Arquivos:** scripts/bash/deploy-producao.sh,scripts/bash/atualizar-dev.sh

## v5.5.0.1 — 2026-07-21 04:10
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Restaura superficie opaca dos modais Bootstrap no tema padrao
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/FileManagerTest.php

## v5.5.0.0 — 2026-07-21 04:05
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cadastro gerenciavel de formas de pagamento em Configuracoes Financeiras, substituindo a lista fixa no codigo; formas de cartao protegidas e detectadas pelo catalogo
- **Arquivos:** backend/database/migrations/2026_07_21_000001_create_financeiro_formas_pagamento_table.php,backend/app/Models/FinanceiroFormaPagamento.php,backend/app/Models/Financeiro.php,backend/app/Http/Controllers/Api/V1/FinanceiroCatalogController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertFinanceiroFormaPagamentoRequest.php,backend/app/Http/Requests/Api/V1/UpsertFinanceiroRequest.php,backend/app/Http/Requests/Api/V1/UpsertFinanceiroContaRequest.php,backend/app/Http/Requests/Api/V1/CloseOrderRequest.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Financeiro/FinanceiroContaService.php,backend/app/Services/Orders/OrderClosureService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/FinanceiroFormaPagamentoTest.php,frontends/desktop/app/Http/Controllers/FinanceiroCatalogController.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/financeiro/configuracoes.blade.php,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/public/assets/js/orders-closure.js

## v5.4.2.0 — 2026-07-21 03:31
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Reorganiza a sidebar do desktop por afinidade (Atendimento/Cadastros/Processos e Modelos/Administracao), move Equipe da Assistencia para Administracao e adiciona grupos de atalho (Relatorios, Ferramentas, Acesso e Integracoes)
- **Arquivos:** frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.4.1.6 — 2026-07-21 02:50
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige bloqueio 429 da politica de retencao atras do proxy da VPS
- **Arquivos:** frontends/desktop/routes/web.php,frontends/desktop/resources/views/files/index.blade.php,frontends/desktop/tests/Feature/Desktop/FileManagerTest.php

## v5.4.1.5 — 2026-07-21 02:19
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Separa registros sem conteudo em colecao de auditoria fora da lixeira
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FileManagerController.php,backend/app/Console/Commands/Files/PurgeTrashedFiles.php,backend/tests/Feature/Files/FileManagerApiTest.php,frontends/desktop/app/Http/Controllers/FileManagerController.php,frontends/desktop/resources/views/files/index.blade.php,frontends/desktop/tests/Feature/Desktop/FileManagerTest.php

## v5.4.1.4 — 2026-07-21 02:03
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Impede restauracao de itens sem binario e sinaliza conteudo ausente na lixeira
- **Arquivos:** frontends/desktop/resources/views/files/index.blade.php,frontends/desktop/tests/Feature/Desktop/FileManagerTest.php

## v5.4.1.3 — 2026-07-21 01:48
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige restauracao segura da lixeira e limite da sincronizacao manual
- **Arquivos:** backend/app/Services/Files/FileAuthorizationRegistry.php,backend/app/Services/Files/ManagedFileDeliveryService.php,backend/app/Http/Controllers/Api/V1/FileManagerController.php,backend/app/Services/Profile/ProfilePhotoImageService.php,backend/tests/Feature/Files/FileManagerApiTest.php,backend/tests/Feature/Files/FileManagerCoreTest.php,frontends/desktop/routes/web.php,frontends/desktop/app/Http/Controllers/FileManagerController.php,frontends/desktop/tests/Feature/Desktop/FileManagerTest.php

## v5.4.1.2 — 2026-07-21 01:26
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige o overlay de carregamento preso apos downloads e navegacoes que nao descarregam a pagina
- **Arquivos:** frontends/desktop/public/assets/js/desktop.js,frontends/desktop/tests/Unit/PageTransitionScriptTest.php

## v5.4.1.1 — 2026-07-21 01:21
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Remove o aviso nativo e enganoso de saida do navegador, preservando o encerramento seguro de sessoes nao lembradas
- **Arquivos:** frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v5.4.1.0 — 2026-07-20 23:27
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige 4 problemas na foto de perfil: nome de arquivo ilegivel, foto antiga nao aposentada no gerenciador, miniatura quebrada (path fora do namespace autorizado) e logoff forcado apos upload (form.submit nao dispara evento submit)
- **Arquivos:** backend/app/Services/Profile/ProfilePhotoImageService.php,backend/app/Http/Controllers/Api/V1/UserPhotoController.php,backend/config/file-manager.php,backend/tests/Feature/Files/FileManagerCoreTest.php,frontends/desktop/public/assets/js/profile-photo.js

## v5.4.0.0 — 2026-07-20 23:02
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Completa a lixeira do Gerenciador de Arquivos com restauracao, preview, exclusao definitiva auditavel e retencao automatica configuravel
- **Arquitetura:** adiciona lifecycle terminal `purged`, registro-túmulo auditável, serviço único de expurgo manual/agendado e política persistida de 0/7/30/90 dias
- **Segurança:** RBAC e step-up, confirmação `EXCLUIR`, kill switch independente, rate limit, legal hold, locks e contenção por disco/path/realpath/symlink; preview da lixeira não libera download
- **Performance/escala:** índice `(lifecycle_status, trashed_at)`, lote de até 250 por padrão, processamento O(1) de memória por arquivo e scheduler `onOneServer/withoutOverlapping`
- **Operação:** migration aditiva aplicada na LAN após backup de 46,45 MB; política inicial de 30 dias ativada; cron diário confirmado às 02:30 e diagnóstico sem arquivo elegível
- **Validação:** 64 testes/353 asserções no núcleo/backend de arquivos e 14 testes/104 asserções no desktop; OpenAPI YAML, PHP, JavaScript, Blade, rotas e caches validados
- **Arquivos:** backend/app/Console/Commands/Files/PurgeTrashedFiles.php,backend/app/Services/Files/ManagedFilePurgeService.php,backend/app/Services/Files/FileTrashRetentionPolicy.php,backend/app/Http/Controllers/Api/V1/FileManagerController.php,backend/database/migrations/2026_07_20_000002_add_managed_file_purge_state.php,backend/routes/api.php,backend/routes/console.php,frontends/desktop/resources/views/files/index.blade.php,frontends/desktop/app/Http/Controllers/FileManagerController.php,frontends/desktop/routes/web.php,specs/022-gerenciador-central-arquivos/contracts/openapi-file-manager.yaml,documentacao/07-novas-implementacoes/2026-07-20-lixeira-gerenciador-arquivos.md

## v5.3.0.0 — 2026-07-20 22:56
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona upload de foto de perfil do usuario, catalogada no Gerenciador de Arquivos (categoria user_profile_photo)
- **Arquivos:** backend/app/Http/Controllers/Api/V1/UserPhotoController.php,backend/app/Services/Profile/ProfilePhotoImageService.php,backend/app/Services/Files/Authorizers/UserProfilePhotoFileAuthorizer.php,backend/app/Providers/AppServiceProvider.php,backend/app/Http/Controllers/Api/V1/AuthController.php,backend/config/file-manager.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/UserPhotoControllerTest.php,backend/tests/Feature/Files/FileManagerCoreTest.php,frontends/desktop/app/Http/Controllers/ProfileController.php,frontends/desktop/app/Services/ProfileService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/profile/edit.blade.php,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/public/assets/js/profile-photo.js,frontends/desktop/public/assets/css/desktop.css

## v5.2.3.0 — 2026-07-20 22:39
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Busca global passa a aceitar seleção de múltiplos escopos via checkboxes (antes só permitia escolher um)
- **Arquivos:** frontends/desktop/app/Http/Controllers/SearchController.php,frontends/desktop/app/Services/SearchService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/resources/views/search/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v5.2.2.0 — 2026-07-20 20:30
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** corrige a seleção e a confirmação da lixeira para usuários administrativos definidos pelo RBAC, sem depender do campo legado `perfil=admin`
- **Arquitetura:** mantém dupla autorização: a sessão exige `arquivos:excluir` e a credencial de step-up exige `arquivos:administrar`; o desktop usa POST sem retry para o comando mutável
- **Segurança:** senha e motivo continuam obrigatórios, rate limit e auditoria permanecem ativos, perfil legado sem RBAC não contorna a regra e falha de escrita no log não transforma uma credencial recusada em HTTP 500
- **Experiência:** amplia a área clicável do checkbox em lista, explica as permissões necessárias e preenche o e-mail da sessão quando o próprio usuário pode administrar arquivos
- **Performance/Resiliência:** elimina três chamadas repetidas ao endpoint de lixeira em respostas 5xx e preserva o binário para restauração
- **Operação:** grupo do log atual corrigido para `www-data`; runbook passa a exigir `setgid` em `backend/storage/logs` para arquivos futuros
- **Validação:** 23 testes direcionados aprovados com 194 asserções; fluxo real do usuário supervisor da LAN validado em transação integralmente revertida
- **Arquivos:** backend/app/Services/Auth/AdminCredentialVerifier.php,backend/app/Http/Controllers/Api/V1/FileManagerController.php,backend/tests/Feature/Files/FileManagerApiTest.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/FileManagerService.php,frontends/desktop/resources/views/files/index.blade.php,frontends/desktop/tests/Feature/Desktop/FileManagerTest.php,documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,documentacao/10-deploy/deploy-producao-lan-ubuntu.md,VERSION,shared/version.php,CHANGELOG.md

## v5.2.1.0 — 2026-07-20 07:35
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** abre a miniatura da Central Documental da OS no modal interno com iframe PDF, em vez de navegar para outra aba
- **Arquitetura:** reutiliza o visualizador compartilhado do Gerenciador de Arquivos e mantém o endpoint autenticado da OS como fonte do iframe e do download
- **Segurança:** iframe same-origin com `referrerpolicy=no-referrer`; o `src` só é atribuído após o clique e retorna para `about:blank` ao fechar o modal
- **Performance:** nenhum PDF é carregado antes da interação; a URL e o nome do arquivo acompanham a versão selecionada sem recarregar a página
- **Compatibilidade:** o `href` autenticado permanece como fallback progressivo, enquanto o JavaScript intercepta o clique para abrir o modal
- **Validação:** teste direcionado aprovado com 26 asserções e JavaScript validado pelo parser do Node
- **Arquivos:** frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,VERSION,shared/version.php,CHANGELOG.md

## v5.2.0.0 — 2026-07-20 05:39
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** adiciona à Central Documental da OS a coluna Foto com miniatura da primeira página do PDF mais recente e atualização dinâmica ao selecionar outra versão
- **Arquitetura:** nova rota autenticada da OS reutiliza o serviço central de miniaturas e o cache por SHA-256; o desktop permanece como BFF e não acessa storage ou banco diretamente
- **Segurança:** autorização `os:visualizar`, validação de vínculo documento/OS, estados seguros do arquivo gerenciado, contenção de path e resposta privada com `nosniff`; não exige a permissão administrativa `arquivos:baixar`
- **Performance:** carregamento lazy, cache privado com ETag e geração única por hash; nenhum PDF completo é transportado na listagem
- **Compatibilidade:** mudança aditiva, sem migration e sem alteração das rotas documentais existentes; documentos ausentes exibem fallback visual
- **Validação:** 3 testes direcionados aprovados (31 asserções), sintaxe PHP/JS validada e novas rotas confirmadas no ambiente LAN
- **Arquivos:** backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/routes/api.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md,documentacao/07-novas-implementacoes/historico-de-versoes.md,VERSION,shared/version.php,CHANGELOG.md

## v5.1.0.0 — 2026-07-20 03:55
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** evolui o Gerenciador de Arquivos com sincronização automática e manual, biblioteca visual, miniaturas, modal seguro, contexto de cliente/data, controles RBAC em massa e criação idempotente de OS
- **Arquitetura:** catálogo central e vínculos de domínio continuam no backend; o desktop atua como BFF; sincronização e renderização são serviços isolados, configuráveis e protegidos por locks
- **Segurança:** autorização por vínculo e RBAC, proteção contra IDOR/traversal/MIME spoofing/command injection, step-up sem flash de senha, CSRF, rate limits, estados seguros e ausência de purga física
- **Performance:** catálogo paginado, cliente resolvido em lote sem N+1, hashes por stream, miniaturas PDF lazy/cacheadas por SHA-256 e sincronização fora da requisição web
- **Banco:** migrations aditivas do catálogo/RBAC/vínculos e `2026_07_20_000001_add_order_creation_idempotency.php`; nenhum campo/path legado removido
- **Compatibilidade:** URLs e paths legados preservados; falhas pós-commit da OS viram avisos; replay idempotente recupera a mesma OS em vez de duplicá-la
- **Documentação:** consolidado de 20/07/2026, arquitetura, contrato da API, runbook, quickstart, histórico e índices atualizados
- **Validação:** 76 testes direcionados aprovados (430 asserções); suítes amplas ainda contêm falhas preexistentes documentadas no consolidado da release
- **Rollout VPS:** sincronização ativada em `shadow` em 20/07/2026; primeira execução processou 573 arquivos, catalogou 566, criou 366 vínculos ativos e terminou sem falhas; segunda execução confirmou idempotência com zero novos findings
- **Correção operacional LAN:** restaurado o ambiente `192.168.1.100` na branch `develop` após uma promoção interrompida deixá-lo temporariamente em `main`/v4.26.3.0; promoção para `main` passa a usar worktree temporário, sem trocar o código servido na LAN
- **Hardening do deploy:** backups com nomes `.env.*` passam a ser ignorados e bloqueados pelo publicador; o backup de ambiente incluído acidentalmente foi removido do estado ativo da `develop`, e a promoção para `main` ficou suspensa até o tratamento das credenciais e do histórico
- **Arquivos:** backend/app/Services/Files,backend/app/Http/Controllers/Api/V1/FileManagerController.php,backend/config/file-manager.php,backend/routes/api.php,backend/routes/console.php,backend/database/migrations/2026_07_20_000001_add_order_creation_idempotency.php,backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/files,frontends/desktop/public/assets/css/file-preview-modal.css,frontends/desktop/public/assets/js/file-preview-modal.js,frontends/desktop/resources/views/groups/permissions.blade.php,frontends/desktop/public/assets/js/orders-create.js,scripts/php/sync-agent-docs.php,documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md,documentacao/03-arquitetura-tecnica/gerenciador-central-arquivos.md,documentacao/03-arquitetura-tecnica/idempotencia-criacao-os.md,documentacao/10-deploy/operacao-gerenciador-central-arquivos.md,specs/022-gerenciador-central-arquivos

## v5.0.0.0 — 2026-07-19 22:01
- **Tier:** major
- **Autor/Agente:** Codex
- **Descrição:** implementa gerenciador central de arquivos, adapters seguros e painel administrativo
- **Arquivos:** backend/app/Services/Files,backend/app/Http/Controllers/Api/V1/FileManagerController.php,frontends/desktop/resources/views/files,backend/openapi.yaml,specs/022-gerenciador-central-arquivos

## v4.27.0.0 — 2026-07-19 20:20
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Endurece uploads e downloads de branding e chat com validacao por conteudo, allowlist, headers seguros e troca atomica de imagens
- **Arquivos:** backend/app/Services/Chat/ChatAttachmentPolicy.php,backend/config/chat.php,backend/app/Services/Chat/MessageAttachmentService.php,backend/app/Http/Controllers/Api/V1/Chat/AttachmentController.php,backend/app/Http/Controllers/Api/V1/Chat/MessageController.php,backend/app/Http/Controllers/Api/V1/Chat/ConversationController.php,backend/app/Services/Company/CompanyProfileService.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/CompanyProfileImageSecurityTest.php,backend/tests/Feature/Chat/ConversationFlowTest.php,backend/tests/Feature/Chat/WhatsappWebhookTest.php,backend/tests/Unit/Services/Chat/ChatAttachmentPolicyTest.php,frontends/desktop/resources/views/configurations/system.blade.php,documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md,documentacao/07-novas-implementacoes/2026-07-19-hardening-arquivos-branding-chat.md,VERSION,shared/version.php,CHANGELOG.md

## v4.26.3.0 — 2026-07-19 18:10
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** substitui a assinatura direta de documentos pendentes por um fluxo obrigatório de visualização e análise da prévia completa, com confirmação explícita antes de assinar e emitir
- **Segurança:** o backend rejeita assinatura sem revisão recente do mesmo usuário, do mesmo snapshot da OS e da mesma versão/hash do template PDF; prévia sem assinatura, privada, sem cache, com autorização por solicitação e auditoria de data/IP/user-agent em hash
- **Performance:** a prévia é renderizada sob demanda sem persistir versão documental; consulta de pendências permanece limitada e indexada
- **Arquivos:** backend/database/migrations/2026_07_19_000005_require_document_review_before_signature.php,backend/database/migrations/2026_07_19_000006_bind_signature_review_to_pdf_template.php,backend/app/Models/DocumentSignatureRequest.php,backend/app/Services/Pdf/PdfGenerationService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Signatures/DocumentSignatureWorkflowService.php,backend/app/Http/Controllers/Api/V1/DocumentSignatureController.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/DocumentSignatureSecurityTest.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/document-signature-review.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/profile/edit.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/07-novas-implementacoes/2026-07-19-assinaturas-digitais-documentos.md,VERSION,CHANGELOG.md

## v4.26.2.0 — 2026-07-19 17:21
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige as caixas de Notificações e Mensagens e documentos para abrirem à direita dos respectivos ícones, sem ficarem ocultas sob a sidebar expandida ou recolhida
- **Segurança:** mantém o posicionamento calculado pelo Bootstrap limitado à viewport, sem introduzir HTML dinâmico ou alterar os controles de autorização das mensagens
- **Performance:** correção declarativa no posicionamento do dropdown, sem listeners, consultas ou processamento adicional no navegador
- **Arquivos:** frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/frontend-desktop-laravel.md,VERSION,CHANGELOG.md

## v4.26.1.0 — 2026-07-19 14:01
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move o registro de acessórios do cadastro permanente do equipamento para a criação e edição da OS, mantém detalhes e PDFs ligados ao snapshot da recepção e migra os valores legados de forma conservadora e auditável
- **Segurança:** backend rejeita acessórios no agregado de equipamento; validação de tamanho na OS; migration não sobrescreve valores existentes e mantém arquivo reversível dos dados legados
- **Performance:** migração processada em lotes de 200 registros e consulta as OS mais recentes em lote, sem N+1
- **Arquivos:** backend/database/migrations/2026_07_19_000004_move_equipment_accessories_to_orders.php,backend/app/Http/Requests/Api/V1/StoreEquipmentRequest.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Services/EquipmentWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/EquipmentCreationTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,backend/tests/Feature/Database/MoveEquipmentAccessoriesToOrdersMigrationTest.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/frontend-desktop-laravel.md,documentacao/07-novas-implementacoes/2026-07-19-acessorios-por-ordem-servico.md

## v4.26.0.0 — 2026-07-19 09:28
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cria a caixa isolada de Mensagens e documentos ao lado do sino e passa a avisar designações de assinatura pelo sistema, e-mail e WhatsApp, com fila assíncrona, auditoria mascarada, retentativas idempotentes e recuperação de pendências anteriores à implantação
- **Segurança:** autorização preservada na abertura do documento; destinatários persistidos somente de forma mascarada e com HMAC; erros externos sanitizados; falhas de canais externos não revertem a designação transacional
- **Arquivos:** backend/app/Services/Notifications/NotificationInboxService.php,backend/app/Http/Controllers/Api/V1/NotificationController.php,backend/app/Notifications/Channels/MobileInboxChannel.php,backend/app/Services/Signatures/DocumentSignatureAssignmentNotifier.php,backend/app/Jobs/DispatchDocumentSignatureAssignmentJob.php,backend/app/Models/DocumentSignatureDelivery.php,backend/database/migrations/2026_07_19_000003_create_document_signature_notification_deliveries.php,backend/routes/console.php,backend/openapi.yaml,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/app/Services/NotificationService.php,frontends/desktop/app/Http/Controllers/NotificationController.php,frontends/desktop/resources/views/notifications/index.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,documentacao/07-novas-implementacoes/2026-07-19-assinaturas-digitais-documentos.md

## v4.25.2.0 — 2026-07-19 04:03
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige prévia da baixa da OS (GET /orders/{id}/closure) mostrando o saldo em aberto de um título já cancelado em vez do título ativo, quando o cancelado é mais recente; a tela dizia 'Saldo em aberto R$0,00' mas a confirmação falhava com 'O valor da baixa não pode ser maior que o saldo em aberto do título' porque close() usa o título ativo de verdade. Mesmo filtro que ensureReceivableTitle() já aplicava, agora também em financialSummary()
- **Arquivos:** backend/app/Services/Orders/OrderClosureService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.25.1.0 — 2026-07-19 03:29
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Fotos de entrada no PDF sempre em paisagem e sem cortes: rotaciona automaticamente fotos em retrato antes de embutir no documento (só nesse bloco; orientação original é mantida em todo o resto do sistema) e troca o recorte (cover) por exibição completa (contain), já que object-fit não é suportado pelo dompdf
- **Arquivos:** backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/resources/views/pdf-engine/document.blade.php,backend/resources/views/pdf-engine/blocks/fotos-entrada.blade.php

## v4.25.0.0 — 2026-07-19 03:29
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Novo bloco 'Galeria de fotos de entrada' no motor de modelos PDF: até 4 fotos de recepção (check-in) da OS lado a lado, adicionável a qualquer tipo de documento (não só abertura); fotos convertidas para base64 sob demanda (só quando o schema usa o bloco), com allowlist de MIME e limite de tamanho
- **Arquivos:** backend/app/Services/Pdf/PdfGenerationService.php,backend/app/Services/Pdf/PdfSchemaValidator.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/resources/views/pdf-engine/blocks/fotos-entrada.blade.php,backend/resources/views/pdf-engine/document.blade.php,frontends/desktop/public/assets/js/pdf-template-editor.js

## v4.24.6.0 — 2026-07-19 03:29
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona teto de segurança de recursão (condicional/colunas) direto no PdfTemplateRenderer: a prévia do editor renderiza rascunhos não publicados sem os limites de profundidade que o publish exige, então um schema fora do padrão podia causar recursão excessiva
- **Arquivos:** backend/app/Services/Pdf/PdfTemplateRenderer.php

## v4.24.5.0 — 2026-07-19 03:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige formatação de data ausente na coluna 'Data' da tabela de recebimentos do comprovante de encerramento (imprimia a string bruta do banco); migration idempotente promove a versão publicada sem afetar customizações
- **Arquivos:** backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/database/migrations/2026_07_18_000014_fix_encerramento_recebimentos_data_format.php

## v4.24.4.1 — 2026-07-19 03:28
- **Tier:** hotfix
- **Autor/Agente:** Claude
- **Descrição:** Corrige encoding quebrado ('Ol?!' -> 'Olá!') na mensagem padrão de envio de documentos quando não há template de WhatsApp configurado
- **Arquivos:** backend/app/Services/Orders/OrderDocumentCenterService.php

## v4.24.4.0 — 2026-07-19 03:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** RBAC granular de publicar/restaurar no motor de modelos PDF do desktop: rotas e botões usavam a permissão genérica 'editar', divergindo do que o backend já exige ('publicar'/'restaurar'); usuário via os botões habilitados e só descobria a falta de permissão após clicar
- **Arquivos:** frontends/desktop/routes/web.php,frontends/desktop/resources/views/knowledge/pdf-templates/engine-edit.blade.php,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php

## v4.24.3.0 — 2026-07-19 03:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Invalida o cache do logo institucional (usado nos PDFs) ao trocar ou remover a logo em Configurações da Empresa; antes o cache de 10min mantinha a logo antiga/removida em qualquer PDF gerado nesse intervalo
- **Arquivos:** backend/app/Services/Company/CompanyProfileService.php

## v4.24.2.0 — 2026-07-19 03:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige botões de ação inertes na central de documentos (ZIP/imprimir/link/enviar): remove poda de seleção que dependia de checkboxes já removidos na reforma da tela de versão-por-linha, reescreve leitura de metadados via dataset da linha, e impede que o polling de 5s reset e a versão selecionada ou feche o menu de Ações aberto
- **Arquivos:** frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.24.1.0 — 2026-07-19 03:22
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Exibe nome, função e data efetiva do signatário e mantém as linhas de assinatura alinhadas nos PDFs
- **Arquivos:** backend/app/Models/User.php,backend/app/Services/Pdf/PdfGenerationService.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/resources/views/pdf-engine/blocks/assinatura.blade.php,backend/resources/views/pdf-engine/document.blade.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Controllers/Api/V1/DocumentSignatureController.php,backend/app/Http/Controllers/Api/V1/PublicDocumentSignatureController.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,documentacao/07-novas-implementacoes/2026-07-19-assinaturas-digitais-documentos.md

## v4.24.0.0 — 2026-07-19 03:00
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Módulo de assinaturas digitais com cadastro por imagem ou tela, Apple Pencil, assinatura própria, reautenticação de outro usuário, pendências e rubrica do cliente por link seguro
- **Segurança:** armazenamento privado, rasterização PNG, confirmação de senha, rate limit, token público armazenado somente como hash, bloqueio de corrida e trilha separada de criador/signatário
- **Documentação:** consolidado executivo/técnico de 18 e 19/07, histórico de versões, índice principal, checklist de deploy e contexto estruturado para agentes atualizados
- **Arquivos:** backend/app/Services/Signatures,backend/app/Http/Controllers/Api/V1/UserSignatureController.php,backend/app/Http/Controllers/Api/V1/DocumentSignatureController.php,backend/app/Http/Controllers/Api/V1/PublicDocumentSignatureController.php,backend/database/migrations/2026_07_19_000002_create_document_signature_infrastructure.php,frontends/desktop/resources/views/profile/edit.blade.php,frontends/desktop/resources/views/signatures/public.blade.php,frontends/desktop/resources/views/orders/documents-center/_signature-modal.blade.php,documentacao/07-novas-implementacoes/2026-07-19-assinaturas-digitais-documentos.md,documentacao/07-novas-implementacoes/2026-07-19-consolidado-implementacoes-18-19-julho.md,documentacao/README.md,documentacao/10-deploy/README.md

## v4.23.0.2 — 2026-07-19 00:36
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Exibe assinaturas do responsável e do cliente lado a lado, substitui o JSON bruto por campos amigáveis no editor e versiona modelos existentes com segurança
- **Arquivos:** backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfSchemaValidator.php,backend/resources/views/pdf-engine/blocks/assinatura.blade.php,backend/database/migrations/2026_07_19_000001_add_client_to_pdf_signature_blocks.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,backend/tests/Feature/Database/AddClientToPdfSignatureBlocksMigrationTest.php,frontends/desktop/public/assets/js/pdf-template-editor.js,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.23.0.1 — 2026-07-18 21:32
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Promove o Termo de Garantia aprovado para todos os ambientes por migration idempotente, sem sobrescrever personalizações existentes
- **Arquivos:** backend/database/migrations/2026_07_18_000016_seed_termo_garantia_template.php,backend/tests/Feature/Database/SeedTermoGarantiaTemplateMigrationTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.23.0.0 — 2026-07-18 20:57
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Padroniza o cabeçalho institucional em três colunas para todos os modelos PDF atuais, novos e clonados, e fixa o rodapé A4 na margem reservada para evitar páginas geradas apenas pelo rodapé
- **Arquivos:** backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateAdminService.php,backend/app/Services/Pdf/PdfGenerationService.php,backend/resources/views/pdf-engine/document.blade.php,backend/database/migrations/2026_07_18_000015_standardize_pdf_template_headers.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.22.4.0 — 2026-07-18 20:15
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Suporte a cabeçalho PDF em três colunas com foto segura do equipamento
- **Arquivos:** backend/app/Services/Pdf/PdfSchemaValidator.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Pdf/PdfGenerationService.php,backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/app/Services/Pdf/Contexts/BudgetPdfContextFactory.php,backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/EquipmentWorkflowService.php,backend/resources/views/pdf-engine/blocks/colunas.blade.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/PdfGenerationServiceTest.php,frontends/desktop/public/assets/js/pdf-template-editor.js,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.22.3.0 — 2026-07-18 18:45
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Amplia a coluna e a área de texto da configuração dos blocos no editor de templates PDF
- **Arquivos:** frontends/desktop/resources/views/knowledge/pdf-templates/engine-edit.blade.php,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php

## v4.22.2.0 — 2026-07-18 18:05
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Audita o contrato de variáveis PDF, aplica fallback seguro ao nome fantasia e separa entrega real da previsão da OS
- **Arquivos:** backend/app/Services/Pdf/Contexts/CompanyContextProvider.php,backend/app/Services/Pdf/Contexts/OrderPdfContextFactory.php,backend/tests/Feature/Api/V1/PdfEngineGuardTest.php,backend/tests/Feature/Api/V1/PdfEngineDocumentCenterParityTest.php

## v4.22.1.0 — 2026-07-18 17:35
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Preserva quebras de linha em parágrafos PDF e organiza textos colados em títulos, seções, parágrafos e listas editáveis
- **Arquivos:** backend/app/Services/Pdf/PdfVariableResolver.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/resources/views/pdf-engine/blocks/paragrafo.blade.php,backend/tests/Feature/Api/V1/PdfEngineDocumentCenterParityTest.php,backend/tests/Feature/Api/V1/PdfEngineGuardTest.php,frontends/desktop/public/assets/js/pdf-template-editor.js,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php

## v4.22.0.0 — 2026-07-18 17:05
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Permite criar e clonar documentos PDF personalizados, publicá-los e gerá-los manualmente na Central Documental
- **Arquivos:** backend/app/Models/PdfTemplate.php,backend/app/Services/Pdf/PdfTemplateRegistry.php,backend/app/Services/Pdf/PdfTemplateAdminService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Http/Controllers/Api/V1/PdfTemplateEngineController.php,backend/database/migrations/2026_07_18_000014_add_custom_pdf_template_support.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/PdfTemplateEngineControllerTest.php,backend/tests/Feature/Api/V1/PdfEngineDocumentCenterParityTest.php,frontends/desktop/app/Services/PdfTemplateEngineService.php,frontends/desktop/app/Http/Controllers/PdfTemplateEngineController.php,frontends/desktop/resources/views/knowledge/pdf-templates/engine-index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.21.0.0 — 2026-07-18 16:07
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Unifica os PDFs no editor versionado e publica tema leve e moderno em A4 e 80mm
- **Arquivos:** backend/app/Services/Pdf/PdfDefaultTemplates.php,backend/app/Services/Pdf/PdfTemplateRenderer.php,backend/resources/views/pdf-engine/document.blade.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderClosurePdfService.php,backend/app/Services/Budgets/BudgetPdfService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/database/migrations/2026_07_18_000013_publish_light_pdf_templates_v2.php,backend/tests/Feature/Api/V1/PdfEngineDocumentCenterParityTest.php,backend/tests/Feature/Api/V1/PdfEngineGuardTest.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/app/Http/Controllers/PdfTemplateEngineController.php,frontends/desktop/resources/views/knowledge/pdf-templates/engine-index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/PdfTemplateEngineTest.php,documentacao/07-novas-implementacoes/2026-07-18-motor-central-documentos-pdf.md

## v4.20.0.2 — 2026-07-18 08:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige collation do extrato e protege erros SQL
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroContaService.php,backend/app/Http/Controllers/Api/V1/FinanceiroContaController.php,backend/tests/Feature/Api/V1/FinanceiroContaTest.php

## v4.20.0.1 — 2026-07-18 08:18
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige conta financeira em lançamento pago
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.20.0.0 — 2026-07-18 04:03
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Consolidado mensal de contas e saldos
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroContaController.php,backend/app/Services/Financeiro/FinanceiroContaService.php,backend/openapi.yaml,backend/routes/api.php,backend/tests/Feature/Api/V1/FinanceiroContaTest.php,frontends/desktop/app/Http/Controllers/FinanceiroContaController.php,frontends/desktop/app/Services/FinanceiroContaService.php,frontends/desktop/resources/views/financeiro/contas/consolidado.blade.php,frontends/desktop/resources/views/financeiro/contas/index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/FinanceiroContaTest.php,specs/021-gestao-contas-financeiras/contracts/api.md,specs/021-gestao-contas-financeiras/spec.md,specs/021-gestao-contas-financeiras/tasks.md

## v4.19.2.0 — 2026-07-18 03:35
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige validacao da conta financeira no fechamento da OS
- **Arquivos:** frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.19.1.0 — 2026-07-18 03:25
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Contas e Saldos integrado ao RBAC com permissões independentes

## v4.19.0.0 — 2026-07-18 02:26
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Gestão de contas, saldos disponíveis, transferências e conciliação patrimonial

## v4.18.4.0 — 2026-07-17 03:53
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Fluxo da OS: catálogo de transições cresce de 87 para 95 (8 cadastradas manualmente em Conhecimento > Fluxo da OS) — 3 voltas de retrabalho a partir de etapas avançadas da raia CONCLUÍDO (reparo_concluido/reparado_disponivel_loja/garantia_concluida -> retrabalho, roteadas como novas setas tracejadas) e 5 transições inertes com destino de encerramento (garantia_concluida/reparado_disponivel_loja/reparo_concluido/reparo_recusado/irreparavel_disponivel_loja -> entregue_reparado_garantia/entregue_reparado_sem_custo/descartado), formalizadas em REAL_TRANSITIONS só pro diagrama continuar espelhando o banco fielmente (sem seta própria, mesma regra das demais 17 transições de encerramento). Nova migration idempotente (2026_07_17_000002) leva as 8 pra qualquer ambiente via php artisan migrate, testada com ciclo completo de rollback+reaplicação sem duplicar linhas
- **Arquivos:** scripts/python/diagrama_fluxo_os_organizado.py,scripts/python/diagrama_fluxo_os_organizado.svg,scripts/python/diagrama_fluxo_os_organizado.png,scripts/python/README-diagrama-fluxo-os.md,frontends/desktop/resources/views/orders/_flow_map_svg.blade.php,backend/database/migrations/2026_07_17_000002_add_retrabalho_return_and_closure_transitions.php

## v4.18.3.0 — 2026-07-17 03:34
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Mapa da OS: adiciona nº da OS e resumo do equipamento (tipo/marca/modelo) na barra de legenda, dentro da moldura do mapa — visível mesmo em tela cheia, onde o cabeçalho da página e o painel lateral (que ficam fora de .os-map-frame) somem. Sem isso, em tela cheia não tinha como saber de qual OS/equipamento se tratava sem sair do fullscreen
- **Arquivos:** frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.18.2.0 — 2026-07-17 03:27
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Mapa da OS: adiciona cards de contexto no painel lateral com dados do cliente (nome, telefone) e do equipamento (tipo, marca, modelo, defeito relatado pelo cliente), antes do trajeto percorrido — evita precisar voltar pra tela de detalhe da OS só pra lembrar quem é o cliente ou o que foi relatado. Reaproveita os mesmos campos já usados na tela de detalhe ($order['cliente']/$order['equipamento']), sem mudança de backend. Defeito relatado com truncamento em 3 linhas (title com o texto completo)
- **Arquivos:** frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.18.1.0 — 2026-07-17 02:51
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige o submenu de navegação exibido inadvertidamente ao recolher a sidebar: grupos abertos pelo contexto da página agora são fechados no carregamento recolhido e no clique de recolher; popovers deliberadamente abertos continuam fechando por clique externo ou Esc com restauração de foco.
- **Arquivos:** frontends/desktop/public/assets/js/desktop.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.18.0.0 — 2026-07-17 02:51
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona editor de corte às fotos de entrada da OS, com recorte individual, zoom, rotação, reedição e substituição segura do arquivo original antes do envio multipart, mantendo o limite de até quatro imagens e 2 MB por foto.
- **Arquivos:** frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/resources/views/orders/create.blade.php,frontends/desktop/resources/views/orders/edit.blade.php,frontends/desktop/resources/views/orders/_photo_crop_modal.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.17.1.0 — 2026-07-17 02:07
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Adiciona o botão 'Mapa da OS' no dropdown de ações de cada linha da listagem de OS (/os), ao lado de 'Documentos da OS' — antes só existia na tela de detalhe da OS. Mesmo link (rota orders.map) usado em ambos os lugares
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.17.0.0 — 2026-07-17 02:01
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Formaliza como migration as 10 transições que haviam sido cadastradas só pela tela Conhecimento > Fluxo da OS nesta máquina (homologação): testes_operacionais → verificacao_garantia/aguardando_orcamento/reparo_concluido/garantia_concluida/cancelado, e irreparavel → diagnostico/aguardando_orcamento/aguardando_reparo/reparo_execucao/retrabalho. Antes, essas 10 só existiam no banco local — 'php artisan migrate' não sincroniza dados entre ambientes, só executa migrations versionadas, então elas nunca chegariam à VPS de produção sem esse arquivo. Mesmo padrão idempotente e reversível das migrations anteriores de transições (resolve código→id em runtime, down() desativa sem deletar); testada localmente com rollback + reaplicação, confirmando 87 transições ativas, 87 pares distintos, zero duplicata
- **Arquivos:** backend/database/migrations/2026_07_17_000001_add_testes_operacionais_e_irreparavel_transitions.php

## v4.16.0.0 — 2026-07-17 01:36
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Diagrama do fluxo da OS: adiciona as 10 transições que o usuário cadastrou em Conhecimento > Fluxo da OS (77 → 87 na tabela os_status_transicoes), roteadas à mão seguindo as convenções de corredor já usadas no script. 'Testes Operacionais' ganha 5 saídas novas — retorno pra Verificação de Garantia e Aguardando Orçamento (quando o teste revela algo que precisa reavaliar), e atalhos direto pra Reparo Concluído, Garantia Concluída ou Cancelado, pulando Testes Finais. 'Irreparável' deixa de ser (quase) definitivo: ganha volta pra Diagnóstico, Aguardando Orçamento, Aguardando Reparo, Em Execução e Retrabalho, permitindo reavaliar um equipamento antes marcado como sem conserto. Nenhuma das 10 aponta pra um encerramento, então todas viraram seta (60 → 70 setas desenhadas, auto-verificado contra as 70 transições utilizáveis do banco)
- **Arquivos:** scripts/python/diagrama_fluxo_os_organizado.py,scripts/python/diagrama_fluxo_os_organizado.svg,scripts/python/diagrama_fluxo_os_organizado.png,scripts/python/README-diagrama-fluxo-os.md,frontends/desktop/resources/views/orders/_flow_map_svg.blade.php

## v4.15.1.0 — 2026-07-17 00:46
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige o pan (arrastar pra navegar) do Mapa da OS disparando seleção de texto do navegador em vez de mover o mapa: os rótulos dentro do SVG e da legenda são texto selecionável por padrão, e um user-select:none isolado só no viewport não bastava — o navegador 'pulava' a seleção pro texto selecionável mais próximo fora dele (legenda, e até a pílula de status no cabeçalho, que fica bem acima do quadro do mapa). Amplia user-select:none para o quadro inteiro (.os-map-frame: legenda + viewport + toolbar) e para a pílula de status isoladamente, mantendo o número da OS e o painel 'Trajeto percorrido' normalmente selecionáveis. Reforça também no JS com preventDefault() no início do arrasto (pointerdown), para os casos em que o CSS sozinho não é respeitado
- **Arquivos:** frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/public/assets/js/orders-map.js

## v4.15.0.0 — 2026-07-16 23:52
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Mapa da OS: trocar de status agora atualiza o mapa no próprio lugar (novo endpoint JSON orders.map.data + redecoração do mesmo SVG) em vez de recarregar a página inteira — um location.reload() sempre encerrava a tela cheia, já que qualquer navegação sai do modo fullscreen do navegador por padrão de segurança. Depois de confirmar a mudança no modal, o JS busca o estado fresco da OS (status, trajeto completo, próximas etapas), atualiza a pílula de status, o banner de encerrada/cancelada e o painel 'Trajeto percorrido' (HTML já renderizado pelo servidor via partial orders._map_trail, reaproveitado tanto no carregamento normal quanto nesse endpoint), e redecora o mesmo SVG: marcador de posição migra para o novo nó, trajeto/rota provável são recalculados, e as próximas etapas clicáveis são atualizadas — tudo sem sair da tela cheia. Cliques nos nós passam a ser delegados no <svg> (um único listener) em vez de um por nó, então a lista de etapas clicáveis muda com o estado sem precisar desligar/religar handlers a cada atualização
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/resources/views/orders/_map_trail.blade.php,frontends/desktop/public/assets/js/orders-map.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.14.2.0 — 2026-07-16 23:34
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige os modais de mudança de status (confirmação de mover etapa, aviso de encerramento/baixa e toasts) somerem em tela cheia no Mapa da OS: SweetAlert2 anexa seu container a document.body por padrão, que fica fora da 'camada' da Fullscreen API nativa quando .os-map-frame está em tela cheia — só o próprio elemento em fullscreen e seus descendentes são renderizados pelo navegador. Todos os Swal.fire() do mapa agora recebem target dinâmico (document.fullscreenElement || document.body), então o modal passa a ser filho do elemento em tela cheia e continua visível e utilizável; fora de tela cheia o comportamento não muda
- **Arquivos:** frontends/desktop/public/assets/js/orders-map.js

## v4.14.1.0 — 2026-07-16 23:22
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Adiciona modo tela cheia à página Mapa da OS: botão na toolbar do mapa entra em fullscreen (API nativa do navegador, com fallback de overlay fixo quando indisponível); sai com Esc ou pelo X no canto superior direito (visível só em tela cheia). Zoom é reajustado automaticamente ao entrar/sair, e a toolbar desce para não disputar o canto com o X
- **Arquivos:** frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/public/assets/js/orders-map.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.14.0.0 — 2026-07-16 22:57
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona a página Mapa da OS (/os/{id}/mapa): visão GPS do ciclo de vida da ordem de serviço sobre o fluxograma real do catálogo, com trajeto percorrido em verde (reconstruído da trilha completa de eventos category=status, não do histórico limitado a 5), posição atual pulsando, rota provável até 'Entregue — Reparado e Pago' calculada por Dijkstra preferindo o caminho feliz, e próximas etapas clicáveis (confirmação com observação e, quando sai de status com prazo congelado, novo prazo) aplicando a mudança pelo endpoint existente de status; encerramentos nunca são clicáveis — apontam para a tela de baixa. Pan/zoom com roda/arrasto/botões e 'centralizar na posição atual'. Botão 'Mapa da OS' no cabeçalho e no menu Mais ações da tela da OS. Antes disso, a migration add_missing_os_status_transitions fecha as duas lacunas de processo documentadas no README do diagrama (cumprimento_garantia sem saída; teste reprovado sem caminho para retrabalho) e formaliza o fluxo de peça com sinal: 8 transições novas (69→77) — cumprimento_garantia→garantia_concluida/irreparavel, testes_finais→retrabalho, testes_operacionais→irreparavel, retrabalho→aguardando_reparo, aguardando_peca→pagamento_pendente/aguardando_reparo e pagamento_pendente→aguardando_reparo — que aparecem automaticamente também no modal Alterar Status (chips). O gerador do fluxograma (scripts/python) foi atualizado: caminho feliz agora passa por Aguardando Avaliação (caso clássico da bancada), 60 setas auto-verificadas contra as 77 transições, e novo modo --embed que gera o partial SVG endereçável (data-status/data-edge) consumido pela página do mapa
- **Arquivos:** backend/database/migrations/2026_07_16_000001_add_missing_os_status_transitions.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,scripts/python/diagrama_fluxo_os_organizado.py,scripts/python/diagrama_fluxo_os_organizado.svg,scripts/python/diagrama_fluxo_os_organizado.png,scripts/python/README-diagrama-fluxo-os.md,frontends/desktop/routes/web.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/orders/map.blade.php,frontends/desktop/resources/views/orders/_flow_map_svg.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/js/orders-map.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.13.0.0 — 2026-07-16 21:43
- **Tier:** minor
- **Autor/Agente:** jovem-tech
- **Descrição:** Adiciona criação contextual de Nova OS nas páginas de cliente, equipamento e OS, com modal para reutilizar o equipamento atual ou iniciar com equipamento novo e preenchimento seguro do proprietário.
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_new_order_context_modal.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.12.2.0 — 2026-07-16 21:25
- **Tier:** patch
- **Autor/Agente:** jovem-tech
- **Descrição:** Substitui o título Sem resumo técnico no detalhe do equipamento pela identificação formada por tipo, marca e modelo, com fallback para o ID.
- **Arquivos:** frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.12.1.0 — 2026-07-16 21:14
- **Tier:** patch
- **Autor/Agente:** jovem-tech
- **Descrição:** Corrige a busca de equipamentos para exibir foto, tipo, marca, modelo, cliente e número de série, com fallback para cadastros legados e pesquisa pelo tipo.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,frontends/desktop/app/Services/SearchService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/search/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.12.0.0 — 2026-07-16 18:26
- **Tier:** minor
- **Autor/Agente:** jovem-tech
- **Descrição:** Adiciona auditoria completa e paginada da OS, com filtros, autoria, proveniência, resumo atual, endpoint protegido e acesso pelo menu Mais ações.
- **Arquivos:** backend/app/Http/Requests/Api/V1/OrderEventIndexRequest.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/audit.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/eventos-os.md

## v4.11.7.0 — 2026-07-16 12:53
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move o Histórico da OS para abaixo do card Fotos na coluna principal, removendo o layout lateral com rolagem interna e cobrindo a nova ordem com teste de regressão.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.11.6.0 — 2026-07-16 10:48
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Alinha o rodapé do card 'Histórico da OS' (coluna lateral) com o rodapé do último card da coluna principal (Fotos) na tela de detalhe da OS. Antes, a coluna lateral (foto + histórico) tinha altura livre e terminava bem acima da coluna principal em qualquer OS com vários cards, deixando um vão vazio grande abaixo do histórico. Agora .os-detail-layout estica as duas colunas para a mesma altura (align-items:stretch), a coluna lateral vira flex column com o card de foto em tamanho fixo e o card de histórico crescendo (flex:1) até preencher a altura total, com o scroll ficando interno à lista de eventos (não ao card inteiro) — mantendo cabeçalho e chips de filtro sempre visíveis. Layout mobile (<=992px) inalterado, pois a coluna lateral já vira display:contents nesse breakpoint
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v4.11.5.0 — 2026-07-16 10:41
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige alinhamento dos títulos de card na tela de detalhe da OS (Defeito e Solução, Valores e Orçamento, Documentos, Fotos): o ícone aparecia isolado à esquerda e o texto do título isolado à direita, bem afastados um do outro. Causa: .os-info-card-title usa display:flex+justify-content:space-between para separar título de botão de ação quando presente, mas nos títulos sem botão o ícone (elemento) e o texto (nó de texto solto) viravam dois itens flex anônimos distintos, que o space-between empurrava para as bordas opostas do card. Corrigido envolvendo ícone+texto num único <span>, mesmo padrão já usado nos cards Cliente/Equipamento, restando só um item flex (alinhado à esquerda) quando não há botão
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v4.11.4.0 — 2026-07-16 10:24
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** No card 'Valores e Orçamento' (v4.11.1.0), as seções 'Orçamento' e 'Datas e garantia' ficavam lado a lado mas sem separação visual entre si, misturando com o fundo do card externo. Nova classe .os-subcard (fundo levemente diferenciado, borda sutil, sem sombra própria) transforma as duas em caixas distintas lado a lado, mesma altura, deixando claro onde uma seção termina e a outra começa.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/css/desktop.css

## v4.11.3.0 — 2026-07-16 10:17
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a duração da OS no cabeçalho da tela de detalhe ('Aberta há X dias' / 'Concluída em X dias'), que exibia um float fracionário cru (ex.: 'Aberta há 4.170775462963 dias') em vez de um número inteiro de dias — Carbon::diffInDays() nesta versão retorna fração de dia por padrão, não inteiro. O mesmo bug também quebrava silenciosamente os casos-limite 'Aberta hoje' e a pluralização 'X dia'/'X dias', já que as comparações ===0/===1 nunca batiam com um valor fracionário. Corrigido com um cast (int) logo após diffInDays(), truncando para dias completos.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v4.11.2.0 — 2026-07-16 10:11
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Move o campo 'Checklist' do card Equipamento para o card 'Defeito e Solução' (logo após Técnico responsável) na página de detalhe da OS, e adiciona o botão 'Ver checklist', que abre um modal com o resultado completo: todos os itens verificados (com rótulo de status OK/Discrepância/Não verificado), a observação registrada em cada item, o resumo textual e as observações gerais do estado do equipamento — antes só o resumo agregado ("Preenchido · 7 itens") ficava visível, sem acesso ao detalhe item a item pela tela de OS.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_checklist_detail_modal.blade.php

## v4.11.1.0 — 2026-07-16 10:02
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Ajusta o card 'Valores e Orçamento' (v4.11.0.0) para eliminar redundância: a seção 'Resumo financeiro' foi extinta — mão de obra e peças já apareciam detalhadas na tabela de peças e serviços do orçamento, e total/desconto/valor final já apareciam na seção Orçamento. Em seu lugar, na coluna esquerda do card, entra a seção 'Orçamento' (antes um bloco full-width abaixo), ficando lado a lado com 'Datas e garantia' na direita — que agora termina com 'Forma de pagamento' (movida de Resumo financeiro). A nota do título financeiro (recebido/saldo) e o alerta de auditoria de peças, que ficavam soltos sob Resumo financeiro, passam a ficar junto da seção Orçamento, já que são informações sobre ele.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v4.11.0.0 — 2026-07-16 09:24
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Reformula a página de detalhe da OS (/os/{id}): remove as 6 abas (Informações, Orçamento, Diagnóstico, Fotos, Documentos, Valores) — investigação confirmou que nenhuma delas tinha ação interativa própria, todas já duplicadas em "Mais ações" ou eram só exibição — e reorganiza tudo em cards sequenciais de leitura direta, sem clique extra: Cliente e Equipamento (dois cards lado a lado, cada um em tabela label→valor, linha só aparece se o campo não estiver vazio); Defeito e Solução (técnico, relato do cliente, diagnóstico, solução, procedimentos, acessórios, observações); Valores e Orçamento (resumo financeiro + datas/garantia lado a lado, bloco do orçamento vinculado com a nova lista de peças e serviços do orçamento aprovado); Documentos (tabela compacta com botão Visualizar, era cards grandes); e por último Fotos (grade por recepção/diagnóstico/entrega, mantendo a mesma lightbox). Backend (OrderWorkflowService::mapLinkedBudget) passa a expor os itens do orçamento vinculado (antes só o resumo agregado existia); mapEquipment() passa a expor o campo acessórios do equipamento, que existia na tabela mas nunca era mapeado. Nova classe de tabela .os-info-table no CSS (label/valor com linhas separadas por borda sutil), reaproveitando .desktop-grid-two e .table-stack já existentes para o layout de 2 colunas e as tabelas responsivas — sem duplicar padrões.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/css/desktop.css

## v4.10.3.0 — 2026-07-16 01:04
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Causa raiz real da trava de orçamento não aprovado (v4.10.0.0 a v4.10.2.0) nunca funcionar de fato para quem interage pela UI real do Select2: o listener 'change' de #encerrarComo usava só addEventListener nativo, sem o binding paralelo via jQuery que o Select2 exige (Select2 dispara change só via jQuery(el).trigger('change'), que não gera evento nativo — mesmo bug já documentado nesta sessão e corrigido para o select de Classificação e os campos de cartão, mas não para #encerrarComo). Resultado: escolher 'Entregue - Reparado e Pago' clicando na tela nunca desabilitava as abas/botão de verdade. Corrigido adicionando o mesmo binding jQuery paralelo. Validado com teste automatizado em Chrome headless clicando de fato na UI do Select2 (não via .value=): opção fica corretamente desabilitada/não-clicável no dropdown quando o orçamento está pendente, e no cenário de reenvio com valor antigo (old() do Laravel após rejeição do backend) as abas Financeiro/Confirmação e o botão Continuar carregam já desabilitados.
- **Arquivos:** frontends/desktop/public/assets/js/orders-closure.js

## v4.10.2.0 — 2026-07-16 00:46
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** A trava de orçamento não aprovado na tela de baixa (v4.10.1.0) só interceptava o clique no botão 'Continuar' — as abas 'Financeiro' e 'Confirmação' continuavam clicáveis diretamente, deixando o técnico pular a etapa 1 e navegar livre pelo resto do wizard. Agora, ao selecionar 'Entregue - Reparado e Pago' com orçamento vinculado ainda não aprovado, as abas Financeiro e Confirmação ficam de fato desabilitadas (mesmo padrão visual já usado para devolução sem reparo/descarte) e o próprio botão 'Continuar' da etapa 1 fica desabilitado — não é mais possível avançar por nenhum caminho até o orçamento ser aprovado ou outro tipo de encerramento ser escolhido.
- **Arquivos:** frontends/desktop/public/assets/js/orders-closure.js

## v4.10.1.0 — 2026-07-16 00:41
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** A trava de orçamento aprovado no encerramento como 'Entregue - Reparado e Pago' (v4.10.0.0) só desabilitava a opção no <select>, o que não impedia o técnico de avançar pelas etapas Financeiro/Confirmação do wizard de baixa e só descobrir o bloqueio no envio final, depois de preencher tudo. A tela de baixa (orders-closure.js) agora barra a navegação já na etapa 1 (Encerramento), com aviso inline imediato, assim que o orçamento pendente é detectado — e mantém o bloqueio no envio final como defesa adicional. O backend (OrderClosureService::close) continua sendo a barreira definitiva.
- **Arquivos:** frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/orders/closure.blade.php

## v4.10.0.0 — 2026-07-16 00:31
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Bloqueia o encerramento da OS como "Entregue - Reparado e Pago" quando existe um orçamento vinculado ainda não aprovado (aguardando resposta, rejeitado, etc.) — antes o fluxo de baixa ignorava completamente o status do orçamento, permitindo encerrar como entregue e pago mesmo com a OS ainda em "Aguardando Autorização". A trava só age quando HÁ orçamento vinculado à OS; OS sem orçamento nenhum (ex.: serviço rápido cobrado direto) continua fechando normalmente. Vale só para o encerramento pago — sem custo e garantia continuam livres mesmo com orçamento pendente, já que não exigem autorização de cobrança. Tela de baixa (orders/closure.blade.php) desabilita a opção "Entregue - Reparado e Pago" no select com aviso visual quando aplicável, além do bloqueio no backend (OrderClosureService::close, novo resultado delivery_requires_approved_budget → HTTP 422 ORDER_CLOSURE_DELIVERY_REQUIRES_APPROVED_BUDGET). Corrige também duas referências residuais ao nome antigo "Equipamento Entregue" nas mensagens de erro de fechamento.
- **Arquivos:** backend/app/Services/Orders/OrderClosureService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,frontends/desktop/resources/views/orders/closure.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v4.9.0.0 — 2026-07-15 23:22
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Divide o encerramento "Equipamento Entregue" (que era vago) em três status explícitos de reparo entregue, todos grupo_macro=encerrado: entregue_reparado_pago (renome de entregue_reparado — reparado, entregue e pago, único que gera receita/REVENUE_CLOSURE_CODE), entregue_reparado_sem_custo (cortesia, R$0 sem lançamentos) e entregue_reparado_garantia (cumprimento de garantia, R$0 sem lançamentos). Mantém devolvido_sem_reparo e descartado. Os três "entregue_reparado_*" contam como equipamento entregue nos indicadores operacionais (card do dashboard + gráfico mensal de entregues reparadas, via nova const OrderStatus::REPAIRED_DELIVERY_CODES) e geram os documentos de reparo (laudo + comprovante de entrega), mas só o pago entra em faturamento/DRE/fluxo de caixa/margem/comissão — o dashboard passou a separar a contagem operacional de entregas (3 códigos) da soma de receita (só o pago). No fechamento (OrderClosureService::close), cortesia e garantia entram no grupo "sem cobrança" junto com devolvido/descartado: não exigem pagamento, não registram movimento financeiro e não deixam saldo pendente/cobrança agendada — só o pago exige pagamento. Isso também preenche um buraco real: reparo em garantia (fluxo verificacao_garantia→cumprimento_garantia→garantia_concluida) antes não tinha como ser encerrado como entregue sem pagamento. Migração idempotente e reversível renomeia a linha do catálogo os_status e migra os dados existentes (os.status, os.status_final_pendente_pagamento, os_status_historico) de entregue_reparado para entregue_reparado_pago. Documentação (catálogo de status + skill sistema-erp-os-fluxo-fechamento) atualizada.
- **Arquivos:** backend/database/migrations/2026_07_15_000001_split_entregue_reparado_status.php,backend/app/Models/OrderStatus.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Dashboard/DashboardSummaryService.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/app/Http/Controllers/AssistanceModelController.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md,.agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md

## v4.8.2.0 — 2026-07-15 19:31
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a baixa/fechamento de uma OS que já teve um título cancelado por "Erro de cobrança" anteriormente (motivo que reverte a OS para pagamento pendente mas mantém o título cancelado vinculado, ao contrário de "Fechamento inadvertido", que apaga o título). Ao fechar a OS de novo, `OrderClosureService::ensureReceivableTitle()` buscava "o" título da OS sem filtrar por status, encontrava o cancelado (único vinculado) e tentava lançar o novo recebimento nele — `FinanceiroService::registerMovement()` bloqueia baixa em título cancelado, então o fechamento falhava com HTTP 500 ("Não é possível registrar baixa em título cancelado."), travando a OS sem nenhum título ativo para receber. A busca agora ignora títulos cancelados (mesmo filtro que `OrderWorkflowService` já aplicava ao resolver o título "atual" da OS para o resumo/financeiro_titulo_id) e cria um título novo quando só existir um cancelado, preservando o cancelado intocado para auditoria. Bug relatado em produção na OS 26070011 (título #29 cancelado bloqueando a baixa); reproduzido e corrigido primeiro no `develop`.
- **Arquivos:** backend/app/Services/Orders/OrderClosureService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.8.1.0 — 2026-07-15 18:47
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reforça a cobertura de teste do cancelamento de título com motivo "Fechamento inadvertido" (reversão completa da baixa da OS): passa a verificar também que `data_entrega` volta a `null` junto com o status, não só o status em si. Investigação disparada por um relato de produção onde uma OS revertida para "Aguardando Reparo" continuou exibindo a data de entrega antiga na listagem — o teste confirma que o `develop` já corrige isso corretamente (via `OrderClosureService::cancelClosure()`, a mesma lógica reaproveitada de "Cancelar baixa"); a causa mais provável do que foi visto em produção é o deploy daquele fluxo ainda não ter chegado lá, ou o cancelamento ter sido feito antes da correção existir
- **Arquivos:** backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.8.0.0 — 2026-07-15 13:15
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Cancelar um título vinculado a uma OS já encerrada ("Equipamento Entregue") passa a exigir motivo (Reparo sem sucesso entregue ao cliente / Erro de cobrança / Fechamento inadvertido) + confirmação de administrador (reaproveitando o AdminCredentialVerifier já usado em "Cancelar baixa" e na edição de orçamento de OS encerrada), com consequência automática no status da OS conforme o motivo: devolvido_sem_reparo, entregue_pagamento_pendente (cancelando também as cobranças automáticas via WhatsApp que estavam agendadas), ou reversão completa da baixa (mesma lógica de "Cancelar baixa"). UI em duas telas dentro do mesmo modal (motivo → credenciais), com um único POST no final. Exclusão (hard delete) de qualquer lançamento passa a exigir as mesmas credenciais de administrador sempre — antes bastava um confirm() do navegador, mesmo para títulos já pagos, sem checar nada; e fica totalmente bloqueada quando a OS vinculada está encerrada (usar "Cancelar" preserva o histórico e corrige o status da OS, diferente do hard delete que não corrigia nada). Corrige a despesa "Taxa de cartão" gerada pela baixa avulsa de um título em cartão (POST /financeiro/{id}/baixar), que não herdava o os_id do título pago — por isso essas taxas nunca acionavam a trava de motivo+admin mesmo vinculadas a uma OS encerrada, diferente da taxa equivalente gerada pelo fechamento direto da OS. Corrige o bug da listagem de OS que não filtrava títulos cancelados ao somar Recebido/Saldo (diferente do detalhe da OS, que já filtrava corretamente) — OS com título estornado aparecia com saldo em aberto fantasma. Corrige dois bugs pré-existentes descobertos durante a implementação: a constante DELIVERED_STATUS (usada para exigir pagamento ao fechar a OS como "Equipamento Entregue") comparava contra "equipamento_entregue", um código que nunca existiu de fato no catálogo de status — o código real é "entregue_reparado" — tornando essa trava de pagamento morta desde que foi criada; o mesmo código errado também estava em 4 pontos da geração de documentos da OS. Corrige também a ordem de verificação de autorização em OrderClosureService::close(), que rodava depois da validação de negócio, retornando 422 em vez de 403 para um técnico sem acesso à OS.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BaseApiController.php,backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/CancelFinanceiroRequest.php,backend/app/Models/OrderStatus.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/resources/views/financeiro/_cancel_reason_modal.blade.php,frontends/desktop/resources/views/financeiro/_delete_admin_modal.blade.php,frontends/desktop/public/assets/js/financeiro-cancel-reason-modal.js,frontends/desktop/public/assets/js/financeiro-delete-admin-modal.js

## v4.7.15.0 — 2026-07-15 13:15
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona "trilha de origem" na listagem de lançamentos financeiros (GET /financeiro): cada linha passa a mostrar, sob a categoria, de onde aquele título veio — cliente/OS/equipamento para receita de OS, fornecedor para despesa avulsa, e para taxas de cartão (geradas automaticamente na baixa em cartão), o título a receber que originou a taxa. Substitui o antigo subtítulo genérico grupo_dre/subgrupo_dre, que era igual para todo lançamento da mesma categoria e não dizia nada sobre a origem específica daquele registro.
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.7.14.0 — 2026-07-15 01:56
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a Central Documental da OS (`/os/{id}/documentos`): as tabelas "Tipos documentais disponíveis" e "Acervo versionado do cliente" (renomeada para "Todas as versões geradas") passam a viver no mesmo card, uma logo abaixo da outra. Cada linha de "Tipos documentais disponíveis" com documento já gerado ganha um dropdown "Ações" com Visualizar A4/80mm (só aparece o formato realmente disponível), Baixar ZIP, Imprimir, Gerar link, Enviar e Arquivar/Reativar, agindo direto naquele documento — sem precisar marcar checkbox nenhum. Corrige o "Baixar ZIP" que parecia não funcionar: a causa raiz era a seleção da tabela "Tipos documentais" (usada só para gerar em lote) ser um estado desconectado da seleção da tabela do acervo (usada pelos botões de ZIP/imprimir/link/enviar) — confirmado por teste automatizado novo que o endpoint de ZIP do backend sempre funcionou corretamente. A tabela do acervo mantém seleção múltipla para combinar vários documentos num único ZIP/impressão/link/envio
- **Arquivos:** backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.13.0 — 2026-07-15 01:50
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** A listagem de lançamentos financeiros (`/financeiro`) passa a ordenar por data de pagamento/recebimento efetivo (mais recente primeiro), em vez de data de vencimento. Títulos ainda pendentes (sem data de pagamento) vão para o final da lista
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.7.12.0 — 2026-07-14 23:53
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona "Ver lançamentos" e "Novo lançamento" ao topo do dropdown "Mais ações" da tela de detalhes do lançamento financeiro, com divisor separando-os das demais ações. "Ver lançamentos" sempre aparece (exige apenas a permissão de visualizar já necessária para chegar nesta página); "Novo lançamento" só aparece com permissão de criar. Como consequência, o botão "Mais ações" deixa de sumir por completo quando o usuário não tem nenhuma outra ação disponível — passa a mostrar ao menos "Ver lançamentos"
- **Arquivos:** frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.7.11.0 — 2026-07-14 23:30
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Estende o padrão "Mais ações" (já usado em OS, orçamentos, documentos e lançamentos financeiros) para os detalhes de Cliente e de Equipamento. Em Cliente (`/clientes/{id}`): "Voltar" e "Nova OS" continuam como botões visíveis; "Editar cliente", "Ver OS do cliente" e "Ver equipamentos" passam para o dropdown. Em Equipamento (`/equipamentos/{id}`): "Voltar" e "Nova OS" continuam visíveis; "Editar" e "Abrir cliente" passam para o dropdown. Cada item mantém sua checagem de permissão de módulo já existente; o botão "Mais ações" some por completo quando nenhum item está disponível para o perfil do usuário
- **Arquivos:** frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.10.0 — 2026-07-14 22:51
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a tela de detalhes do lançamento financeiro (`/financeiro/{id}`) para reduzir a quantidade de cards e o scroll necessário. "Dados do lançamento" passa a concentrar também os dados de "Quem pagou"/"Para quem pagou" e de "Origem do lançamento" (antes três cards separados), com data/forma de pagamento do recebimento movidos para dentro dele; o card KPI "Recebido em/Pago em" do topo foi substituído por um card "Quem pagou"/"Para quem pagou" (nome, documento, telefone e e-mail da contraparte, no mesmo estilo compacto dos demais KPIs); "Tipo de origem" passa a ser só um campo dentro de "Dados do lançamento" (removida a subseção "Origem do lançamento" e o campo "Lançamento de origem"); "Dados do lançamento" e "OS vinculada" ficam lado a lado numa grade própria com altura igualada (bases alinhadas); "Baixas e formas de pagamento" e "Auditoria" passam a ocupar a largura inteira, abaixo desse par
- **Arquivos:** frontends/desktop/resources/views/financeiro/show.blade.php

## v4.7.9.0 — 2026-07-14 22:00
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o dropdown "Ações" da LISTAGEM de OS (não só da tela de detalhe, já corrigida antes), que não mostrava "Ver lançamento financeiro" mesmo em OS com título vinculado (ex.: OS26070002, com R$ 80,00 recebidos) — o id do título nunca era exposto na resposta da listagem, só na tela de detalhe. Backend passa a incluir `financeiro_titulo_id` em cada linha de `GET /orders` (o dado já era calculado internamente para "Recebido/Saldo", só faltava expor o id); listagem do desktop ganha o mesmo item de menu já usado no detalhe, condicionado a ter lançamento vinculado e permissão de visualizar o módulo financeiro
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.8.0 — 2026-07-14 21:38
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** O dropdown "Mais ações" da tela de detalhe da OS ganha o item "Ver lançamento financeiro" quando a OS tem um título "a receber" vinculado (não cancelado) — mesmo dado já usado no resumo financeiro da aba Valores, agora também como atalho direto para a página de detalhes do lançamento. Item some automaticamente quando a OS não tem lançamento vinculado ou o usuário não tem permissão de visualizar o módulo financeiro
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.7.0 — 2026-07-14 20:53
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Página de detalhes do lançamento financeiro ganha o botão "Mais ações" (mesmo padrão das telas de OS), agrupando todas as ações do lançamento: Editar lançamento; Registrar baixa (modal completo com valor total/parcial, forma de pagamento e taxas de cartão — antes só existia na listagem, agora dá para baixar direto dos detalhes); Ver OS vinculada; Ver orçamento vinculado (novo atalho — backend passa a expor o orçamento mais recente da OS no payload de detalhes); Ver cliente/fornecedor (contraparte, com id agora exposto pelo backend); Cancelar lançamento e Excluir lançamento (com as mesmas confirmações da listagem). O botão "Editar" isolado do cabeçalho foi movido para dentro do dropdown; sem nenhuma ação disponível para o perfil, o dropdown some. Baixa e cancelamento feitos a partir dos detalhes voltam para os detalhes (novo campo voltar_para), em vez de sempre caírem na listagem
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.7.6.0 — 2026-07-14 08:49
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a tela de detalhe da OS: cabeçalho ganha pill de status ao lado do número, linha de metadados (duração 'Aberta há Xd'/'Concluída em Xd', previsão, pill de prazo/SLA com mesma paleta da listagem, técnico responsável) para leitura de relance sem abrir aba; card KPI 'Técnico responsável' (removido em ajuste anterior) segue disponível na aba Diagnóstico; histórico da OS na coluna lateral ganha faixa de filtros rolável (em vez de quebrar em várias linhas) para caber na largura reduzida da coluna; novo campo prazo (SLA) exposto em GET /orders/{id}, reaproveitando o mesmo cálculo já usado na listagem
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/orders/show.blade.php

## v4.7.5.0 — 2026-07-14 07:34
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige de vez o guard "fechar o navegador = deslogar" no Edge, após teste real do usuário mostrar que a v4.7.4 ainda falhava. Quatro falhas encontradas e corrigidas: (1) a detecção dependia só da idade do heartbeat (20s) — fechar e reabrir rápido (teste manual típico) passava despercebido; agora um MARCADOR DE FECHAMENTO é gravado no exato instante em que a aba fecha (evento pagehide, quando a saída não é navegação interna), tornando a detecção instantânea mesmo reabrindo em 2 segundos, não importa o que o Edge restaure; (2) o beforeunload consumia a flag de navegação interna antes de o pagehide lê-la, o que gravaria marcador falso em navegações normais — a flag agora expira sozinha (10s) em vez de ser consumida; (3) o rastreamento de navegação interna ficava dentro do if do aviso de fechamento — com o aviso desligado, toda navegação interna viraria "fechamento"; movido para fora, sempre ativo; (4) recarregar pelo botão do navegador seria tratado como fechamento — agora o tipo de navegação (PerformanceNavigationTiming: reload) isenta qualquer recarregamento com precisão. Fallback por heartbeat mantido (agora 90s, só para crash/kill, evitando falso positivo com abas em segundo plano que têm timers estrangulados pelo navegador). Logs de diagnóstico no console do navegador ([ERP Sessão]) para suporte remoto. Verificado em navegador real (Chromium headless) em 11 cenários, incluindo reabertura em 2s com aba inteira restaurada (caso Edge), reload da barra, multi-abas, crash e anti-laço
- **Arquivos:** frontends/desktop/resources/views/layouts/app.blade.php

## v4.7.4.0 — 2026-07-14 07:08
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o guard de "fechar o navegador = deslogar" para funcionar também no Microsoft Edge (e no Chrome com restauração completa de sessão). A versão anterior dependia do navegador limpar o sessionStorage ao fechar; o Edge, com "Inicialização rápida" + "Continuar de onde parei" (ligados por padrão), restaura a aba inteira — cookie E sessionStorage — deixando o guard cego. Agora o sinal principal é um "heartbeat" gravado no localStorage a cada 3 segundos por cada aba viva: ao fechar o navegador os beats param, mas o relógio continua, então na reabertura o último beat estará velho (> 20s) e a sessão restaurada é detectada e encerrada, independentemente do que o navegador restaure. Inclui verificação de escrita real do localStorage (se não persistir, o guard se desativa em vez de entrar em laço de logout) e mantém o anti-laço via sessionStorage. Verificado em navegador real (Chrome headless, mesmo motor do Edge) nos cenários: reabertura com sessionStorage restaurado → desloga; reload normal → mantém; primeiro login → mantém; nova aba legítima → mantém
- **Arquivos:** frontends/desktop/resources/views/layouts/app.blade.php

## v4.7.3.0 — 2026-07-14 01:23
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona um aviso ao fechar o navegador/aba com sessão ativa sem "Manter-me conectado": ao tentar fechar, o navegador exibe uma confirmação nativa lembrando de encerrar a sessão (útil como lembrete em computadores de clientes). O aviso é suprimido durante a navegação normal dentro do sistema (cliques em links do mesmo host, envio de formulários e recarregar a página) para não incomodar no uso do dia a dia — fica reservado ao fechamento de fato. Novo interruptor "Avisar ao fechar o navegador com sessão ativa" em Configurações do Sistema > Sessão e Segurança (ligado por padrão) controla o recurso. Observação técnica: navegadores modernos mostram a mensagem padrão do próprio navegador (não é possível personalizar o texto) e o diálogo também aparece ao recarregar pelo botão do navegador ou digitar outra URL
- **Arquivos:** frontends/desktop/database/migrations/2026_07_14_000002_add_warn_on_close_to_session_security_settings.php,frontends/desktop/app/Models/SessionSecuritySetting.php,frontends/desktop/app/Support/SessionSecuritySettings.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.2.0 — 2026-07-14 00:48
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reforça o "fechar o navegador = deslogar" para sessões sem "Manter-me conectado", corrigindo duas causas reais: (1) o cookie XSRF-TOKEN nascia com validade de 30 dias (efeito colateral do teto de sessão para o remember-me), aparecendo como um cookie "muito persistente" — agora, sem remember-me, tanto o XSRF-TOKEN quanto o cookie de sessão passam a ter validade curta (igual ao timeout de inatividade configurado), e o cookie de sessão continua morrendo ao fechar o navegador; (2) o recurso "Continuar de onde parei" do Chrome/Edge restaura o cookie de sessão ao reabrir o navegador, mantendo o usuário logado mesmo com o cookie efêmero — adicionado um guard em JS no layout autenticado (só para sessões não-lembradas) que usa sessionStorage (que o navegador limpa ao fechar a aba) mais um heartbeat em localStorage para distinguir "nova aba legítima da mesma sessão" de "navegador reaberto com sessão restaurada"; neste último caso força logout automático e volta para a tela de login. O guard não roda para sessões com "Manter-me conectado" marcado
- **Arquivos:** frontends/desktop/app/Http/Middleware/EnsureBackendToken.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.1.0 — 2026-07-14 00:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Torna configurável, em Configurações do Sistema > Sessão e Segurança, o que antes era fixo por `.env`: tempo de inatividade (minutos) que encerra sessões sem "Manter-me conectado" marcado, duração em dias do "Manter-me conectado", e um interruptor para desativar completamente o recurso (some o campo do login e invalida imediatamente o efeito de qualquer sessão já marcada como "lembrada", sem esperar novo login). Valores ficam guardados numa tabela local nova (`session_security_settings`, banco próprio do desktop) com fallback automático para os padrões de `.env` enquanto a tabela não existir ou estiver vazia
- **Arquivos:** frontends/desktop/database/migrations/2026_07_14_000001_create_session_security_settings_table.php,frontends/desktop/app/Models/SessionSecuritySetting.php,frontends/desktop/app/Support/SessionSecuritySettings.php,frontends/desktop/app/Support/DesktopSession.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.0.0 — 2026-07-13 23:46
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Corrige vulnerabilidade de segurança: o cookie de sessão do desktop sobrevivia ao fechamento do navegador em qualquer login (config `expire_on_close` vinha `false`), permitindo que alguém que reabrisse o navegador (ex.: em um computador de cliente, após o técnico esquecer o logoff) reaproveitasse a sessão autenticada de quem usou o sistema antes. Agora o padrão é seguro: a sessão morre ao fechar o navegador e também expira após 120 minutos de inatividade (configurável). Para quem realmente precisa continuar conectado, foi criado o recurso "Manter-me conectado neste dispositivo" — um checkbox no login (com aviso para não usar em computadores compartilhados) que, quando marcado, mantém a sessão viva por até 30 dias mesmo fechando o navegador, sem enfraquecer o timeout padrão de quem não marcar. A aba "Sessão e Segurança" (Configurações do Sistema) agora mostra os valores realmente aplicados. Também corrigido um erro de digitação histórico na variável de ambiente do cookie seguro (`SESSION_SECURE_COOKIES` → `SESSION_SECURE_COOKIE`, nome que o Laravel realmente lê)
- **Arquivos:** frontends/desktop/config/session.php,frontends/desktop/.env.example,frontends/desktop/app/Support/DesktopSession.php,frontends/desktop/app/Http/Middleware/EnsureBackendToken.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.6.6.0 — 2026-07-13 14:42
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza o acesso administrativo do sistema: o antigo item de menu lateral "Níveis de Acesso" agora é acessado por um botão dentro da aba "Sessão e Segurança" de Configurações do Sistema; a visualização e o gerenciamento de "Usuários" (listagem, filtros, criação, edição, ativar/desativar) foram movidos para uma nova aba "Usuários" dentro de Configurações do Sistema (embutida de verdade, não apenas um link — reaproveitando o mesmo conteúdo/modais/JS da página própria via partials); e uma nova aba "Integrações" concentra o acesso à tela de integrações (WhatsApp, pagamentos, e-mail, Google). O menu lateral "Configurações" agora só tem "Configurações do Sistema". Rotas e páginas próprias (/usuarios, /grupos, /configuracoes/integracoes) continuam existindo e funcionando normalmente — só o ponto de acesso principal mudou. Cada aba nova respeita a permissão do módulo correspondente (usuarios/grupos), com fallback automático para a primeira aba permitida caso alguém force `?tab=` sem permissão
- **Arquivos:** frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/UserController.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/users/index.blade.php,frontends/desktop/resources/views/users/_index-content.blade.php,frontends/desktop/resources/views/users/_index-modals.blade.php,frontends/desktop/resources/views/users/_index-scripts.blade.php,frontends/desktop/tests/Feature/Desktop/ConfigurationSystemTest.php

## v4.6.5.0 — 2026-07-13 09:34
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza o acesso às telas financeiras secundárias: removidos do menu lateral os 4 relatórios (DRE por Competência, DRE de Caixa, Fluxo de Caixa, Margem por OS) e também Cartões e Taxas, Configurações Financeiras e Precificação — a seção "Financeiro" do menu agora só tem "Lançamentos". Em vez disso, a tela de Lançamentos ganhou dois botões dropdown: "Relatórios" (os 4 relatórios) e "Mais ações" (Cartões e Taxas, Configurações Financeiras, Precificação — este último só aparece com permissão própria do módulo `precificacao`, distinta de `financeiro`). Rotas e páginas continuam as mesmas, só o ponto de acesso mudou
- **Arquivos:** frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.6.4.0 — 2026-07-13 07:49
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a notificação do sino "Orçamento aprovado/recusado pelo cliente" (link público), que estava documentada em notificacoes-sino.md mas nunca foi implementada — o evento era gravado normalmente na timeline da OS (os_eventos), porém `BudgetApprovalService::approveByToken()`/`rejectByToken()` nunca chamavam o `NotificationDispatchService`, então nenhuma linha em mobile_notifications era criada e nada era transmitido em tempo real via Reverb. Corrigido injetando o `NotificationDispatchService` no serviço e disparando `orcamento.approved`/`orcamento.rejected` para responsável + criador do orçamento + técnico da OS vinculada (mesma lista de destinatários já documentada), tanto na aprovação quanto na recusa pelo link público
- **Arquivos:** backend/app/Services/Budgets/BudgetApprovalService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v4.6.3.0 — 2026-07-13 07:17
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o gráfico "Tipos de Equipamento" do dashboard, que renderizava quebrado (segmentos empilhados com contorno branco sólido + cantos arredondados em todos os lados, dando aspecto de "pílulas" desconectadas em vez de uma coluna única) e com cores fora do padrão visual do sistema (12 cores arbitrárias, sem validação, com fórmula HSL gerando tons extras a partir do 13º tipo). Trocado por uma paleta categórica de 8 cores fixas e validadas (separação segura para daltonismo, contraste testado contra o fundo real do card, com um tom violeta próximo do roxo primário do sistema); do 9º tipo em diante agora entra no agregado "Outros" com uma cor neutra fixa, nunca mais uma cor gerada por índice. Só o segmento do topo da pilha recebe cantos arredondados (reto embaixo e entre segmentos), com um espaçamento fino na cor da superfície do card em vez de contorno branco chapado. Corrigido também o container do canvas, que só tinha "min-height" (sem altura explícita) — sem uma referência estável de altura o Chart.js (responsive + maintainAspectRatio:false) deixava o gráfico crescer sem limite, nunca cabendo inteiro na tela; agora usa altura explícita nas três faixas responsivas, mesmo padrão já usado no gráfico de rosca "OS por status"
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/dashboard.js

## v4.6.2.0 — 2026-07-12 22:51
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a listagem padrão de OS ocultando indevidamente ordens "Entregue - Pendência Financeira" (o escopo "aberta" filtrava também por status_final_pendente_pagamento; agora usa só os 3 status literais de OrderStatus::closureCodes(), no listing e no card "OS abertas" do dashboard — fixture de teste com grupo_macro divergente do banco real corrigido junto); corrige bug no botão "Editar" de Financeiro > Cartões e Taxas (mismatch camelCase/snake_case entre o JS e os campos do form deixava operadora, bandeira, parcelas, taxa % e taxa fixa em branco ao editar); campo "Parcelas" da baixa da OS agora respeita a faixa (min/max) realmente cadastrada para a operadora/modalidade/bandeira, com aviso da faixa liberada; e reorganiza a tela Financeiro > Cartões e Taxas (abas "Taxa por parcela" e "Taxas online"): tabela cadastrada ocupa a linha inteira e os formulários de cadastro/edição foram movidos para modais, acionados por um botão "Nova taxa"/"Nova taxa online" ou pelo "Editar" de cada linha
- **Arquivos:** .agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md,backend/app/Services/Dashboard/DashboardSummaryService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/public/assets/js/financeiro-cartoes.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/financeiro/cartoes.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartoesTest.php

## v4.6.1.0 — 2026-07-12 20:22
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Correções: cadastro rápido de cliente (erro 500 por CPF/CNPJ duplicado agora vira validação amigável; CPF/CNPJ normalizado para dígitos e com máscara progressiva 000.000.000-00 / 00.000.000/0000-00), correção de encoding mojibake nas mensagens em português do cadastro de equipamento (câmera/galeria/etc.), e regra na baixa da OS: encerrar como "Equipamento Entregue" passa a exigir ao menos algum valor recebido (pagamento parcial aceito, saldo restante segue como pendência financeira), validado no frontend e no backend
- **Arquivos:** backend/app/Http/Controllers/Api/V1/ClientController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderClosureService.php,frontends/desktop/public/assets/js/clients-form.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/orders/closure.blade.php

## v4.6.0.0 — 2026-07-12 20:10
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Implanta a Equipe da Assistência como base operacional separada de Usuários, com cadastro real de membros, vínculo opcional ao usuário do sistema, técnico da OS vindo dessa grade, contagem de documentos gerados no histórico da OS e padronização visual dos cards KPI da baixa
- **Arquivos:** backend/app/Http/Controllers/Api/V1/TeamMemberController.php,backend/app/Http/Requests/Api/V1/StoreTeamMemberRequest.php,backend/app/Http/Requests/Api/V1/UpdateTeamMemberActiveRequest.php,backend/app/Http/Requests/Api/V1/UpdateTeamMemberRequest.php,backend/app/Models/TeamMember.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_12_180000_create_equipe_membros_table.php,backend/routes/api.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Http/Controllers/PeopleController.php,frontends/desktop/app/Services/TeamMemberService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/people/technical-team.blade.php,frontends/desktop/routes/web.php

## v4.5.0.0 — 2026-07-12 19:54
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Reformulação da Central Documental da OS (geração/envio/compartilhamento assíncrono via AJAX, com polling de fila e link público), dropdown "Mais ações" padronizado em todas as telas de OS e orçamento (edição, baixa/encerramento, documentos, orçamento), item "Ver orçamento"/"Gerar orçamento" condicional, e validação guiada de campos obrigatórios na abertura/edição de OS (botão "Próximo" navega até o campo pendente, técnico/data de previsão passam a ser obrigatórios, telefone do cliente exibido no resumo e na seleção)
- **Arquivos:** backend/app/Support/TemplateHtmlSanitizer.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/bootstrap/app.php,frontends/desktop/phpunit.xml,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/resources/views/orders/documents-center/_documents-table.blade.php,frontends/desktop/resources/views/orders/documents-center/_send-history.blade.php,frontends/desktop/resources/views/orders/documents-center/_send-modal.blade.php,frontends/desktop/resources/views/orders/documents-center/_share-links.blade.php,frontends/desktop/resources/views/orders/documents-center/_share-modal.blade.php,frontends/desktop/resources/views/orders/documents-print.blade.php,frontends/desktop/resources/views/orders/edit.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.4.1.0 — 2026-07-11 20:07
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** correção do coletor dois cliques sem comando
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentCollectorController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Services/EquipmentWorkflowService.php,backend/database/migrations/2026_07_11_190000_add_submission_token_to_equipment_collector_pairings_table.php,backend/public/assets/agents/bench-collector/linux-x64/jovemtech-bench-collector.sh,backend/public/assets/agents/bench-collector/linux-x64/README.md,backend/public/assets/agents/bench-collector/win-x64/jovemtech-bench-collector.ps1,backend/public/assets/agents/bench-collector/win-x64/README.md,backend/routes/api.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/routes/web.php

## v4.4.0.0 — 2026-07-11 18:43
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** implantaçãodo coletor de harwares
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/EquipmentWorkflowService.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Support/Knowledge/PlaceholderCatalog.php,backend/config/services.php,backend/.env.production.example,backend/openapi.yaml,backend/public/assets/agents/bench-collector/linux-x64/jovemtech-bench-collector.sh,backend/public/assets/agents/bench-collector/linux-x64/README.md,backend/public/assets/agents/bench-collector/win-x64/jovemtech-bench-collector.ps1,backend/public/assets/agents/bench-collector/win-x64/README.md,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-11-fotos-sem-corte-e-visualizador-modal.md,documentacao/07-novas-implementacoes/2026-07-11-os-abertura-pdf-e-envio-whatsapp.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/layouts/partials/photo-viewer-modal.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,scripts/versionar.sh,VERSIONING.md

## v4.3.0.0 — 2026-07-11 16:07
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** restaura o PDF de abertura da OS, vincula o documento e adiciona envio opcional ao cliente
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Support/Knowledge/PlaceholderCatalog.php,backend/openapi.yaml,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/07-novas-implementacoes/2026-07-11-os-abertura-pdf-e-envio-whatsapp.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.2.0.1 — 2026-07-11 13:03
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** restaura css dos filtros do historico da os
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v4.2.0.0 — 2026-07-11 10:36
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fotos do desktop sem corte, visualizador modal e melhorias no versionar.sh com sincronização automática da documentação de agentes
- **Arquivos:** documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-11-fotos-sem-corte-e-visualizador-modal.md,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/layouts/partials/photo-viewer-modal.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,scripts/versionar.sh,VERSIONING.md

## v4.1.0.1 — 2026-07-10 22:04
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** ajuste da cor do painel administrativo em tema azul
- **Arquivos:** frontends/desktop/public/assets/css/themes/jovem-tech.css

## v4.1.0.0 — 2026-07-10 20:11
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** correção e ajustes das notificações (sino)
- **Arquivos:** backend/app/Console/Commands/NotifyOrderDeadlines.php,backend/app/Events/NotificationCreated.php,backend/app/Notifications/Channels/MobileInboxChannel.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Notifications/NotificationDispatchService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/channels.php,backend/routes/console.php,documentacao/03-arquitetura-tecnica/notificacoes-sino.md,frontends/desktop/app/Http/Controllers/NotificationController.php,frontends/desktop/app/Services/NotificationService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/routes/web.php

## v4.0.2.0 — 2026-07-10 18:01
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move o botao de notificacoes (sino) do lado direito para o lado esquerdo da barra superior, ficando ao lado do botao de inicio (casa)
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v4.0.1.1 — 2026-07-10 17:54
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Adiciona botao de atalho para o Dashboard (icone de casa) ao lado do toggle do menu lateral, e corrige sobreposicao entre a logo e o botao de expandir/recolher quando a sidebar esta recolhida (agora empilham verticalmente no hover)
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v4.0.1.0 — 2026-07-10 15:30
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste de segurança
- **Arquivos:** .agents/skills/sistema-erp-autenticacao-step-up/SKILL.md,backend/app/Http/Controllers/Api/V1/AuthController.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Web/BudgetPublicController.php,backend/app/Http/Requests/Api/V1/RevealEquipmentPasswordRequest.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/EquipmentWorkflowService.php,backend/config/services.php,backend/openapi.yaml,backend/phpunit.xml,backend/routes/api.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/BroadcastAuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Services/ConfigurationService.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/config/session.php,frontends/desktop/public/assets/js/configurations-integrations.js,frontends/desktop/public/assets/js/equipments-reveal-password-modal.js,frontends/desktop/public/assets/js/orders-list.js,frontends/desktop/resources/views/equipments/_reveal_password_modal.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/routes/web.php

## v4.0.0.0 — 2026-07-10 03:38
- **Tier:** major
- **Autor/Agente:** Codex
- **Descrição:** Hardening de seguranca: remove token do frontend, protege secrets de integracoes, mascara senhas de equipamentos, expira orcamentos publicos e endurece sessao/RBAC
- **Arquivos:** backend/app/Http/Controllers/Api/V1/AuthController.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Web/BudgetPublicController.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/EquipmentWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/BroadcastAuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ConfigurationService.php,frontends/desktop/config/session.php,frontends/desktop/public/assets/js/configurations-integrations.js,frontends/desktop/public/assets/js/orders-list.js,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/routes/web.php

## v3.21.0.0 — 2026-07-10 00:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** correções na tela de login, correções no RBCA, correções e ajustes na baixa da os
- **Arquivos:** .agents/skills/sistema-erp-os-fluxo-fechamento/references/regra-fechamento-os.md,.agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md,backend/app/Console/Commands/BackfillOsEventos.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Controllers/Api/V1/UserController.php,backend/app/Http/Requests/Api/V1/CloseOrderRequest.php,backend/app/Http/Requests/Api/V1/StoreUserRequest.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Http/Requests/Api/V1/UpdateUserRequest.php,backend/app/Models/OrderEvent.php,backend/app/Models/Order.php,backend/app/Notifications/FrontendPasswordResetNotification.php,backend/app/Providers/AppServiceProvider.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Company/CompanyProfileService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderEventService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/bootstrap/app.php,backend/database/migrations/2026_07_09_000001_create_os_eventos_table.php,backend/openapi.yaml,backend/routes/api.php,backend/routes/web.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,backend/tests/Feature/Api/V1/PasswordResetFlowTest.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,documentacao/03-arquitetura-tecnica/eventos-os.md,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Http/Controllers/UserController.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/app/Services/UserService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/_event_timeline.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/users/index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Unit/

## v3.20.0.1 — 2026-07-09 20:14
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Aproxima paineis do login em telas grandes
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.20.0.0 — 2026-07-09 08:22
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** modernizaçao e alinhamento do painel de login
- **Arquivos:** backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Services/Company/CompanyProfileService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/layouts/guest.blade.php,frontends/desktop/routes/web.php

## v3.19.1.2 — 2026-07-09 07:46
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta login para azul institucional e layout mobile enxuto
- **Arquivos:** frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.19.1.1 — 2026-07-09 07:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Moderniza tela de login com branding da assistência técnica
- **Arquivos:** backend/app/Services/Company/CompanyProfileService.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/routes/api.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/layouts/guest.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/routes/web.php

## v3.19.1.0 — 2026-07-09 04:23
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** implantação de botão de detalhes em um lançamanto
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php

## v3.19.0.1 — 2026-07-09 04:19
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Adiciona detalhe operacional dos lancamentos financeiros
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php

## v3.19.0.0 — 2026-07-09 03:56
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** recuperação da base de conhecimento, ajustes na visualização da os
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_09_000001_seed_conhecimento_module.php,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/routes/web.php

## v3.18.2.3 — 2026-07-09 03:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Restaura modulo de conhecimento e implementa checklist de entrada operacional na OS
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/routes/api.php,backend/database/migrations/2026_07_09_000001_seed_conhecimento_module.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/css/desktop.css

## v3.18.2.2 — 2026-07-09 03:24
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** ajusta status e diagnostico na tela de detalhe da os
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v3.18.2.1 — 2026-07-09 02:59
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Detalhe da OS passa a exibir tipo, marca e modelo no card Equipamento, mantendo serie e resumo tecnico como complemento.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v3.18.2.0 — 2026-07-09 02:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste e correção no processo de baixa da os
- **Arquivos:** backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/resources/views/orders/show.blade.php

## v3.18.1.3 — 2026-07-09 02:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Aba Valores da OS passa a exibir forma de pagamento resolvida pelos movimentos financeiros e alerta de peca orcada sem baixa de estoque vinculada.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/show.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v3.18.1.2 — 2026-07-09 01:42
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta status da OS conforme status do orçamento
- **Arquivos:** backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v3.18.1.1 — 2026-07-09 01:29
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Garante link publico copiavel e valor da OS em orcamentos
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v3.18.1.0 — 2026-07-09 01:06
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste de layout de elementos de paginas de listagem
- **Arquivos:** frontends/desktop/app/Http/Controllers/ServicoController.php,frontends/desktop/app/Http/Controllers/StockController.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/clients/index.blade.php,frontends/desktop/resources/views/components/,frontends/desktop/resources/views/equipments/index.blade.php,frontends/desktop/resources/views/estoque/index.blade.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/groups/index.blade.php,frontends/desktop/resources/views/orcamentos/index.blade.php,frontends/desktop/resources/views/servicos/index.blade.php,frontends/desktop/resources/views/suppliers/index.blade.php,frontends/desktop/resources/views/users/index.blade.php

## v3.18.0.0 — 2026-07-09 00:11
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** ajuste e correção de layout de graficos do dashboard
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/dashboard.js,frontends/desktop/resources/views/dashboard/index.blade.php

## v3.17.4.3 — 2026-07-09 00:05
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Protege graficos do dashboard no mobile
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.17.4.2 — 2026-07-09 00:02
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta densidade visual dos graficos do dashboard
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.17.4.1 — 2026-07-08 23:54
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Reorganiza graficos do dashboard
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/index.blade.php,frontends/desktop/public/assets/js/dashboard.js,frontends/desktop/public/assets/css/desktop.css

## v3.17.4.0 — 2026-07-08 22:57
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste no grafico de os entregues reparadas mes de março 2026
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.3.1 — 2026-07-08 22:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige serie mensal de entregas reparadas do dashboard para ignorar atualizacoes de importacao legado
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.3.0 — 2026-07-08 22:00
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajustes no dashboard
- **Arquivos:** frontends/desktop/app/Services/DocumentationService.php,frontends/desktop/resources/views/configurations/system.blade.php

## v3.17.2.1 — 2026-07-08 21:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Alinha KPI de OS abertas do dashboard ao escopo operacional da listagem de OS
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.2.0 — 2026-07-08 21:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** mostrar versionamento na documentaçãodo sistema nas configurações
- **Arquivos:** frontends/desktop/app/Services/DocumentationService.php

## v3.17.1.0 — 2026-07-08 21:20
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige permissao de execucao dos scripts (core.fileMode=false ignorava chmod +x) via git update-index --chmod, e corrige deploy-completo.sh lendo VERSION/CHANGELOG de develop (nao do main antigo) para a mensagem do merge
- **Arquivos:** scripts/bash/atualizar-dev.sh,scripts/bash/deploy-completo.sh,scripts/bash/deploy-producao.sh,scripts/bump-version.sh,scripts/classify-change.sh,scripts/versionar.sh

## v3.17.0.0 — 2026-07-08 21:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona scripts/versionar.sh e scripts/bash/deploy-completo.sh para versionar e publicar (dev->main) sem depender de IA
- **Arquivos:** AGENTS.md,.agents/skills/sistema-erp-deploy-producao/SKILL.md,documentacao/10-deploy/workflow-git-multiambiente.md,scripts/bash/deploy-completo.sh,scripts/versionar.sh,VERSIONING.md

## v3.16.0.1 — 2026-07-08 19:42
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige Baixa sumindo para Irreparavel/Reparo Recusado na listagem (is_encerrada ausente em mapSummary) e evita N+1 em OrderStatus::closureCodes()
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/index.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v3.16.0.0 — 2026-07-08 19:42
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Bloqueia mudanca de status em OS encerrada e adiciona Cancelar baixa com gate de administrador (step-up auth)
- **Arquivos:** backend/app/Models/OrderStatus.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/CancelOrderClosureRequest.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/app/Services/Financeiro/OsMargemService.php,backend/routes/api.php,backend/bootstrap/app.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_cancel_closure_modal.blade.php,frontends/desktop/public/assets/js/orders-cancel-closure-modal.js

## v3.15.2.4 — 2026-07-08 12:32
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige botao Limpar da listagem de OS para remover filtros via rota limpa
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php

## v3.15.2.3 — 2026-07-08 12:27
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Indica filtros ativos e trava recolhimento do painel de filtros da listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.2 — 2026-07-08 11:58
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta altura do badge de resultados e botao Filtros na listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.1 — 2026-07-08 11:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Alinha contador de resultados e botao Filtros ao campo de busca na listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.0 — 2026-07-08 11:46
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS: sincronizacao instantanea Status/Macrofase com Select2 e limpeza sem recarregar pagina
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.15.1.0 — 2026-07-08 11:32
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS: Macrofase movida para filtros principais e sincronizada bidirecionalmente com Status
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.15.0.0 — 2026-07-08 11:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS passam a usar catalogo proprio de status autorizado por os:visualizar, restaurando Select2 de status e macrofase
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/DesktopOrderStatusFlowService.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.14.2.0 — 2026-07-08 10:50
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Listagem inicial de OS passa a ocultar encerramentos canonicos e entregas com cobranca pendente, mantendo filtros explicitos para historico
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/index.blade.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.14.1.0 — 2026-07-07 12:54
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Listagem de OS: botao 'Filtrar' ao lado do campo de busca no cabecalho + correcao do campo de busca que estava fora do <form> (nao era submetido ao filtrar). A busca agora submete o form de filtros via atributo HTML5 form=osFilterPanel, carregando status/itens por pagina/filtros avancados junto, mesmo com o painel recolhido.
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.14.0.0 — 2026-07-07 12:45
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Overhaul do modal 'Alterar status da OS': card de equipamento passa a exibir tipo+marca+modelo; switch 'Notificar o cliente' movido para o rodape do modal; nova aba 'Procedimentos' em 2 colunas — registro de procedimentos executados com historico datado por tecnico (nova tabela os_procedimentos_historico) + campos de diagnostico e solucao salvos junto com o status; botao 'Salvar status' sempre habilitado (permite salvar diagnostico/solucao sem trocar o status, sem gerar historico/notificacao espuria); notificacao WhatsApp ao cliente na mudanca de status quando o switch esta ativo, com fallback de envio direto pela Evolution API quando a Central de Atendimento (banco chat) esta indisponivel; corrigido fundo transparente do modal (faltava a classe modal-shell). Novo endpoint POST /api/v1/orders/{order}/procedures; migration aditiva os_procedimentos_historico.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpdateOrderStatusRequest.php,backend/app/Http/Requests/Api/V1/StoreOrderProcedureRequest.php,backend/app/Models/Order.php,backend/app/Models/OrderProcedureHistory.php,backend/database/migrations/2026_07_07_000001_create_os_procedimentos_historico_table.php,backend/routes/api.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/_status_modal.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/js/orders-status-modal.js,frontends/desktop/public/assets/css/desktop.css

## v3.13.1.0 — 2026-07-06 23:59
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige 'Ocorreu um erro inesperado' ao salvar orcamento (INSERT falhava com SQLSTATE 42S22 Unknown column desconto_tipo): a migration 2026_07_03_000001_add_adjustment_modes_to_orcamentos_tables estava marcada como executada no laravel_migrations, mas as 4 colunas de ajuste percentual (desconto_tipo, desconto_percentual, acrescimo_tipo, acrescimo_percentual) nunca existiram de fato nas tabelas orcamentos e orcamento_itens deste banco (drift de schema, mesma classe de problema ja documentada no deploy Contabo). Corrigido com ALTER aditivo direto no banco de dev (192.168.1.100), sem alterar nenhum arquivo de codigo
- **Arquivos:** (nenhum arquivo de codigo — correcao aplicada diretamente no banco sistema_hml de 192.168.1.100)

## v3.13.0.0 — 2026-07-06 23:44
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cadastro rapido de item no orcamento: campo 'Tipo de equipamento' virou Select2 com tags (escolher existente ou digitar novo), reaproveitando o catalogo ja usado em Servicos/Estoque; corrigido bug generico de dropdown do Bootstrap dentro de tabela responsiva (abria para cima sobre a propria linha e ficava cortado em tabelas curtas) — menu agora e movido para o body enquanto aberto, corrigindo Acoes em todas as listagens (OS, equipamentos, financeiro etc.)
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/quick-item-modal.blade.php

## v3.12.0.1 — 2026-07-06 12:04
- **Tier:** hotfix
- **Autor/Agente:** Claude
- **Descrição:** Documenta na armadilha do runbook Contabo o erro 'untracked working tree files would be overwritten by merge' no passo [2/5] do deploy-producao.sh (arquivos nao versionados na VPS colidindo com o commit remoto) e como resolver movendo-os para backup antes de repetir o script
- **Arquivos:** documentacao/10-deploy/deploy-producao-contabo-vps.md

## v3.12.0.0 — 2026-07-06 11:12
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Fluxo de caixa: coluna 'Entrada projetada' (dia em que o dinheiro efetivamente cai na conta para vendas em cartão, podendo cruzar de mês) e coluna 'Saldo líquido em conta' (acumulado já líquido de taxa, corrigindo também um bug pré-existente em que o saldo inicial só somava o dia anterior ao período em vez do histórico completo); botão de Detalhes por dia com modal de lançamentos (pago/recebido e previsto para cair no dia) e submodal de detalhes do cartão (operadora, bandeira, modalidade, parcelas, taxa, prazo); correção de um bug do Bootstrap 5 (modal empilhado perde o scroll-lock do modal externo ao fechar o interno)
- **Arquivos:** backend/app/Models/Financeiro.php,backend/app/Models/FinanceiroMovimento.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/tests/Feature/Api/V1/FinanceiroReportTest.php,frontends/desktop/resources/views/financeiro/relatorios/fluxo-caixa.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js

## v3.11.0.0 — 2026-07-06 11:12
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Cancelamento de lançamento financeiro: botão Cancelar no dropdown de Ações (estorna movimentos do título e, se houver, da despesa de Taxa de cartão vinculada), exclusão de títulos cancelados do DRE por competência, e taxa da operadora passa a ser registrada como despesa separada (Despesas Operacionais / Taxas e impostos) no dia do pagamento, em vez de ficar invisível no fluxo de caixa e no DRE
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/routes/api.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/lang/pt_BR/validation.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v3.10.0.0 — 2026-07-05 23:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Baixa de lancamento financeiro: botoes de valor total/parcial e forma de pagamento com campos de cartao (operadora/bandeira/modalidade/parcelas) e estimativa de taxa, no mesmo padrao da baixa da OS; backend passa a expor valor_aberto por lancamento e o catalogo de cartao, e registra FinanceiroMovimentoCartao quando a baixa e' em cartao. Corrigido tambem um bug critico pre-existente (ja presente antes desta entrega): o modal de baixa era um `<div>` filho direto de `<tbody>` (invalido em HTML), o que faz o navegador aplicar "foster parenting" e esvaziar o `<form>` — o `Confirmar baixa` submetia o formulario sem nenhum campo. Os modais agora sao renderizados num loop separado, fora de `<table>`/`<tbody>`
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCatalogController.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Http/Requests/Api/V1/RegisterFinanceiroMovementRequest.php,backend/app/Services/Financeiro/FinanceiroService.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/public/assets/js/financeiro-pay.js

## v3.9.1.0 — 2026-07-05 23:13
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige TypeError 'destroy is not a function' no Select2 da tela Financeiro (Cliente/Categoria): atributo data-select2="false" colidia com a chave interna do plugin e foi trocado por data-native-select="true"
- **Arquivos:** frontends/desktop/resources/views/financeiro/form.blade.php

## v3.9.0.0 — 2026-07-05 20:30
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Lançamentos financeiros avulsos sem OS, opcionais por cliente, com histórico protegido no cliente e bloqueio no fluxo da OS
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertFinanceiroRequest.php,backend/app/Models/Financeiro.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/database/migrations/2026_07_05_190000_add_avulso_to_financeiro_table.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/resources/views/financeiro/index.blade.php,specs/020-lancamentos-avulsos-financeiro-cliente,documentacao/07-novas-implementacoes/2026-07-05-lancamentos-avulsos-financeiro-cliente.md

## v3.8.0.0 — 2026-07-05 18:04
- **Tier:** minor
- **Autor/Agente:** jovem-tech
- **Descrição:** Sistema de desenvolvimento e deploy profissional: repositorio GitHub (jovem-tech/erp) como fonte unica da verdade, branches develop (dev 192.168.1.100) / main (producao VPS Contabo), deploy keys dedicadas por servidor, scripts de deploy git-based, XAMPP definitivamente descontinuado. AGENTS.md ganha mandato LEIA ISTO PRIMEIRO para qualquer IA.
- **Arquivos:** AGENTS.md,README.md,documentacao/10-deploy/workflow-git-multiambiente.md,scripts/bash/deploy-producao.sh,scripts/bash/atualizar-dev.sh

## v3.7.3.1 — 2026-07-05 16:37
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Documentacao em dia com a producao: notas do deploy Contabo (subdominios, copia de dados reais, fixes de schema/broadcasting/DNS) e da padronizacao de cliente; novo runbook Contabo; historico 3.7.1-3.7.3; AGENTS e skill de deploy com topologia atual

## v3.7.3.0 — 2026-07-05 16:24
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Padroniza nome (Title Case pt-BR, so pessoa fisica) e telefone (mascara (DDD) numero) no cadastro de cliente do desktop (rapido e completo): JS para UX ao vivo + ClientController autoritativo
- **Arquivos:** frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/public/assets/js/clients-form.js

## v3.7.2.0 — 2026-07-05 06:08
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige broadcasting/auth 403 em producao: channels.php passa a ser carregado com require (nao loadRoutesFrom) para sobreviver ao route:cache, registrando os canais de broadcasting (tempo real da Central de Atendimento e OS ao vivo)
- **Arquivos:** backend/app/Providers/AppServiceProvider.php

## v3.7.1.0 — 2026-07-04 22:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige OrderController::jsonFailure ausente (500 na busca de clientes/Select2 da Nova OS) e reconcilia schema de clientes/usuarios/financeiro com colunas que o ERP espera (referencia etc.), aplicado em dev e VPS
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrderController.php

## v3.7.0.0 — 2026-07-04 17:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Ambiente de desenvolvimento oficial migrado para Linux (BANCADA-02); nova topologia de portas (desktop 443, backend 8443); correcoes de auditoria: pools FPM dedicados, upload 25M, MySQL/Redis tuning, UFW+fail2ban, SSH hardening, TLS com SAN, backup diario, cookie Secure; ApiClient Guzzle 8-ready; raiz backend sem welcome page
- **Arquivos:** documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md,backend/routes/web.php,frontends/desktop/app/Services/ApiClient.php,AGENTS.md

## v3.6.0.1 — 2026-07-04 09:20
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Rodape do desktop passa a exibir a versao de 4 posicoes lida do arquivo VERSION (fonte unica), com fallback para shared/version.php
- **Arquivos:** frontends/desktop/app/Providers/DesktopAppServiceProvider.php

## v3.6.0.0 — 2026-07-04 08:35
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Deploy de producao em LAN Ubuntu documentado (runbook 10-deploy), aba Documentacao em Configuracoes>Sistema no desktop, correcao ForceHttps com BinaryFileResponse, adocao do protocolo de versionamento 4 posicoes
- **Arquivos:** documentacao/10-deploy/deploy-producao-lan-ubuntu.md,frontends/desktop/app/Services/DocumentationService.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/configurations/system.blade.php,backend/app/Http/Middleware/ForceHttps.php,VERSIONING.md,VERSION,CHANGELOG.md

## v3.5.3.0 — Baseline
- **Tier:** —
- **Autor/Agente:** Otávio
- **Descrição:** Ponto de partida do novo protocolo de versionamento de 4 posições. Versão anterior era V3.5.3 (3 posições); a partir daqui todo commit deve gerar uma entrada aqui.

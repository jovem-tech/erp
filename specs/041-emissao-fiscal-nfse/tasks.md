# Tarefas — Emissão fiscal: NFS-e e prontidão de dados

> Só a **Fase 1** está detalhada. As fases seguintes ficam em uma linha de
> propósito: detalhar tarefa que ainda depende de certificado digital, de
> contador e da regra municipal de material seria inventar trabalho.

## Bloco 1 — Banco
- [x] Migration `2026_09_01_000001_add_fiscal_fields_to_clientes_and_servicos` —
      `clientes.codigo_ibge_municipio`; `servicos.codigo_tributacao_nacional`,
      `item_lc116`, `aliquota_iss`, `unidade`. Guardas `hasTable`/`hasColumn`,
      tudo nullable ⚠️ **sem** `NOT NULL` em `cpf_cnpj`: 1.323 linhas existentes
      violariam
- [x] Espelho em `BuildsLegacyErpSchema` (o trait recria as tabelas do zero)
- [x] Aplicada com `--path` ⚠️ o grant de `sistema_erp_chat` aborta o
      `artisan migrate` inteiro
- [x] `Servico`: cast `aliquota_iss => decimal:2`

## Bloco 2 — Cadastro da empresa
- [x] Chaves fiscais em `CompanyProfileService::DEFAULTS` — endereço
      estruturado, código IBGE, inscrição municipal, CNAE, código de tributação
      ⚠️ nenhuma migration: `configuracoes` é chave-valor
- [x] `UpdateCompanyProfileRequest` com as regras novas
- [x] `CompanyContextProvider` expõe os campos novos **de forma aditiva**;
      `empresa_endereco` (linha única) fica intacto porque os PDFs já emitidos
      dependem dele
- [x] `ConfigurationController::updateCompany` encaminha os campos
- [x] Bloco "Dados fiscais" fechado em `configurations/system.blade.php`

## Bloco 3 — Validação de documento
- [x] `App\Support\Documento` — normalização `[0-9A-Z]`, DV de CPF e de CNPJ
      (inclusive **alfanumérico**, `ord($c) - 48`), recusa de sequência repetida,
      `formatar()` e `regra()` para `$request->validate()`
- [x] `tests/Unit/Support/DocumentoTest.php` — 15 casos, 34 asserções
- [x] `Api/V1/ClientController::validatedClientPayload()` — trocar o
      `preg_replace('/\D+/')` da linha ~261 por `Documento::normalizar()` e somar
      `Documento::regra()` às regras ⚠️ o `preg_replace` apaga letra de CNPJ
      alfanumérico; a normalização precisa acontecer **antes** da validação para o
      DV conferir o valor canônico
- [x] `UpsertOrderRequest` — mesma troca em `prepareForValidation()` (linha ~171)
      e `Documento::regra()` em `cliente_atualizacao.cpf_cnpj`
- [x] `UpsertOrderRequest` — criar a regra de `novo_cliente.cpf_cnpj`, que hoje
      não existe ⚠️ sem `unique`: recusar OS por CPF já cadastrado é mudança de
      fluxo, decisão própria
- [x] Mensagem de erro tem de dizer o que está errado, ou o operador contorna
      digitando qualquer coisa
- [x] `ClientFlowTest` — DV inválido recusado nas portas, vazio aceito,
      CNPJ alfanumérico preservado com as letras, unicidade seguindo íntegra

## Bloco 4 — UI (desktop)
- [x] `public/assets/js/documento.js` — máscara CPF/CNPJ (alfanumérico incluído)
      e checagem de DV inline; espelho em JS de `App\Support\Documento`, **não**
      a autoridade ⚠️ carregado no `layouts/app.blade.php`, global, porque o
      modal rápido é incluído por 5 telas
- [x] `clients/form.blade.php` e `clients/quick-modal.blade.php` com
      `data-documento`; no modal o CPF subiu para logo abaixo do telefone
      principal, continuando **opcional**
- [x] Bloco "Dados fiscais" em `configurations/system.blade.php` — endereço
      estruturado, IBGE, inscrição municipal, CNAE, código de tributação
- [x] `clients/form.blade.php`: código IBGE do município
      ⚠️ só no formulário completo — código de município não é dado que se
      pergunta no balcão, então o modal rápido não pede
- [x] `servicos/form.blade.php`: bloco "Dados fiscais (opcional)", fechado,
      no mesmo formato da aba fiscal da peça (`027`)

## Bloco 5 — Relatório de prontidão fiscal
- [x] `App\Services\Fiscal\ProntidaoFiscalService` (backend) — total, sem
      documento, documento inválido, prontos e percentual, já dividido por área
      para receber peça/serviço/empresa na fatia 2
- [x] Comando `fiscal:prontidao {--json}`, no padrão de `file-manager:diagnose`
      ⚠️ sai com código 0 mesmo com pendência: cadastro incompleto não é falha
      de execução
- [x] `Api/V1/FiscalController` + rota `GET /api/v1/fiscal/prontidao`
- [x] Tela do desktop (`fiscal.prontidao`) + `ProntidaoFiscalService` (BFF)
- [x] `ProntidaoFiscalTest` no backend (6 casos) e no desktop (4 casos)
- [x] Peças sem NCM, serviços sem código e campos da empresa entraram no
      relatório ⚠️ conta só serviço e peça **ativos**: item encerrado não vai
      para nota, e inflar o número faria o relatório exagerar

## Bloco 6 — Dados
- [ ] **NCM das 9 peças e código de tributação dos 10 serviços** — não
      preenchido de propósito: classificação fiscal é decisão do contador, e
      chutar NCM produz nota recusada ou imposto errado. O relatório
      (`fiscal:prontidao`) já mostra exatamente quais faltam.

## Fase 042 — documento fiscal e modo assistido
- [x] Migration `2026_09_01_000002_create_documentos_fiscais_table` — tabela
      nova, **não** colunas em `os`: uma OS gera mais de um documento (NFS-e do
      serviço, NF-e da peça) e um cancelado é substituído por outro
      ⚠️ **não** espelhada em `BuildsLegacyErpSchema`: o trait só recria tabela
      legada; esta nasce da migration, e duplicar dá "table already exists"
- [x] `DocumentoFiscal` (model) com os estados rascunho/emitido/cancelado/rejeitado
- [x] `DocumentoFiscalService` — rascunho **idempotente** por OS (dois rascunhos
      virariam duas notas do mesmo serviço), registro do retorno do portal,
      rejeição e cancelamento
      ⚠️ peça **não** entra na discriminação da NFS-e: é mercadoria, sai por
      NF-e/NFC-e na SEFAZ estadual
- [x] `Api/V1/DocumentoFiscalController` + 5 rotas, autorizando por `os`
      ⚠️ quando a emissão virar integração (`043`) deixa de ser ação de OS e
      pede um módulo `fiscal` próprio no RBAC
- [x] `DocumentoFiscalTest` — 9 casos, 35 asserções
- [x] Tela do desktop: `/fiscal/pendentes` (OS encerradas com valor e sem
      nota) e `/fiscal/os/{id}/nota` (rascunho, copiar discriminação, registrar
      retorno, rejeição, cancelamento), mais botão no detalhe da OS
      ⚠️ pendente marca cliente **sem documento** — é o aviso que manda alguém
      preencher o cadastro em vez de tentar emitir
- [x] Anexo de XML e PDF do portal
      ⚠️ **não** por `managed_file_id`: o Gerenciador roda em `shadow` com
      escrita central desligada (`FILE_MANAGER_MODE=shadow`,
      `ALLOW_WRITES=false`), então no upload não existe `ManagedFile` para
      apontar. Grava caminho + hash + tamanho como `os_documento_arquivos`, e a
      varredura automática cataloga depois — caminho de todo upload do sistema
      - [ ] Acrescentar `private/fiscal` a `FILE_MANAGER_AUTOMATIC_SYNC_ROOTS`
            para a varredura enxergar a pasta (mudança de `.env`, não de código)

## Fases seguintes
## Fase 043 — certificado A1 e DPS assinada
- [x] `config/fiscal.php` — caminho e senha do `.pfx` no `.env`, conteúdo nunca
      no banco ⚠️ mesma decisão de `config/inter.php`: o dump diário é gzip sem
      cifra e o backup de configuração carrega o `APP_KEY`, então chave e
      segredo cairiam no mesmo pacote. Custo aceito: trocar certificado é mexer
      em servidor, não em tela
- [x] `App\Services\Fiscal\CertificadoA1` — abre o `.pfx`, valida senha, lê
      titular, CNPJ e validade ⚠️ "não sei" nunca é tratado como "está válido":
      arquivo ilegível derruba a emissão igual a certificado vencido
- [x] Comando `fiscal:verificar-certificado {--json}`, espelhando
      `inter:verificar-certificado` — o A1 vence em silêncio
- [x] `App\Services\Fiscal\DpsXmlBuilder` — monta a DPS e assina em XMLDSig
      ⚠️ anexar a `<Signature>` ANTES de assinar: solto, o `SignedInfo`
      canonicaliza com o próprio `xmlns`; depois de anexado o C14N descarta a
      declaração redundante, e a assinatura falha sem erro na geração
- [x] Estado do certificado na tela de prontidão ⚠️ ausente **não** é
      pendência: sem A1 a NFS-e continua saindo pelo modo assistido. Instalado
      e quebrado, sim — aí alguém pagou e acha que está emitindo
- [x] `CertificadoA1Test` (6 casos) e `DpsXmlBuilderTest` (5 casos), com
      certificado autoassinado gerado no teste; a assinatura é conferida de
      verdade com a chave pública
- [x] **Layout da DPS validado contra o XSD oficial v1.01**, versionado em
      `tests/Fixtures/nfse-schemas` (ver `ORIGEM.md`). A validação pegou 4 erros
      que a leitura da documentação não pegou: `regEspTrib` faltando em
      `regTrib`, `locPrest` obrigatório antes de `cServ`, e `trib` obrigatório
      dentro de `valores`
      ⚠️ defeito **no pacote oficial**: o `pattern` de `serie` é insatisfazível
      (em XSD 1.0 `^`/`$` são literais; com `maxLength=5` só "^1$" passa). O
      teste tolera exatamente esse erro e reprova qualquer outro
- [ ] Cliente da API do ADN (mTLS) e transmissão — depende do A1 real e de
      credenciais de homologação
- [x] **Instalação do A1 pela tela** — Configurações › Integrações › aba
      "Certificado A1": upload do `.pfx` + senha, validados **antes** de gravar
      (senha errada, arquivo inválido ou certificado vencido não substituem o
      que já funciona). Decisão revista a pedido: o sistema é vendido e o novo
      dono não tem terminal
      ⚠️ divisão deliberada: o **`.pfx` vai para o disco**, nunca para o banco
      (chave privada não entra em dump); a **senha vai cifrada** em
      `configuracoes` via `SecretSettings` — mais seguro que o `.env`, onde
      ficava em texto puro. O `.env` continua valendo como fallback
      ⚠️ quem grava é o `www-data` (processo web), então o dono do arquivo
      nasce certo — some a armadilha do `scp` como outro usuário
      ⚠️ o estado é carregado **sob demanda**, quando a aba abre: buscar no
      render faria toda visita a Configurações pagar uma ida ao backend
- [ ] `044` — NF-e/NFC-e de peça, destaque de IBS/CBS e split payment

## Antes de testar
- [ ] `config:clear` **e** `route:clear` ⚠️ rota nova dá "Route não definida"
      mesmo presente no arquivo, e teste de desktop vira 302, com cache quente
- [ ] Baseline da suíte com `git stash` antes de atribuir falha a esta entrega

## Fase 045 — importar XML, guardar na OS, emitir na baixa
- [x] **Correções que um XML real do ADN impôs** — `opSimpNac` 1 → **2** (MEI);
      assinatura passou a `rsa-sha256` + `xml-exc-c14n#WithComments`; `cNBS`
      opcional emitido
      ⚠️ o defeito pior era declarar c14n **inclusiva** e canonicalizar
      **exclusiva**: gerador e verificador calculavam bytes diferentes. O teste
      não pegava porque repetia a chamada em vez de seguir o algoritmo
      declarado — agora ele lê `CanonicalizationMethod`/`SignatureMethod` do
      próprio XML
- [x] `App\Services\Fiscal\NfseXmlImporter` — lê `NFSe/infNFSe` + `DPS/infDPS`,
      extrai número, série, chave, valores, tomador e códigos fiscais
      ⚠️ o portal entrega o XML com acentuação **duplamente codificada**
      (`SÃ£o Pedro`); o importador normaliza
      ⚠️ recusa XML de outro CNPJ (prestador) e de outro tomador (OS errada) —
      número e chave parecem legítimos porque são, só que de outro atendimento
- [x] `DocumentoFiscalService::registrarPorXml()` + endpoint
      `POST /fiscal/documentos/{id}/importar-xml`, guardando o XML no mesmo ato
- [x] Arquivos passam a `private/os_documentos/{os}/fiscal/` — o root
      `order_files` já cataloga e já vincula à OS, então aparecem em
      "Documentos de OS" sem código de vínculo
- [x] `inferCategory()`: XML sob a OS e PDF em `/fiscal/` viram `FiscalDocument`
- [x] Tela da nota: "Importar o XML da nota" antes do preenchimento manual, e
      DANFSe oficial embutido quando há PDF anexado
- [x] `tests/Fixtures/nfse/nfse-real-mei.xml` — NFS-e MEI real como fixture
      (ver `ORIGEM.md`) ⚠️ o XML da nota **não** indica cancelamento: no padrão
      nacional isso é evento separado (`evento_v1.01.xsd`)
- [x] DANFSe reconstruído do XML — `App\Services\Pdf\NfseDanfseRenderer` +
      `resources/views/pdf/nfse-danfse.blade.php`, exibido só quando não há o
      PDF oficial (que sempre tem precedência)
      ⚠️ mora em `app/Services/Pdf/` porque é o único namespace autorizado a
      chamar dompdf (`PdfEngineGuardTest`) — a primeira versão gerava do
      controller e o guarda pegou
      ⚠️ **não** é tipo do `PdfTemplateRegistry` de propósito: o DANFSe tem
      forma definida em norma, e expô-lo na tela de Modelos convidaria alguém a
      editar um documento fiscal
- [x] `rascunhoDeOrdem()` passa a devolver a nota **já emitida** da OS
      ⚠️ antes só reaproveitava rascunho/rejeitado, então cada visita depois de
      emitir criava um rascunho novo: a tela dizia "Rascunho" para uma OS que já
      tinha nota, e reimportar o XML batia na trava de número duplicado
- [x] Erro de número duplicado passa a dizer em qual OS o número já está
- [x] `ApiClient` normaliza `['campo' => $arquivo]` e `['campo' => [$arquivo]]`
      ⚠️ passar o objeto solto fazia o `foreach` iterar as propriedades do
      `UploadedFile` e **descartar o anexo sem erro** — três uploads meus
      (XML, arquivos do portal e certificado A1) saíam sem arquivo
- [x] **Aba fiscal na baixa** — 4ª etapa em `orders/closure.blade.php`
      (Encerramento › Financeiro › Fiscal › Confirmação), habilitada só quando
      "Encerrar como" = entregue reparado e pago; importa o XML no mesmo POST
      do encerramento, abre o portal em popup e traz a edição rápida do cliente
      ⚠️ o portal **não** entra em iframe: `nfse.gov.br` responde
      `X-Frame-Options: SAMEORIGIN` e `frame-ancestors 'none'` (conferido).
      Popup foi o caminho possível
      ⚠️ falha na importação é **aviso**, nunca falha do encerramento: a OS já
      foi fechada e desfazer isso por causa do anexo seria pior que o problema
- [ ] Envio da nota ao cliente (WhatsApp/e-mail) — depende do bloco abaixo,
      que é onde mora a máquina de envio
- [x] **Tela "Notas emitidas"** (`fiscal.emitidas`, menu Fiscal) — contraparte
      de "Notas pendentes": lá o eixo é a OS sem nota, aqui é o documento que já
      existe. Busca por número, chave, OS, cliente ou CPF/CNPJ; filtro de
      situação e de período; XML, PDF e DANFSe na própria linha
      ⚠️ o padrão do filtro é `emitido,cancelado`: cancelada some da fila de
      pendentes (a OS volta para lá) e sumiria de todo lugar se não aparecesse
      aqui — o histórico é parte da guarda de 5 anos
      ⚠️ a **soma conta só o que está `emitido`**. Somar cancelada e rascunho
      inventaria receita; o valor sai de `valor_xml` quando existe, porque o que
      vale é o declarado, não o que o ERP calculou
      ⚠️ o filtro de período usa `emitido_em` e **não** `created_at`: a data que
      o contador cobra é a da emissão
      ⚠️ status desconhecido na URL é descartado em vez de virar `whereIn` vazio
      — que devolveria zero linhas e pareceria "não há notas"
      ⚠️ a busca normaliza o termo antes de comparar com `chave` e
      `tomador_documento`, que são guardados sem pontuação
- [ ] Tipo `nota_fiscal` na Central de Documentos da OS
- [ ] **Alinhar o gate do desktop ao módulo `fiscal`** — as rotas fiscais do
      desktop ainda exigem `os,visualizar`/`os,editar`, enquanto o backend já
      autoriza por `fiscal:*`. Não é brecha (o backend é a autoridade e recusa),
      mas um grupo com `os` e sem `fiscal` vê o menu e leva 403 da API em vez de
      não ver a tela

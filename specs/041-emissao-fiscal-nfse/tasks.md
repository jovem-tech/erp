# Tarefas — Emissão fiscal: NFS-e e prontidão de dados

> Só a **Fase 1** está detalhada. As fases seguintes ficam em uma linha de
> propósito: detalhar tarefa que ainda depende de certificado digital, de
> contador e da regra municipal de material seria inventar trabalho.

## Bloco 1 — Banco
- [ ] Migration `add_fiscal_fields_to_clientes` — `codigo_ibge_municipio`
      (`string(7)`, nullable), com guardas `hasTable`/`hasColumn`
      ⚠️ **sem** `NOT NULL` em `cpf_cnpj`: 1.323 linhas existentes violariam
- [ ] Migration `add_fiscal_fields_to_servicos` — `codigo_tributacao_nacional`,
      `item_lc116`, `aliquota_iss`, `unidade`, todas nullable, espelhando o
      formato que `pecas` ganhou na `027`
- [ ] Espelho de **toda** coluna nova em `BuildsLegacyErpSchema`
      ⚠️ o trait recria as tabelas do zero depois das migrations; coluna não
      repetida ali some nos testes sem erro
- [ ] Aplicar com `--path` ⚠️ o grant de `sistema_erp_chat` aborta o
      `artisan migrate` inteiro

## Bloco 2 — Cadastro da empresa (backend)
- [ ] Chaves fiscais em `Configuration`: `empresa_logradouro`, `empresa_numero`,
      `empresa_complemento`, `empresa_bairro`, `empresa_cidade`, `empresa_uf`,
      `empresa_cep`, `empresa_codigo_ibge`, `empresa_inscricao_municipal`,
      `empresa_cnae`, `empresa_codigo_tributacao_nacional`
- [ ] `UpdateCompanyProfileRequest`: regras dos campos novos
- [ ] `CompanyProfileService` + `CompanyContextProvider`: ler e escrever os novos
      ⚠️ `empresa_endereco` (`string(255)`) continua existindo para os PDFs já
      emitidos — os campos novos convivem, não substituem
- [ ] Teste de round-trip do perfil com os campos fiscais

## Bloco 3 — Validação de documento
- [x] `App\Support\Documento` — normalização `[0-9A-Z]`, DV de CPF e de CNPJ
      (inclusive **alfanumérico**, `ord($c) - 48`), recusa de sequência repetida,
      `formatar()` e `regra()` para `$request->validate()`
- [x] `tests/Unit/Support/DocumentoTest.php` — 15 casos, 34 asserções
- [ ] Extrair a normalização que **já existe** em
      `UpsertOrderRequest::prepareForValidation()` (reduz `cpf_cnpj` a dígitos)
      para um lugar compartilhado — não escrever uma segunda
- [ ] Regra de dígito verificador de CPF/CNPJ, sobre essa normalização
- [ ] Aplicar nas **duas portas do backend** na mesma entrega:
      `Api/V1/ClientController::validatedClientPayload()` (hoje só
      `'cpf_cnpj' => ['nullable','string','max:20']`, sem normalizar) e
      `UpsertOrderRequest` ⚠️ mudar uma só faz o mesmo documento entrar em dois
      formatos e o `Rule::unique` deixar passar a duplicata
- [ ] ⚠️ o desktop **não** recebe cópia da regra: é BFF, encaminha. O feedback
      inline do formulário é JavaScript
- [ ] ⚠️ mensagem tem de dizer o que está errado, ou o operador contorna
      digitando qualquer coisa
- [ ] Testes: CPF válido, CPF de dígito errado, CNPJ válido, vazio (aceita),
      e o mesmo CPF com e sem máscara pelas duas portas

## Bloco 4 — UI (desktop)
- [ ] Aba "Fiscal" em `configurations/system.blade.php` +
      `ConfigurationController` — endereço estruturado, IBGE, inscrição
      municipal, CNAE, código de tributação
- [ ] `clients/form.blade.php`: código IBGE, junto de cidade/UF
- [ ] `clients/quick-modal.blade.php` e `orders/_wizard.blade.php`: CPF em
      destaque ⚠️ **não** `required` — pede, não exige (decisão da spec)
- [ ] `servicos/form.blade.php`: aba "Fiscal" com os campos novos
- [ ] ⚠️ nada de `class=` junto com `@class()` na mesma tag: o navegador ignora
      o segundo e `assertSee` não pega o defeito
- [ ] Testes de render por permissão

## Bloco 5 — Relatório de prontidão fiscal
- [ ] Serviço de diagnóstico: clientes sem CPF, clientes sem IBGE, peças sem
      NCM, serviços sem código de tributação, campos faltando na empresa
- [ ] Tela do relatório, com contagem e link para o cadastro de cada pendência
- [ ] Command de console para rodar o mesmo diagnóstico fora da tela
- [ ] Testes: base vazia (tudo pendente) e base completa (nada pendente)

## Bloco 6 — Dados
- [ ] NCM nas 9 peças do catálogo
- [ ] Conferir com o contador o código de tributação dos serviços recorrentes
      antes de preencher em massa

## Fases seguintes
- [ ] `042` — `documentos_fiscais` e modo assistido de emissão
- [ ] `043` — NFS-e pela API, com certificado A1
- [ ] `044` — NF-e/NFC-e de peça, destaque de IBS/CBS e split payment

## Antes de testar
- [ ] `config:clear` **e** `route:clear` ⚠️ rota nova dá "Route não definida"
      mesmo presente no arquivo, e teste de desktop vira 302, com cache quente
- [ ] Baseline da suíte com `git stash` antes de atribuir falha a esta entrega

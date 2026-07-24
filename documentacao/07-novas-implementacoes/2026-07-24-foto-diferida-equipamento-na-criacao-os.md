# Foto diferida do equipamento na criação de OS

**Data:** 24/07/2026  
**Versão do sistema:** 5.11.1.0  
**Versão da API:** 1.6.0

## Objetivo

Corrigir o fluxo de criação de uma ordem de serviço a partir de orçamento avulso aprovado para que a foto obrigatória do novo equipamento:

- seja exibida imediatamente no card principal e na aba **Fotos**;
- permaneça somente na memória do navegador durante o preenchimento;
- seja enviada apenas no POST final da OS;
- seja persistida junto com cliente, equipamento e OS;
- nunca seja confundida ou duplicada como foto de entrada da OS.

## Arquitetura

O fluxo usa dois grupos de imagens independentes:

1. `novo_equipamento_fotos[]`: fotos cadastrais do novo equipamento;
2. `fotos[]`: fotos operacionais de entrada vinculadas à OS.

O iframe de cadastro do equipamento devolve dados e objetos `File` por `postMessage`. A página da OS normaliza os arquivos para o contexto da janela principal, cria URLs `blob:` apenas para preview e recompõe um `input[type=file]` oculto com `DataTransfer`.

Nenhuma requisição de cadastro é realizada ao fechar o modal. O POST ocorre somente no salvamento final da OS.

No backend, `novo_cliente`, `novo_equipamento`, as fotos do equipamento e a OS são processados pela mesma operação de criação. Cliente e equipamento diferidos são criados dentro da transação da OS. Uma requisição sem a foto obrigatória é rejeitada antes de qualquer workflow de persistência.

## Correções implementadas

- preview da foto principal do equipamento pendente;
- galeria temporária na aba **Fotos**, identificada como não persistida;
- contagem separada entre fotos do equipamento e fotos de entrada;
- normalização segura de `File`/`Blob` recebido do iframe;
- validação de MIME, tamanho máximo de 2 MB e limite de quatro fotos no contexto pai;
- falha explícita quando o navegador não consegue preparar o arquivo para multipart;
- sincronização defensiva do campo `novo_equipamento_fotos[]` imediatamente antes do submit;
- limpeza das URLs `blob:` ao trocar/remover o equipamento ou sair da página;
- validação condicional no desktop e na API: novo equipamento exige de uma a quatro fotos;
- contrato OpenAPI atualizado com os cadastros diferidos e as fotos condicionais.

## Segurança e integridade

- `postMessage` é aceito apenas da mesma origem e do `contentWindow` do iframe esperado;
- extensões não são a única barreira: o MIME e a imagem são revalidados no desktop e no backend;
- nomes de arquivo são apenas metadados; o backend continua responsável pelo caminho físico controlado;
- ausência ou falha de transferência da foto bloqueia o envio;
- o formulário usa chave de idempotência para evitar OS duplicada em reenvios;
- nenhum cliente, equipamento ou arquivo é criado durante a edição do formulário;
- uma requisição inválida não chama o backend central pelo desktop;
- uma requisição inválida recebida diretamente pela API retorna HTTP 422 e não cria cliente, equipamento ou OS.

## Performance e escalabilidade

- no máximo quatro imagens e 2 MB por imagem;
- previews usam `blob:` local, sem upload antecipado e sem base64 no DOM;
- URLs temporárias são revogadas para evitar retenção de memória;
- o arquivo é transmitido uma única vez, no multipart final;
- fotos cadastrais não são duplicadas como anexos da OS, reduzindo armazenamento e catalogação redundante.

## Testes

### Backend

Arquivo: `backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php`

- criação diferida com foto;
- persistência das fotos junto com a OS;
- respeito ao índice da foto principal;
- rejeição de novo equipamento sem foto;
- verificação de que cliente, equipamento e OS não são criados na requisição inválida;
- conversão de orçamento avulso com cliente e equipamento diferidos.

Resultado em 24/07/2026: **25 testes aprovados, 120 asserções**.

### Desktop

Arquivo: `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`

- multipart normal de fotos da OS;
- encaminhamento de `novo_equipamento_fotos[]` somente no salvamento final;
- ausência da foto impede a chamada ao backend;
- presença das rotinas de normalização, preview e sincronização no JavaScript.

Resultado em 24/07/2026: **4 testes aprovados, 25 asserções**.

## Operação

1. Abra a Nova OS e selecione o orçamento avulso aprovado.
2. Cadastre o cliente eventual sem sair do formulário.
3. Cadastre o equipamento e selecione sua foto.
4. Confirme que a imagem aparece no card **Foto do equipamento**.
5. Na aba **Fotos**, confirme a seção **Fotos do novo equipamento** com o rótulo temporário.
6. Conclua os demais campos e salve a OS.
7. Verifique o novo equipamento e sua foto principal após o redirecionamento.

Cancelar, trocar o orçamento ou sair da Nova OS antes do salvamento descarta os dados e as fotos mantidos em memória.

## Arquivos principais

- `frontends/desktop/public/assets/js/orders-create.js`
- `frontends/desktop/resources/views/orders/_wizard.blade.php`
- `frontends/desktop/app/Http/Controllers/OrderController.php`
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`
- `backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php`
- `backend/openapi.yaml`
- `backend/tests/Feature/Api/V1/BudgetAvulsoFlowTest.php`

# Emissão automática de NFS-e pelo Ambiente Nacional (2026-09-09)

**Spec:** `specs/041-emissao-fiscal-nfse/spec.md`
**Tipo:** funcionalidade nova (MINOR) — v5.81.0.0

## Nota de correção

Este trabalho foi commitado dentro de `4be5141` ("Documentação da entrega do
Mapa da OS"), junto com uma entrega não relacionada de outra sessão — o
`git add` daquele commit pegou tudo que estava no diretório no momento do
deploy, e a mensagem descreve só a parte do Mapa da OS. O código já estava
testado e correto; faltava só o registro. Este documento e a entrada
correspondente no `CHANGELOG.md` (v5.81.0.0) existem para isso.

## O problema

O sistema já montava e assinava a DPS (Declaração de Prestação de Serviços)
corretamente — `DpsXmlBuilder`, validado contra o XSD oficial v1.01 — mas
**nada transmitia**. Para emitir uma NFS-e, o operador saía do ERP: abria o
Emissor Nacional, redigitava os dados da OS, emitia, baixava o XML e subia de
volta no sistema. O certificado A1 já estava instalado e válido; faltava uma
peça só.

## O que foi entregue

### Transmissão ao SEFIN Nacional

`AmbienteNacionalClient` fala com `https://sefin.nfse.gov.br/SefinNacional`
(produção) ou `https://sefin.producaorestrita.nfse.gov.br/SefinNacional`
(homologação) por **mTLS** — o certificado A1 na própria conexão TLS, não em
header. A DPS assinada vai `gzip` → `base64` no campo `dpsXmlGZipB64`; a
resposta traz a NFS-e pronta pelo caminho inverso, no campo `nfseXmlGZipB64`
— a emissão é síncrona.

Path e nomes de campo vivem em `config/fiscal.php` (`nfse.transmissao`), não
em constante: o Swagger oficial exige certificado para abrir, então a
conferência do contrato só acontece em homologação, com o certificado da
empresa na mão. Divergir é `.env`, não deploy.

**O POST de emissão não tem retry.** Repetir um POST que talvez tenha sido
processado emite a nota duas vezes — e nota fiscal duplicada não se desfaz
com nova tentativa, exige pedido de cancelamento. Só o GET de consulta repete
sozinho.

### O certificado em memória vira arquivo temporário

`CertificadoA1::pem()` devolve PEM em memória; Guzzle/cURL exigem caminho de
arquivo. `CertificadoPemTemporario::comOpcoesTls()` recebe um callback, grava
cert+cadeia+chave num arquivo único (`storage/framework/cache`, `0600`,
criado já com a permissão antes de escrever — não depois) e apaga no
`finally`, mesmo se o callback lançar exceção. É o único ponto do sistema
onde a chave privada toca o disco em texto claro.

### Proteção contra nota duplicada

O risco central desta integração não é falhar — é emitir duas notas para o
mesmo serviço. A proteção tem três partes, e as três precisam existir juntas:

1. O `nDPS` (número da DPS) é gravado no documento **antes** de transmitir —
   `SequenciaDps::proximo()`, com `lockForUpdate()` contra concorrência;
2. Uma retentativa **reusa** esse número, nunca aloca outro;
3. Antes de retransmitir, `EmissaoNfseService` **consulta** o ADN
   (`GET /dps/{idDps}`) perguntando se aquela DPS já virou nota. Se já
   existir, registra sem reenviar.

Sem (1) a retentativa não saberia o que consultar; sem (2) a consulta
olharia para o Id errado; sem (3) a consulta não aconteceria.

### Numeração do nDPS: nasce em zero, de propósito

`SequenciaDps` numera por série, numa tabela própria — e não
`MAX(numero_dps)+1` sobre `documentos_fiscais`, porque esse máximo só
enxerga o que o sistema já emitiu; quem emitiu pelo portal antes de ligar a
automação tem números que o banco nunca viu.

A migration semeia em **zero**, não no número real de nenhuma empresa: o ERP
é vendido, e cravar o contador de um cliente quebraria a próxima instalação.
`php artisan fiscal:sequencia-dps --serie=SERIE --definir=N` reposiciona o
contador para o último `nDPS` que o Emissor Nacional mostra — passo manual
obrigatório antes de ligar em produção numa empresa que já emitia pelo
portal.

### Tomador deixa de ser obrigatório

`DpsXmlBuilder` recusava montar a DPS sem CPF/CNPJ do tomador
(`RuntimeException`), tratando como obrigatório algo que o XSD oficial marca
`minOccurs="0"`. Balcão de assistência atende quem não quer se identificar,
e a nota tem de sair assim mesmo.

A prova não é só leitura de schema: `tests/Fixtures/nfse/
nfse-real-sem-tomador.xml` é uma NFS-e real, autorizada em produção pelo
Emissor Nacional (`cStat` 107), cujo `infDPS` vai direto de `</prest>` para
`<serv>` — sem bloco de tomador. Não se usa `cNaoNIF` (que existe para
estrangeiro sem NIF e exigiria `xNome` junto): o layout já oferece a
omissão limpa.

### Na tela

**Tela da nota** (`fiscal/nota.blade.php`): card "Emitir agora pelo sistema",
acima dos caminhos manuais (que continuam existindo como alternativa). Badge
de ambiente — amarelo "Homologação" com aviso de que a nota é de teste, ou
vermelho "Produção" com aviso de que gera documento fiscal de verdade. Botão
desabilitado com o motivo explícito quando o certificado não está pronto,
com link direto para Integrações.

O desktop é uma aplicação separada do backend e não lê `config/fiscal.php`
diretamente — por isso o endpoint de rascunho (`POST /orders/{id}/
documento-fiscal`) passou a devolver também `emissao: {ambiente,
disponivel, impedimento}`, uma chamada só, a mesma que já abria o rascunho.

**Encerramento da OS** (`orders/closure.blade.php`): o checkbox "Emitir
automaticamente com o certificado A1", que existia desabilitado com o texto
"ainda não disponível", ganhou `name` e passou a funcionar. **O encerramento
não depende da emissão**: falha na transmissão vira aviso, a OS fecha do
mesmo jeito e a nota fica pendente — mesma regra que já valia para o upload
de XML na baixa.

### Reuso, não duplicação

A resposta do ADN entra pela **mesma porta** que o XML baixado manualmente
do portal: `DocumentoFiscalService::registrarPorConteudoXml()` (extraído de
`registrarPorXml()`, que agora só lê o `UploadedFile` e delega) roda a
mesma conferência — assinatura, tomador, duplicidade — que o fluxo manual
sempre teve. Duas trilhas de registro divergiriam com o tempo, e a que
ninguém olha é a que aceita lixo.

## O que ficou de fora

- **Cancelamento pela API.** O evento de cancelamento
  (`POST /nfse/{chave}/eventos`, código 101101) não entrou nesta entrega —
  cancelar continua manual pelo portal.
- **NF-e/NFC-e de peça.** Peça é mercadoria, sai pela SEFAZ estadual, que é
  outro webservice — fora do escopo desta integração, que atende só NFS-e.
- **Correção do bug de exibição do CNPJ.** `CertificadoA1::documentoTitular()`
  extrai o CPF da razão social do MEI (formato `NOME:CPF`) em vez do CNPJ
  que vem depois dos dois-pontos — confirmado pelo XML real usado nesta
  entrega, mas é bug separado, pré-existente.

## Antes de ligar em produção

1. Abrir o Swagger de produção restrita com o certificado da empresa e
   conferir path/campos contra os defaults de `config/fiscal.php`
   (`nfse.transmissao`) — corrigir por `.env` se divergir;
2. `php artisan fiscal:sequencia-dps --serie=SERIE --definir=N` com o último
   `nDPS` real da série, lido no Emissor Nacional;
3. Testar em homologação: OS com documento, OS sem documento, rede caindo
   no meio do envio (confirmar que a consulta evita duplicar);
4. `FISCAL_NFSE_AMBIENTE=1` e conferir a primeira nota real no portal do
   governo antes de liberar para o time.

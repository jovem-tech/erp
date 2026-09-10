# Emissão automática de NFS-e pelo Ambiente Nacional (2026-09-09)

**Spec:** `specs/041-emissao-fiscal-nfse/spec.md`
**Tipo:** funcionalidade nova (MINOR) — v5.81.0.0, com correções em
v5.81.1.0 e feature de administração em v5.82.0.0.

## Duas notas de correção

**Primeira:** a build original foi commitada dentro de `4be5141`
("Documentação da entrega do Mapa da OS"), junto com uma entrega não
relacionada de outra sessão — o `git add` daquele commit pegou tudo que
estava no diretório no momento do deploy, e a mensagem descreve só a parte
do Mapa da OS. O código já estava testado e correto; faltava o registro.

**Segunda, mesma causa, outra origem:** as correções encontradas na
validação com nota real (seção abaixo) foram commitadas em `12684d0` por
`scripts/bash/deploy-completo.sh`, rodado por fora desta sessão enquanto o
trabalho ainda estava em andamento. O script reaproveitou a última entrada
do `CHANGELOG.md` — a da build original — como mensagem, então `12684d0`
tem 11 arquivos de correção real com uma mensagem que descreve a v5.81.0.0,
não o que o commit de fato contém. Este documento (atualizado) e a entrada
v5.81.1.0 do `CHANGELOG.md` existem para reconciliar isso.

O padrão que gerou os dois problemas é o mesmo: um processo automático
(`git add` amplo ou reaproveitamento de mensagem) commitando o estado do
diretório sem saber o que está commitando de fato. Nenhuma mudança de
processo foi feita aqui além de registrar — é o alerta que fica.

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
contador quando necessário.

**A série declara o tipo de emissor, não a empresa** — `00001`-`49999` é
aplicativo próprio (API), `70000`-`79999` é o Emissor Web do portal. Como a
numeração é por série, notas emitidas pelo portal **não** consomem números da
série usada pela API: não se semeia o contador da API com o último número
visto no portal. `EmissaoNfseService::conferirFaixaDaSerie()` recusa emitir
com série fora da faixa de aplicativo próprio, trocando a rejeição E0010 do
ADN (que só chega depois de queimar um número) por uma mensagem local.

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

## Validação com nota real — o que quebrou e por quê

A build original passava em 186 testes e nunca tinha tocado o Ambiente
Nacional de verdade. A primeira tentativa de emissão real (em homologação,
depois em produção) encontrou oito defeitos em cadeia — a maioria só visível
com o webservice real, porque nenhuma fixture do repositório vinha de uma
resposta de **transmissão** (as existentes eram XML baixado do portal).

Nesta ordem, cada um destravando o seguinte:

1. **Cadastro fiscal da empresa vazio.** `empresa_codigo_ibge` e
   `empresa_codigo_tributacao_nacional` não estavam preenchidos — só o CNPJ.
   `EmissaoNfseService::conferirCadastroDaEmpresa()` passou a validar isso
   **antes** de alocar o `nDPS`, porque a falha original acontecia depois:
   cada clique queimava um número da série só para descobrir, no meio do
   `DpsXmlBuilder`, que faltava um campo.

2. **`RuntimeException` do builder escapava como erro 500.** O builder já
   sinalizava cadastro incompleto, mas com uma exceção que não é
   `NfseException` — passava direto pelo `catch` do controller e virava
   "erro inesperado" na tela, sem dizer o quê faltava. `EmissaoNfseService`
   agora envolve a chamada ao builder e reembala qualquer `RuntimeException`
   como `NfseException::local()`.

3. **`Id` da DPS com município zerado, em silêncio.** `montarId()` usa
   `str_pad` no código IBGE; sem cadastro, isso produz `DPS0000000...` em vez
   de falhar. Um `Id` assim passa por toda a mecânica local (assina, loga,
   parece certo) e só é recusado pelo ADN, longe da causa. `idDps()` agora
   valida o código IBGE antes de montar.

4. **Série `70000` na faixa errada.** A série da DPS declara o **tipo de
   emissor**, não a empresa: `00001`-`49999` é aplicativo próprio (API),
   `70000`-`79999` é o Emissor Web (portal). O padrão original do sistema
   (`00001`) já estava certo; foi trocado por engano para `70000` ao ler um
   XML real da empresa como se a série fosse dela. Revertido, e
   `EmissaoNfseService::conferirFaixaDaSerie()` agora recusa antes de
   transmitir — trocando a rejeição **E0010** do ADN (que só chega depois de
   queimar um número) por uma mensagem local, com a faixa certa.

5. **`Codigo`/`Descricao` com inicial maiúscula, descartados.** O ADN
   devolve os erros de validação capitalizados; `AmbienteNacionalClient`
   procurava só a forma minúscula, e a mensagem útil ("série fora da faixa
   do tipo de emissor") virava um "HTTP 400" sem informação. Corrigido com
   `array_change_key_case()` no corpo da resposta inteiro, não só na lista
   de erros.

6. **Assinatura aninhada: dois defeitos pré-existentes em `AssinaturaXml`.**
   A NFS-e que o ADN devolve tem **duas assinaturas**: a do contribuinte
   (sobre a DPS enviada) e a do próprio ADN (sobre a NFS-e, que contém a DPS
   assinada dentro). Isso expôs dois bugs que nenhuma fixture do repositório
   exercitava — a única fixture assinada tinha a assinatura removida de
   propósito:

   - A transform `enveloped-signature` removia **todas** as assinaturas do
     documento antes de canonicalizar, quando o padrão manda remover **só a
     própria**. Como o ADN assinou incluindo a DPS já assinada, arrancá-la
     mudava os bytes canonicalizados e o digest não batia.
   - O clone feito por `importNode()` não é fiel para canonicalização: ele
     redistribui declarações de namespace pelos elementos filhos. Num XML
     real isso somou 232 bytes ao conteúdo canonicalizado. Trocado por
     clone via serialização (`saveXML()` + `loadXML()`), que preserva a
     estrutura de namespaces exatamente como veio.

   Os dois juntos faziam uma NFS-e **legítima e autorizada pelo governo**
   ser recusada com a mensagem mais grave que o verificador sabe dar: "o
   arquivo foi alterado depois de assinado". Isso não é bug só da emissão
   automática — o fluxo manual (importar XML baixado do portal, com
   `exigir_assinatura_xml` ligado) tinha o mesmo problema para qualquer XML
   de duas assinaturas. `tests/Fixtures/nfse/nfse-real-adn-assinada.xml` é a
   prova versionada: a resposta real do ADN para a nota emitida nesta
   validação, com as duas assinaturas intactas.

7. **Consulta em dois passos, não um.** O `AmbienteNacionalClient` original
   assumia que `GET /dps/{idDps}` devolvia o XML da nota. O contrato real é
   outro: esse endpoint só confirma que a DPS virou nota e devolve a
   **chave de acesso**; o XML vem de um segundo `GET /nfse/{chave}`. Como
   consequência prática, a consulta prévia contra duplicidade (item da seção
   anterior) **nunca funcionava de verdade** — ela sempre "falhava" por
   formato inesperado, e o sistema seguia para o envio. Corrigido com
   `consultarPorIdDps()` encadeando os dois passos.

8. **"DPS já existe" (E0014) tratado como rejeição, em vez de recuperação.**
   Consequência direta do item 7: a consulta prévia não encontrava a nota
   (porque não sabia buscar a chave), a DPS era reenviada, e o ADN recusava
   com `E0014` — "conjunto de série, número, código do município e CNPJ já
   existe em uma NFS-e gerada anteriormente". Isso **não é** erro de regra
   de negócio no sentido de precisar correção do operador: é o ADN dizendo
   que o documento existe do lado dele. Marcar como rejeitado registraria o
   oposto da verdade. `EmissaoNfseService::ehDpsJaEmitida()` reconhece o
   E0014 (por código e por texto, porque o catálogo de rejeições muda entre
   versões) e dispara nova consulta — agora corrigida — para recuperar e
   registrar a nota em vez de desistir.

   Um nono ponto, adjacente: enquanto o item 8 não estava corrigido, uma
   nota chegou a ser **autorizada pelo ADN e não registrada localmente**
   (falha de validação no registro), e isso acontecia **em silêncio** —
   `ValidationException` não é logada pelo Laravel, e entre "nota
   autorizada" e o erro na tela não havia linha nenhuma no log. É o pior
   estado que esta integração pode produzir. `EmissaoNfseService::registrar()`
   agora salva o XML em `storage/app/private/fiscal/recuperacao/` antes de
   deixar a exceção subir, grita no canal `fiscal`, e a mensagem ao operador
   é explícita: *"A nota FOI EMITIDA... Não emita de novo"*.

Todos os oito têm teste de regressão. Vários testes anteriores fingiam
contratos que se provaram errados (XML saindo direto de `/dps/`, série
copiada do portal) — foram corrigidos contra o comportamento real, não só
complementados.

### Prova em produção

Depois das correções, uma nota real foi emitida nesta máquina
(192.168.1.100) para validar o caminho de ponta a ponta com o Ambiente
Nacional de produção — OS de serviço de R$ 80,00, tomador **sem CPF/CNPJ**
(o caso que motivou a integração), cancelada no portal do governo logo após
a confirmação, com justificativa de implementação em sistema.

| Campo | Valor |
|---|---|
| NFS-e | nº 6, série `00001`, nDPS `3` |
| Situação | `107` (autorizada) |
| Assinatura conferida | `true` |
| Tomador | sem documento — bloco `<toma>` omitido |
| Valor OS × XML | `80,00` × `80,00` |

Confirma, com o webservice real: emissão sem tomador aceita, assinatura
aninhada conferida, e nenhuma duplicidade (o teste anterior, ainda com o
item 8 quebrado, tinha gerado exatamente o E0014 que a correção resolve).

## Ambiente de emissão administrável pela tela (v5.82.0.0)

Ligar produção dependia só do `.env` (`FISCAL_NFSE_AMBIENTE`) — terminal.
Isso contradizia o resto do módulo: o sistema é vendido, e o próprio
`config/fiscal.php` já registra que trocar certificado tem de ser pela tela.
Trocar de ambiente é decisão ainda mais operacional, e de consequência
maior — é a diferença entre testar e emitir documento com obrigação
tributária real.

`AmbienteFiscal` centraliza a decisão, com o mesmo padrão de precedência que
`CertificadoA1::senha()` já usa: valor da tabela `configuracoes` vence o
`.env`, que segue valendo como padrão de instalação (nasce em homologação).
`AmbienteNacionalClient`, `DpsXmlBuilder` e `EmissaoNfseService` passaram a
ler o ambiente daqui, não de `config()` direto.

Três guardas, porque a chave é a de maior consequência do módulo fiscal:

- **Ligar produção é recusado** se o certificado estiver inválido ou faltar
  algum dos campos fiscais obrigatórios da empresa — a mesma checagem de
  `EmissaoNfseService::conferirCadastroDaEmpresa()`, reaproveitada. Ligar
  para descobrir na primeira nota que falta o código IBGE troca um erro
  barato por um caro.
- **Permissão própria**, `fiscal:administrar` (nova, semeada espelhando
  `fiscal:excluir` — o poder fiscal mais pesado do catálogo até aqui), e não
  reuso de `fiscal:criar` ou `configuracoes:editar`: emitir gera *um*
  documento; virar a chave faz *toda* emissão seguinte valer de verdade,
  para todo mundo.
- **Rastro obrigatório**: toda troca grava usuário, IP e horário na tabela
  `logs` (que passou a existir também no schema de teste,
  `BuildsLegacyErpSchema`, para a auditoria ser verificável) e no canal
  `fiscal`.

Na tela (Configurações → Integrações → Certificado A1, mesma sub-aba do
certificado — as duas coisas juntas é o que decide se uma nota vale):
badge vermelho "Produção" ou amarelo "Homologação", com o significado
escrito por extenso. Ligar produção exige **digitar a palavra `PRODUCAO`**
num campo de confirmação — não decorativo: um clique herdado de outra aba
não pode armar emissão fiscal real. Voltar para homologação não pede
confirmação nenhuma — o caminho de reduzir risco tem de ser o mais fácil
dos dois.

## O que ficou de fora

- **Cancelamento pela API.** O evento de cancelamento
  (`POST /nfse/{chave}/eventos`, código 101101) não entrou nesta entrega —
  cancelar continua manual pelo portal. A nota emitida na validação real foi
  cancelada assim.
- **NF-e/NFC-e de peça.** Peça é mercadoria, sai pela SEFAZ estadual, que é
  outro webservice — fora do escopo desta integração, que atende só NFS-e.
- **Correção do bug de exibição do CNPJ.** `CertificadoA1::documentoTitular()`
  extrai o CPF da razão social do MEI (formato `NOME:CPF`) em vez do CNPJ
  que vem depois dos dois-pontos — confirmado pelo XML real usado nesta
  entrega, mas é bug separado, pré-existente. Ainda não corrigido.
- **`backend/openapi.yaml`.** Só o Anexo X fiscal está documentado lá; o
  módulo de documento fiscal (rascunho, emissão manual, importação de XML,
  envio, cancelamento e agora emissão automática + ambiente) não está —
  gap anterior a esta entrega, não coberto aqui.
- **Ambiente separado por série na sequência de nDPS.** Homologação e
  produção compartilham o mesmo contador de `fiscal_sequencias` por série.
  Não causa colisão (o Ambiente Nacional de produção nunca viu DPS
  transmitida em homologação), só deixa lacunas na numeração — inofensivo,
  mas vale separar depois.

## Antes de ligar em produção

1. Abrir o Swagger de produção restrita com o certificado da empresa e
   conferir path/campos contra os defaults de `config/fiscal.php`
   (`nfse.transmissao`) — corrigir por `.env` se divergir;
2. Conferir `FISCAL_NFSE_SERIE` — tem de estar na faixa `00001`-`49999`
   (aplicativo próprio). **Não** copiar a série de notas emitidas pelo portal;
3. `php artisan fiscal:sequencia-dps` só se a empresa já tiver emitido **por
   API** nessa mesma série antes — emissões do portal não contam;
4. Testar em homologação: OS com documento, OS sem documento, rede caindo
   no meio do envio (confirmar que a consulta evita duplicar sem reenviar —
   ver item 7/8 da seção de validação);
5. Ligar produção **pela tela** (Configurações → Integrações →
   Certificado A1), não pelo `.env`: exige `fiscal:administrar` e digitar
   `PRODUCAO` para confirmar. A tela já recusa se certificado ou cadastro
   fiscal não estiverem prontos;
6. Conferir a primeira nota real no portal do governo antes de liberar para
   o time — foi o que validou esta entrega.

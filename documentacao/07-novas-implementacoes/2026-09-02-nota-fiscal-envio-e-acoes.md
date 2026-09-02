# Nota fiscal: envio ao cliente, nova nota e "Mais ações" (2026-09-02)

**Spec:** `specs/041-emissao-fiscal-nfse/spec.md`
**Tipo:** funcionalidade nova (MINOR) — v5.71.0.0

## O problema

Depois de registrar a emissão, a tela da nota acabava. O que faltava era o que
mais se faz com uma nota:

- **Mandar para o cliente.** Não havia caminho nenhum. O operador baixava o PDF,
  abria o WhatsApp Web ou o e-mail e anexava à mão — e mandava só o PDF. O XML,
  que é o documento fiscal de que o contador do cliente precisa, ficava para trás
  e virava pedido depois.
- **Emitir outra nota depois de cancelar.** Cancelar devolvia a OS para a fila de
  pendentes, mas abrir a tela continuava mostrando a nota cancelada, sem caminho
  para a substituta. O fluxo terminava num beco.
- **Ações que não existiam ou estavam escondidas.** Copiar os 50 dígitos da chave
  só dava selecionando com o mouse; consultar a nota no portal nacional exigia
  achar o endereço; imprimir era baixar e abrir à mão.

## O que foi entregue

### Envio ao cliente, na coluna da esquerda

Card **"Enviar a nota ao cliente"**, logo abaixo dos dados da nota — é o passo
seguinte natural de quem acabou de registrar a emissão.

**O DANFSe vai na frente; o XML só acompanha nota de tomador com CNPJ.**

A ordem é a do leitor, não a da importância fiscal: o DANFSe é o que a pessoa
abre e entende, o XML é o que ela repassa ao contador. E pessoa física não tem
contador — para ela o XML seria um anexo que não abre no celular e não serve
para nada, no meio da nota que ela queria ver. A regra olha o tomador **gravado
na nota**, não o cadastro do cliente, que pode ter mudado depois da emissão.

No WhatsApp **cada anexo leva a própria legenda**: a mensagem principal vai no
DANFSe e o XML leva uma linha dizendo para que serve. No e-mail os dois chegam
juntos, então a explicação vai no corpo — e só cita o XML quando ele foi mesmo
anexado.

- **Canal:** WhatsApp ou e-mail, com o WhatsApp pré-selecionado quando há
  telefone no cadastro — mesma preferência do Centro de Documentos da OS.
- **Destino preenchido com o contato do cadastro**, mas em campo livre: cliente
  que trocou de e-mail não pode travar o envio até alguém lembrar de atualizar o
  cadastro. Trocar o canal troca o destino sugerido — **exceto** se o operador já
  digitou algo, caso em que o que ele escreveu manda.
- **Mensagem opcional**, com um padrão que explica o que é cada anexo.

O destino é conferido **no servidor**, não só na tela: é digitando que a nota do
cliente vai para o endereço errado.

### Nota nova depois de cancelar

Botão **"Emitir nova nota"**, que só aparece quando a nota está cancelada.

`rascunhoDeOrdem()` continua idempotente — é ela que impede duas notas para o
mesmo serviço. A porta nova é separada de propósito: abrir documento novo tem de
ser **ato explícito** do operador, e não efeito de abrir a tela. Com nota emitida
e válida a API recusa (422): o caminho é cancelar primeiro.

### "Mais ações"

Menu no cabeçalho, só para nota emitida: baixar DANFSe, baixar XML, imprimir
DANFSe, copiar chave de acesso e consultar no portal nacional.

Dois detalhes que decidem se a coisa funciona na oficina:

- **Copiar** não usa só `navigator.clipboard`: o desktop roda por `http` na rede
  local, onde essa API não existe. Há fallback por `execCommand`.
- **Imprimir** usa o próprio iframe do DANFSe quando ele já está na tela, em vez
  de abrir uma janela que o bloqueador de pop-up derruba.

A **consulta no portal usa o mesmo endereço do QR Code** do DANFSe (NT-008, item
2.4.3). Dois endereços diferentes para a mesma nota significaria um deles errado.

## Decisões

**Não reusa o `OrderDocumentCenterService`.** Aquele serviço opera sobre
`OrderDocument` — documentos que o próprio sistema gera a partir de modelos
editáveis. A nota fiscal não é um deles: é um `DocumentoFiscal`, com XML assinado
pelo Ambiente Nacional e guarda legal de cinco anos. Transformá-la num documento
daquele catálogo a exporia ao motor de modelos, que é justamente o que o DANFSe
não pode ter. O que se reusa são os despachadores de canal, que são os mesmos
(`IntegrationSettingsService::sendDirectMedia` e `Mail`).

**O envio é registrado na timeline da OS (`os_eventos`), não numa tabela nova.**
Mandar documento fiscal ao cliente é ato de que se presta contas, e a timeline já
é onde se procura "o que aconteceu nesta OS". O destino vai **mascarado** também
no registro: a timeline é lida por quem não precisa do contato completo.

**O DANFSe anexado é o do portal quando existe**; sem ele, é gerado na hora pela
NT-008, num arquivo temporário que some depois do envio, dando certo ou errado.

## Arquivos

| Arquivo | Papel |
|---|---|
| `backend/app/Services/Fiscal/NotaFiscalEnvioService.php` | Envio por e-mail/WhatsApp, anexos e registro |
| `backend/app/Services/Fiscal/DocumentoFiscalService.php` | `novoRascunhoAposCancelamento()` |
| `backend/app/Http/Controllers/Api/V1/DocumentoFiscalController.php` | `enviar()`, `novoDocumento()`, contatos no payload |
| `backend/routes/api.php` | `POST .../envio` e `POST .../documento-fiscal/novo` |
| `frontends/desktop/resources/views/fiscal/nota.blade.php` | Card de envio, "Mais ações", botão de nota nova |
| `frontends/desktop/app/Http/Controllers/DocumentoFiscalController.php` | Ações da tela |
| `frontends/desktop/app/Services/DocumentoFiscalService.php` | Chamadas à API |
| `frontends/desktop/routes/web.php` | Rotas da tela |

## Verificação

**182 testes verdes** no backend (grupos `Fiscal|Pdf|Danfse|Documento`) e **37**
no desktop. Entre os novos:

- nota nova é aberta depois de cancelar, e **recusada** com nota válida;
- envio por e-mail com destino digitado, com o registro aparecendo em
  `os_eventos` e o destino mascarado na resposta;
- destino inválido recusado nos dois canais;
- rascunho não é enviado;
- a tela abre com o contato do cadastro preenchido e o canal certo marcado, e
  explica quando o cliente não tem contato nenhum;
- "Emitir nova nota" aparece na nota cancelada e **não** aparece na válida.

## Correção de estreia: "Bad Request" na tela

O primeiro envio real por WhatsApp falhou com **"Bad Request"** — uma frase que
não diz o que fazer. O e-mail, no mesmo documento, saiu normalmente.

Diagnosticando contra a Evolution API: o payload estava certo. O 400 vinha da
checagem de número, e o telefone do cadastro (`(22) 99274-1004`) **não tem
WhatsApp** — confirmado consultando `/chat/whatsappNumbers`, com e sem o nono
dígito, `exists: false` nos dois. Não era defeito de código; era dado.

O defeito era **não dizer isso**. A Evolution devolve

```json
{"status":400,"error":"Bad Request",
 "response":{"message":[{"jid":"...","exists":false,"number":"5522992741004"}]}}
```

e o `IntegrationSettingsService` lia o `error` do topo — que é só o nome do
status. `extractErrorMessage()` passou a ler primeiro o `response.message`, onde
está o motivo, e a traduzir as três formas em que ele chega: string, lista de
erros de validação, e lista da checagem de número. Esta última vira
**"O número (22) 99274-1004 não tem WhatsApp."**, com o telefone no formato em
que o operador o reconhece.

A correção é no serviço compartilhado: vale para todo envio por Evolution do
sistema, não só o da nota. Coberta por `EvolutionErrorMessageTest`.

## Segunda correção: a arrumação do envio

O primeiro envio real por WhatsApp saiu com o **XML na frente carregando a
mensagem** e o DANFSe por último e mudo. Do lado do cliente, o primeiro anexo era
um arquivo que não abre no celular, e a pré-visualização da conversa mostrava
`danfse-3.pdf` sem contexto nenhum.

O que mudou:

- **DANFSe primeiro**, com a mensagem principal; XML depois, com legenda própria;
- **XML só para tomador com CNPJ** — ver acima;
- **mensagem padrão mais curta**, agora que cada anexo se explica: ela só diz de
  que nota se trata, com o número da OS quando existe;
- **e identifica o aparelho**: tipo, marca, modelo e número de série, cada parte
  só se existir. Quem deixa três equipamentos na assistência recebe três notas
  parecidas, e o número da OS sozinho não diz qual é qual — sem isso o cliente
  teria de abrir o anexo para descobrir. IMEI entra como reserva quando não há
  número de série, e rotulado como IMEI: chamá-lo de "número de série" confunde
  quem vai conferir contra o aparelho.

Coberto por dois testes que verificam as requisições que saem de fato: a ordem e
as legendas no caso CNPJ, e o anexo único no caso CPF.

## O que ficou de fora

- **Nota substituta de uma nota válida** (com `chSubstda`): a substituição no
  padrão nacional tem evento próprio, e a decisão desta entrega foi cobrir o caso
  que travava — emitir depois de cancelar.
- **`backend/openapi.yaml`**: o módulo fiscal ainda não está descrito lá; estes
  dois endpoints entram junto quando o módulo for documentado.

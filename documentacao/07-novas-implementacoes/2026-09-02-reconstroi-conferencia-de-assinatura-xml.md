# Verificação de assinatura do XML reconstruída (2026-09-02)

**Spec:** `specs/041-emissao-fiscal-nfse/spec.md`
**Tipo:** correção de bug / segurança (PATCH)

## O que se perdeu, e como foi achado

Investigando o achado anterior desta mesma entrega (XML de outro cliente
anexado a uma OS), ficou constatado que `NfseXmlImporter` **não confere
assinatura digital nenhuma** — aceita qualquer XML bem-formado, com o CNPJ do
prestador certo, como se fosse emitido pelo Ambiente Nacional.

Isso é regressão, não lacuna original: mais cedo neste mesmo dia, outra sessão
implementou essa conferência (`conferirAssinatura()`, `conferirChave()`,
DOCTYPE/XXE em `carregar()`) — e o próprio docblock dela dizia por quê: *"até
aqui um XML montado à mão, com o CNPJ do prestador e o CPF do tomador certos,
era aceito como nota emitida e virava a prova guardada por cinco anos"*. Esse
trabalho nunca foi commitado, e um `git reset --hard HEAD` rodado por outra
sessão no meio do dia reverteu `NfseXmlImporter.php` para antes dele — junto
com o teste que o cobria (`NfseXmlImporterTest.php` também reverteu, o que fez
`--filter=NfseXmlImporterTest` continuar "passando" sem mais provar nada sobre
assinatura). Ver `[[sistema-erp-sessoes-claude-concorrentes]]`.

## O que sobreviveu, e o que precisou ser refeito

`AssinaturaXml` — a classe que faz a conta de verdade (canonicaliza,
confere digest, confere RSA contra o certificado X.509 embutido) —
**sobreviveu intacta**: já fazia parte do código commitado antes de hoje,
usada por `DpsXmlBuilder` para ASSINAR a DPS. O reset não a atingiu porque
ela nunca deixou de estar na árvore commitada.

O que precisou ser refeito foi só a **ligação**: `NfseXmlImporter` voltou a
chamar `AssinaturaXml::conferir()` sobre os bytes originais do arquivo (não os
normalizados — ver nota abaixo), respeitando `fiscal.nfse.exigir_assinatura_xml`
(ligado por padrão: sem assinatura conferida, o arquivo não vira registro).

Também foram refeitos, no mesmo pacote porque fazem parte da mesma defesa:

- **DOCTYPE/XXE** em `carregar()`: DOCTYPE é recusado antes de chegar ao
  parser, `LIBXML_NONET` corta busca de recurso externo.
- **Tamanho máximo** (10 MB): um arquivo de megabytes não é NFS-e.
- **Chave com 50 dígitos** (NT-008, item 2.1.1): checagem de formato, não de
  conteúdo — ver nota abaixo sobre o que ficou de fora.

## Por que a assinatura é conferida sobre os bytes ORIGINAIS

A assinatura foi feita sobre os bytes que o portal entregou. O importador
normaliza a acentuação duplamente codificada de um defeito conhecido do
portal (`SÃ£o Pedro` → `São Pedro`) — e normalizar ANTES de conferir invalidaria
a assinatura de uma nota legítima que tivesse esse defeito. Por isso
`conferirAssinatura()` recebe os bytes originais (`$original`), e só a
extração de dados usa o texto normalizado.

## O que ficou de fora, deliberadamente

A chave de acesso tem uma estrutura interna documentada (município, CNPJ,
data, sequencial...), e a outra sessão parece ter implementado uma checagem
"a chave confere com o número da nota" que decodifica essa estrutura. Esta
reconstrução **não tentou reproduzir isso**: decodificar a chave errado e
recusar uma nota real é pior do que não ter a checagem, e uma tentativa de
engenharia reversa a partir de um único exemplo (a fixture) não é confiança
suficiente para um documento fiscal. Fica só a checagem de formato (50
dígitos numéricos), que é fato direto da NT-008.

## Verificação

`NfseXmlImporterTest` reconstruído com prova **criptográfica real**, não
simulada: os testes assinam a fixture em tempo de execução com
`DpsXmlBuilder::assinar()` (mesmo assinador de produção, sobre um certificado
autoassinado gerado na hora) e conferem com `AssinaturaXml::conferir()` — se
os dois divergissem (o defeito clássico de declarar um algoritmo e executar
outro), o teste cairia. Cobre: recusa sem assinatura, aceita sem assinatura
com a trava desligada, confere assinatura válida, recusa XML adulterado depois
de assinado, recusa chave fora do tamanho, recusa DOCTYPE.

`DocumentoFiscalTest` (que testa o FLUXO, não a conferência em si) desliga a
exigência no `setUp()` — a fixture dele teve a assinatura removida antes de
ser versionada, e exigi-la ali só obrigaria assinar em toda importação sem
testar nada a mais.

**284 testes** na suíte rápida do backend, **39** no desktop.

## Achado à parte: múltiplos agentes commitando no mesmo `develop`

Durante esta investigação ficou claro que, além de sessões concorrentes do
Claude Code, há um processo autônomo identificado como **"Codex"** também
commitando (e aparentemente fazendo push) direto em `develop` — inclusive
varrendo mudanças de OUTRAS sessões junto das próprias, usando a última
descrição do `CHANGELOG.md` como mensagem de commit. Foi assim que
`AssinaturaXml.php` (novo, nunca commitado por quem o escreveu) sobreviveu ao
`git reset --hard` como arquivo não rastreado e depois acabou entrando num
commit posterior, sob outra mensagem, sem relação direta com o que descrevia.
Registrado em `[[sistema-erp-sessoes-claude-concorrentes]]` para quem for
investigar histórico de commits confuso no futuro.

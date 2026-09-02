# "Guardar arquivos do portal" aceitava XML de outro cliente (2026-09-02)

**Spec:** `specs/041-emissao-fiscal-nfse/spec.md`
**Tipo:** correção de bug (PATCH)

## O sintoma

Uma OS de "Otavio Conceição" (CPF) ficou marcada com o XML **de outra empresa**
anexado — chave e tomador reais pertenciam a "ABRIGO DO MARINHEIRO" (CNPJ), um
cliente completamente diferente. A listagem mostrava o badge verde "XML" como
se o documento provasse a nota, quando na verdade não tinha relação nenhuma com
o serviço prestado a este cliente.

## A causa

O sistema tem **dois caminhos** para um XML virar arquivo de um
`documentos_fiscais`:

1. **"Importar o XML da nota"** (antes de emitir) → `registrarPorXml()` → lê o
   XML com `NfseXmlImporter`, chama `conferirTomador()` — se o CPF/CNPJ do
   tomador do XML não bate com o da OS, recusa.
2. **"Guardar arquivos do portal"** (depois de emitir, usado quando a emissão
   foi registrada à mão) → `anexarArquivo()` → só verificava que o documento
   já estava emitido, gravava os bytes e calculava o hash. **Não lia o
   conteúdo — não conferia tomador, não conferia chave, não conferia se o
   arquivo era sequer uma NFS-e.**

A assimetria é o defeito: o segundo caminho existe precisamente para o caso em
que o operador registrou o número à mão e só depois volta para anexar o
arquivo que baixou do portal — e é exatamente aí que colar/soltar o arquivo da
aba errada (duas OS abertas, dois clientes com nome parecido) passa batido.

## A correção

`anexarArquivo()`, quando `formato === 'xml'`, agora lê o conteúdo com o mesmo
`NfseXmlImporter` e aplica duas conferências, na ordem do que é mais preciso:

1. **Chave**: se o documento já tem uma chave registrada (da emissão manual ou
   de uma importação anterior), o XML anexado tem de ser dela. É a conferência
   mais forte — pega até "XML certo, mas da nota errada do MESMO cliente", que
   uma checagem só de tomador deixaria passar.
2. **Tomador**: reusa `conferirTomador()`, a mesma regra que
   `registrarPorXml()` já aplicava.

Falha ao **ler** o arquivo (não é XML, não é NFS-e) também rejeita.

## Verificação

- Testes novos provam a rejeição nos dois casos (cliente errado, chave
  diferente) e confirmam que o caminho legítimo (chave e tomador batendo)
  continua funcionando.
- **Rodado contra o incidente real**: o XML de fato anexado ao documento
  quebrado (mesma chave da fixture `nfse-real-mei.xml`, tomador
  "ABRIGO DO MARINHEIRO" CNPJ 72.063.654/0013-09) foi conferido contra a
  correção — rejeitado com "O tomador desta nota (72.063.654/0013-09) não é o
  cliente desta OS (126.845.190-80)."
- **278 testes** na suíte rápida do backend, **39** no desktop.

## Achado à parte, registrado aqui por transparência

Investigando esta correção, foi constatado que a **verificação de assinatura
digital** do XML (`NfseXmlImporter::conferirAssinatura()`/`conferirChave()`),
implementada mais cedo neste mesmo dia por outra sessão, **não está mais
presente no código** — foi perdida durante a recuperação de um
`git reset --hard` que outra sessão rodou no meio do trabalho (ver
`[[sistema-erp-sessoes-claude-concorrentes]]`). A recuperação restaurou o
trabalho desta sessão (DANFSe/NT-008), mas não havia cópia salva do trabalho de
assinatura da outra sessão para restaurar junto.

Isso é **diferente e mais sério** do que o defeito corrigido aqui: sem a
verificação de assinatura, o importador aceita qualquer XML bem-formado com o
CNPJ certo — inclusive um montado à mão — como se fosse emitido pelo Ambiente
Nacional. Fica registrado para decisão do dono do sistema sobre reconstruir ou
não essa verificação.

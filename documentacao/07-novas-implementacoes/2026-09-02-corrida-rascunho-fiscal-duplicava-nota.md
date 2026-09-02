# Corrida em `rascunhoDeOrdem()` deixava uma OS acumular nota fiscal duplicada (2026-09-02)

**Spec:** `specs/041-emissao-fiscal-nfse/spec.md`
**Tipo:** correção de bug (PATCH — dado de produção, não muda contrato de API)

## O sintoma

A listagem `/fiscal/notas` mostrava, para a mesma OS (OS26090002), **duas notas
"Emitida"**: uma com XML e DANFSe guardados, outra com número "001" e nenhum
arquivo. A pergunta que trouxe isto à tona foi exatamente a certa: como pode
existir nota emitida sem XML?

Investigando o banco, a OS tinha **três** `documentos_fiscais`, não dois:

| id | status | número | XML | criado em | emitido em |
|---|---|---|---|---|---|
| 3 | emitido | 001 | não | 16:19:39 | 16:20:22 |
| 4 | emitido | 3 | sim | 16:20:22 | 23:52:09 |
| 9 | rascunho | — | não | 00:18:21 | — |

Uma segunda OS (OS26070032) tinha o mesmo padrão em miniatura: um documento
emitido (id 5) e um rascunho órfão (id 6), criado **um segundo** depois da
emissão do primeiro.

## A causa

`DocumentoFiscalService::rascunhoDeOrdem()` — o método que toda visita à tela
"Emitir nota" chama para buscar-ou-criar o documento da OS — fazia **duas
consultas separadas, sem transação nem lock**:

1. "Já existe documento emitido ou cancelado?"
2. "Já existe rascunho ou rejeitado?"

Se um `registrarEmissao()` concorrente (de outra aba, de um clique duplo, ou —
no caso real — da própria baixa da OS, que chama `rascunhoDeOrdem()` →
`importarXml()` → redireciona para a tela, que chama `rascunhoDeOrdem()` de
novo) comitava a transição rascunho→emitido **entre** as duas consultas, a
linha "sumia" das duas buscas ao mesmo tempo — não era mais rascunho, ainda não
tinha sido visto como emitida — e o método criava um documento **novo**. A
janela é estreita, mas a baixa com XML embutido é o caminho que mais a abre: três
chamadas ao mesmo método, para a mesma OS, em rápida sucessão, dentro de uma
única operação de encerramento.

`registrarEmissao()` já protegia sua própria checagem de duplicidade com
`DB::transaction()` + `lockForUpdate()` — o padrão certo já existia no código,
só não tinha chegado a `rascunhoDeOrdem()`.

## A correção

`rascunhoDeOrdem()` e `novoRascunhoAposCancelamento()` passam a rodar dentro de:

- **`Cache::lock('fiscal:rascunho-os:'.$order->id, 10)`** (mesmo padrão de
  `FileManagerFacade::store()`) — serializa chamadas concorrentes ao *mesmo
  método*, fechando a corrida de "OS sem documento nenhum, duas chamadas
  simultâneas criam duas".
- **uma única consulta `lockForUpdate()`** (não mais duas) dentro de
  `DB::transaction()` — fecha a corrida contra `registrarEmissao()`/`cancelar()`:
  um `UPDATE` toma lock de linha mesmo sem pedir explicitamente, então o
  `SELECT ... FOR UPDATE` espera o commit antes de ler.

`novoRascunhoAposCancelamento()`, ao constatar que nunca houve documento,
chama um novo método privado `criarRascunho()` — e não `rascunhoDeOrdem()` —
porque chamar, de dentro da trava já tomada, o método que tenta tomar a *mesma*
trava travaria a própria requisição até o timeout de 5 segundos.

## Verificação

- **Prova contra MySQL de verdade** (`FiscalRascunhoConcorrenciaMysqlTest`,
  grupo `mysql`): abre duas conexões PDO, uma segura um `UPDATE` não comitado
  na mesma linha que a outra tenta ler com `SELECT ... FOR UPDATE` — com
  `innodb_lock_wait_timeout` curto, prova que a segunda **espera e estoura**
  em vez de "passar direto". É a mesma técnica que
  `InterLiquidacaoConcorrenciaMysqlTest` já usava para o problema análogo do
  Pix, e roda contra a base de desenvolvimento tocando só linhas com prefixo
  próprio, limpas no `tearDown` mesmo quando o teste falha.
- Confirmado, por `DB::listen`, que o SQL que o Eloquent gera é literalmente o
  mesmo testado: `select * from documentos_fiscais where os_id = ? and tipo = ?
  order by id desc for update`.
- Teste novo prova que o ramo "nunca houve documento" não trava na própria
  trava (`test_novo_documento_em_os_sem_documento_nenhum_nao_trava`).
- **276 testes** na suíte rápida do backend, **39** no desktop.

## O que não foi mexido nesta entrega

Os três documentos órfãos/duplicados que já existem no banco de desenvolvimento
(ids 3, 6 e 9) **não foram apagados nem alterados** — são registros fiscais, e
essa é decisão do dono do sistema, não do código. Ver conversa da entrega para
o que foi decidido.

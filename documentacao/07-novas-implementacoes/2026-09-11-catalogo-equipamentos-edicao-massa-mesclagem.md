# Catálogo de equipamentos: edição em massa por ID e mesclagem de duplicados (2026-09-11)

**Spec:** `specs/045-catalogo-equipamentos-edicao-massa-mesclagem/spec.md`
**Base:** `specs/044-catalogo-equipamentos-csv/spec.md`
**Tipo:** funcionalidade nova (MINOR)

## O problema

A entrega anterior (044) resolveu "adicionar/atualizar modelos por nome" —
útil para subir a lista de modelos de um fabricante, mas inútil para o
problema seguinte: o catálogo tem 1.964 modelos já cadastrados, muitos
incompletos ou ambíguos, e alguns são o **mesmo aparelho duplicado** sob
nomes diferentes — `Samsung - J7`, `Samsung - j 7`, `Samsung - SM-J730G`.
Casar por nome não ajuda a corrigir isso: era preciso exportar com o
identificador de cada registro, corrigir na planilha e reimportar apontando
explicitamente qual registro é qual — e, para os duplicados, decidir qual
fica e mover para ele tudo que já apontava para os outros.

## O que foi entregue

O mesmo CSV de sempre (mesmo "Exportar CSV" / "Importar em lote" da 044)
ganhou duas colunas opcionais — arquivos sem elas continuam funcionando
exatamente como antes:

**`id`** — o id do modelo. Preenchido, a linha deixa de casar por nome e
passa a mirar aquele registro exato: o nome pode virar qualquer coisa
(mesmo sem nenhuma palavra em comum com o antigo) que o sistema renomeia o
registro certo. É a peça que faltava para revisar os 1.964 modelos numa
planilha — inclusive com apoio de uma IA externa para sugerir nomes
completos e sem ambiguidade — e aplicar tudo de volta de uma vez.

**`mesclar_com_id`** — funde o modelo da linha no id informado. Precisa ser
da mesma marca e estar ativo. Ao reimportar, todo aparelho já cadastrado
com o modelo perdedor passa a apontar para o vencedor, a relação de
catálogo do perdedor é migrada (ou descartada, se o vencedor já cobria o
mesmo tipo), e o perdedor é desativado — nunca excluído, porque 3.618
aparelhos apontam para o catálogo por FK. O resto da linha (nome, tipo,
ativo) é ignorado numa mesclagem: ela só diz "isto vira aquilo".

Duas proteções: mesclar em cadeia no mesmo arquivo (A funde em B, e B
também funde em C) é rejeitado — resolve-se em duas importações — e um
`id` repetido no arquivo com dados diferentes gera erro em vez de deixar a
última linha decidir silenciosamente o resultado.

### O detalhe que evitava um bug de produção: a unique key da relação

`equipamentos_catalogo_relacoes` tem uma unique key real em
`(tipo_id, marca_id, modelo_id)`. Repontar a relação do perdedor para o
vencedor com um `UPDATE` direto quebraria sempre que o vencedor já tivesse
essa mesma relação (o caso comum — os dois "J7" e o "SM-J730G" certamente
já estavam vinculados ao mesmo tipo Smartphone). A mesclagem reusa
`ensureCatalogRelationRecord()` — o mesmo método select-then-upsert que a
044 já usava para o quick-add — em vez de um UPDATE cru, e só então apaga a
linha original do perdedor.

### Busca de OS não fica desatualizada

`os.busca_texto` é um snapshot, não um join ao vivo — nada mais no sistema
tem esse problema (listagem de OS, orçamento, dashboard leem tipo/marca/
modelo ao vivo), mas a busca textual só é reindexada em `Order/Client/
Equipment saved`, e não existe (nem existia antes desta entrega) um
observer para uma mudança no próprio catálogo. Isso já era uma lacuna
silenciosa desde a 044 (renomear um modelo não atualizava a busca das OS
que o usam) — o próprio comando `php artisan os:reindexar-busca`, que já
existia, documenta essa exceção. A mesclagem fecha essa lacuna para si
mesma: como se sabe exatamente quais aparelhos mudaram de modelo, ela
chama `OrderSearchIndexService::rebuildForEquipment()` para cada um logo
depois do merge. Um lote grande de renomeações simples (sem mesclagem)
continua exigindo o comando manual — reindexar de forma síncrona um modelo
usado por centenas de aparelhos dentro do próprio upload do CSV custaria
caro demais para o caso comum (uma duplicata ambígua afeta poucos
aparelhos).

## Testes

- `EquipmentCatalogTest` backend (+6): exportação inclui o id do modelo;
  renomear por id sem casar por nome; rejeita quando a coluna marca não
  bate com a marca atual do registro; id repetido com dados conflitantes
  gera erro (idêntico é idempotente); mesclagem reponta aparelhos,
  orçamentos e relações de catálogo e desativa o perdedor sem violar a
  unique key; mesclagem rejeita marcas diferentes, alvo inativo, alvo igual
  a si mesmo e cadeia de mesclagem no mesmo arquivo.
- `EquipmentCatalogTest` desktop (+2): coluna ID aparece nas tabelas;
  relatório de importação mostra a contagem e a lista de mesclagens.

Suíte completa: backend 1312 testes (1 falha pré-existente e não
relacionada, em `EstoqueFlowTest`/Peça — arquivo sendo editado por outra
sessão concorrente no mesmo servidor); desktop 617 testes (10 falhas
pré-existentes e não relacionadas, em Peça/Orçamento/Financeiro — mesma
causa, confirmado via `git status` antes de qualquer alteração desta
entrega).

Verificação extra contra o banco real de desenvolvimento (`sistema_hml`,
`192.168.1.100`) via `DB::transaction()` com rollback forçado, reproduzindo
o cenário exato do pedido: criados dois modelos duplicados de verdade na
marca Samsung ("J7 TESTE-045" e "j 7 TESTE-045"), um aparelho cadastrado
apontando para um deles, mesclados os dois num terceiro modelo
("SM-J730G TESTE-045") — o aparelho passou a apontar para o vencedor, os
dois perdedores ficaram desativados, a relação de catálogo do vencedor
permaneceu única (sem duplicar a linha, mesmo com os três já vinculados ao
mesmo tipo Smartphone), e tudo foi revertido ao final sem deixar rastro no
banco real.

## O que isto NÃO faz

- **Não funde marcas** — só modelos da mesma marca. Fundir marcas exigiria
  também decidir o destino dos modelos da marca perdedora; mesma mecânica
  (`ensureCatalogRelationRecord` + repontar FK + desativar), aplicada a
  `equipamentos_marcas`/`equipamentos_modelos.marca_id`, fica para uma
  entrega futura se for necessário.
- **Não reatribui a marca de um modelo via CSV** — a coluna `marca` de uma
  linha com `id` precisa bater com a marca atual; o caminho para "modelo
  cadastrado na marca errada" continua sendo mesclar (se já existir um
  equivalente correto) ou desativar e recriar.
- **Não sugere duplicados automaticamente** — a identificação continua
  manual, olhando a planilha exportada (com ou sem apoio de IA externa).

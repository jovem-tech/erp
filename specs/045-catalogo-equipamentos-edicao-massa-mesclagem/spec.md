# Catálogo de equipamentos — edição em massa por ID e mesclagem de duplicados

**Base:** `specs/044-catalogo-equipamentos-csv/spec.md`

## Problema

A `044` resolveu "adicionar/atualizar modelos por nome" — a importação CSV casa
por nome e cria o que falta. Mas não resolve o problema seguinte, que é o que
motivou esta entrega: o catálogo tem 1.964 modelos já cadastrados, muitos
incompletos ou ambíguos, e alguns duplicados — o mesmo aparelho registrado mais
de uma vez com nomes diferentes (`Samsung - J7`, `Samsung - j 7`,
`Samsung - SM-J730G`). Casar por nome não ajuda aqui: o objetivo agora é
**corrigir e consolidar** o que já existe, não criar mais nada.

## Objetivo

Duas capacidades novas, ambas no mesmo CSV de sempre (mesmo botão "Exportar
CSV"/"Importar em lote"), via duas colunas opcionais:

1. **Edição em massa por ID** — exportar o catálogo com o `id` de cada modelo,
   corrigir os nomes numa planilha (manualmente ou com apoio de IA externa) e
   reimportar. Com `id` preenchido a linha deixa de casar por nome e passa a
   mirar aquele registro exato.
2. **Mesclar duplicados** — coluna `mesclar_com_id`: aponta o id de um modelo
   duplicado para o id do modelo que deve prevalecer. Todo aparelho já
   cadastrado com o duplicado passa a apontar para o vencedor, e o duplicado
   é desativado.

## Decisões

- **Mesclagem só via coluna no CSV, sem botão manual e sem detecção
  automática de duplicados.** O usuário identifica os duplicados olhando a
  planilha exportada (com ou sem apoio de IA) e preenche a coluna. Um recurso
  de sugestão automática ou uma ação de mesclagem avulsa na tela ficam para
  uma entrega futura se o volume justificar.
- **O perdedor é desativado e escondido (`ativo=0`), sem coluna de rastreio.**
  Mesma convenção simples do resto do catálogo — nunca há exclusão de
  verdade, porque 3.618 aparelhos apontam para o catálogo por FK.
- **Só mescla modelos da mesma marca**, com o vencedor obrigatoriamente ativo.
  Fundir marcas fica fora de escopo (o exemplo real é sempre intra-marca; ver
  "Fora de escopo").
- **A linha de mesclagem ignora o resto de si mesma** (nome/tipo/ativo) — ela
  só diz "isto vira aquilo". Evita ambiguidade sobre o que fazer quando a
  linha carrega, ao mesmo tempo, uma correção de nome e uma mesclagem.
- **Cadeia de mesclagem no mesmo arquivo é rejeitada** (A funde em B, e B
  também funde em C na mesma importação) — resolve-se em duas importações.
- **Reatribuir marca de um modelo via CSV não é suportado** — a coluna
  `marca` de uma linha com `id` precisa bater com a marca atual do registro;
  do contrário a linha é rejeitada com o motivo. Só a mesclagem move um
  aparelho de um modelo para outro.
- **Mesclagem reindexa sozinha a busca de OS dos aparelhos afetados**
  (`OrderSearchIndexService::rebuildForEquipment()`), porque `os.busca_texto`
  é um snapshot, não um join ao vivo — sem isso, a OS continuaria aparecendo
  na busca pelo nome do modelo perdedor. Renomear por ID (sem mesclagem) não
  dispara isso automaticamente, pelo mesmo motivo de custo que já valia para
  o rename manual da `044`: o comando `php artisan os:reindexar-busca`
  (`ReindexOrderSearch`, já existente) continua sendo o caminho para um lote
  grande de renomeações.

## Fora de escopo

- **Mesclagem entre marcas.** Se um dia for necessário, a mesma mecânica
  (`ensureCatalogRelationRecord` + repontar FK + desativar) se aplica a
  `equipamentos_marcas`/`equipamentos_modelos.marca_id`.
- **Reatribuir a marca de um modelo via CSV** (mover um modelo cadastrado na
  marca errada para outra marca sem que já exista um equivalente lá).
- **Sugestão automática de duplicados** (comparação de similaridade de nomes
  dentro da mesma marca).

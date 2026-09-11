# Catálogo de equipamentos (tipos → marcas → modelos) com importação CSV

## Problema

O cadastro de tipo/marca/modelo de um aparelho, na OS e no orçamento, já é um
`<select>` em cascata alimentado por `equipamentos_tipos` / `equipamentos_marcas`
/ `equipamentos_modelos` (`EquipmentWorkflowService::formData()`) — a base é
relacional desde a 2026-06-25 (`playbooks/catalogo-equipamentos-vinculo-rapido.md`).
O que faltava era **uma tela para administrar esse catálogo**: hoje a única
forma de cadastrar uma marca ou um modelo é digitá-los no meio do cadastro de
um aparelho específico (quick-add), um de cada vez. Não existe onde ver a
lista inteira, corrigir um nome digitado errado (`"Iphonee"`), desativar um
modelo descontinuado, ou — o pedido que disparou esta entrega — atualizar de
uma vez todos os modelos de um fabricante (ex.: recarregar a linha completa
Samsung ou Motorola a partir de uma lista pronta).

## Objetivo

Uma tela **Equipamentos**, na seção **Cadastros** da sidebar, com três abas —
Modelos, Marcas, Tipos — sobre o mesmo catálogo que a OS e o orçamento já
consultam. Puramente consultiva: nada aqui cria vínculo direto com uma OS ou
orçamento, só mantém atualizada a lista de onde eles escolhem.

## Decisões

- **Nenhuma tabela nova.** `equipamentos_tipos/marcas/modelos/catalogo_relacoes`
  já existem, já têm 12/185/1971/1982 linhas em produção e já são a fonte que
  os formulários de OS/orçamento/mobile leem. A entrega é a tela de gestão que
  faltava sobre essa base — ver `EquipmentCatalogService` (extraído do que já
  existia em `EquipmentWorkflowService::createBrand/createModel` e dos helpers
  de escopo `ensureBrandTypeCatalogScope`/`ensureModelTypeCatalogScope`, que
  continuam idênticos e cobertos por `EquipmentCreationTest`).

- **Nunca há exclusão de verdade.** 3.618 aparelhos apontam para o catálogo por
  FK (`equipamentos.tipo_id/marca_id/modelo_id`). "Excluir" é sempre
  `ativo = false`, mesma convenção de `EstoqueCatalogController` para Tipo
  (que já era gerido como "Grupo" em Estoque > Gerenciar categorias — esta
  tela não substitui aquela, as duas continuam editando a mesma tabela
  `equipamentos_tipos`).

- **Renomear é intencional, não é "editar com cuidado".** Corrigir o nome de
  um modelo/marca reflete em todo aparelho que já usa aquele registro — é
  assim que se corrige um erro de digitação antigo sem recriar histórico.
  Documentado na Ajuda da tela.

- **Importação CSV é upsert aditivo, nunca espelho.** Colunas
  `tipo;marca;modelo;ativo`. Casa por nome ignorando maiúsculas/minúsculas;
  cria o que falta; reativa o que estava inativo; atualiza o *casing* do
  registro para o do arquivo (o CSV é a fonte curada — "samsung" no arquivo
  vira o nome oficial da marca, mesmo que já existisse como "Samsung"). Uma
  linha nunca desativa o que ficou de fora do arquivo — só `ativo=0`
  explícito na própria linha desativa. Isso é o que permite "atualizar todos
  os modelos da Samsung" com um CSV que só tem Samsung, sem tocar em nenhuma
  outra marca.

- **Tipo desconhecido rejeita a linha, nunca cria um tipo novo.** Só são 12 e
  são compartilhados com Estoque, Serviços, Checklists e Base de defeitos — um
  erro de digitação na coluna `tipo` de um CSV não pode vazar um tipo novo
  para essas telas. A linha entra no relatório de erros com o motivo; o resto
  do arquivo é processado normalmente.

- **Uma linha sem `modelo` cadastra (ou reativa) só a marca**, vinculada ao
  tipo da linha via a mesma âncora técnica `__CATALOG_BRAND_SCOPE__` que o
  quick-add já usa para registrar `tipo → marca` antes do primeiro modelo real
  (a tabela `equipamentos_catalogo_relacoes` exige `modelo_id` não nulo). A
  âncora nunca aparece nas listagens nem é editável.

- **Import não falha o arquivo inteiro por causa de uma linha ruim.** As
  escritas válidas rodam numa única transação (tudo ou nada nelas), mas os
  erros de linha são coletados à parte e devolvidos num relatório — o
  operador revisa e reimporta só o que falhou.

- **Sem mudança nos formulários de OS/orçamento/mobile.** Eles continuam
  chamando `GET equipments/form-data` como sempre; o que entra pelo CSV ou
  pelo CRUD desta tela aparece lá automaticamente, sem tocar nesses fluxos.

## Fora de escopo

- Importação de XLSX (só CSV, mesmo padrão nativo `fgetcsv`/`fputcsv` de
  Serviços e Estoque — sem biblioteca nova).
- Fusão de marcas/modelos duplicados (ex.: unir "Sansung" com "Samsung") —
  hoje isso é renomear um dos dois manualmente; uma ferramenta de merge fica
  para uma entrega futura se o volume de duplicatas legadas justificar.

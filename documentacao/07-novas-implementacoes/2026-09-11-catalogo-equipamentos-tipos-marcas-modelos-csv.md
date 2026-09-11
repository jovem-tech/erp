# Catálogo de equipamentos: tela de gestão + importação CSV (2026-09-11)

**Spec:** `specs/044-catalogo-equipamentos-csv/spec.md`
**Tipo:** funcionalidade nova (MINOR)

## O problema

O select em cascata de tipo/marca/modelo que a OS e o orçamento já usam para
padronizar o cadastro de um aparelho é relacional desde 2026-06-25
(`equipamentos_tipos/marcas/modelos/catalogo_relacoes` — ver
`playbooks/catalogo-equipamentos-vinculo-rapido.md`). Mas **não existia
nenhuma tela para administrar esse catálogo**: a única forma de cadastrar uma
marca ou um modelo era digitá-los no meio do cadastro de um aparelho
específico (quick-add), um de cada vez, sem lugar para ver a lista inteira,
corrigir um nome digitado errado ou desativar um modelo descontinuado. O
pedido que disparou esta entrega foi justamente esse: atualizar de uma vez
todos os modelos de um fabricante (Samsung, Motorola, Apple, Dell...) a partir
de uma lista pronta.

## O que foi entregue

Menu **Equipamentos**, na seção **Cadastros** da sidebar, com três abas sobre
o mesmo catálogo:

- **Modelos** (principal): Tipo(s) · Marca · Modelo · Status, paginado,
  filtrável por tipo/marca/status/busca.
- **Marcas**: nome, tipos vinculados, contagem de modelos ativos.
- **Tipos**: nome, contagem de marcas e modelos — com aviso de que o tipo é
  compartilhado com Estoque (Grupo), Serviços, Checklists e Base de defeitos.

Cada aba tem novo/renomear (modal) e ativar/desativar (nunca exclusão real —
3.618 aparelhos apontam para o catálogo por FK). Mais ações: Exportar CSV,
baixar Modelo CSV e Importar em lote.

### Importação é upsert aditivo, não espelho

Colunas `tipo;marca;modelo;ativo` (ativo opcional, default 1). Casa por nome
ignorando maiúsculas/minúsculas, cria o que falta, reativa o que estava
inativo e **atualiza o casing para o do arquivo** — o CSV é a fonte curada, e
"samsung" no arquivo vira o nome oficial da marca mesmo que já existisse como
"Samsung". Uma linha nunca desativa o que ficou de fora do arquivo; só
`ativo=0` explícito na própria linha desativa. É isso que permite subir um CSV
só com os modelos Samsung sem tocar em nenhuma outra marca.

Tipo desconhecido na coluna `tipo` **rejeita só aquela linha** (com o motivo
no relatório) — nunca cria um tipo novo, porque só são 12 e vazam para outras
quatro telas do sistema. As escritas válidas do arquivo rodam numa transação
única; os erros de linha são coletados à parte e devolvidos num relatório para
o operador revisar e reimportar só o que falhou.

Uma linha sem `modelo` cadastra (ou reativa) só a marca, vinculada ao tipo via
a mesma âncora técnica `__CATALOG_BRAND_SCOPE__` que o quick-add já usa — a
âncora nunca aparece nas listagens.

## Arquitetura

**Nenhuma tabela nova, nenhuma migration, nenhuma mudança de RBAC** — o
catálogo e o módulo `equipamentos` (com as ações `visualizar/criar/editar/
excluir/exportar/importar`) já existiam. A entrega é a tela e o serviço que
faltavam sobre a base existente.

`EquipmentCatalogService` (backend) nasceu como extração dos helpers de
escopo que já existiam em `EquipmentWorkflowService` (`ensureBrandTypeCatalogScope`,
`ensureModelTypeCatalogScope`, `ensureCatalogRelationRecord`,
`ensureBrandScopeAnchorModel`), agora públicos e reaproveitados por
`createBrand`/`createModel` (que passaram a delegar — comportamento idêntico,
inclusive o Title Case peculiar do quick-add, preservado por compatibilidade)
e pelas novas listagens/mutations/import/export. `EquipmentCatalogController`
expõe 12 endpoints em `equipments/catalog/*`, documentados em `openapi.yaml`.

No desktop, `EquipmentCatalogController` + `EquipmentCatalogService` seguem o
mesmo BFF fino de sempre (nunca fala com o banco), com rotas em
`/equipamentos/catalogo/*`. As abas são links `?aba=X` (não JS) para que
filtro e paginação sobrevivam à troca de aba sem duplicar estado no cliente.
O item da sidebar fica **antes** do item oculto "Aparelhos / Equip." (mesmo
módulo `equipamentos`) — quem só tem `equipamentos:visualizar` agora cai no
catálogo, não mais na lista de aparelhos de cliente, como destino de
`firstAllowedRouteName()`.

**Sem mudança em OS, orçamento ou mobile** — todos continuam lendo
`GET equipments/form-data` como sempre; o que entra pelo CSV ou pelo CRUD
desta tela aparece lá automaticamente.

## Testes

- `EquipmentCatalogTest` backend (10): listagem/filtros/sem-âncora, contagens
  de marcas/tipos, criar/renomear/desativar modelo sem excluir, marca
  vinculada via âncora, import com casing+erro por linha, cabeçalho inválido,
  limite de linhas, export→reimport idempotente, permissões.
- `EquipmentCreationTest` (17, regressão pós-extração do serviço) — inclusive
  o teste que reproduz a garantia do playbook de vínculo rápido.
- `EquipmentCatalogTest` desktop (9): menu na sidebar com/sem permissão, aba
  Modelos preserva filtros na paginação, abas Marcas/Tipos com contagens,
  salvar modelo, desativar marca, relatório de import com erro por linha,
  import sem arquivo válido, botões de export/import respeitam permissão.

Suítes completas: backend 1306 testes (1 falha pré-existente e não
relacionada, em `EstoqueFlowTest`/Peça — arquivo sendo editado por outra
sessão concorrente no mesmo servidor); desktop 615 testes (9 falhas
pré-existentes e não relacionadas, em Peça/Orçamento/Financeiro — mesmos
arquivos em edição concorrente, confirmado via `git status` antes de
qualquer alteração desta entrega).

Verificação extra contra o banco real de desenvolvimento (`sistema_hml`,
`192.168.1.100`) via `DB::transaction()` com rollback forçado: import de um
CSV misto (marca com casing diferente do cadastrado, modelo novo, tipo
inexistente) produziu os contadores e o erro de linha esperados sem alterar
o banco.

## O que isto NÃO faz

- **Não importa XLSX** — só CSV, mesmo padrão nativo (`fgetcsv`/`fputcsv`) já
  usado por Serviços e Estoque, sem biblioteca nova.
- **Não funde marcas/modelos duplicados** (ex.: "Sansung" e "Samsung"
  coexistindo). Hoje isso é renomear um dos dois manualmente; uma ferramenta
  de merge fica para uma entrega futura se o volume de duplicatas legadas
  justificar.
- **Não migra dados existentes** — os 12/185/1971 registros e as 1982 relações
  já cadastrados continuam exatamente como estavam; a tela só passa a
  administrá-los.

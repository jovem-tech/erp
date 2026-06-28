# 2026-06-24 - Cadastro completo de equipamentos no desktop ERP

## Versão

- Desktop ERP: `v3.1.19`

## Escopo entregue

- criação da tela `/equipamentos/novo` no `frontends/desktop`;
- paridade visível com o legado nas abas `Informações`, `Cor` e `Fotos`;
- quick-add de cliente, marca e modelo sem sair do formulário;
- senha por `Desenho` ou `Texto`, com a grade de desenho oculta por padrão e exibida somente ao acionar `Mostrar desenho`;
- painel técnico condicional para desktop e notebook;
- fluxo de cor com preview e metadados `hex` e `rgb`;
- upload de até 4 fotos com galeria, câmera, Cropper.js, preview local e definição da foto principal;
- seletor de cliente convertido para Select2 com lista pré-carregada do backend e busca local, preservando o label operacional em caso de validação;
- a cascata de catálogo passa a seguir `tipo -> marca -> modelo` com leitura em runtime de `equipamentos_catalogo_relacoes`, sem qualquer alteração estrutural no banco;
- coletor local legado em `C:\JovemTechBenchCollector` como fluxo principal no formulário;
- o cartão do coletor local passa a aparecer somente para equipamentos da família `desktop` ou `notebook`;
- pareamento remoto do coletor mantido como apoio para cenários futuros;
- leitura segura de fotos por endpoint autenticado no backend central;
- dropdowns do desktop padronizados com `Select2` como regra visual e operacional;
- o campo de cliente do cadastro de equipamentos usa Select2 com lista pré-carregada do backend e busca local, e o bloco de desenho da senha permanece oculto até o usuário clicar em `Mostrar desenho`.
- o campo `resumo_tecnico` é limitado ao tamanho suportado pela coluna existente antes de persistir, evitando `Data too long` sem alterar o banco.

## Backend central afetado

- `GET /api/v1/equipments/form-data`
- `GET /api/v1/equipments/models/suggestions`
- `POST /api/v1/equipments/brands`
- `POST /api/v1/equipments/models`
- `GET /api/v1/equipments/collector/local-snapshot`
- `POST /api/v1/equipments/collector/local-collect`
- `POST /api/v1/equipments/collector-pairings`
- `GET /api/v1/equipments/collector-pairings/{code}`
- `POST /api/v1/equipments`
- `GET /api/v1/equipments/{equipment}/photos/{photo}`
- `POST /api/v1/collector/snapshots`

## Ajustes técnicos relevantes

- o backend passou a aplicar defaults automáticos de `Desktop montado`;
- o formulário de novo equipamento agora carrega os clientes no `form-data` e usa Select2 local como o legado para garantir busca imediata mesmo sem AJAX;
- o storage de fotos permaneceu privado no `backend/storage/app/private/equipamentos/...`;
- o desktop continuou sem acesso direto ao banco e sem token Bearer no navegador;
- o formulário agora prioriza a leitura local do snapshot e a execução automática do coletor quando o ERP estiver na mesma máquina Windows;
- os dropdowns do desktop foram padronizados com `Select2` para manter consistência visual e operacional, com o campo de cliente seguindo o padrão local carregado no formulário;
- o pareamento remoto do coletor foi preservado como capacidade auxiliar e a migration correspondente foi corrigida para o schema real de `usuarios`.

## Regra de negócio: Notebook é sempre OEM / fabricante

- `Desktop montado` é uma modalidade exclusiva do tipo `Desktop`; não existe a possibilidade de um `Notebook` ser "montado";
- o campo `Modalidade` do formulário fica desabilitado e travado em `OEM / fabricante` quando o `Tipo` selecionado é `Notebook` (SSR no `create.blade.php` e atualização em runtime no `equipments-create.js` ao trocar o `Tipo`);
- a marca/modelo genéricos usados como default de `Desktop montado` (`Montado` / `Desktop montado`) deixaram de ser injetados como opção para `Notebook` na cascata `tipo -> marca -> modelo`;
- `EquipmentWorkflowService::applyDesktopDefaults` força `desktop_modalidade = 'oem'` para qualquer equipamento da família `notebook`, independentemente do valor enviado, e nunca aplica os defaults de catálogo do `Desktop montado` a um notebook;
- `StoreEquipmentRequest` exige `marca_id` e `modelo_id` reais para `Notebook`; a isenção que permite cadastrar sem marca/modelo (usando os defaults de `Desktop montado`) ficou restrita ao tipo `Desktop`;
- `EquipmentWorkflowService::resolveTypeFamily` passou a ser pública para ser reaproveitada pela validação do `StoreEquipmentRequest`, evitando duplicar a lógica de slug do nome do tipo.

## Correções encontradas durante a validação

- remoção de `foto_principal_index` do `insert` da tabela `equipamentos`;
- correção da persistência da senha por desenho no `EquipmentWorkflowService`;
- correção do coletor no `sistema-erp` para seguir o fluxo local do legado em vez de depender visualmente do pareamento remoto;
- correção da foreign key da tabela `equipment_collector_pairings` para casar com o tipo real da tabela `usuarios`;
- correção do bloqueio indevido do campo `Marca` ao selecionar o `Tipo` pela interface do Select2: a causa raiz era o uso de `addEventListener('change', ...)` nos campos `tipo`, `marca`, `modalidade desktop` e `cliente`, evento que o Select2 não dispara (ele notifica via `jQuery(...).trigger('change')`); o `equipments-create.js` passou a vincular esses eventos por um helper que usa `jQuery(...).on(...)` quando disponível — ver detalhamento da regra em `2026-06-24-select2-obrigatorio-desktop.md`.

## Validação executada

- `php artisan test tests/Feature/Api/V1/EquipmentCreationTest.php`
- `php artisan migrate --force`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter equipment`
- suíte completa do backend (`php artisan test`, 48 testes) e do frontend desktop (`php artisan test`, 37 testes) após a regra de `Notebook` sempre `OEM / fabricante`
- novos testes: `test_store_requires_brand_and_model_for_notebook_type`, `test_store_forces_oem_modality_for_notebook_even_when_montado_is_requested` (backend) e `test_equipment_create_page_locks_modalidade_to_oem_for_notebook_type` (frontend desktop)

## Próximo passo sugerido

- abrir o detalhe e a edição completa de equipamento com a mesma paridade operacional do legado;
- depois conectar esse fluxo ao módulo de OS para abertura contextual a partir do equipamento.

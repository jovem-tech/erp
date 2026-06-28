# 2026-06-23 - Equipamentos operacionais no desktop do sistema-erp

## Resumo

O módulo de equipamentos do `frontends/desktop` foi reorganizado para seguir o mesmo padrão operacional aplicado aos clientes: leitura rápida, chips de contexto, menu único de ações e detalhe com visão de cliente e OS vinculadas.

## O que entrou

- listagem de equipamentos com busca e paginação;
- listagem reorganizada no padrão do legado, com ID, nome do equipamento em destaque, chip de quantidade de OS, coluna de cliente, série / IMEI, modalidade, status e menu único de ações;
- acesso contextual ao cliente vinculado;
- acesso contextual à listagem de OS filtrada pelo equipamento;
- ação de `Nova OS` já pré-preenchendo cliente e equipamento quando o perfil possui permissão;
- detalhe do equipamento com identificação completa, dados do cliente vinculado, contagem de OS e lista resumida de ordens relacionadas;
- preservação do comportamento responsivo da tabela em desktop e mobile;
- filtro `equipment_id` suportado na listagem de OS do backend central para manter o fluxo operacional partindo do equipamento.

## Ajustes técnicos

- `backend/app/Http/Controllers/Api/V1/EquipmentController.php`
  - passou a devolver `orders_count` na listagem e no detalhe;
  - passou a enriquecer o cliente vinculado com `cpf_cnpj`, cidade e UF;
  - passou a expor `created_at` e `updated_at` no detalhe.
- `backend/app/Services/Orders/OrderWorkflowService.php`
  - passou a aceitar `equipment_id` como filtro na listagem de OS.
- `frontends/desktop/app/Http/Controllers/EquipmentController.php`
  - passou a buscar as OS vinculadas ao equipamento;
  - passou a expor atalhos para cliente, lista de OS e criação de nova OS contextual.
- `frontends/desktop/app/Http/Controllers/OrderController.php`
  - passou a preservar o filtro `equipment_id` na listagem administrativa de OS.
- `frontends/desktop/resources/views/equipments/`
  - ganhou organização visual inspirada no legado, com menu de ações e detalhe operacional.
- `frontends/desktop/resources/views/orders/index.blade.php`
  - passou a preservar o filtro de equipamento na navegação.
- `backend/tests/Feature/Api/V1/RbacAdministrationTest.php`
  - passou a cobrir `orders_count`, filtro `equipment_id` e o detalhe do equipamento.
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`
  - passou a cobrir a nova organização da listagem e o detalhe operacional de equipamentos.

## Versão

- `shared/version.php` atualizado para `3.0.6`.

## Validação recomendada

- abrir a listagem de equipamentos e confirmar nome em destaque, chip de OS e menu de ações;
- abrir o detalhe de um equipamento e confirmar cliente vinculado, OS vinculadas e atalhos contextuais;
- abrir a listagem de OS a partir do equipamento e confirmar que o filtro é preservado;
- acionar `Nova OS` a partir do equipamento e verificar cliente e equipamento pré-selecionados;
- executar `php artisan test` no backend e no desktop após a alteração.

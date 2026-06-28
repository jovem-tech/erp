# 2026-06-23 - Clientes operacionais no desktop do sistema-erp

## Resumo

O módulo de clientes do `frontends/desktop` foi expandido para funcionar como hub operacional do atendimento, e não apenas como consulta cadastral.

## O que entrou

- listagem de clientes com busca, filtro, ordenação e paginação;
- listagem defensiva, tolerante a payloads legados sem campos opcionais como `nome_contato`;
- resumo da listagem enriquecido com `nome_contato` e dados de contato principal quando disponíveis;
- listagem reorganizada para seguir o padrão visual do legado, com ID, nome em destaque, chips de OS e equipamentos, colunas operacionais e menu único de ações por linha;
- cadastro de novo cliente;
- edição de cliente existente;
- detalhe do cliente com foco operacional;
- ações rápidas de contato direto por telefone, WhatsApp e e-mail;
- card de resumo com quantidade de OS e equipamentos vinculados;
- lista resumida das ordens do cliente;
- lista resumida dos equipamentos do cliente;
- ação contextual de `Nova OS` já pré-selecionando o cliente;
- navegação direta para a listagem filtrada de OS e equipamentos;
- filtro `client_id` no endpoint administrativo de equipamentos no backend central;
- suporte do formulário de nova OS para pré-seleção de cliente e equipamento via query string.

## Ajustes técnicos

- `backend/app/Http/Controllers/Api/V1/EquipmentController.php`
  - passou a aceitar `client_id` como filtro opcional.
- `backend/app/Http/Controllers/Api/V1/ClientController.php`
  - passou a aceitar listagem com busca, filtro, ordenação e mutações cadastrais.
- `backend/routes/api.php`
  - passou a expor `POST /api/v1/clients` e `PUT/PATCH /api/v1/clients/{client}`.
- `frontends/desktop/app/Http/Controllers/ClientController.php`
  - passou a buscar OS e equipamentos relacionados ao cliente e agora também suporta cadastro e edição.
- `frontends/desktop/resources/views/clients/`
  - ganhou telas de listagem, cadastro, edição e detalhe com ações operacionais;
  - a listagem passou a espelhar a organização visual do legado com chips de quantidade e dropdown de ações.
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`
  - passou a cobrir detalhe, cadastro, edição e a nova organização da listagem.

## Versão

- `shared/version.php` atualizado para `3.0.2`.
- `shared/version.php` atualizado para `3.0.3` após endurecimento da listagem contra campos opcionais ausentes no payload.
- `shared/version.php` atualizado para `3.0.4` após enriquecimento do resumo de clientes com o contato principal.
- `shared/version.php` atualizado para `3.0.5` após reorganização da listagem de clientes no padrão visual do legado.

## Validação recomendada

- abrir a listagem de clientes e confirmar busca, filtro, ordenação e ações rápidas;
- abrir um cliente e confirmar a lista de OS relacionadas;
- abrir um cliente e confirmar a lista de equipamentos vinculados;
- acionar `Nova OS` a partir do cliente e verificar os campos pré-selecionados;
- abrir as telas de novo cliente e edição de cliente;
- executar `php artisan test` no backend e no desktop após a alteração.

## Atualização complementar - 2026-06-24

- cadastro rápido de cliente em modal a partir da nova OS, com retorno imediato para o seletor sem sair do fluxo;
- `shared/version.php` atualizado para `3.0.7` após a introdução do cadastro rápido de cliente no modal da OS.

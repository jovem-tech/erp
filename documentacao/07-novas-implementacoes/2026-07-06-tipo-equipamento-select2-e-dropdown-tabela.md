# Tipo de equipamento em Select2 com tags e correcao de dropdown em tabela responsiva

## Contexto

- versao: `3.13.0.0`
- data: `2026-07-06`
- ambiente-alvo: `Ubuntu VPS` (reproduzido em `192.168.1.100`)
- area afetada: `Orçamentos > Novo/Editar > Cadastro rápido de item` (campo "Tipo de equipamento") e o dropdown de "Ações" de qualquer tabela do desktop (OS, equipamentos, financeiro, etc.)

## Entrega

### Tipo de equipamento em Select2 (cadastro rápido do orçamento)

- O campo "Tipo de equipamento" do modal de cadastro rápido de serviço/peça (`orcamentos/partials/quick-item-modal.blade.php`) deixou de ser um `<input type="text">` livre e virou um `<select>` com Select2 (`tags: true`): o usuário pode escolher um tipo já cadastrado ou digitar um novo, mantendo o comportamento de texto livre que já existia.
- Backend: `BudgetWorkflowService::formData()` passou a expor `tipos_equipamento` (reaproveitando `Servico::tiposEquipamentoAtivos()`, a mesma lista de `EquipmentType` ativos já usada em Serviços e Estoque) no endpoint já existente `/orcamentos/form-data` — sem endpoint novo.
- Desktop: `orcamentos/form.blade.php` repassa `tipos_equipamento` para o partial; `orcamentos-form.js` ganhou `initEquipmentTypeSelect`/`setEquipmentTypeValue`, no mesmo padrão do Select2 com tags já usado em "Categoria" no Financeiro.
- **Achado durante a validação**: o Select2 dentro do modal (Bootstrap) não recebia nenhuma tecla digitada na busca — o dropdown do Select2 é anexado ao `<body>` por padrão, fora da área em que o *focus trap* do Bootstrap Modal mantém o foco. Corrigido configurando `dropdownParent` apontando para o `.modal` mais próximo (`select.closest('.modal')`), o mesmo padrão já usado em `desktop.js::getSelect2DropdownParent` para os outros Select2 do sistema.

### Correção geral do dropdown de "Ações" em tabelas

Ao validar visualmente a tela de Ordens de Serviço, o menu "Ações" (não relacionado ao Select2 acima, mas ao dropdown padrão do Bootstrap) apresentava dois problemas, ambos com a mesma causa raiz:

- `.table-responsive` precisa de `overflow-x: auto` para permitir rolagem horizontal em telas estreitas, mas isso força `overflow-y` a também virar `auto` (regra do CSS: um eixo diferente de `visible` "contamina" o outro).
- **Sintoma 1** (visto na listagem de OS): com a tabela tendo só a altura do conteúdo, o Popper (motor de posicionamento do dropdown) entendia que não havia espaço abaixo do botão e abria o menu **para cima**, sobre a própria linha.
- **Sintoma 2** (visto após uma tentativa de correção anterior, com tabela curta/poucas linhas): mesmo forçando o menu a abrir para baixo (via `popperConfig` sobrescrevendo o boundary dos modifiers `preventOverflow` e `flip` — a opção simples `boundary` do Bootstrap só afeta o `preventOverflow`), o menu continuava sendo filho no DOM da `.table-responsive` e ficava **cortado visualmente** pelo `overflow-y: auto` dela quando a tabela tinha poucas linhas.
- **Correção definitiva**: `desktop.js::initDropdowns` agora move o `.dropdown-menu` para o final do `<body>` no evento `show.bs.dropdown` (deixando um comentário-marcador no lugar original) e devolve para a posição original no `hidden.bs.dropdown`. Assim o menu nunca é filho de um ancestral com overflow clipado, independente da altura da tabela.
- Esse é um problema estrutural do padrão `.table-responsive` + dropdown do Bootstrap, não específico da tela de OS — a correção em `desktop.js` é global e cobre automaticamente qualquer tabela do sistema com esse mesmo padrão (equipamentos, financeiro, etc.), sem precisar tocar em cada blade individualmente.

## Impactos

- Sem migration nova: `tipos_equipamento` reaproveita a tabela `equipment_types` já existente e o método estático já usado por Serviços/Estoque.
- Contrato de `/orcamentos/form-data` mudou apenas de forma aditiva (campo novo `tipos_equipamento`, nada removido/renomeado).
- A correção do dropdown é puramente client-side (`desktop.js`) e não altera nenhum contrato de API, rota ou banco; se aplica a qualquer `[data-bs-toggle="dropdown"]` da aplicação.

## Validação

- `node --check` em `orcamentos-form.js` e `desktop.js`; `php -l` em `BudgetWorkflowService.php`; `php artisan view:cache` sem erros.
- Backend: `BudgetWorkflowService::formData()` testado via tinker retornando `tipos_equipamento` com a lista real de tipos ativos.
- Chrome headless (assets reais do servidor) simulando o modal de cadastro rápido dentro do Bootstrap Modal: confirmado que o campo de busca do Select2 não recebia nenhuma tecla antes do fix de `dropdownParent`; depois do fix, digitar filtra corretamente, selecionar uma opção existente aplica o valor certo, e digitar um termo novo cria e aplica a tag corretamente.
- Chrome headless reproduzindo a listagem de OS com uma única linha (tabela curta, cenário relatado): antes do fix, `data-popper-placement` vinha `top-end` (menu sobre a própria linha) e, mesmo após corrigir para `bottom-end`, o menu ficava cortado pela `.table-responsive`; depois do fix de reparenting, o menu é filho de `document.body`, renderiza totalmente visível abaixo do card, e o ciclo abrir → fechar (clique fora) → abrir de novo foi testado e restaura corretamente a posição original do menu no DOM a cada fechamento.
- Sanity check adicional: dropdown do menu de perfil (fora de qualquer tabela) testado para confirmar que a correção genérica não introduziu regressão em dropdowns que já funcionavam.

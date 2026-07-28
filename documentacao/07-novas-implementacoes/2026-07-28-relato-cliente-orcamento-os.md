# Auto-preenchimento do relato do cliente a partir da OS vinculada no orçamento

## Contexto

- versao: `5.20.7.0`
- data: `2026-07-28`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- Ao criar um orçamento com "OS vinculada" selecionada (aba "Dados do cliente"), o campo "Relato do cliente / defeito relatado" (aba "Dados do equipamento") passa a ser preenchido automaticamente com o `relato_cliente` já registrado naquela OS.
- Cobre os dois caminhos existentes de seleção de OS no formulário `orcamentos/novo`:
  - carregamento inicial da página quando chega via `?os_id=` (fluxo "Gerar orçamento" a partir do listing de OS) — pré-preenchido no server-side (`BudgetWorkflowService::formData`, chave `selected_order_relato`);
  - troca manual do select "OS vinculada" pelo usuário já com a página aberta — pré-preenchido via JS (`applyOrderLinkedRelato` em `orcamentos-form.js`), lendo o novo atributo `data-relato-cliente` da `<option>` selecionada.
- Só sobrescreve o campo quando o texto vinculado é diferente do atual — não apaga nem repete o que já estiver lá, e não mexe no campo quando a OS é desmarcada.
- Backend (`backend/app/Services/Budgets/BudgetWorkflowService.php`): `relato_cliente` passou a ser selecionado na query de OS do cliente (`clientOrdersQuery`) e exposto em `mapOrderOption`, tanto no `formData` (form inicial) quanto no `clientContext` (endpoint usado ao trocar de cliente).
- Frontend desktop (`OrcamentoController::clientContext`, `orcamentos/form.blade.php`, `orcamentos-form.js`): repassa o `relato_cliente` de cada OS para o Select2 (`data-relato-cliente`) e aplica no textarea, espelhando o padrão já existente de auto-seleção do equipamento vinculado (`applyOrderLinkedEquipment`).

## Impactos

- Não há migration nem mudança de schema — apenas adição de um campo já existente (`orders.relato_cliente`) às queries e ao payload já retornado pelos endpoints `orcamentos/form-data` e `orcamentos/cliente-contexto`.
- Não quebra contrato de rota: o campo é aditivo dentro do array `orders[]` já consumido pelo desktop; nenhum consumidor existente depende da ausência dele.
- Nenhum novo arquivo criado no domínio de código (só a nota de release e a entrada no changelog).

## Validacao

- `php -l` nos arquivos PHP alterados (sem erros).
- `php artisan view:cache` no `frontends/desktop` para forçar a compilação de todas as views Blade do projeto e confirmar que `orcamentos/form.blade.php` compila sem erro; em seguida `php artisan view:clear` para restaurar a compilação preguiçosa usada em produção neste ambiente.
- Revisão manual do fluxo `syncEquipmentMode()`/`handleOrderChange` no JS para confirmar que nada mais no formulário sobrescreve o campo depois do preenchimento automático.
- Não foi possível validar via navegador autenticado nesta sessão (sem credenciais de login); recomenda-se conferência manual em produção: abrir "Novo orçamento" com uma OS vinculada e conferir o preenchimento do relato.

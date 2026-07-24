# Cadastro de orçamento: trava o salvamento até completar os campos obrigatórios de todas as abas

## Contexto

- versao: `5.12.1.0`
- data: `2026-07-24`
- ambiente-alvo: `Ubuntu VPS` (validado em `192.168.1.100`, ambiente dev)

Antes desta entrega, o botão "Salvar orçamento" do cadastro (`/orcamentos/novo`)
era um `submit` sempre habilitado, independente do que estivesse preenchido nas
4 abas do formulário (Dados do cliente, Dados do equipamento, Dados
operacionais, Orçamento e financeiro). O único mecanismo existente
(`collectReviewPendencies()`, no modal de revisão que abre ao salvar) só
bloqueava a opção "Salvar e enviar para aprovação"; "Salvar sem enviar" sempre
funcionava mesmo com pendências. Pedido do usuário: não liberar o salvamento
enquanto faltar campo obrigatório em qualquer aba — antes disso, mostrar um
botão "Próximo" no lugar do "Salvar orçamento".

> Nota de processo: uma primeira implementação desta trava (então marcada como
> v5.11.2.0) foi sobrescrita por uma sessão concorrente de outro agente que
> editou os mesmos `orcamentos-form.js`/`orcamentos/form.blade.php` (feature de
> seletor de clientes paginado) sem commit intermediário — um clássico
> lost-update de working tree compartilhada. Esta entrega reaplica a trava por
> cima do estado atual dos arquivos e renumera para v5.12.1.0.

## Entrega

- `orcamentos/form.blade.php`: cada botão de aba ganhou um
  `<span data-budget-tab-flag hidden>` (ponto âmbar) que a aba acende quando
  tem pendência; o botão de rodapé ganhou `data-budget-primary-action` para o
  JS conseguir alternar seu `type`/texto;
- `orcamentos-form.js`: `computeBudgetTabValidity()` calcula, direto do DOM
  (sem reusar `collectReviewSnapshot()`, que chamaria `updateSummary()` de
  novo e causaria recursão — `syncPrimaryAction()` já é chamada de dentro de
  `updateSummary()`), a validade de cada aba:
  - **Cliente**: cliente cadastrado (select) ou nome de cliente eventual
    preenchido (mutuamente exclusivos, como já era);
  - **Equipamento**: se o checkbox "Orçamento para reparo de um equipamento"
    estiver desmarcado, a aba passa automaticamente; se marcado, exige
    equipamento cadastrado OU tipo+marca+modelo avulsos preenchidos (cor
    eventual continua opcional);
  - **Operacional**: sem campo obrigatório, sempre válida;
  - **Financeiro**: ao menos 1 item com descrição preenchida e total > 0
    (reaproveita `rowHasMeaningfulContent()` para ignorar linhas vazias), e
    total final do orçamento > 0;
  - `syncPrimaryAction()` roda a cada `updateSummary()` (cobre adicionar/
    remover item, digitar em qualquer campo, marcar/desmarcar "envolve
    equipamento", trocar tipo/referência de item) e alterna o botão principal
    entre `type="button"` + texto "Próximo" (enquanto alguma aba está
    incompleta) e `type="submit"` + texto original "Salvar orçamento" (quando
    as 4 abas passam). Clicar em "Próximo" só avança para a próxima aba da
    sequência (cíclico) — a navegação direta clicando numa aba específica
    continua livre, o ponto âmbar já indica onde falta algo;
  - a trava só se aplica ao cadastro novo (`form.dataset.budgetIsEdit !== '1'`)
    — orçamentos em edição continuam podendo ser salvos parcialmente, como
    sempre puderam, para não travar retroativamente dados que já existem sob
    as regras antigas, mais permissivas;
- `desktop.css`: `.equipment-tab-flag` (ponto de 11px, cor
  `var(--desktop-warning)`, com anel usando `var(--desktop-surface)`) e
  `position: relative` em `.equipment-tab` para ancorar o ponto no canto.
- O modal de revisão existente (pendências ao enviar para aprovação,
  confirmação de administrador para OS encerrada) não foi alterado — esta
  trava age só na entrada, antes do `submit` chegar até esse fluxo.
- `syncPrimaryAction()` é reafirmada ao final do init e num `setTimeout(…, 0)`,
  para o caso de o Select2 do seletor de clientes (adicionado em paralelo por
  outra entrega) só terminar de montar num tick posterior — garante que o
  estado inicial "Próximo" apareça mesmo com a inicialização assíncrona do
  Select2 no meio.

## Impactos

- **Contrato:** nenhum. Mudança 100% client-side (Blade + JS), nenhuma rota,
  controller ou regra de validação do backend foi tocada — o backend continua
  aceitando os mesmos payloads de antes (a maioria dos campos já era
  `nullable` lá).
- **Módulos:** só `frontends/desktop` (cadastro de orçamento). Nenhuma
  migration, nenhum endpoint novo.
- **Comportamento:** usuários só conseguem salvar um orçamento novo pela UI se
  as 4 abas tiverem os campos mínimos acima; antes disso o botão não submete o
  formulário. Edição de orçamentos existentes não é afetada.

## Validacao

- Renderização real do Blade via `php artisan tinker`
  (`view('orcamentos.form', [...])->render()`), carregando o HTML gerado + o
  `orcamentos-form.js` real num DOM headless (jsdom) sem jQuery/Select2/
  SweetAlert2 (o script degrada graciosamente na ausência deles). 14
  verificações cobrindo: estado inicial (botão "Próximo", 3 abas sinalizadas),
  "Próximo" avança a aba corrente, preencher cliente eventual apaga o
  sinalizador da aba Cliente, desmarcar "envolve equipamento" libera a aba
  Equipamento, preencher descrição+valor do item libera a aba Financeiro e
  libera "Salvar orçamento" (`type=submit`), e esvaziar a descrição volta a
  travar — todas passando.
- Verificação separada confirmando que, em modo edição (`isEditMode: true`), o
  botão permanece sempre `type=submit` com o texto original mesmo com campos
  vazios — a trava não afeta orçamentos existentes.
- `node --check orcamentos-form.js` sem erros de sintaxe; `php artisan
  view:cache` sem erros (compila todos os `.blade.php`, incluindo o alterado).
- `php artisan test --filter=DesktopFrontendTest`: 113 passando; as mesmas 4
  falhas pré-existentes (dashboard/orders, não relacionadas a orçamento) foram
  confirmadas via `git stash` do diff desta entrega — já falhavam antes.
- Rituais de cache do ambiente dev (`route:clear`/`config:clear` antes,
  `route:cache`/`config:cache`/`view:cache` depois).

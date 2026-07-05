# Orçamentos: desconto e acréscimo em valor ou percentual

**Data:** 2026-07-03  
**Versão:** 3.5.3  
**Módulo:** `backend` + `frontends/desktop`

## Contexto

O formulário de orçamento no desktop aceitava apenas desconto e acréscimo monetários. Isso limitava negociações comerciais em que o operador precisa aplicar ajuste por percentual, tanto no item quanto no resumo financeiro.

## Entrega

- orçamento desktop agora permite escolher `R$` ou `%` para desconto e acréscimo em cada item;
- resumo financeiro também permite `R$` ou `%` para desconto geral e acréscimo geral;
- os controles visuais de modo foram consolidados em um toggle segmentado inline acoplado ao campo de valor, evitando ambiguidade entre valor e tipo do ajuste;
- quando o desconto está em `%`, a interface mostra um campo adicional somente leitura com o valor monetário equivalente em `R$`, tanto no item quanto no resumo financeiro;
- quando o acréscimo está em `%`, a interface também mostra um campo adicional somente leitura com o valor monetário equivalente em `R$`, tanto no item quanto no resumo financeiro;
- o resumo financeiro foi encapsulado em um card de fechamento com destaque visual do total, reforçando que essa área representa o resultado final consolidado do orçamento;
- o JavaScript do formulário recalcula automaticamente subtotal, total por item, desconto, acréscimo e total final conforme o modo selecionado;
- o payload do desktop envia ao backend o valor monetário efetivo e, quando aplicável, o percentual informado;
- o backend passou a persistir o modo do ajuste e o percentual correspondente em orçamento e itens;
- o contrato em `backend/openapi.yaml` foi atualizado para documentar `desconto_tipo`, `desconto_percentual`, `acrescimo_tipo` e `acrescimo_percentual`.

## Impactos

- a operação comercial ganha flexibilidade sem mover regra de negócio para fora do backend central;
- a leitura da linha financeira fica mais estável porque `valor + modo` passam a ser percebidos como um único controle composto;
- o desktop continua apenas compondo o payload e recalculando a experiência local, com validação final preservada no backend;
- a edição de orçamento mantém compatibilidade com ajustes monetários já existentes;
- a mudança exige migração para acrescentar metadados de modo e percentual nas tabelas de orçamentos.

## Segurança

- o frontend normaliza percentuais e valores antes do envio, reduzindo inconsistência no payload;
- o backend valida modo e números com `Rule::in(...)`, `numeric` e `min:0`, evitando estados inválidos triviais;
- a regra efetiva do total continua centralizada no backend, reduzindo risco de manipulação do cálculo apenas no cliente.

## Performance

- os recálculos acontecem no navegador com complexidade linear pela quantidade de itens visíveis;
- os novos campos adicionam baixo custo de armazenamento e não introduzem consultas extras nem N+1.

## Validação

- `node --check frontends/desktop/public/assets/js/orcamentos-form.js`
- `php artisan test --filter='test_budget_supports_percentual_and_monetary_adjustments'`
- `php artisan test --filter='test_orcamentos_create_page_renders_dynamic_item_reference_select_without_select2_exclusion|test_orcamentos_store_normalizes_brazilian_currency_values_before_forwarding_to_backend|test_orcamentos_store_forwards_percentual_adjustments_with_normalized_payload'`

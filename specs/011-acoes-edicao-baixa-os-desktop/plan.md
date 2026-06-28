# Implementation Plan: Ações de OS no Desktop — Dropdown, Edição e Baixa (paridade completa com o legado)

**Branch**: `011-acoes-edicao-baixa-os-desktop` | **Date**: 2026-06-27 | **Updated**: 2026-06-28 | **Spec**: [spec.md](./spec.md)

## Summary

Padronizar a coluna "Ações" de `/os` no mesmo dropdown já usado em
`/equipamentos`, e implementar as duas ações que faltam: edição completa
da OS (reaproveitando o endpoint de update já existente no backend) e a
baixa (encerramento) com paridade completa em relação ao
`OsSettlementService` do legado — status final + data de entrega +
múltiplos recebimentos (incluindo simulação de taxa de cartão por
operadora/bandeira/parcelas) + cobrança automática agendada por WhatsApp
em D+1/D+3/D+5 quando sobra saldo + follow-up opcional de retorno
pós-serviço (CRM) + notificação WhatsApp manual.

Esta entrega substitui o MVP original (status final + data de entrega + 1
recebimento) por decisão explícita do usuário após a primeira entrega:
"Quero a feature completa do legado, incluindo taxa de cartao e cobranca
agendada". O limite assumido (não revertido pelo usuário, é a única forma
sensata de não abrir um módulo inteiro novo): não constrói uma tela de
listagem/gestão de `crm_followups` — só a criação do registro pela baixa.

## Technical Context

**Language/Version**: PHP 8+ / Laravel, em `backend/` e `frontends/desktop/`.

**Primary Dependencies**: Bootstrap 5 (dropdown e dialogs já em uso),
`WhatsappMessagingService` (camada de mensageria já usada pelo módulo de
chat/inbox, reaproveitada aqui em vez de uma integração própria).

**Storage**: MySQL/MariaDB compartilhado. As tabelas de cartão, cobrança
agendada e follow-up (`financeiro_cartao_operadoras`,
`financeiro_cartao_bandeiras`, `financeiro_cartao_taxas`,
`financeiro_movimentos_cartao`, `os_cobranca_agendamentos`,
`crm_followups`) e `os_itens` já existem na produção compartilhada
(origem legada, com dados reais de taxas já cadastrados). Diferente da
suposição inicial ("nenhuma migration nova"), esta entrega formaliza as 6
primeiras como uma migration com `Schema::hasTable()` guard — no-op em
produção, mas garante que ambientes novos/CI/testes tenham o mesmo schema
sem depender de um dump externo — e adiciona `os_itens` ao schema usado
pelos testes (`BuildsLegacyErpSchema.php`) por ser puramente legada, sem
migration própria em nenhum ambiente.

**Testing**: `php artisan test` no backend e no desktop.

**Constraints**: sem acesso direto ao banco pelo desktop; reaproveitar
`OrderWorkflowService::updateStatus` para a transição de status (não
duplicar histórico/cálculo de margem); reaproveitar
`FinanceiroService::registerMovement`/`movementSummary` para cada
recebimento (não duplicar lógica de título/movimento); reaproveitar
`WhatsappMessagingService::sendSystemMessage` para o envio de WhatsApp
manual e o agendado (não criar um novo service de envio — a integração
Evolution API por trás continua sendo `IntegrationSettingsService`, só que
por dentro do driver de canal já existente); a simulação de taxa de
cartão é a única fonte de verdade para a regra "operadora obrigatória
quando forma de pagamento é cartão" — não duplicada no FormRequest.

**Scale/Scope**: 1 migration nova (6 tabelas, todas com guard); 7 Models
novos; 1 Service novo (`FinanceiroCartaoService`); 1 comando agendado
novo; `OrderClosureService` estendido (simulação de cartão, cobrança
agendada, follow-up); 1 tela do desktop reescrita como assistente de 3
etapas + 1 arquivo JS novo.

## Constitution Check

- Backend central como fonte única: toda regra de negócio da baixa
  (validação de status, simulação de taxa de cartão, cálculo de saldo,
  agendamento de cobrança, criação de follow-up) vive em `backend/`; o
  desktop só coleta o formulário (com pré-visualização client-side da taxa
  estimada, não autoritativa) e exibe o resultado.
- UX operacional e falha segura: falha no envio de WhatsApp (manual ou
  agendado) não pode impedir a baixa nem travar o comando agendado em um
  registro com erro; falha de conexão na edição/baixa não pode apagar
  dados já digitados; um recebimento em cartão sem taxa ativa rejeita a
  baixa inteira antes de qualquer gravação (sem efeito colateral parcial).
- Documentação sincronizada: contrato da API, nota de implementação e
  versionamento atualizados junto da entrega.
- Responsividade: a tela de baixa reaproveita os componentes
  (`desktop-form-card`, `summary-card`, `form-select`, breakpoints já
  existentes) em vez de criar um layout próprio; o assistente de 3 etapas
  usa apenas Bootstrap + JS vanilla já carregados (sem dependência nova).

## Project Structure

### Documentation (this feature)

```text
specs/011-acoes-edicao-baixa-os-desktop/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code (repository root)

```text
backend/
├── app/Models/
│   ├── FinanceiroCartaoOperadora.php                  # novo
│   ├── FinanceiroCartaoBandeira.php                   # novo
│   ├── FinanceiroCartaoTaxa.php                       # novo
│   ├── FinanceiroMovimentoCartao.php                  # novo
│   ├── OsCobrancaAgendamento.php                      # novo
│   ├── CrmFollowup.php                                # novo
│   └── OrderItem.php                                  # novo (os_itens, leitura)
├── app/Services/Financeiro/FinanceiroCartaoService.php # novo (simulate/findApplicableRate/buildActiveDataset)
├── app/Services/Orders/OrderClosureService.php        # estendido (recebimentos[], cartao, cobranca, follow-up)
├── app/Console/Commands/ProcessPendingOsCollections.php # novo
├── app/Http/Requests/Api/V1/CloseOrderRequest.php     # reescrito (array de recebimentos)
├── app/Http/Controllers/Api/V1/OrderController.php    # + custo_summary/retorno_padrao/cartao na resposta, invalid_card_payment
├── database/migrations/2026_06_29_000001_create_os_closure_module_tables.php # novo (6 tabelas, guard hasTable)
├── routes/console.php                                  # + schedule do comando agendado
└── tests/
    ├── Concerns/BuildsLegacyErpSchema.php              # + os_status novos, os_itens
    └── Feature/Api/V1/OrderFlowTest.php                # + testes de cartao/cobranca/follow-up/comando

frontends/desktop/
├── app/Http/Controllers/OrderController.php           # closureStore com payload de recebimentos[]
├── resources/views/orders/closure.blade.php           # reescrita: assistente de 3 etapas
├── public/assets/js/orders-closure.js                 # novo: navegação de etapas, recebimentos dinâmicos, preview de taxa
└── tests/Feature/Desktop/DesktopFrontendTest.php       # ajustado para a nova tela
```

**Structure Decision**: mantém a separação já existente; a única
estrutura nova é a migration do módulo de baixa (cartão/cobrança/CRM), que
seguiu o mesmo precedente já estabelecido pela migration do módulo
Financeiro (`2026_06_27_000001_create_financeiro_module_tables.php`).

## Phase 0 - Research Decisions

- Dropdown de ações: reaproveita as classes `os-actions-dropdown/-toggle/-menu`
  já entregues no MVP (mirror de `equipment-actions-*`); sem mudança nesta
  fase.
- Transição de status na baixa: continua delegando a
  `OrderWorkflowService::updateStatus()`; quando sobra saldo em aberto (e
  o encerramento não é "sem reparo"/"descartado"), o status aplicado é o
  intermediário `entregue_pagamento_pendente` (já existente no catálogo,
  `status_final=0`/`estado_fluxo_padrao=pausado`), preservando o status
  final desejado em `os.status_final_pendente_pagamento` para aplicar
  quando o saldo for finalmente zerado em uma baixa posterior da mesma OS.
- Simulação de taxa de cartão: porta literal da fórmula do legado
  (`FinanceiroCartaoService::simulate()` em `sistema-hml`) —
  `valor_taxa = valor_bruto * (taxa_percentual / 100) + taxa_fixa`,
  seleção de taxa por operadora + modalidade + intervalo de parcelas,
  priorizando taxa específica de bandeira sobre a genérica, com
  tie-break por intervalo de parcelas mais estreito e depois por menor id
  — roda **antes** da transação de gravação, para falhar rápido sem
  efeito colateral.
- Persistência das tabelas de cartão/cobrança/CRM: formalizadas como
  migration com `Schema::hasTable()` guard (no-op em produção, que já tem
  essas tabelas com dados reais) em vez de só simular no fixture de teste
  — mesmo precedente do módulo Financeiro. Os nomes dos índices não-únicos
  foram deixados sem nome explícito (exceto quando o nome de produção já
  inclui o prefixo da tabela, como `idx_crm_followups_origem_evento`),
  porque SQLite (usado nos testes) exige nomes de índice únicos no banco
  inteiro, e não por tabela como o MySQL de produção — nomes curtos tipo
  `cliente_id` colidiam entre tabelas diferentes. A uniqueness real de
  cada índice (confirmada via `SHOW INDEX` no banco real antes de
  escrever a migration) foi preservada; só o nome interno do índice não é
  byte-a-byte idêntico ao de produção, o que não tem efeito funcional.
- Cobrança agendada: 3 registros (D+1/D+3/D+5 às 10h) em
  `os_cobranca_agendamentos`, processados por
  `app:process-pending-os-collections` (novo, registrado em
  `routes/console.php` a cada 15 minutos — cadência não documentada no
  legado, escolha desta implementação). Reabrir a baixa da mesma OS
  cancela os agendamentos pendentes/com erro anteriores antes de criar
  (ou não, se o saldo já foi zerado) novos agendamentos.
- Notificação (manual e agendada): ambas chamam
  `WhatsappMessagingService::sendSystemMessage()` — a mesma camada de
  mensageria do módulo de chat/inbox — em vez de um wrapper próprio sobre
  `IntegrationSettingsService`. Um wrapper `sendMessage()` foi
  inicialmente adicionado a `IntegrationSettingsService` mas removido por
  ficar órfão depois que a notificação passou a usar
  `WhatsappMessagingService` (que já encapsula a mesma integração
  Evolution API por dentro do seu driver de canal).
- Follow-up de retorno: dedupe por `origem_evento` (string
  `os_retorno_agendado_{osId}_{Ymd da data prevista}`), igual ao legado —
  validado via `exists()` antes do `create()` (a unicidade é de
  aplicação, não de banco; o índice real em produção é não-único).
- Tela de edição: sem mudança nesta fase (entregue no MVP).
- Assistente de 3 etapas no desktop: HTML+JS vanilla sobre os mesmos
  componentes já usados nas outras telas do desktop (`desktop-form-card`,
  `summary-card`), navegação por etapas controlada por `d-none` (não pelo
  componente de tabs do Bootstrap, porque a navegação aqui é guiada por
  validação client-side simples antes de avançar, não por clique livre em
  abas). Pré-visualização da taxa de cartão replica em JS o mesmo
  algoritmo de seleção de taxa do backend (mesma ordem de tie-break), mas
  não é autoritativa — a simulação real sempre roda de novo no backend no
  submit.

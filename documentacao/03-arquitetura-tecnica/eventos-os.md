# Timeline de Eventos da OS (`os_eventos`)

> Criado em 2026-07-09. Feature: histórico unificado, categorizado e auditável
> de toda movimentação de uma Ordem de Serviço, exibido na seção "Histórico da
> OS" da tela de detalhe do desktop (`orders/show.blade.php` →
> `orders/_event_timeline.blade.php`).

## Conceito

Toda ação que acontece numa OS — abertura, mudança de status, criação/envio/
aprovação de orçamento, lançamentos e exclusões financeiras, PDFs gerados,
mensagens de WhatsApp enviadas, fotos, checklist, laudo/diagnóstico,
procedimentos — vira uma linha **append-only** na tabela `os_eventos`.

Regras de projeto:

1. **Writer único**: `App\Services\Orders\OrderEventService::record()` é o
   ÚNICO caminho de escrita. Nenhum código da aplicação atualiza ou exclui
   linhas desta tabela (exceção: `os:backfill-eventos` gerencia apenas as
   linhas importadas do legado, identificadas por `legacy_tabela NOT NULL`).
2. **Falha isolada**: exceção dentro de `record()` vira `Log::warning` — o
   registro de evento **nunca** quebra a ação de negócio que o emitiu.
3. **Transacional**: chamado inline nos services; quando a ação roda dentro de
   `DB::transaction`, o evento participa da mesma transação (rollback junto).
   Envios de WhatsApp emitem fora de transação, após confirmação de envio.
4. **Autoria**: `usuario_id` explícito quando o service tem o actor; fallback
   `Auth::id()` quando origem for `usuario` (ex.: caminhos genéricos do
   `FinanceiroService`, que não recebem o actor por parâmetro).

## Schema

| Coluna | Tipo | Uso |
|---|---|---|
| `os_id` | bigint | FK lógica para `os.id` |
| `categoria` | varchar(20) | `status` · `orcamento` · `financeiro` · `documento` · `mensagem` · `registro` |
| `tipo` | varchar(60) | Slug máquina — catálogo completo em `App\Models\OrderEvent` (constantes `TIPO_*`) |
| `titulo` | varchar(160) | Título humano curto |
| `descricao` | text nullable | Detalhe legível |
| `dados` | json nullable | Payload auditável (antes/depois, valores, ids); strings truncadas em ~500 chars |
| `usuario_id` | bigint nullable | Autor (null = sistema/cliente/automação) |
| `origem` | varchar(20) | `sistema` · `usuario` · `cliente` · `automacao` |
| `legacy_tabela`/`legacy_id` | nullable | Dedupe do backfill; NULL em eventos ao vivo |
| `created_at` | datetime | Preservado do legado no backfill |

Índices: `(os_id, created_at)`, `(os_id, categoria, created_at)` e UNIQUE
`(legacy_tabela, legacy_id, tipo)` (permite re-rodar o backfill sem duplicar;
`tipo` participa porque uma linha legada pode gerar 2 eventos — ex.: envio de
orçamento → `mensagem` + `documento`).

## Categorias e cores (desktop)

| Categoria | Cor | Ícone | Exemplos de tipos |
|---|---|---|---|
| Status | `#6f5afc` | bi-arrow-repeat | `os_criada`, `status_alterado`, `status_sincronizado_orcamento`, `prazo_redefinido`, `fechamento_cancelado` |
| Orçamento | `#3b82f6` | bi-file-earmark-text | `orcamento_criado/atualizado/excluido/enviado/aprovado/recusado` |
| Financeiro | `#22c55e` | bi-cash-coin | `titulo_criado/atualizado/excluido/cancelado`, `movimento_registrado`, `adiantamento_registrado`, `cobrancas_agendadas/canceladas`, `financeiro_fechamento_removido` |
| Documentos | `#f59e0b` | bi-file-earmark-pdf | `orcamento_pdf_gerado`, `fechamento_pdf_gerado` |
| Mensagens | `#14b8a6` | bi-whatsapp | `whatsapp_enviado`, `cobranca_enviada` |
| Registros | `#94a3b8` | bi-journal-text | `os_atualizada`, `dados_tecnicos_atualizados`, `procedimento_registrado`, `fotos_adicionadas`, `checklist_registrado`, `fechamento_concluido`, `retorno_agendado` |

O mapa categoria→cor/ícone/label vive num único lugar:
`frontends/desktop/resources/views/orders/_event_timeline.blade.php`.

## Pontos de emissão (backend)

- `OrderWorkflowService::createStatusHistory()` — hook central: além do write
  legado em `os_status_historico` (INTOCADO — dependência operacional do
  cancelamento de baixa), emite o evento de status para os 3 chamadores
  (criação de OS, updateStatus, updateOrder).
- `OrderWorkflowService`: updateOrder (diff de campos), diagnóstico/solução,
  procedimentos, fotos, checklist, WhatsApp de mudança de status.
- `OrderClosureService`: fechamento concluído, adiantamento/sinal, cancelamento
  de baixa (+ snapshot dos lançamentos removidos ANTES do hard delete),
  cobranças agendadas/canceladas/enviadas, PDF de fechamento, WhatsApps,
  retorno agendado, taxa de cartão da baixa.
- `FinanceiroService` (só quando `os_id > 0`): título criado/atualizado/
  excluído/cancelado, movimento registrado.
- `BudgetWorkflowService`/`BudgetApprovalService`/`BudgetOrderSyncService`
  (só quando o orçamento tem `os_id`): criado/atualizado/excluído, PDF gerado,
  enviado (+ mensagem WhatsApp), aprovado/recusado pelo cliente (origem
  `cliente`, com IP e user-agent em `dados`), sync de status (origem
  `automacao`).

Notas de decisão:
- Sync de status por orçamento é categoria **status** (o fato é uma transição);
  a causa fica em `tipo`/`dados`. A decisão do cliente é o evento `orcamento`
  separado.
- `sendOrderNotification` (push interno de staff) **não** emite — duplicaria
  todo evento sem valor de auditoria.
- Eventos de mensagem só são emitidos em envio **bem-sucedido**.

## Leitura

O detalhe da OS (`GET /api/v1/orders/{order}`) traz `eventos` (máx. 200, mais
recentes primeiro) via `OrderWorkflowService::mapEventCollection()`. Os campos
legados `historico` e `procedimentos_historico` continuam presentes e
inalterados para os demais consumidores.

A auditoria integral usa o endpoint dedicado
`GET /api/v1/orders/{order}/events`, paginado em 25, 50 ou 100 itens. Não há
corte absoluto: todos os eventos permanecem navegáveis. A consulta aceita os
filtros `category`, `origin`, `type`, `search`, `date_from` e `date_to`, sempre
depois da mesma verificação de acesso da OS (incluindo o escopo do técnico).
O retorno inclui:

- resumo atual da OS para contextualizar a trilha;
- eventos com autoria, payload técnico completo e proveniência nativa/legada;
- contagens globais por categoria, origem e tipo;
- metadados de paginação no envelope padrão da API.

No desktop, a rota `GET /os/{order}/historico` renderiza a página "Auditoria
completa da OS". O acesso fica no menu "Mais ações" do detalhe. Todo valor do
payload é renderizado com escaping do Blade; nenhum JSON auditável é injetado
como HTML bruto.

## Backfill do histórico legado

```bash
php artisan os:backfill-eventos            # importa tudo (idempotente)
php artisan os:backfill-eventos --os=123   # só uma OS
php artisan os:backfill-eventos --fresh    # apaga importados e reimporta
```

Fontes → eventos: `os_status_historico` (status, detectando criação e sync de
orçamento pelo texto da observação), `os_procedimentos_historico` (registro),
`orcamento_status_historico` (orçamento; linhas com origem `cliente` são
puladas — a fonte canônica da decisão é `orcamento_aprovacoes`),
`orcamento_envios` (mensagem + documento quando tem PDF), `orcamento_aprovacoes`
(aprovado/recusado), `financeiro` com os_id (título criado),
`financeiro_movimentos` (movimento), `os_cobranca_agendamentos` (agendada +
enviada quando `enviado_em`). `created_at` e `usuario_id` originais são
preservados. `--fresh` remove SOMENTE linhas com `legacy_tabela` preenchida —
eventos ao vivo nunca são tocados.

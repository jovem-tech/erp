# Orcamentos com revisao antes de salvar e aprovacao publica

**Data:** 2026-07-03
**Versao:** 3.5.3
**Modulo:** `frontends/desktop` + `backend`

## Contexto

O fluxo de orcamento precisava ganhar uma etapa final de revisao antes do salvamento e um caminho comercial completo para aprovacao do cliente, semelhante ao comportamento praticado no legado `sistema-hml`, mas agora centralizado no backend modular do ERP.

## Entrega

- modal de revisao no desktop ao clicar em `Salvar orcamento`, com resumo do cliente, contexto operacional, itens, resultado financeiro e observacoes;
- exibicao de pendencias que bloqueiam somente o envio para aprovacao, sem impedir o salvamento interno do orcamento;
- duas decisoes explicitas no fechamento do formulario: `Salvar sem enviar` e `Salvar e enviar para aprovacao`;
- novo endpoint autenticado `POST /api/v1/orcamentos/{budget}/send-approval` no backend central;
- geracao de PDF privado do orcamento com link publico de decisao do cliente;
- pagina publica por token para consulta, download do PDF, aprovacao e rejeicao;
- registro de envios em `orcamento_envios`, decisoes em `orcamento_aprovacoes` e trilha de status em `orcamento_status_historico`;
- reflexo minimo na OS vinculada, incluindo `orcamento_pdf`, `orcamento_aprovado` e `data_aprovacao`.

## Arquitetura e decisoes

- o backend central continua como fonte unica da regra de negocio, do token publico e do historico comercial;
- o desktop apenas orquestra a experiencia: primeiro persiste o orcamento, depois decide se dispara ou nao o fluxo de aprovacao;
- se o envio falhar por pendencia ou problema externo, o orcamento permanece salvo e o usuario recebe aviso, evitando perda de dados;
- o canal inicial adotado para o disparo e WhatsApp com PDF em anexo e link publico de aprovacao, mantendo paridade funcional com o processo de referencia do `sistema-hml`.

## Seguranca

- o PDF do orcamento permanece em storage privado;
- o acesso publico depende de `token_publico` aleatorio e expiracao vinculada a validade do orcamento;
- as acoes publicas sao limitadas por `throttle` nas rotas web;
- o desktop nao expande privilegios: o envio exige permissao de edicao de orcamentos no backend.

## Impactos

- contrato atualizado em `backend/openapi.yaml` para o endpoint de envio para aprovacao;
- nova experiencia de fechamento no formulario desktop de orcamentos;
- nova superficie publica do backend para decisao do cliente sem exigir autenticacao do ERP.

## Validacao

- `php -l frontends/desktop/app/Http/Controllers/OrcamentoController.php`
- `php -l frontends/desktop/app/Services/OrcamentoService.php`
- `node --check frontends/desktop/public/assets/js/orcamentos-form.js`
- `php artisan test --filter=BudgetFlowTest`
- `php artisan test --filter=test_orcamentos_create_page_renders_review_modal_for_save_decision`
- `php artisan test --filter=test_orcamentos_store_with_send_for_approval_dispatches_second_backend_request`
- `php artisan test --filter=test_orcamentos_store_keeps_budget_saved_when_send_for_approval_returns_warning`
- `php scripts/php/sync-agent-docs.php`

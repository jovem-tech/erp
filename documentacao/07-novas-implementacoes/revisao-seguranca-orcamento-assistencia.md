# Revisão de segurança — Fluxo de orçamento na assistência (com OS)

> Escopo: fluxo de **orçamento de equipamento já na assistência** (vinculado a OS) —
> criação/edição, envio para aprovação, link público de aprovação/rejeição, geração de
> PDF e guarda de OS encerrada. **Nenhuma alteração de código foi feita neste fluxo**;
> este documento é apenas revisão + sugestões, conforme decisão do usuário.
>
> Data: 2026-07-22 · Versão do sistema: 5.5.1.0

## Arquivos analisados

- `app/Http/Controllers/Web/BudgetPublicController.php` (link público)
- `app/Services/Budgets/BudgetApprovalService.php` (token, aprovação/rejeição, PDF)
- `app/Services/Budgets/BudgetWorkflowService.php` (create/update + guarda de OS encerrada)
- `app/Services/Budgets/BudgetOrderSyncService.php` (sincronização OS↔orçamento)
- `app/Services/Auth/AdminCredentialVerifier.php` (confirmação de admin)
- `routes/web.php` (rotas públicas + throttle)
- `resources/views/budgets/public/*` (página pública)

## Pontos fortes (já corretos)

1. **Token público forte**: `Str::random(64)` (CSPRNG do Laravel) com verificação de
   unicidade e **expiração** (`token_expira_em`, checada em `tokenExpired()`).
   Coluna `token_publico` (90 chars) comporta o token.
2. **Rate limiting** em todas as rotas públicas (`throttle:120/60/30` por minuto) — mitiga
   força bruta de token e flood de decisões.
3. **Autorização admin com defesa**: `AdminCredentialVerifier` usa `RateLimiter`
   (tentativas máximas + decay por `email|ip`), `Hash::check` para a senha e limpa o
   contador em sucesso. Exigido para editar/criar orçamento em **OS encerrada**
   (`isOrderClosed()` usa `FINANCIAL_IMPACT_CLOSURE_CODES`).
4. **Sem XSS óbvio na página pública**: as views usam apenas `{{ }}` (auto-escape); não há
   `{!! !!}` em `resources/views/budgets/`.
5. **PDF sem SSRF**: o renderer converte fontes e fotos em **data URI** (assets internos),
   evitando busca de recursos externos durante a renderização.
6. **Idempotência de decisão**: `approveByToken`/`rejectByToken` bloqueiam nova decisão
   quando o status já é `aprovado`/`pendente_abertura_os`/`rejeitado` (`already_resolved`).
7. **Download de PDF seguro**: caminho gerado no servidor a partir do orçamento (não do
   input), com `Storage::disk('local')->exists()` antes do `download()`.

## Achados e recomendações

Severidade: 🔴 alta · 🟠 média · 🟡 baixa · 🔵 informativo.

### 🟠 1. Aprovação pública sem confirmação explícita de identidade
`approve()` aceita `POST` no token sem nenhum dado que ligue quem clicou ao cliente
(qualquer um com o link aprova). O link é secreto e expira, mas vaza facilmente
(encaminhamento de WhatsApp, histórico de navegador compartilhado).
**Sugestão**: exigir uma confirmação leve antes de aprovar/rejeitar — ex.: últimos 4
dígitos do telefone do cliente ou um "aceite" com checkbox + nome digitado, registrado em
`orcamento_aprovacoes`. Aumenta o não-repúdio sem fricção relevante.

### 🟠 2. Comparação de token não é constante no tempo
`findByToken()` faz `where('token_publico', $normalized)` (igualdade no banco, não
constant-time). Com 64 chars aleatórios + throttle, o risco prático de *timing attack* é
baixo, mas é uma boa prática defensiva. **Sugestão**: manter o lookup por índice, porém
armazenar/derivar um **hash** do token (ex.: SHA-256) e comparar por hash — remove o valor
bruto do banco e neutraliza timing por completo.

### 🟡 3. Enumeração de estado via códigos HTTP distintos
`show()` retorna `410` (expirado) vs `404` (inexistente). Isso permite a um atacante
distinguir "token válido porém expirado" de "token inexistente". Impacto baixo (não revela
dados), mas facilita sondagem. **Sugestão**: padronizar em `404`/página neutra para ambos,
mantendo a mensagem amigável só quando o token confere.

### 🟡 4. Ausência de trava de concorrência na decisão pública
`approveByToken`/`rejectByToken` fazem `refresh()` dentro da transação, mas não usam
`lockForUpdate()`. Dois cliques simultâneos poderiam correr entre a checagem de status e o
`save()`. O guard `already_resolved` reduz a janela, porém não a elimina. **Sugestão**:
`Budget::where('id', …)->lockForUpdate()->first()` dentro da transação antes de decidir.

### 🟡 5. Motivo de rejeição/observação exibidos em canais internos
`motivo_rejeicao` e `resposta_cliente` (texto livre do cliente) são renderizados em
`orcamentos/show.blade.php`, histórico e eventos. Confirmar que **todos** os pontos de
exibição usam auto-escape (`{{ }}`) — inclusive PDFs e e-mails, se houver — para evitar
XSS armazenado a partir do texto do cliente. (Na página pública já está OK.)

### 🔵 6. `throttle` por IP pode ser insuficiente atrás de proxy
Os limites usam o IP visto pela aplicação. Se houver proxy/CDN, garantir `TrustProxies`
configurado para que `$request->ip()` reflita o IP real do cliente — senão o throttle
agrupa todo mundo sob o IP do proxy (ou, pior, é contornável). **Sugestão**: validar a
config de proxies confiáveis no ambiente de produção.

### 🔵 7. Guard de OS encerrada depende de sincronização correta de status
A edição em OS encerrada exige admin, mas a definição de "encerrada" vem de
`OrderStatus::FINANCIAL_IMPACT_CLOSURE_CODES`. Se o catálogo de status for alterado por
config sem atualizar essa lista, a guarda pode ser burlada. **Sugestão**: cobrir com teste
de regressão que amarre os códigos de fechamento à guarda.

## Resumo executivo

O fluxo de assistência está **sólido** nos pontos críticos (token forte, expiração,
throttle, escape, admin com rate-limit, PDF sem SSRF). Não há vulnerabilidade de severidade
alta identificada. As melhorias sugeridas são incrementais e de baixo risco de implementação
— com prioridade para **#1 (não-repúdio da aprovação)** e **#4 (trava de concorrência)**.

> Próximo passo opcional: rodar a skill `/security-review` sobre o diff desta entrega para
> uma verificação automatizada complementar.

# Implementation Plan: Lançamentos avulsos com histórico financeiro do cliente

**Branch**: `develop` | **Date**: 2026-07-05 | **Spec**: [spec.md](./spec.md)

## Summary

Persistir `avulso` no título financeiro, permitir lançamentos sem OS com ou sem cliente, impedir a combinação com OS e exibir os recebíveis vinculados em uma seção protegida da página do cliente.

## Technical Context

- **Backend**: Laravel 13, Eloquent, API REST v1 e Sanctum/RBAC.
- **Desktop**: Laravel/Blade consumindo exclusivamente a API central.
- **Storage**: tabela `financeiro`, sem nova entidade ou duplicação de histórico.
- **Testing**: PHPUnit/Pest com SQLite em memória e `Http::fake()` no desktop.
- **Performance**: paginação limitada, eager loading e índice `cliente_id, tipo, data_vencimento, id`.

## Architecture

1. Uma migration aditiva cria `financeiro.avulso` e o índice do histórico.
2. `FinanceiroService` concentra invariantes de avulso, OS, cliente e movimentos.
3. O filtro `cliente_id` é aplicado pelo model com query parametrizada.
4. `ClientController` consulta os cinco recebíveis mais recentes apenas quando a sessão possui `financeiro:visualizar`; a API revalida a mesma permissão.
5. O formulário Blade melhora a UX, mas todas as regras permanecem autoritativas no backend.

## Trade-offs

- O histórico é derivado de `financeiro`, evitando nova tabela e sincronização duplicada.
- Registros antigos permanecem `avulso=false`; não há inferência retroativa que possa reclassificar histórico.
- O vínculo manual não avulso com cliente continua compatível com o comportamento anterior; apenas o caso sem cliente/OS depende explicitamente de `avulso=true`.

## Delivery

- atualizar backend, desktop, OpenAPI e testes;
- documentar a feature e a nota de implementação;
- classificar como `minor` por conter migration aditiva;
- executar migração e rebuild de caches somente no ambiente de desenvolvimento;
- não promover para `main` nem implantar na VPS sem autorização específica.

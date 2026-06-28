# Criterios de Pronto

Uma entrega so esta pronta quando:

1. O codigo respeita a arquitetura oficial.
2. Os testes e validacoes relevantes foram executados.
3. `documentacao/` foi atualizada nos pontos impactados.
4. `backend/openapi.yaml` foi revisado se houve impacto de contrato.
5. `php scripts/php/sync-agent-docs.php` foi executado para mudancas estruturais.
6. Existe nota versionada em `documentacao/07-novas-implementacoes/` quando a entrega merece rastreio de release.

## Perguntas de fechamento

- Existe impacto em deploy Ubuntu VPS?
- Existe impacto em storage, auth, RBAC ou logs?
- Existe spec correspondente ou justificativa para nao abrir uma?

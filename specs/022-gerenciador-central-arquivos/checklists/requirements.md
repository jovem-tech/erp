# Checklist de qualidade, segurança e prontidão

## 1. Especificação

- [x] Objetivo e valor operacional definidos.
- [x] MVP e fora de escopo definidos.
- [x] User stories priorizadas e testáveis isoladamente.
- [x] Requisitos funcionais e não funcionais mensuráveis.
- [x] Critérios de sucesso definidos.
- [x] Riscos críticos e mitigação registrados.
- [x] Assumptions explícitas.
- [ ] Owners e aprovações nomeados.
- [ ] Volume real e SLA confirmados pelo inventário.
- [ ] Requisitos de SVG, anexos e compactados confirmados com produto/operação.

## 2. Arquitetura

- [x] Backend central permanece fonte de verdade.
- [x] Serviços de domínio mantêm suas regras.
- [x] Storage abstraído por Laravel/Flysystem.
- [x] Arquivo físico imutável.
- [x] Estados ortogonais separados.
- [x] Consistência storage/banco tratada por staging e reconciliação.
- [x] Conexão `chat` tratada sem FK/transação distribuída fictícia.
- [x] Vínculos usam allowlist de subject types.
- [x] Paths absolutos não entram no contrato.
- [ ] Outbox existente ou necessidade de nova tabela confirmada.
- [ ] Tipos/nomes finais validados contra convenções do código.

## 3. Compatibilidade

- [x] Rotas e payloads existentes preservados por padrão.
- [x] Fallback legado obrigatório durante transição.
- [x] Migrations apenas aditivas.
- [x] Rollback operacional não depende de drop de tabelas.
- [x] Arquivos novos no híbrido possuem requisito explícito de leitura em modo off.
- [x] Paths `private/private` atuais tratados como compatibilidade.
- [ ] Todos os campos/consumidores legados inventariados.
- [ ] URLs históricas externas inventariadas.
- [ ] Teste real de rollback por módulo aprovado.

## 4. Segurança de upload e geração

- [x] Allowlist por categoria.
- [x] MIME real + extensão + decoder/tipo esperado.
- [x] Tamanho e arquivo vazio tratados.
- [x] SHA-256 por stream.
- [x] Nome físico aleatório e storage key segura.
- [x] HTML/SVG/XML ativo sem inline.
- [x] Quarentena separada e sem serving.
- [x] Nenhum endpoint genérico de upload no MVP.
- [x] Nenhuma execução/include/descompactação pelo scanner.
- [ ] Matriz final de formatos aprovada.
- [ ] Integração antivírus avaliada sem alegação falsa de cobertura.

## 5. Segurança de acesso

- [x] Autorização pelo registro vinculado.
- [x] UUID não é autorização.
- [x] Metadados administrativos separados do conteúdo.
- [x] RBAC por ação.
- [x] `nosniff` e Content-Disposition seguro.
- [x] Cache/CSP/no-store definidos por policy.
- [x] Quarentena bloqueia usuário comum.
- [x] Ações sensíveis exigem justificativa e step-up quando aplicável.
- [ ] Threat model por módulo revisado antes do hybrid.
- [ ] Testes IDOR entre usuários/OSs/conversas aprovados.

## 6. Scanner e migração

- [x] Roots allowlist.
- [x] Canonicalização e bloqueio de escape.
- [x] Symlink não seguido.
- [x] Dry-run padrão.
- [x] Lote, profundidade, timeout e I/O limitados.
- [x] Checkpoint, heartbeat e retomada.
- [x] Idempotência.
- [x] Findings sem correção automática.
- [x] Código/assets/backups/logs/cache fora do catálogo funcional.
- [ ] Roots e exclusões reais aprovadas por ambiente.
- [ ] Evidência de dry-run sem mutação aprovada.

## 7. Banco de dados

- [x] UUID unique e ID interno separado.
- [x] Storage disk/key unique.
- [x] Índices de category/status/date.
- [x] Links idempotentes e indexados.
- [x] Aliases múltiplos.
- [x] Eventos append-only.
- [x] JSON limitado a campos não críticos de consulta.
- [x] Nenhuma deduplicação automática por hash.
- [ ] Tipos MySQL/SQLite de testes confirmados.
- [ ] EXPLAIN das queries críticas aprovado.

## 8. Performance e escalabilidade

- [x] Upload/download por stream.
- [x] Scanner fora de request.
- [x] Dashboard paginado e sem binários.
- [x] `last_accessed_at` não atualizado sincronicamente.
- [x] Jobs idempotentes com retry/backoff/lock.
- [x] Flysystem permite provider futuro.
- [ ] Benchmark p95/memória do piloto aprovado.
- [ ] Limites de fila, I/O e espaço definidos operacionalmente.

## 9. Observabilidade

- [x] Métricas de operações, bytes, erros, fallback e quarentena definidas.
- [x] Métricas de reconciliação e scanner definidas.
- [x] Alertas de disk, permissão, fila e integridade definidos.
- [x] Logs sem conteúdo/path absoluto/PII desnecessária.
- [ ] Backend real de métricas/alertas escolhido.
- [ ] Dashboards e alertas testados antes do hybrid.

## 10. Testes

- [x] Estratégia de caracterização.
- [x] Unitários de policy/path/state/idempotência.
- [x] Feature de autorização/headers.
- [x] Filesystem Linux real para symlink/permissões.
- [x] Falhas storage/banco/fila.
- [x] Conexão `chat` separada.
- [x] Segurança: XSS, MIME, traversal, IDOR, tamanho e arquivos malformados.
- [x] Migração, interrupção, retomada e rollback.
- [x] Regressão por módulo.
- [ ] Baseline executada e arquivada.
- [ ] Restore de backup testado.
- [ ] Teste de carga aprovado.

## 11. Deploy e operação

- [x] Default `off`.
- [x] Sequência off → observe → shadow → hybrid → primary.
- [x] Kill switch destrutivo separado e false.
- [x] Rollback por configuração.
- [x] Migrations aditivas permanecem em incidente.
- [x] Backup conjunto banco/storage obrigatório.
- [x] Promoção e produção exigem aprovação própria.
- [ ] Runbook implementado com comandos reais confirmados.
- [ ] Operação/suporte treinados.
- [ ] Janela e critérios de observação definidos por release.

## 12. LGPD e governança

- [x] Classificação de confidencialidade separada.
- [x] Auditoria de ator/ação/resultado.
- [x] Fingerprints em vez de IP/user-agent integrais quando possível.
- [x] Retenção destrutiva fora do MVP.
- [x] Logs não armazenam conteúdo sensível.
- [ ] Base legal e prazos de retenção aprovados com responsável competente.
- [ ] Procedimento de acesso/eliminação LGPD compatibilizado com retenção legal e referências.

## Gate final para iniciar implementação

- [ ] Projeto aprovado pelo usuário/responsável.
- [ ] Inventário autorizado.
- [ ] Scope owner e security owner definidos.
- [ ] Release A priorizada.
- [ ] Nenhuma expectativa de “implementar tudo de uma vez”.


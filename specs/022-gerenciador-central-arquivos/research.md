# Research: Gerenciador Central de Arquivos

## Objetivo da pesquisa

Registrar o contexto real consultado antes do projeto, decisões arquiteturais, alternativas descartadas e pontos que ainda precisam ser medidos. Este documento não é um inventário completo; o inventário reproduzível é o primeiro entregável da implementação.

## Fontes consultadas

- `AGENTS.md` e `.specify/memory/constitution.md`;
- `VERSIONING.md` e workflow Git multiambiente;
- `documentacao/01-fundacao/acesso-seguro-a-arquivos.md`;
- documentação da Central de Documentos da OS, assinaturas e endurecimento do chat;
- `backend/composer.json`;
- `backend/config/filesystems.php`;
- controllers, requests, services e migrations relacionados a branding, chat, fotos, PDFs, assinaturas e documentos;
- `backend/routes/api.php`, `backend/routes/web.php` e referências no OpenAPI;
- estrutura de testes existente.

## Stack e operação confirmadas

- Laravel 13, PHP declarado `^8.3`, runtime atual PHP 8.5;
- MySQL 8.4;
- Redis para cache, sessão e filas;
- Nginx/PHP-FPM, Supervisor e scheduler em Ubuntu;
- Dompdf e motor central próprio de templates;
- Sanctum e RBAC central;
- storage privado local como padrão;
- conexão adicional `chat` usada por modelos/migrations do atendimento.

## Superfície atual observada

- 24 arquivos ativos da aplicação contêm operações relevantes de storage/upload/leitura direta;
- há aproximadamente 65 referências relacionadas a documentos, anexos, PDFs, CSV e assinaturas nas rotas pesquisadas;
- fluxos persistentes incluem branding, fotos, assinaturas, anexos do chat e documentos/PDFs de OS;
- fluxos temporários incluem CSV por streaming, ZIP temporário e arquivos intermediários de PDF;
- assets legítimos incluem binários do coletor de equipamentos;
- backups residem em área própria e não pertencem ao acervo funcional.

Os números são baseline de código, não substituem o inventário de dados físicos e campos do banco.

## Findings de arquitetura

### R-001 — Laravel Storage já é a abstração correta

**Decisão**: construir `FileStorage` sobre Flysystem/Laravel Storage.

**Justificativa**: o projeto já possui discos `local`, `public`, `legacy_public` e `s3`. Criar acesso direto ao filesystem duplicaria configuração, testes e portabilidade.

**Alternativa descartada**: implementar provider próprio com `fopen`/`rename` em todos os serviços.

### R-002 — A raiz `local` já é privada

`local.root = storage/app/private`. Alguns serviços ainda usam chaves iniciadas por `private/`, produzindo `storage/app/private/private/...`.

**Decisão**: preservar as chaves atuais como aliases/namespace compatível durante o rollout. Novas chaves canônicas futuras serão relativas à raiz (`files/...`), sem repetição de `private/`.

**Risco**: “corrigir” isso movendo arquivos na primeira migration quebraria registros, PDFs e rollback.

### R-003 — O `legacy_public` já representa uma ponte

**Decisão**: o resolver central reutilizará disks allowlisted e nunca concatenará caminhos absolutos fornecidos por requisição.

**Alternativa descartada**: redirecionar o navegador diretamente para URLs do legado.

### R-004 — Serviços de domínio não devem ser substituídos

`OrderDocumentCenterService` já possui catálogo de tipos, versão, arquivo por formato, geração, links, envio, arquivamento e autorização. Assinaturas e chat também possuem regras próprias.

**Decisão**: esses serviços chamarão o núcleo de arquivos. O núcleo não decide quando gerar documento, quem assina, qual versão é atual ou qual conversa o usuário acessa.

**Risco mitigado**: impedir novo God Object e regressão de regras críticas.

### R-005 — Estado único é insuficiente

Um arquivo pode ser `active`, `missing`, `legacy` e `quarantined` em dimensões diferentes.

**Decisão**: quatro colunas de estado independentes.

**Alternativa descartada**: enum único com combinações mutuamente incompatíveis.

### R-006 — Arquivo e banco não têm transação atômica conjunta

**Decisão**: staging, promoção imutável, transação de metadados, operation key e reconciliador.

**Alternativa descartada**: envolver `Storage::put()` e Eloquent na mesma transaction e assumir atomicidade.

### R-007 — Chat exige consistência entre conexões

`mensagem_anexos` usa a conexão `chat`; o catálogo central ficará no banco principal.

**Decisão**: saga simples com UUID nullable e estados reconciliáveis. Não criar FK cruzada nem transação distribuída.

**Alternativa descartada**: mover imediatamente todas as tabelas do chat ou duplicar catálogo na conexão chat.

### R-008 — Vínculo polimórfico precisa de allowlist

**Decisão**: aliases estáveis (`order_document`, `chat_attachment`, etc.) e registry de authorizers.

**Risco**: FQCN ou tipo arbitrário vindo da API permitiria resolver classe indevida, quebrar autorização e produzir referências órfãs.

### R-009 — `last_accessed_at` síncrono cria hot row

**Decisão**: registrar eventos e produzir agregados assíncronos quando o painel precisar de último acesso.

**Alternativa descartada**: update na linha `managed_files` em todo GET/stream.

## Findings de segurança imediatos

### S-001 — Anexos amplos servidos inline

Os endpoints de chat aceitam `file|max:25600` sem allowlist de MIME/extensão e o controller de anexos usa resposta inline com MIME registrado.

**Risco**: stored XSS/conteúdo ativo no mesmo origin e comportamento inconsistente de navegador.

**Ação Release A**:

- definir allowlist por canal/finalidade;
- inline somente para raster validado e PDF conforme política;
- demais tipos como `attachment`;
- `nosniff`, nome sanitizado e testes de HTML/SVG;
- manter autorização por conversa já existente.

### S-002 — SVG de branding público inline

O request de perfil aceita SVG para logo e a rota pública entrega o arquivo inline.

**Risco**: stored XSS e conteúdo ativo público.

**Ação Release A**: remover SVG do upload do MVP ou rasterizar/sanitizar com solução comprovada. Não confiar apenas no `mimes` do Laravel.

### S-003 — Substituição delete-before-create

O serviço de branding remove o arquivo anterior antes de confirmar a gravação/otimização do novo e o disk está configurado com `throw=false`.

**Risco**: perda de disponibilidade e referência quebrada quando disco/processamento falha.

**Ação Release A**: create-before-swap; validar retorno/arquivo final; trocar referência; manter anterior para compensação; limpar depois.

### S-004 — Tipos ativos precisam de política de resposta

**Decisão**: preview e download são ações diferentes. Policy define `inline`, `attachment`, CSP/no-store e MIME final; controller não reutiliza automaticamente MIME fornecido pelo cliente.

### S-005 — Scanner amplo ameaça trust zones

O repositório distribui executáveis legítimos do coletor e mantém backups/temporários.

**Decisão**: roots por propósito e exclusão padrão de código, assets, binários de distribuição, backups, logs, cache e dependências.

## Findings de performance

### P-001 — Hash global inicial pode saturar I/O

**Decisão**: primeiro inventário coleta stat, tamanho, extensão e referência; hash completo ocorre em lotes, com prioridade baixa e janela operacional.

### P-002 — Scanner não roda em request

**Decisão**: comandos Artisan + jobs; limites de lote, profundidade, tempo e taxa; checkpoint/heartbeat.

### P-003 — Downloads devem permanecer streaming

**Decisão**: `openReadStream`/streamed response e suporte a provider; não usar `file_get_contents` para arquivos grandes.

### P-004 — Auditoria crescerá mais rápido que o catálogo

**Decisão**: tabela append-only indexada, payload mínimo, paginação e política própria. Agregados para dashboard serão cacheados/assíncronos.

### P-005 — JSON não substitui coluna pesquisável

**Decisão**: status, category, disk, hash, timestamps e IDs permanecem em colunas indexáveis. `metadata_json` recebe somente dados raros/variáveis.

## Decisões de produto e escopo

### D-001 — Não haverá upload genérico no painel

Uploads continuam entrando pelos endpoints de domínio, que conhecem autorização e finalidade. O painel central administra metadados, saúde e ações controladas.

### D-002 — Fundo de login é o piloto

**Motivos**:

- baixo volume;
- apenas imagens raster;
- teste existente de otimização;
- sem vínculo direto com registros financeiros ou assinatura;
- rollback e compatibilidade de caminho podem ser exercitados com clareza.

Logo vem depois porque também participa do contexto de PDFs.

### D-003 — CSV por streaming não vira acervo automaticamente

Relatórios/exports que não persistem hoje continuam streaming. Imports ficam em staging temporário e são eliminados por TTL após processamento seguro.

### D-004 — Retenção e deduplicação são posteriores

São capacidades destrutivas/complexas que não agregam valor ao primeiro objetivo: segurança, visibilidade e compatibilidade.

### D-005 — Antivírus é integração, não alegação

O núcleo terá contrato opcional e estado `pending/quarantined`. Até existir scanner operacional, allowlist, validação, normalização e download seguro são as defesas efetivamente disponíveis.

## Perguntas a resolver no inventário

1. Quais colunas/tabelas guardam caminhos ou nomes em todos os módulos?
2. Qual o volume, tamanho total e distribuição por categoria no dev e produção?
3. Quais arquivos possuem URL histórica usada fora do ERP?
4. Qual usuário/grupo é proprietário de cada raiz em dev e produção?
5. Backup atual inclui storage privado e teste de restauração conjunta?
6. Quais tipos de anexo do chat são necessários de fato?
7. SVG de logo é requisito comercial real ou pode ser removido?
8. Há arquivos compactados funcionais que precisam ser armazenados ou extraídos?
9. Existe outbox reutilizável no backend ou será necessária tabela específica?
10. Há multiempresa real no mesmo backend/banco ou apenas uma empresa por instalação?
11. Qual SLA/volume de download e upload em produção?
12. Quais arquivos temporários precisam sobreviver a reinício de worker?
13. Quais campos legados precisam continuar preenchidos para o `sistema-hml` em produção paralela?

## Critério para encerrar a pesquisa

A pesquisa de implementação estará completa quando as perguntas acima tiverem evidência reproduzível, o inventário estiver versionado, os riscos por módulo tiverem owner e todos os `[NEEDS VALIDATION]` do checklist estiverem resolvidos. Isso é gate para criar migrations centrais definitivas, não para aplicar correções urgentes de segurança.


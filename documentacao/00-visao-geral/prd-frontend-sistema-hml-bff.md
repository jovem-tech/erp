# PRD - Frontend sistema-hml como BFF

Atualizado em 24/06/2026.

## Resumo executivo

O `sistema-erp/frontend/sistema-hml` deve evoluir como frontend server-side/BFF integrado ao `sistema-erp/backend`. Ele preserva a experiência operacional do legado, mas não deve continuar sendo backend de negócio, não deve acessar banco em módulos migrados e não deve armazenar anexos operacionais em pasta pública.

Fluxo oficial:

```text
Browser -> sistema-erp/frontend/sistema-hml -> sistema-erp/backend/api/v1 -> banco de dados/storage/integrações
```

## Objetivo

Criar uma base documental sólida antes de qualquer implementação funcional, deixando explícitos contrato, fronteiras, riscos e critérios de aceite para a migração incremental do legado para uma arquitetura centralizada.

## Público

- Direção técnica e produto.
- Desenvolvedores do backend central.
- Desenvolvedores do BFF CodeIgniter.
- Operação, suporte e segurança.
- Revisores de futuras migrações por módulo.

## Problema

Sem governança clara, o clone do `sistema-hml` pode repetir o desenho antigo e continuar acumulando regra de negócio, queries diretas, uploads públicos, autenticação própria e integrações locais. Isso criaria dois backends concorrentes dentro do `sistema-erp`, aumentando risco de inconsistência, latência, falhas de segurança e dificuldade de manutenção.

## Proposta

Antes de qualquer nova implementação funcional:

- consolidar PRD, plano técnico e contrato resumido em `specs/008-governanca-bff-sistema-hml/`;
- reforçar o guia técnico do BFF;
- criar contrato humano obrigatório da API central;
- sincronizar `backend/openapi.yaml` com `backend/routes/api.php`;
- atualizar índices de documentação.

## Escopo desta etapa

- Documentação e contrato.
- Nenhuma rota nova.
- Nenhuma alteração em controllers, models, banco, migrations, middlewares ou comportamento runtime.
- Nenhuma migração funcional do `frontend/sistema-hml`.

## Não objetivos

- Não reimplementar autenticação no BFF nesta etapa.
- Não migrar dashboard, OS, clientes, equipamentos, usuários, grupos ou anexos nesta etapa.
- Não transformar o BFF em SPA.
- Não criar banco próprio, filas próprias ou storage operacional próprio no BFF.
- Não permitir que CodeIgniter replique regras finais de autorização, validação persistente, cálculo ou workflow.

## Política obrigatória

O `frontend/sistema-hml` pode:

- renderizar HTML server-side;
- manter sessão server-side;
- adaptar payloads para views;
- executar validações de experiência de usuário;
- chamar a API central por um cliente HTTP único.

O `frontend/sistema-hml` não pode:

- acessar banco em módulos migrados;
- duplicar regra de negócio;
- tomar decisão final de permissão;
- armazenar fotos, PDFs e anexos operacionais em `public/uploads` ou equivalente;
- expor token Bearer ao navegador;
- criar endpoints de domínio que concorram com o backend central.

## Contrato da API

A fonte técnica oficial é `backend/openapi.yaml`.

A fonte de runtime atual é `backend/routes/api.php`.

Regra de governança:

- `backend/routes/api.php` define o que existe em execução.
- `backend/openapi.yaml` deve permanecer sincronizado com o runtime.
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md` explica o contrato em linguagem humana.
- Specs documentam intenção, critérios e evolução esperada.

## Segurança

- Token Bearer fica somente em sessão server-side do BFF.
- O navegador não deve receber token persistente.
- RBAC final pertence ao backend.
- Arquivos operacionais são servidos por endpoints autenticados do backend.
- Logs devem preservar rastreabilidade sem expor segredo.
- Falhas `401` encerram sessão do frontend; falhas `403` mostram acesso negado sem tentar contornar permissão.

## Latência, atrasos e gargalos

Riscos principais:

- páginas com muitas chamadas pequenas para a API;
- dashboard e telas operacionais buscando dados em cascata;
- retries duplicando mutações;
- cache de permissões desatualizado;
- anexos grandes concorrendo com chamadas transacionais.

Diretrizes:

- usar endpoints agregados para telas densas;
- definir timeout explícito no cliente HTTP;
- aplicar retry somente em leitura idempotente;
- evitar polling agressivo;
- cachear apenas catálogos e dados estáveis, com escopo por usuário quando houver permissões;
- medir chamadas por tela antes de migrar módulos críticos.

## Estabilidade

A migração deve ocorrer por módulo:

- módulo não migrado: comportamento legado isolado, sem ampliar dependência;
- módulo migrado: leitura e escrita somente via API central;
- módulo híbrido: permitido apenas de forma temporária, documentada e com prazo de remoção.

Qualquer exceção precisa ser registrada na documentação técnica e tratada como dívida arquitetural.

## Critérios de sucesso

- O OpenAPI documenta 100% dos endpoints atuais relevantes da API v1 e não documenta rotas inexistentes.
- O PRD e os guias declaram explicitamente que o BFF não é backend de negócio.
- Novas implementações no `frontend/sistema-hml` conseguem seguir contrato único sem pesquisar decisões em conversas antigas.
- Revisões futuras conseguem bloquear duplicação de regra, banco ou storage no BFF com base documental objetiva.
- A primeira migração futura, autenticação mais `auth/me`, pode começar sem redefinir arquitetura.

## Critérios de aceite

- PRD executivo criado neste arquivo.
- Spec canônica criada em `specs/008-governanca-bff-sistema-hml/`.
- Guia BFF reforçado em `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md`.
- Contrato humano da API criado em `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`.
- `backend/openapi.yaml` alinhado com as rotas reais de `backend/routes/api.php`.
- Índices principais atualizados.
- Validação confirma ausência de rotas antigas inexistentes no contrato.

## Próxima etapa recomendada

Após esta base documental, migrar autenticação e `GET/PATCH /auth/me` no `frontend/sistema-hml`, criando um cliente HTTP único, sessão server-side segura e tratamento centralizado de `401`, `403`, `422`, `429` e falhas transitórias.

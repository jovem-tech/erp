# Spec 008 - Governança do BFF sistema-hml

Atualizado em 24/06/2026.

## Resumo

Formalizar o `frontend/sistema-hml` como frontend server-side/BFF do `sistema-erp/backend`, preservando a experiência operacional do legado enquanto a lógica de negócio, autenticação, RBAC, persistência e arquivos operacionais permanecem centralizados no backend.

Esta etapa é exclusivamente documental e contratual. Nenhuma rota nova, migração funcional ou alteração de comportamento runtime deve ser criada aqui.

## Problema

O `sistema-hml` legado concentra tela, sessão, regra de negócio, acesso a banco e manipulação de arquivos no mesmo projeto. Ao copiá-lo para `sistema-erp/frontend/sistema-hml`, existe risco de o clone continuar evoluindo como um segundo backend, criando:

- divergência de regras entre CodeIgniter e backend central;
- acesso concorrente e inconsistente ao banco;
- anexos operacionais expostos por pasta pública;
- autenticação e permissões duplicadas;
- gargalos por chamadas sem contrato ou por consultas locais não auditadas;
- aumento de latência e instabilidade por integração improvisada.

## Objetivos

- declarar o papel oficial do `frontend/sistema-hml` como frontend/BFF, não como backend de negócio;
- definir o `backend/openapi.yaml` como contrato técnico canônico da API v1;
- criar guias humanos para consumo seguro da API central;
- proteger token Bearer em sessão server-side;
- exigir um único cliente HTTP no BFF para qualquer comunicação com o backend;
- impedir acesso direto ao banco em módulos migrados;
- impedir duplicação de regra de negócio e storage operacional paralelo;
- preparar a futura migração incremental, começando por autenticação e `auth/me`.

## Não objetivos

- não criar rotas novas no backend;
- não alterar controllers, models, migrations, middlewares ou comportamento runtime;
- não migrar login, dashboard, OS, clientes, equipamentos ou arquivos nesta etapa;
- não remover código legado do clone;
- não transformar o BFF em SPA;
- não criar banco, fila, storage ou serviço de domínio próprio dentro do BFF.

## Personas

- Gestor técnico: precisa de contrato claro para validar arquitetura, risco e ordem de migração.
- Desenvolvedor backend: precisa manter `routes/api.php` e `openapi.yaml` sincronizados.
- Desenvolvedor frontend/BFF: precisa consumir a API sem tocar no banco nem duplicar regras.
- Operação/suporte: precisa entender onde autenticação, anexos, logs e erros devem ser tratados.

## Histórias principais

### US1 - Governança antes da migração

Como gestor técnico,
quero um PRD e contrato formal antes de novas implementações,
para reduzir retrabalho, inconsistência e decisões arquiteturais implícitas.

### US2 - BFF sem backend paralelo

Como desenvolvedor do `frontend/sistema-hml`,
quero regras claras sobre o que posso e não posso implementar no BFF,
para preservar a API central como fonte única de negócio.

### US3 - Contrato de API confiável

Como consumidor da API,
quero que o OpenAPI reflita as rotas reais de `backend/routes/api.php`,
para integrar frontends com previsibilidade e sem endpoint fantasma.

### US4 - Segurança operacional

Como responsável por segurança,
quero que tokens, permissões e anexos sejam tratados pelo backend central,
para evitar vazamento de credenciais, bypass de RBAC e exposição pública de arquivos.

## Requisitos funcionais

- RF01: Deve existir PRD executivo em `documentacao/00-visao-geral/prd-frontend-sistema-hml-bff.md`.
- RF02: Deve existir guia técnico em `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md`.
- RF03: Deve existir contrato humano da API em `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`.
- RF04: Deve existir contrato resumido em `specs/008-governanca-bff-sistema-hml/contracts/api-resumo.md`.
- RF05: `backend/openapi.yaml` deve documentar somente rotas existentes em `backend/routes/api.php` nesta etapa.
- RF06: A documentação deve declarar que o BFF não acessa banco em módulos migrados.
- RF07: A documentação deve declarar que o BFF não duplica regra de negócio.
- RF08: A documentação deve declarar que anexos operacionais não podem ser armazenados em pasta pública do BFF.
- RF09: A documentação deve declarar que token Bearer fica somente em sessão server-side.
- RF10: A documentação deve exigir cliente HTTP único para comunicação entre BFF e backend.
- RF11: Os índices principais devem apontar para PRD, guia BFF e contrato da API.

## Requisitos não funcionais

- RNF01: Toda comunicação entre BFF e backend deve respeitar timeout curto e retries limitados.
- RNF02: Retries automáticos só devem ser permitidos em leituras idempotentes e falhas transitórias.
- RNF03: O BFF deve evitar fan-out excessivo de chamadas por página.
- RNF04: O backend deve fornecer endpoints agregados para telas pesadas sempre que necessário.
- RNF05: Cache no BFF deve ser curto, explícito, por usuário quando houver permissões e nunca conter segredo.
- RNF06: Logs não devem expor tokens, senhas, dados sensíveis ou payloads completos de anexos.
- RNF07: Erros `401`, `403`, `404`, `422`, `429` e `5xx` devem ter tratamento padronizado pelos frontends.

## Critérios de aceite

- CA01: O PRD executivo existe e cobre objetivo, público, problema, sucesso, não objetivos, riscos e aceite.
- CA02: O guia BFF contém seção explícita de regras proibindo backend paralelo.
- CA03: O contrato humano da API define envelope, autenticação, erros, paginação, filtros, versionamento, idempotência, arquivos, cache e retry.
- CA04: O OpenAPI não contém `/auth/forgot-password`, `/auth/reset-password` ou `/auth/profile`.
- CA05: O OpenAPI contém `POST /auth/password/forgot`, `POST /auth/password/reset`, `PATCH /auth/me`, `PUT /auth/password` e `GET /dashboard/summary`.
- CA06: O OpenAPI contém notificações, OS, clientes, equipamentos, usuários, grupos, módulos e permissões conforme `routes/api.php`.
- CA07: Os índices `README.md`, `documentacao/README.md` e `documentacao/03-arquitetura-tecnica/README.md` apontam para os novos documentos.
- CA08: A validação final confirma que todos os paths do OpenAPI existem em `backend/routes/api.php`.

## Métricas de sucesso

- 100% dos endpoints documentados no OpenAPI existem no runtime atual.
- 0 rotas inexistentes documentadas no OpenAPI desta etapa.
- 100% dos documentos de arquitetura relacionados apontam para a política BFF sem backend paralelo.
- Qualquer implementação futura do `frontend/sistema-hml` consegue identificar, antes de codar, onde ficam autenticação, RBAC, persistência, arquivos, cache, retries e erros.

## Riscos

- Risco: o BFF acumular regra de negócio por conveniência durante a migração.
  Mitigação: regra documental obrigatória, revisão por módulo e cliente HTTP único.
- Risco: latência aumentar por múltiplas chamadas pequenas ao backend.
  Mitigação: endpoints agregados e orçamento de chamadas por tela.
- Risco: tokens vazarem para JavaScript ou storage do navegador.
  Mitigação: token Bearer apenas em sessão server-side.
- Risco: anexos operacionais voltarem para `public/uploads`.
  Mitigação: arquivos servidos por endpoints autenticados do backend central.
- Risco: OpenAPI ficar defasado.
  Mitigação: `routes/api.php` define runtime; `openapi.yaml` deve ser atualizado no mesmo PR.

## Assumptions

- O escopo desta etapa é documentação, PRD e contrato.
- O primeiro módulo futuro a migrar será autenticação mais `auth/me`.
- O `frontend/sistema-hml` continuará renderizando HTML server-side no curto prazo.
- A API v1 atual permanece REST com token Bearer emitido pelo backend.
- O backend central é a fonte única para regras de negócio e persistência.

## Status posterior

Em 24/06/2026, após a aprovação desta governança, foi implementada a primeira fatia funcional de autenticação BFF em `frontend/sistema-hml`, registrada em `documentacao/07-novas-implementacoes/2026-06-24-frontend-sistema-hml-auth-bff.md`.

Essa implementação não altera o escopo histórico desta spec; ela materializa a próxima etapa prevista: cliente HTTP único, login, `auth/me`, logout e recuperação de senha consumindo o backend central.

# Plan 008 - Governança do BFF sistema-hml

Atualizado em 24/06/2026.

## Escopo

Criar a base documental e contratual para que `sistema-erp/frontend/sistema-hml` evolua como frontend server-side/BFF do `sistema-erp/backend`, sem implementação funcional nova.

## Stack e fronteiras

- BFF: CodeIgniter 4 copiado para `frontend/sistema-hml`.
- Backend central: Laravel em `backend/`.
- Contrato técnico: `backend/openapi.yaml`.
- Runtime real da API: `backend/routes/api.php`.
- Documentação humana: `documentacao/`.

## Decisão arquitetural

O `frontend/sistema-hml` pode:

- renderizar páginas, formulários e layouts;
- manter sessão server-side do canal web;
- adaptar payloads para views;
- fazer orquestração de tela;
- consumir `backend/api/v1` por cliente HTTP único.

O `frontend/sistema-hml` não pode:

- acessar banco em módulos migrados;
- conter regra de negócio final;
- emitir ou validar permissões finais fora do backend;
- armazenar anexos operacionais em diretórios públicos;
- expor token Bearer ao navegador;
- criar endpoints de domínio que concorram com a API central.

## Artefatos planejados

- `specs/008-governanca-bff-sistema-hml/spec.md`: PRD formal da feature.
- `specs/008-governanca-bff-sistema-hml/plan.md`: plano técnico desta etapa.
- `specs/008-governanca-bff-sistema-hml/contracts/api-resumo.md`: contrato resumido da API para a spec.
- `documentacao/00-visao-geral/prd-frontend-sistema-hml-bff.md`: PRD executivo.
- `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md`: guia técnico BFF reforçado.
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`: contrato humano obrigatório da API.
- `backend/openapi.yaml`: contrato técnico canônico da API v1.

## Restrições obrigatórias

- Nenhuma rota nova deve ser criada.
- Nenhum controller, model, migration, middleware ou teste runtime deve ser alterado.
- O OpenAPI deve refletir apenas rotas reais de `backend/routes/api.php`.
- `backend/routes/api.php` define o runtime; `backend/openapi.yaml` documenta o contrato sincronizado.
- Specs e documentação explicam intenção, governança e critérios de evolução.

## Estratégia de latência

- Evitar fan-out por tela no BFF.
- Preferir endpoints agregados para dashboard e telas operacionais densas.
- Usar timeout explícito no cliente HTTP.
- Permitir retry apenas para leitura idempotente, com limite baixo e backoff.
- Cachear apenas dados estáveis, não sensíveis e com escopo claro por usuário quando envolver permissões.

## Estratégia de segurança

- Token Bearer fica somente na sessão server-side do BFF.
- Navegador nunca recebe token persistente.
- Permissões finais são calculadas no backend.
- Anexos são servidos por endpoints autenticados do backend.
- Logs removem ou mascaram token, senha e payload sensível.
- Erros devem ser normalizados pelo cliente HTTP único.

## Validação

1. Conferir paths do OpenAPI contra `backend/routes/api.php`.
2. Conferir ausência de rotas antigas inexistentes no OpenAPI.
3. Validar estrutura YAML do OpenAPI com ferramenta local, se disponível.
4. Revisar links Markdown dos índices atualizados.
5. Confirmar que a documentação declara que `frontend/sistema-hml` não é backend de negócio.

## Próxima etapa fora deste plano

Migrar autenticação e `GET/PATCH /auth/me` no `frontend/sistema-hml` usando o cliente HTTP único, sessão server-side e contrato definido nesta etapa.

## Status posterior

A primeira fatia dessa próxima etapa foi implementada em 24/06/2026:

- cliente HTTP único no CodeIgniter BFF;
- login via `POST /auth/login`;
- hidratação de sessão e permissões via `GET /auth/me`;
- logout via `POST /auth/logout`;
- recuperação e redefinição de senha via endpoints oficiais do backend central.

Detalhes: `documentacao/07-novas-implementacoes/2026-06-24-frontend-sistema-hml-auth-bff.md`.

# Governança do frontend sistema-hml como BFF

Data: 24/06/2026.

## Resumo

Foi criada a base documental e contratual para orientar a evolução de `frontend/sistema-hml` como frontend server-side/BFF do backend central, sem implementar novas rotas ou alterar comportamento runtime.

## Arquivos principais

- `specs/008-governanca-bff-sistema-hml/spec.md`
- `specs/008-governanca-bff-sistema-hml/plan.md`
- `specs/008-governanca-bff-sistema-hml/contracts/api-resumo.md`
- `documentacao/00-visao-geral/prd-frontend-sistema-hml-bff.md`
- `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md`
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`
- `backend/openapi.yaml`

## Decisões reforçadas

- `frontend/sistema-hml` é frontend/BFF, não backend de negócio.
- Módulos migrados não podem acessar banco pelo BFF.
- Regras de negócio, RBAC, workflow, persistência e anexos operacionais pertencem ao backend central.
- Token Bearer deve ficar somente na sessão server-side do BFF.
- Toda comunicação do BFF com o backend deve passar por cliente HTTP único.
- `backend/openapi.yaml` é o contrato técnico oficial da API v1.
- `backend/routes/api.php` continua sendo a fonte do runtime atual.

## Contrato de API

O OpenAPI foi atualizado para refletir as rotas reais de `backend/routes/api.php`, incluindo:

- autenticação e recuperação de senha;
- `auth/me`, atualização de perfil e alteração de senha;
- dashboard;
- notificações;
- ordens de serviço e anexos autenticados;
- clientes;
- equipamentos;
- usuários;
- grupos;
- módulos e permissões.

Rotas antigas inexistentes, como `/auth/forgot-password`, `/auth/reset-password` e `/auth/profile`, não fazem parte do contrato atual.

## Validação esperada

- Conferir que todos os paths do OpenAPI existem em `backend/routes/api.php`.
- Conferir que o OpenAPI não documenta rotas inexistentes nesta etapa.
- Revisar links nos índices principais.
- Confirmar que a documentação declara explicitamente que `frontend/sistema-hml` não é backend paralelo.

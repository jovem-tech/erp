# Contrato da API do Backend Central

Atualizado em 25/06/2026.

## Objetivo

Este documento define o contrato humano obrigatório para qualquer frontend que consuma `sistema-erp/backend`.

Ele complementa o contrato técnico em `backend/openapi.yaml` e deve ser lido antes de implementar integrações no `frontends/desktop`, `frontends/mobile`, `frontend/sistema-hml` ou qualquer outro canal.

## Fontes de verdade

- Runtime atual: `backend/routes/api.php`.
- Contrato técnico oficial: `backend/openapi.yaml`.
- Guia humano obrigatório: este documento.
- Governança do BFF legado: `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md`.

Política:

- `backend/routes/api.php` define quais rotas existem em execução.
- `backend/openapi.yaml` deve ficar sincronizado com `routes/api.php`.
- Nenhum frontend deve consumir rota não documentada no OpenAPI.
- Se uma tela precisar de dado não exposto, a evolução deve ocorrer no backend central.

## Base URL

Ambiente local padrão:

```text
http://127.0.0.1:8000/api/v1
```

Produção deve usar domínio HTTPS dedicado, definido por variável de ambiente do frontend consumidor.

## Envelope padrão

Todas as respostas JSON da API central usam o mesmo envelope.

Sucesso:

```json
{
  "status": "success",
  "data": {},
  "error": null,
  "meta": {
    "timestamp": "2026-06-24T10:00:00-03:00",
    "request_id": "req_..."
  }
}
```

Erro:

```json
{
  "status": "error",
  "data": null,
  "error": {
    "code": "AUTH_REQUIRED",
    "message": "Usuário não autenticado.",
    "details": null
  },
  "meta": {
    "timestamp": "2026-06-24T10:00:00-03:00",
    "request_id": "req_..."
  }
}
```

Regras para frontends:

- Nunca inferir sucesso apenas pelo HTTP status.
- Em JSON, verificar `status`.
- Usar `error.code` para fluxo técnico.
- Usar `error.message` para mensagem amigável quando apropriado.
- Registrar `meta.request_id` em logs de erro do frontend.

## Autenticação

A API usa token Bearer emitido pelo backend central.

Rotas públicas:

- `GET /health`
- `POST /auth/login`
- `POST /auth/password/forgot`
- `POST /auth/password/reset`

`POST /auth/password/forgot` aceita o campo opcional `frontend` com valores fechados:

- `desktop`

Esse campo escolhe qual URL aprovada pelo backend será usada no e-mail de redefinição. Nesta fase, o único valor aceito é `desktop`, resolvido por `FRONTEND_DESKTOP_URL`; o cliente nunca deve enviar URL livre.

Rotas autenticadas exigem:

```text
Authorization: Bearer <access_token>
```

Regras obrigatórias:

- Frontend server-side/BFF deve guardar token apenas em sessão server-side.
- SPA/mobile podem usar mecanismo próprio seguro do canal, conforme arquitetura aprovada.
- O navegador do `frontend/sistema-hml` não deve receber token Bearer persistente.
- `401` deve limpar sessão local do frontend e exigir novo login.
- `403` deve mostrar acesso negado sem tentar contornar permissão.
- `PUT /auth/password` revoga tokens e exige novo login quando `requires_relogin = true`.

Consumo atual por `frontend/sistema-hml`:

- `POST /auth/login` autentica o usuário no backend central.
- `GET /auth/me` hidrata usuário, grupo, módulos e permissões efetivas na sessão server-side do BFF.
- `POST /auth/logout` revoga o token no backend central antes de destruir a sessão local.
- `POST /auth/password/forgot` solicita recuperação de senha sem consulta local ao banco.
- `POST /auth/password/reset` redefine a senha no backend central.
- Se não houver um canal operacional de e-mail no backend central, `POST /auth/password/forgot` responde com erro operacional e não faz preview local do link.
- O token fica na sessão do CodeIgniter como dado server-side e nunca é renderizado no HTML.

## Autorização e RBAC

O backend central é responsável pela decisão final de permissão.

`GET /auth/me` retorna dados do usuário, grupo, módulos e permissões efetivas. Frontends podem usar esse retorno para montar menu e esconder ações, mas isso é apenas melhoria de UX. A proteção real fica no backend.

Frontends não devem:

- recalcular permissão efetiva;
- permitir ação bloqueada pelo backend;
- criar fallback local de perfil;
- consultar tabelas de RBAC diretamente.

## Erros

Status comuns:

| HTTP | Uso | Ação esperada no frontend |
| --- | --- | --- |
| 400 | erro genérico de requisição | mostrar mensagem e registrar contexto |
| 401 | autenticação ausente, inválida ou expirada | limpar sessão e redirecionar para login |
| 403 | permissão negada | exibir acesso negado |
| 404 | recurso inexistente | exibir estado não encontrado |
| 422 | validação ou regra de entrada | mapear campos e mensagens |
| 429 | limite de requisições | respeitar espera antes de nova tentativa |
| 500 | falha inesperada | mostrar erro genérico e registrar `request_id` |
| 503 | dependência indisponível | orientar nova tentativa posterior |

`error.details` pode conter:

- mapa de campos em validação;
- dados de retry, como `retry_after`;
- detalhes técnicos controlados.

Nunca exibir detalhes sensíveis crus ao usuário final.

## Paginação

Listagens paginadas retornam dados no `data` e paginação em `meta.pagination`.

Formato:

```json
{
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 120,
      "last_page": 8,
      "from": 1,
      "to": 15
    }
  }
}
```

Parâmetros comuns:

- `page`: página solicitada.
- `per_page`: quantidade por página, com limite de segurança definido no backend.
- `search` ou `q`: busca textual, quando suportada.

Frontends devem preservar filtros na paginação e não assumir limite maior que o backend permite.

## Filtros e ordenação

Filtros documentados no OpenAPI são os únicos garantidos.

Padrões atuais:

- `search` e `q` para busca textual em clientes, fornecedores, equipamentos e usuários.
- `status` para filtros de situação onde existir.
- `active` para usuários ativos/inativos.
- `client_id` para equipamentos de um cliente.
- `only_unread` para notificações.
- `ano`, `equip_mes` e `equip_ano` para dashboard.
- `sort` somente nos endpoints que documentam ordenação.

Se uma tela precisar de novo filtro, o backend central deve expor e documentar esse filtro.

## Versionamento

A versão atual é `/api/v1`.

Regras:

- mudanças compatíveis podem evoluir dentro da v1;
- remoção de campo, mudança de tipo ou alteração semântica relevante exige plano de compatibilidade;
- novas versões devem ter prefixo explícito, como `/api/v2`;
- frontends não devem montar URLs fora do prefixo configurado.

## Idempotência

Métodos naturalmente idempotentes:

- `GET`
- `PUT`
- `DELETE` quando a semântica do endpoint permitir

Métodos não idempotentes por padrão:

- `POST`
- `PATCH`

Política:

- retry automático é permitido apenas para leitura idempotente e falha transitória.
- não repetir `POST`, `PATCH`, upload ou alteração de status automaticamente.
- qualquer idempotência para criação futura deve ser formalizada com chave explícita, como `Idempotency-Key`, antes de uso.

## Arquivos e anexos

Arquivos operacionais devem ser servidos pelo backend central por endpoints autenticados.

Rotas atuais:

- `GET /orders/{order}/photos/{photo}`
- `GET /orders/{order}/documents/{document}`

Essas rotas podem retornar arquivo binário com `Content-Type` adequado ou envelope de erro JSON.

Regras:

- Frontends não devem expor caminho físico do arquivo.
- BFF não deve copiar anexos para pasta pública.
- Download/preview deve respeitar token e permissões.
- Falhas de arquivo devem preservar `request_id` para suporte.

## Fornecedores

O backend central expÃµe o mÃ³dulo de fornecedores em `GET /suppliers`, `POST /suppliers`, `GET /suppliers/{supplier}`, `PUT/PATCH /suppliers/{supplier}`, `PATCH /suppliers/{supplier}/encerrar`, `DELETE /suppliers/{supplier}` e `GET /suppliers/consultar-cnpj`.

Regras do contrato:

- a listagem aceita `search`, `q`, `active` e `per_page`;
- a consulta de CNPJ Ã© usada pelo desktop para auto-preenchimento;
- `fornecedores:visualizar`, `fornecedores:criar`, `fornecedores:editar`, `fornecedores:encerrar` e `fornecedores:excluir` continuam sendo validadas no backend;
- o desktop deve tratar fornecedores como fluxo operacional real, nÃ£o como item estÃ¡tico de navegaÃ§Ã£o.

## Configurações e integrações

O backend central expõe o primeiro painel operacional de integrações do desktop em:

- `GET /configuracoes/integracoes`
- `PUT /configuracoes/integracoes`
- `POST /configuracoes/integracoes/testar-conexao`
- `POST /configuracoes/integracoes/enviar-teste`
- `POST /configuracoes/integracoes/self-check-inbound`
- `GET /configuracoes/integracoes/gateway/status`
- `GET /configuracoes/integracoes/gateway/qr`
- `POST /configuracoes/integracoes/gateway/restart`
- `POST /configuracoes/integracoes/gateway/logout`
- `POST /configuracoes/integracoes/gateway/start`
- `POST /webhooks/whatsapp`

Regras do contrato:

- `configuracoes:visualizar` libera leitura do painel;
- `configuracoes:editar` libera gravação e ações de gateway;
- as respostas de settings usam o envelope padrão com `data.integration`;
- as respostas de ações usam `data.result` ou `data.gateway`, conforme a operação;
- o `self-check inbound` devolve um resultado estruturado com validação de host, token e origem;
- `POST /webhooks/whatsapp` é inbound público autenticado por `X-Webhook-Token` e não por Bearer;
- o desktop não deve replicar esse contrato localmente, apenas consumi-lo pela API central.

## Cache

Cache em frontend/BFF deve ser conservador.

Permitido:

- catálogos pequenos;
- menu resolvido;
- permissões efetivas por usuário com TTL curto;
- metadados estáveis de tela.

Proibido:

- token Bearer fora da sessão server-side aprovada;
- senha, reset token ou segredo;
- anexos sensíveis em pasta pública;
- cache global de permissões sem chave por usuário;
- cache de resposta mutável sem invalidação documentada.

Eventos que exigem invalidação:

- login, logout ou refresh com troca de usuário;
- alteração de grupo;
- alteração de permissões;
- alteração de ativo/inativo do usuário;
- resposta `401` ou `403` relevante.

## Retry, timeout e circuit breaker

Todo cliente HTTP deve definir timeout explícito.

Diretrizes:

- timeout padrão recomendado para BFF: entre 10 e 15 segundos.
- retry máximo recomendado: 1 ou 2 tentativas para `GET` idempotente.
- usar backoff curto e jitter quando disponível.
- não fazer retry em `401`, `403`, `404`, `422` ou `429` sem regra específica.
- respeitar `retry_after` quando presente.
- registrar falhas recorrentes por endpoint.

Telas críticas devem preferir mensagem degradada em vez de travar a renderização inteira.

## Observabilidade

Frontends devem registrar:

- método e path lógico;
- HTTP status;
- `error.code`;
- `meta.request_id`;
- duração aproximada;
- usuário ou sessão quando seguro e permitido.

Frontends não devem registrar:

- token Bearer;
- senha;
- reset token;
- payload completo com dados sensíveis;
- binários ou anexos.

## Contrato atual por domínio

Autenticação:

- `POST /auth/login`
- `POST /auth/password/forgot`
- `POST /auth/password/reset`
- `GET /auth/me`
- `PATCH /auth/me`
- `PUT /auth/password`
- `POST /auth/refresh`
- `POST /auth/logout`

Dashboard:

- `GET /dashboard/summary`

Resumo funcional atual do endpoint de dashboard:

- cards principais de operação;
- OS recentes;
- alertas operacionais;
- série mensal de OS abertas e entregues;
- distribuição por status/macrofase;
- resumo financeiro com faturamento do mês e faturamento do mês anterior;
- recorte por tipos de equipamento;
- carga por técnico.

Notificações:

- `GET /notifications`
- `PATCH /notifications/{notification}/read`
- `PATCH /notifications/read-all`
- `DELETE /notifications/read`

Contrato funcional atual de notificações:

- a inbox oficial consumida pelo BFF legado é `mobile_notifications`;
- o backend central retorna `id`, `tipo_evento`, `titulo`, `corpo`, `rota_destino`, `payload`, `lida_em` e `created_at`;
- `GET /notifications` também retorna `unread_count` e `last_notification_id`;
- `DELETE /notifications/read` remove somente notificações já lidas do usuário autenticado.

Ordens de serviço:

- `GET /orders`
- `GET /orders/{order}`
- `POST /orders`
- `PUT /orders/{order}`
- `PATCH /orders/{order}`
- `GET /orders/{order}/photos/{photo}`
- `GET /orders/{order}/documents/{document}`
- `PATCH /orders/{order}/status`

Clientes:

- `GET /clients`
- `POST /clients`
- `GET /clients/{client}`
- `PUT /clients/{client}`
- `PATCH /clients/{client}`

Equipamentos:

- `GET /equipments/form-data`
- `GET /equipments/models/suggestions`
- `POST /equipments/brands`
- `POST /equipments/models`
- `GET /equipments/collector/local-snapshot`
- `POST /equipments/collector/local-collect`
- `POST /equipments/collector-pairings`
- `GET /equipments/collector-pairings/{code}`
- `GET /equipments`
- `POST /equipments`
- `GET /equipments/{equipment}`
- `PUT /equipments/{equipment}`
- `PATCH /equipments/{equipment}`
- `GET /equipments/{equipment}/photos/{photo}`

Contrato funcional atual de equipamentos:

- `GET /equipments/form-data`, `GET /equipments/models/suggestions`, rotas do coletor e `GET /equipments/{equipment}/photos/{photo}` podem ser usadas tanto no contexto de criação quanto no de edição, desde que o usuário tenha a permissão operacional correspondente no backend;
- `PUT/PATCH /equipments/{equipment}` aceita `multipart/form-data` para atualizar o mesmo cadastro operacional, com campos equivalentes ao create, `fotos[]` opcionais, `existing_photo_sync`, `existing_photo_ids[]` e `foto_principal_existente_id`;
- `PUT/PATCH /equipments/{equipment}` preserva a regra de 1 a 4 fotos no estado final, remove arquivos descartados do storage privado e garante exatamente uma foto principal;
- `POST /equipments/brands` e `POST /equipments/models` continuam reservados a usuários com capacidade real de criação do catálogo; o fluxo de edição não amplia esse acesso por estar reaproveitando a mesma interface;
- `GET /equipments/form-data` entrega clientes, catálogos, defaults de `Desktop montado`, modos de senha, limite de fotos e metadados do coletor;
- `POST /equipments` aceita `multipart/form-data` com 1 a 4 fotos, define a principal pela ordem de preview e grava os arquivos em storage privado;
- `POST /equipments` devolve erro de validacao quando o cadastro inicial tenta seguir sem foto, mesmo que o desktop tenha bloqueio local previo;
- `POST /equipments/brands` e `POST /equipments/models` suportam quick-add operacional no desktop, com `tipo_id` obrigatorio para persistir o escopo do catalogo;
- no quick-add de marca, o backend usa uma ancora tecnica invisivel ao usuario quando precisa manter o vinculo `tipo -> marca` antes da criacao do primeiro modelo real, porque a tabela legada `equipamentos_catalogo_relacoes` exige `modelo_id` nao nulo;
- `GET /equipments/models/suggestions` centraliza a consulta externa de sugestões de modelo com timeout curto, cache e fallback vazio;
- `GET /equipments/collector/local-snapshot` lê o último snapshot local do coletor em `C:\JovemTechBenchCollector` e devolve os campos mapeados para o formulário;
- `POST /equipments/collector/local-collect` tenta executar o coletor local legado na própria máquina Windows e, se necessário, cai com segurança para o último snapshot disponível;
- `POST /equipments/collector-pairings` cria o código temporário do formulário atual para o fluxo remoto de apoio;
- `GET /equipments/collector-pairings/{code}` devolve o último snapshot pareado para importação assistida no desktop quando o modo remoto for usado;
- `POST /collector/snapshots` é a entrada autenticada do agente remoto de bancada e nunca deve ser chamada diretamente pelo navegador;
- `GET /equipments` e `GET /equipments/{equipment}` retornam `primary_photo_id` e `primary_photo_url` para destacar a imagem principal do ativo sem inferência no frontend;
- `GET /equipments/{equipment}` retorna `photos[]` ordenado com a foto principal primeiro, mantendo a trilha visual do equipamento durante todo o ciclo de vida;
- `GET /equipments/{equipment}/photos/{photo}` só libera a foto com autenticação válida, vínculo correto ao equipamento e permissão compatível com visualizar ou editar o ativo no fluxo autorizado.

Configurações e integrações:

- `GET /configuracoes/integracoes`
- `PUT /configuracoes/integracoes`
- `POST /configuracoes/integracoes/testar-conexao`
- `POST /configuracoes/integracoes/enviar-teste`
- `POST /configuracoes/integracoes/self-check-inbound`
- `GET /configuracoes/integracoes/gateway/status`
- `GET /configuracoes/integracoes/gateway/qr`
- `POST /configuracoes/integracoes/gateway/restart`
- `POST /configuracoes/integracoes/gateway/logout`
- `POST /configuracoes/integracoes/gateway/start`
- `POST /webhooks/whatsapp`

Usuários:

- `GET /users`
- `POST /users`
- `PUT /users/{user}`
- `PATCH /users/{user}`
- `PATCH /users/{user}/active`

Grupos e RBAC:

- `GET /groups`
- `POST /groups`
- `PUT /groups/{group}`
- `PATCH /groups/{group}`
- `DELETE /groups/{group}`
- `GET /groups/{group}/permissions`
- `PUT /groups/{group}/permissions`
- `GET /modules`
- `GET /permissions`

## Checklist para novos consumidores

Antes de integrar um frontend:

- confirme que a rota existe em `backend/openapi.yaml`;
- confirme que o cliente HTTP central do frontend trata o envelope padrão;
- confirme que token e sessão seguem a regra do canal;
- confirme que `401`, `403`, `422` e `429` têm UX definida;
- confirme que retries não repetem mutações;
- confirme que anexos não são expostos por pasta pública;
- atualize este documento e o OpenAPI se o backend evoluir.
## Financeiro - Cartões e taxas

- `GET /financeiro/cartoes`
- `POST /financeiro/cartoes/simular`
- `POST /financeiro/cartoes/operadoras`
- `PATCH /financeiro/cartoes/operadoras/{operadora}`
- `DELETE /financeiro/cartoes/operadoras/{operadora}`
- `POST /financeiro/cartoes/bandeiras`
- `PATCH /financeiro/cartoes/bandeiras/{bandeira}`
- `DELETE /financeiro/cartoes/bandeiras/{bandeira}`
- `POST /financeiro/cartoes/taxas`
- `PATCH /financeiro/cartoes/taxas/{taxa}`
- `DELETE /financeiro/cartoes/taxas/{taxa}`
- `POST /financeiro/cartoes/taxas-online`
- `PATCH /financeiro/cartoes/taxas-online/{gatewayTaxa}`
- `DELETE /financeiro/cartoes/taxas-online/{gatewayTaxa}`

Contrato funcional atual de cartões e taxas:

- `GET /financeiro/cartoes` devolve os catálogos operacionais, o resumo de operadoras/bandeiras/taxas, o conjunto mínimo para o simulador e o estado das taxas online;
- `POST /financeiro/cartoes/simular` calcula taxa total, valor líquido, percentual aplicado e previsão de recebimento para a combinação ativa de operadora, bandeira e parcelas;
- `POST /financeiro/cartoes/operadoras`, `POST /financeiro/cartoes/bandeiras` e `POST /financeiro/cartoes/taxas` mantêm o catálogo financeiro do desktop com gravação centralizada;
- `PATCH` e `DELETE` das rotas de cartões e taxas fazem desativação controlada ou atualização assistida, preservando o contrato do desktop;
- `POST /financeiro/cartoes/taxas-online` e seus `PATCH`/`DELETE` mantêm o catálogo de gateway para Pix, boleto, crédito e débito.

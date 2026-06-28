# Frontend sistema-hml como BFF

Atualizado em 24/06/2026.

## Objetivo

O diretório `frontend/sistema-hml/` guarda uma cópia segura do legado `sistema-hml` para evolução como frontend server-side/BFF integrado ao backend central de `sistema-erp/backend`.

Fluxo alvo:

```text
Browser -> frontend/sistema-hml -> backend/api/v1 -> banco de dados/storage/integrações
```

Neste modelo, o CodeIgniter 4 continua renderizando páginas, layouts, formulários e fluxos AJAX, mas deixa de acessar o banco diretamente à medida que cada módulo é migrado para consumo da API central.

## Regra central

`frontend/sistema-hml` é frontend/BFF. Ele não é backend de negócio.

A fonte de verdade para regra de negócio, autenticação, RBAC, persistência, workflow, anexos operacionais, auditoria e integrações é o `backend/`.

## Cópia inicial

A cópia foi criada a partir de `C:\xampp\htdocs\sistema-hml`, sem alterar o projeto original.

Foram excluídos da cópia:

- `.git`;
- `.env` e backups de ambiente;
- `vendor/`;
- `writable/`;
- `public/uploads/`;
- `public/assets/agents/`;
- `whatsapp-api/`;
- `documentacao/**/node_modules/`;
- logs, snapshots JSON temporários e arquivos de depuração volumosos.

Essas exclusões reduzem risco de vazamento, aceleram versionamento e evitam que runtime, anexos de cliente, binários distribuíveis e dependências instaladas sejam tratados como código-fonte do frontend.

## Responsabilidades do BFF

`frontend/sistema-hml/` deve concentrar:

- renderização HTML do canal legado;
- assets visuais e scripts de interface;
- sessão server-side do frontend;
- CSRF e proteções próprias de formulário web;
- orquestração de tela;
- adaptação de payloads para views existentes;
- normalização de mensagens para a experiência do usuário;
- chamadas HTTP para `backend/api/v1` por uma camada única de cliente de API.

## Responsabilidades do backend central

`backend/` deve concentrar:

- autenticação e emissão de tokens;
- RBAC e permissões efetivas;
- regras de negócio;
- validações persistentes;
- workflows e transições de estado;
- persistência;
- uploads e storage privado;
- entrega autenticada de fotos, PDFs e anexos;
- integrações com WhatsApp, e-mail, pagamentos e webhooks;
- logs operacionais, auditoria e rastreabilidade.

## Contrato inegociável

Estas regras são obrigatórias para qualquer módulo migrado:

- BFF não acessa banco em módulos migrados.
- BFF não duplica regra de negócio.
- BFF não toma decisão final de permissão.
- BFF não calcula workflow, status final, valores finais, descontos, garantias ou permissões efetivas.
- BFF não armazena anexos operacionais em pasta pública.
- BFF não expõe token Bearer ao navegador.
- Token Bearer fica somente em sessão server-side.
- Toda comunicação com o backend passa por um cliente HTTP único.
- Todo endpoint usado pelo BFF deve existir em `backend/openapi.yaml`.
- Toda divergência entre necessidade de tela e contrato atual deve gerar evolução do backend central, não atalho local no BFF.

## Anti-padrões proibidos

Não implementar no BFF:

- Model de domínio que leia ou escreva tabelas de negócio em módulo migrado.
- Query SQL direta para compensar endpoint ausente.
- Controller CodeIgniter atuando como API de domínio paralela.
- Upload operacional em `public/uploads`, `writable/uploads` exposto ou pasta pública equivalente.
- Validação final de regra de negócio que possa divergir do backend.
- Cache global de permissões sem chave por usuário.
- Retry automático em `POST`, `PUT`, `PATCH` ou `DELETE` sem idempotência explícita.
- Armazenamento de token em `localStorage`, `sessionStorage`, cookie legível por JavaScript ou HTML renderizado.
- Chamadas HTTP espalhadas por controllers, views ou helpers.

## Cliente HTTP único

Antes de migrar módulos, o BFF deve possuir uma camada única para comunicação com a API central.

Esse cliente deve ser responsável por:

- base URL configurável;
- envio do token Bearer a partir da sessão server-side;
- propagação de `X-Request-Id` quando existir;
- timeout explícito;
- tratamento centralizado de `401`, `403`, `404`, `422`, `429` e `5xx`;
- retries limitados apenas para leituras idempotentes;
- logs técnicos sem token, senha ou payload sensível;
- normalização do envelope `{ status, data, error, meta }`.

Controllers e views do BFF não devem chamar cURL, Guzzle, `file_get_contents` HTTP ou bibliotecas equivalentes diretamente.

## Implementação atual - autenticação e dashboard

Em 24/06/2026 foi implementada a primeira fatia funcional do BFF em `frontend/sistema-hml`, sem alterar o projeto original `C:\xampp\htdocs\sistema-hml`.

Arquivos principais:

- `frontend/sistema-hml/app/Config/ErpBackend.php`
- `frontend/sistema-hml/app/Services/ErpBackendApiClient.php`
- `frontend/sistema-hml/app/Services/ErpBackendAuthService.php`
- `frontend/sistema-hml/app/Services/ErpBackendSessionService.php`
- `frontend/sistema-hml/app/Services/ErpBackendDashboardService.php`
- `frontend/sistema-hml/app/Services/ErpBackendNotificationService.php`
- `frontend/sistema-hml/app/Controllers/Auth.php`
- `frontend/sistema-hml/app/Controllers/Admin.php`
- `frontend/sistema-hml/app/Controllers/Notificacoes.php`
- `frontend/sistema-hml/app/Filters/AuthFilter.php`
- `frontend/sistema-hml/app/Views/auth/login.php`
- `frontend/sistema-hml/app/Views/auth/reset_password.php`
- `frontend/sistema-hml/app/Views/admin/dashboard.php`

Fluxos migrados:

- login pelo backend central usando `POST /auth/login`;
- hidratação obrigatória do perfil usando `GET /auth/me`;
- sessão server-side do CodeIgniter com `user_id`, `user_nome`, `user_email`, `user_perfil`, grupo, módulos e `user_permissions`;
- logout no backend central usando `POST /auth/logout`;
- recuperação de senha usando `POST /auth/password/forgot` com `frontend=sistema-hml`;
- redefinição de senha usando `POST /auth/password/reset`;
- renderização inicial do dashboard via `GET /dashboard/summary`;
- atualização AJAX do dashboard por `admin/stats`, sempre adaptando a resposta do backend central para o payload esperado pelo legado.
- feed, leitura e limpeza de notificações do topo via backend central.

Regras aplicadas nesta fatia:

- `Auth.php` não consulta `UsuarioModel` para login, logout ou recuperação de senha.
- `AuthFilter.php` não restaura sessão via banco local.
- `Admin.php` não consulta models locais nem executa query direta para o dashboard migrado.
- `Notificacoes.php` não consulta `MobileNotificationModel` nem faz escrita local na inbox migrada.
- O token Bearer fica apenas em `erp_backend_access_token` na sessão server-side.
- O navegador não recebe token Bearer.
- O cookie persistente legado de "lembrar-me" é descartado.
- A tela de login não oferece mais "lembrar-me" nesta cópia BFF.
- Permissões usadas por `can()` e filtros legados passam a vir de `auth/me`, via `user_permissions` em sessão.
- O `fetch()` do dashboard envia `Accept: application/json` e `X-Requested-With: XMLHttpRequest` para garantir JSON limpo em ambiente `development`, sem injeção do Debug Toolbar na resposta de `admin/stats`.
- O topo envia os mesmos headers AJAX no feed e nas ações de notificações.
- O stream legado local deixou de ser a fonte da inbox migrada; nesta fase o topo usa polling controlado até existir contrato de realtime dedicado no backend central.

Variáveis de ambiente:

```text
ERP_BACKEND_API_BASE_URL=http://127.0.0.1:8000/api/v1
ERP_BACKEND_API_TIMEOUT=15
ERP_BACKEND_API_CONNECT_TIMEOUT=5
ERP_BACKEND_AUTH_DEVICE=frontend-sistema-hml
database.default.hostname=127.0.0.1
database.default.database=sistema_hml
database.default.username=root
database.default.password=
database.default.DBDriver=MySQLi
database.default.port=3306
```

Observação operacional: em produção, esses valores devem apontar para a API v1 do backend central publicada em VPS Linux (Ubuntu), preferencialmente por HTTPS interno ou domínio seguro, conforme contrato de ambiente.

O backend central também deve possuir:

```text
FRONTEND_SISTEMA_HML_URL=https://sistema.seudominio.com
```

Essa variável define a URL aprovada para links de redefinição de senha enviados ao usuário quando o BFF solicita `frontend=sistema-hml`.

## Estado atual da migração por módulo

Mapa detalhado obrigatório:

- `documentacao/03-arquitetura-tecnica/mapa-migracao-legado-frontend-sistema-hml.md`

Já pronto no BFF:

- autenticação;
- sessão server-side;
- logout;
- recuperação e redefinição de senha;
- dashboard;
- `admin/stats` do dashboard.
- notificações do topo.

Híbrido temporário, mas controlado:

- layout geral do shell;
- menu superior;
- busca global e componentes auxiliares que ainda dependem do legado.

Mais arriscado e deixado para fases seguintes:

- CRUDs completos de OS;
- fluxos com upload operacional;
- financeiro transacional;
- módulos com alto acoplamento a helpers, models e consultas locais.

Inventário técnico atual do legado remanescente:

- `105` models locais ainda presentes no clone;
- `83` migrations locais ainda presentes no clone;
- `12` controllers de API própria ainda presentes em `app/Controllers/Api/V1/`;
- `8` commands internos ainda presentes em `app/Commands/`;
- arquivos residuais de backup e pré-ajuste ainda pendentes de limpeza.

## Regra de convivência durante a migração

Enquanto o shell ainda não estiver 100% migrado, a cópia em `frontend/sistema-hml` pode manter configuração de banco local apenas para módulos ainda legados.

Essa exceção temporária não muda a regra central:

- módulo migrado não consulta banco local;
- módulo migrado não duplica regra de negócio;
- módulo migrado não cria API paralela;
- módulo migrado consome somente o backend central.

A presença de `database.default.*` no `.env` do clone não autoriza novos acessos locais em módulos já migrados. Ela existe apenas para preservar estabilidade do shell legado durante a migração incremental.

## Latência e gargalos

O frontend BFF não deve transformar uma página em dezenas de chamadas pequenas para a API. Telas pesadas, como dashboard, OS, clientes, equipamentos, financeiro e WhatsApp, precisam de endpoints agregados no backend.

Diretrizes:

- usar chamadas server-side do BFF para evitar CORS desnecessário no navegador;
- consolidar dados de tela em endpoints como `GET /api/v1/dashboard/summary` e equivalentes por módulo;
- estabelecer orçamento de chamadas por página antes de migrar telas críticas;
- aplicar timeout curto e retries controlados somente para leitura idempotente;
- cachear no BFF apenas dados estáveis e não sensíveis, como menus resolvidos, listas de status e catálogos pequenos;
- invalidar cache por usuário quando `auth/me`, grupo ou permissões mudarem;
- evitar polling agressivo;
- preferir endpoint agregado, SSE ou polling incremental onde houver atualização frequente;
- separar entrega de anexos grandes do carregamento transacional da tela quando isso reduzir bloqueios.

## Segurança

O token do backend central deve ficar somente na sessão server-side do CodeIgniter. O navegador não deve receber token Bearer persistente.

Regras obrigatórias:

- preservar CSRF no frontend para formulários web;
- traduzir `401` para encerramento de sessão do frontend;
- traduzir `403` para tela ou alerta de acesso negado;
- tratar `422` como erro de validação e exibir mensagens em pt-BR;
- respeitar `429` sem insistir em novas tentativas imediatas;
- registrar falhas de API com contexto operacional sem expor segredos em tela;
- mascarar token, senha, documentos sensíveis e payloads de anexos em logs;
- servir fotos, PDFs e anexos por endpoints autenticados do backend central;
- negar qualquer tentativa de criar bypass local de RBAC.

## Arquivos e anexos

Arquivos operacionais pertencem ao backend central.

O BFF pode:

- renderizar previews recebidos por URL autenticada;
- enviar formulários ou streams ao backend quando houver endpoint oficial;
- mostrar erros e progresso de upload na interface.

O BFF não pode:

- persistir anexos operacionais em pasta pública;
- criar caminho público direto para arquivo sensível;
- copiar fotos/PDFs do backend para servir localmente;
- depender de refresh manual da página para refletir mudança de arquivo.

## Estabilidade e migração incremental

A migração deve ser incremental por módulo, mantendo fronteira clara:

- módulo ainda não migrado: comportamento legado isolado, sem ampliar acoplamento;
- módulo migrado: leitura e escrita somente via API central;
- módulo híbrido: permitido apenas temporariamente, documentado e com prazo de remoção.

Não deve existir regra de negócio duplicada entre BFF e backend central. O BFF pode formatar payloads para view, mas decisões de negócio, permissões finais, cálculos, validações persistentes e workflows pertencem ao backend.

## Matriz de decisão por mudança

| Necessidade | Local correto | Observação |
| --- | --- | --- |
| Renderizar tela, filtros visuais e estados de UI | BFF | Sem persistência direta |
| Login, refresh, logout e `auth/me` | Backend central + BFF como consumidor | Token guardado em sessão server-side |
| Validar regra persistente | Backend central | BFF pode validar UX, nunca regra final |
| Listar dados operacionais | Backend central | BFF consome endpoint documentado |
| Criar/alterar OS, cliente, usuário ou grupo | Backend central | BFF envia payload ao contrato oficial |
| Calcular permissão efetiva | Backend central | BFF só consome resultado |
| Servir foto/PDF/anexo | Backend central | Endpoint autenticado |
| Cachear catálogo simples | BFF, com TTL | Nunca cachear segredo |
| Resolver falta de endpoint | Evoluir backend central | Não criar atalho local |

## Contrato da API

Referências obrigatórias:

- contrato técnico: `backend/openapi.yaml`;
- contrato humano: `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`;
- runtime atual: `backend/routes/api.php`.

Política oficial:

- `backend/routes/api.php` define o runtime atual;
- `backend/openapi.yaml` deve ficar sincronizado;
- specs e documentação explicam intenção, governança e critérios de evolução.

## Próximas etapas recomendadas

1. Concluir a validação funcional de login/logout/recuperação de senha em ambiente local integrado ao backend central.
2. Montar menu e permissões a partir da API central quando o layout exigir ajustes adicionais.
3. Migrar primeiro telas de leitura, começando por dashboard e listagens.
4. Migrar fluxos CRUD com retorno de payload suficiente para reatividade da UI.
5. Migrar uploads e fotos para endpoints autenticados do backend central.
6. Remover dependências legadas de banco por módulo à medida que cada tela for migrada.

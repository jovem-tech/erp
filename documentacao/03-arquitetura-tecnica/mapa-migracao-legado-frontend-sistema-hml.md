# Mapa Completo de Migração e Limpeza do `frontend/sistema-hml`

Atualizado em 24/06/2026.

## Objetivo

Este documento inventaria tudo o que ainda permanece legado dentro de `frontend/sistema-hml/` e que precisa:

1. migrar para consumo do `backend/api/v1`;
2. parar de acessar banco local quando o módulo estiver migrado;
3. sair do clone ao final da transição, para que o BFF fique limpo e não mantenha um backend paralelo embutido.

Fluxo alvo permanente:

```text
Browser -> frontend/sistema-hml -> backend/api/v1 -> banco de dados/storage/integrações
```

## Resumo executivo

Hoje o clone já tem a primeira fatia BFF funcional, mas ainda carrega uma base legada muito maior do que apenas algumas telas:

- BFF já implantado: autenticação, `auth/me`, sessão server-side, logout, recuperação/redefinição de senha, dashboard e notificações do topo;
- ainda existem `105` models locais em `app/Models/`;
- ainda existem `83` migrations locais em `app/Database/Migrations/`;
- ainda existem `12` controllers de API própria em `app/Controllers/Api/V1/`;
- ainda existem `8` commands internos em `app/Commands/`;
- ainda existem `10` arquivos residuais de backup/ajuste manual dentro de `app/`.

Conclusão objetiva: o clone ainda contém um backend legado inteiro embutido. A migração segura exige atacar não só páginas, mas também controllers, services, APIs paralelas, models, migrations, comandos e rotinas de upload local.

## Legenda de status

| Status | Significado operacional |
| --- | --- |
| `BFF pronto` | Já consome apenas o backend central no escopo migrado. |
| `Pronto para migrar` | O backend central já tem contrato suficiente para migrar sem criar backend paralelo. |
| `Parcial` | O backend central cobre parte do módulo, mas ainda faltam endpoints/contratos para remover o legado. |
| `Alto gap` | O módulo ainda depende fortemente de banco, models, storage ou integrações locais no clone. |
| `Deixar por último` | Módulo transversal ou muito acoplado; migrar só depois que os domínios-base estiverem no backend central. |
| `Limpar` | Não é só migrar tela; é remover infraestrutura legada do clone quando houver paridade. |

## Cobertura real do backend central hoje

O `backend/routes/api.php` já expõe cobertura útil para a migração incremental, mas ela ainda é seletiva.

| Domínio | Cobertura atual no backend central | Uso imediato no BFF |
| --- | --- | --- |
| Autenticação | `POST /auth/login`, `POST /auth/password/forgot`, `POST /auth/password/reset`, `GET /auth/me`, `PATCH /auth/me`, `PUT /auth/password`, `POST /auth/refresh`, `POST /auth/logout` | Login, sessão, perfil básico, troca de senha e recuperação |
| Dashboard | `GET /dashboard/summary` | Dashboard inicial e `admin/stats` |
| Notificações | `GET /notifications`, `PATCH /notifications/{id}/read`, `PATCH /notifications/read-all`, `DELETE /notifications/read` | Feed do topo, leitura, limpar lidas |
| OS | `GET /orders`, `GET /orders/{id}`, `POST /orders`, `PUT/PATCH /orders/{id}`, `PATCH /orders/{id}/status`, anexos autenticados | Base para migrar OS core |
| Clientes | `GET /clients`, `POST /clients`, `GET /clients/{id}`, `PUT/PATCH /clients/{id}` | Base para clientes core |
| Equipamentos | `GET /equipments`, `GET /equipments/{id}` | Leitura de equipamentos |
| Usuários | `GET /users`, `POST /users`, `PUT/PATCH /users/{id}`, `PATCH /users/{id}/active` | Gestão básica de usuários |
| Grupos e permissões | `GET /groups`, `POST /groups`, `PUT/PATCH /groups/{id}`, `DELETE /groups/{id}`, `GET/PUT /groups/{id}/permissions` | Gestão de grupos e RBAC |
| Catálogo RBAC | `GET /modules`, `GET /permissions` | Montagem de matriz de permissões e shell |

Tudo que não aparece nessa tabela ainda não tem contrato oficial suficiente para substituir o legado do clone sem nova evolução do backend central.

## Mapa por módulo do shell administrativo

### 1. Núcleo já migrado

| Área | Arquivos principais no clone | Situação | Observação |
| --- | --- | --- | --- |
| Autenticação e sessão | `app/Controllers/Auth.php`, `app/Filters/AuthFilter.php`, `app/Services/ErpBackendAuthService.php`, `app/Services/ErpBackendSessionService.php` | `BFF pronto` | Não usa mais `UsuarioModel` para login e sessão. |
| Dashboard | `app/Controllers/Admin.php`, `app/Services/ErpBackendDashboardService.php`, `app/Views/admin/dashboard.php` | `BFF pronto` | Consome `GET /dashboard/summary`. |
| Notificações topo | `app/Controllers/Notificacoes.php`, `app/Services/ErpBackendNotificationService.php`, `public/assets/js/navbar-notifications.js` | `BFF pronto` | Inbox oficial está no backend central. |

### 2. Módulos com backend central já útil ou quase suficiente

| Área | Arquivos principais no clone | Dependência legada ainda ativa | Cobertura atual do backend central | Status |
| --- | --- | --- | --- | --- |
| Perfil | `app/Controllers/Perfil.php`, `app/Views/perfil/index.php` | `UsuarioModel`, `LogModel`, troca de senha local, upload de foto em `uploads/usuarios` | `PATCH /auth/me` e `PUT /auth/password` já existem, mas não há endpoint oficial para foto nem contrato completo de perfil | `Parcial` |
| Grupos | `app/Controllers/Grupos.php`, `app/Views/grupos/*` | `GrupoModel`, `LogModel`, refresh local de permissões | Backend já cobre CRUD e permissões de grupo | `Pronto para migrar` |
| Usuários | `app/Controllers/Usuarios.php`, `app/Views/usuarios/*` | `UsuarioModel`, `GrupoModel`, DataTable local, exclusão local | Backend cobre listar, criar, editar e ativar/inativar; exclusão ainda não está no contrato oficial | `Parcial` |
| Clientes core | `app/Controllers/Clientes.php`, `app/Views/clientes/*` | `ClienteModel`, resumo operacional local, `ClientePortalAcessoModel`, `ClientePortalLogModel`, CRM agregado local | Backend cobre CRUD core; ainda faltam portal, importação, CNPJ, resumo operacional expandido e integrações auxiliares | `Parcial` |
| OS core | `app/Controllers/Os.php`, `app/Controllers/OsWorkflow.php`, `app/Views/os/*` | `OsModel` e dezenas de models/services locais, filtros, catálogos, anexos, checklist, precificação, cobrança, WhatsApp | Backend já cobre CRUD base, status e leitura autenticada de anexos | `Parcial` |
| Equipamentos leitura | `app/Controllers/Equipamentos.php`, `app/Views/equipamentos/*` | `EquipamentoModel`, `ClienteModel`, catálogos locais, upload/fotos locais, serviços de identidade/perfil | Backend já cobre listagem e detalhe | `Parcial` |

### 3. Módulos operacionais ainda sem contrato backend suficiente

| Área | Arquivos principais no clone | Dependência legada dominante | Status |
| --- | --- | --- | --- |
| Catálogo de equipamentos | `app/Controllers/EquipamentosTipos.php`, `EquipamentosMarcas.php`, `EquipamentosModelos.php`, `EquipamentosDefeitos.php`, `DefeitosRelatados.php` | Models e relações locais de catálogo, inclusive regras auxiliares de OS/orçamento | `Alto gap` |
| Serviços | `app/Controllers/Servicos.php`, `app/Views/servicos/*` | `ServicoModel`, importação/exportação local, busca de catálogo local | `Alto gap` |
| Estoque / peças | `app/Controllers/Estoque.php`, `app/Views/estoque/*` | `PecaModel`, movimentações e saldos locais, integração com OS | `Alto gap` |
| Contatos | `app/Controllers/Contatos.php`, `app/Views/contatos/*` | `ContatoModel`, vínculo com CRM e central de mensagens | `Alto gap` |
| Fornecedores | `app/Controllers/Fornecedores.php`, `app/Views/fornecedores/*` | `FornecedorModel`, consulta CNPJ local | `Alto gap` |
| Funcionários | `app/Controllers/Funcionarios.php`, `app/Views/funcionarios/*` | `FuncionarioModel`, dependência em OS e filtros | `Alto gap` |
| Anotações | `app/Controllers/Anotacoes.php`, `app/Views/anotacoes/*` | `AnotacaoModel`, `AnotacaoAnexoModel`, anexos locais | `Alto gap` |
| Checklists | `app/Controllers/Checklists.php`, `app/Views/checklists/*`, `app/Services/ChecklistService.php` | Execução, respostas e fotos locais | `Alto gap` |
| Conhecimento | `app/Controllers/ConhecimentoTemplates.php`, `app/Views/conhecimento/*` | Templates PDF/WhatsApp persistidos localmente | `Alto gap` |
| Vendas | `app/Controllers/Vendas.php`, `app/Views/vendas/index.php` | Ainda depende do legado e não possui contrato central atual | `Alto gap` |

### 4. Módulos pesados que devem ficar para depois dos domínios-base

| Área | Arquivos principais no clone | Dependência legada dominante | Motivo para ficar depois | Status |
| --- | --- | --- | --- | --- |
| Orçamentos | `app/Controllers/Orcamento.php`, `app/Controllers/Orcamentos.php`, `app/Views/orcamentos/*`, `app/Services/Orcamento*` | Catálogo, pacotes, aprovação cliente, PDF, WhatsApp, e-mail, conversão para OS | Depende de clientes, equipamentos, OS, serviços, peças e CRM | `Deixar por último` |
| Pacotes e precificação | `app/Controllers/PacotesServicos.php`, `app/Controllers/Precificacao.php`, `app/Services/PecaPrecificacaoService.php`, `ServicoPrecificacaoService.php` | Regras de preço, níveis de pacote, simulações e matrizes locais | Depende de estoque, serviços, orçamentos e financeiro | `Deixar por último` |
| Financeiro | `app/Controllers/Financeiro.php`, `FinanceiroConfiguracoes.php`, `FinanceiroCartoes.php`, `Cobrancas.php`, `app/Services/FinanceiroCartaoService.php`, `OsSettlementService.php` | Lançamentos, DRE, cartões, cobrança online, baixa, integrações e vínculo com OS | Alto risco de consistência, auditoria e impacto operacional | `Deixar por último` |
| CRM | `app/Controllers/Crm.php`, `app/Views/crm/*`, `app/Services/CrmService.php` | Timeline, follow-up, pipeline, campanhas, eventos, contatos e automações locais | Cruza clientes, OS, central de mensagens e WhatsApp | `Deixar por último` |
| Central de Mensagens / WhatsApp | `app/Controllers/CentralMensagens.php`, `WhatsAppWebhook.php`, `app/Services/CentralMensagensService.php`, `WhatsAppService.php`, `MensageriaService.php`, `app/Services/WhatsApp/*` | Conversas, inbound, outbound, templates, chatbot, SLA, gateway local, provedores externos | Maior acoplamento a integrações e filas operacionais | `Deixar por último` |
| Relatórios | `app/Controllers/Relatorios.php`, `app/Views/relatorios/*`, `app/Services/ViabilidadeEquipamentoService.php` | Consolida dados de vários domínios legados ao mesmo tempo | Só faz sentido depois que os domínios-base migrarem | `Deixar por último` |
| Configurações | `app/Controllers/Configuracoes.php`, `app/Views/configuracoes/index.php` | Configs de sistema, e-mail, WhatsApp, pagamentos e automações locais | Configuração só pode migrar depois das integrações centrais | `Deixar por último` |

### 5. Módulos fora do shell administrativo, mas ainda legados no clone

| Área | Arquivos principais no clone | Dependência legada dominante | Status |
| --- | --- | --- | --- |
| Portal do cliente | `app/Controllers/Portal/Auth.php`, `app/Controllers/Portal/Portal.php`, `app/Views/portal/*`, `app/Services/Pagamentos/PortalPagamentoService.php`, `app/Services/OrcamentoClienteDecisionService.php` | Login próprio do portal, consulta de OS/orçamentos/pagamentos, decisões de cliente, checkout e documentos locais | `Alto gap` |
| Webhooks de pagamento | `app/Controllers/PagamentosWebhook.php` | Processamento local de notificações de gateway | `Alto gap` |
| Site público institucional | `app/Controllers/Home.php`, `app/Views/public/*` | Parte pública, formulários de suporte, consulta de OS e páginas institucionais | `Alto gap` |
| API do site oficial | `app/Controllers/Api/Site/ClientesController.php`, `app/Services/SiteApi/ClienteLocalizacaoService.php` | Wrapper HMAC e localização local de clientes | `Alto gap` |
| Documentação e páginas de apoio | `app/Controllers/Documentacao.php`, `DesignSystem.php`, `Sessao.php` | Pouca ou nenhuma lógica de domínio, mas ainda vivem no clone | `Parcial` |

## Busca global: módulo transversal que depende de vários legados

`app/Controllers/GlobalSearch.php` e `app/Libraries/GlobalSearchService.php` ainda consultam diretamente:

- `OsModel`;
- `ClienteModel`;
- `EquipamentoModel`;
- `MensagemWhatsappModel`;
- `ServicoModel`;
- `PecaModel`;
- `AnotacaoModel`.

Conclusão: a busca global não pode ser tratada como “detalhe de UI”. Ela é um agregador de vários domínios legados e só deve migrar para o BFF limpo quando existir endpoint agregado oficial no backend central.

Status: `Deixar por último`.

## APIs paralelas embutidas no clone que precisam ser aposentadas

Hoje o clone ainda expõe uma API própria em `app/Controllers/Api/V1/`, o que é incompatível com o objetivo de não manter backend paralelo no BFF final.

Controllers ainda locais:

- `Api/V1/AuthController.php`
- `Api/V1/AgentsController.php`
- `Api/V1/UsersController.php`
- `Api/V1/ClientsController.php`
- `Api/V1/EquipmentsController.php`
- `Api/V1/OrdersController.php`
- `Api/V1/ConversationsController.php`
- `Api/V1/MessagesController.php`
- `Api/V1/NotificationsController.php`
- `Api/V1/PushSubscriptionsController.php`
- `Api/V1/RealtimeController.php`
- `Api/V1/BaseApiController.php`

Leitura técnica:

- parte dessa API nasceu antes do backend central e ainda fala com models locais;
- alguns fluxos já têm equivalente em `backend/api/v1`, mas a API do clone continua existindo;
- enquanto essa camada existir, o risco de divergência funcional continua alto.

Destino correto:

1. migrar clientes consumidores para `backend/api/v1`;
2. desativar endpoints equivalentes do clone;
3. remover essa camada do frontend final.

Status: `Limpar`.

## Services de domínio que não devem permanecer no BFF final

Os services abaixo ainda carregam regra de negócio, persistência ou integração operacional local dentro do clone:

### Domínio OS / orçamento / precificação

- `app/Services/OrcamentoService.php`
- `app/Services/OrcamentoLifecycleService.php`
- `app/Services/OrcamentoConversaoService.php`
- `app/Services/OrcamentoClienteDecisionService.php`
- `app/Services/OrcamentoPdfService.php`
- `app/Services/OrcamentoMailService.php`
- `app/Services/OsStatusFlowService.php`
- `app/Services/OsSettlementService.php`
- `app/Services/OsCobrancaAutomaticaService.php`
- `app/Services/OsPrintService.php`
- `app/Services/OsPdfService.php`
- `app/Services/OsPdfTemplateService.php`
- `app/Services/PecaPrecificacaoService.php`
- `app/Services/ServicoPrecificacaoService.php`
- `app/Services/PacoteOfertaPdfService.php`

### Domínio equipamentos / checklist / catálogos

- `app/Services/EquipamentoIdentidadeService.php`
- `app/Services/EquipamentoProfileService.php`
- `app/Services/ChecklistService.php`
- `app/Services/ViabilidadeEquipamentoService.php`

### Domínio CRM / mensagens / WhatsApp

- `app/Services/CrmService.php`
- `app/Services/CentralMensagensService.php`
- `app/Services/ChatbotService.php`
- `app/Services/WhatsAppService.php`
- `app/Services/MensageriaService.php`
- `app/Services/MetricasMensageriaService.php`
- `app/Services/WhatsApp/*`

### Domínio financeiro / portal / apoio

- `app/Services/FinanceiroCartaoService.php`
- `app/Services/Pagamentos/PortalPagamentoService.php`
- `app/Services/Pagamentos/PortalPagamentoGatewayService.php`
- `app/Services/Pagamentos/AsaasPaymentProvider.php`
- `app/Services/Pagamentos/MercadoPagoPaymentProvider.php`
- `app/Services/SiteApi/ClienteLocalizacaoService.php`
- `app/Services/RememberLoginService.php`

Regra de destino:

- service de UI/adaptação pode permanecer no BFF;
- service de regra de negócio, persistência, cálculo ou integração operacional precisa migrar para `backend/` ou ser removido.

Status: `Limpar`.

## Camadas estruturais legadas que precisam sair do clone ao final

| Camada | Quantidade atual | Problema | Destino correto |
| --- | --- | --- | --- |
| `app/Models/` | `105` arquivos | Mantêm acesso direto a tabelas de negócio no frontend | Backend central |
| `app/Database/Migrations/` | `83` arquivos | Mantêm autoridade de esquema dentro do clone | Backend central ou arquivo histórico fora do frontend |
| `app/Commands/` | `8` arquivos | Jobs e operações internas ainda acoplados ao clone | Backend central, `scripts/` ou descarte |
| `app/Controllers/Api/V1/` | `12` arquivos | API paralela dentro do BFF | Backend central |
| `app/Libraries/GlobalSearchService.php` | `1` biblioteca transversal | Busca agregada com consultas locais em vários domínios | Endpoint agregado no backend central |
| `app/Helpers/sistema_helper.php` | helper global | Mistura utilidade de UI com comportamento legado | Auditar e separar o que é só view do que é regra/processo |

Status: `Limpar`.

## Uploads e storage locais que ainda precisam sumir dos módulos migrados

Pontos críticos já identificados:

- `Perfil.php` ainda grava foto em `uploads/usuarios`;
- `Equipamentos.php` ainda grava fotos em `public/uploads/equipamentos_perfil`;
- `Os.php`, `Orcamentos.php`, `Portal/Portal.php` e módulos correlatos ainda lidam com anexos, PDFs e imagens por caminhos legados;
- anotações, checklist, WhatsApp e documentos de OS ainda dependem de arquivos locais.

Regra final:

- arquivo operacional deve sair do clone;
- entrega deve ocorrer por endpoint autenticado do backend central;
- o BFF só pode orquestrar envio e renderização.

## Resíduos técnicos já visíveis para limpeza

Arquivos residuais que não devem permanecer no BFF final:

- `app/Config/SystemRelease.php.pre_v2_15_0`
- `app/Controllers/Orcamento.php.pre_utf8_fix`
- `app/Controllers/Orcamento.php.utf8bak`
- `app/Controllers/Orcamentos.php.codex.bak_`
- `app/Controllers/Orcamentos.php.pre_utf8_fix`
- `app/Controllers/Orcamentos.php.utf8bak`
- `app/Controllers/Os.php.codex.bak_`
- `app/Controllers/Os.php.pre_utf8_fix`
- `app/Controllers/Os.php.utf8bak`
- `app/Views/os/index.php.pre_modal_close_guard`

Esses arquivos são ruído operacional e devem ser removidos assim que a etapa funcional correspondente estiver estável.

Status: `Limpar`.

## Sequência segura recomendada de migração

### Fase A: baixo risco e rápido retorno

1. Grupos
2. Usuários
3. Perfil

### Fase B: domínios-base com backend parcialmente pronto

4. Clientes core
5. Equipamentos leitura e depois escrita
6. OS core: listagem, detalhe, criação, edição, status e anexos autenticados

### Fase C: catálogos e agregadores necessários para OS/orçamentos

7. Catálogos de equipamentos
8. Serviços
9. Estoque / peças
10. Checklists
11. Busca global agregada

### Fase D: módulos acoplados à operação e receita

12. Orçamentos
13. Pacotes e precificação
14. Financeiro
15. Cobranças online

### Fase E: integrações e canais de maior risco

16. CRM
17. Central de Mensagens / WhatsApp
18. Portal do cliente
19. Site público e APIs auxiliares
20. Relatórios e configurações finais

## Critério objetivo para considerar um módulo “limpo”

Um módulo só pode ser considerado realmente migrado quando cumprir todos os pontos abaixo:

1. controller do clone não instancia mais `*Model` de domínio;
2. controller do clone não usa `Database::connect()`, `db_connect()`, `->db` nem query builder local para o módulo;
3. regras de negócio saem do clone e passam a existir no backend central;
4. uploads e anexos do módulo saem de pasta pública/local;
5. rotas auxiliares do clone deixam de funcionar como API paralela de domínio;
6. documentação do contrato no `backend/openapi.yaml` e em `documentacao/` está sincronizada;
7. se não houver mais uso local do domínio, models, migrations, commands e arquivos residuais correspondentes entram em plano de remoção.

## Decisão operacional final

Enquanto houver módulos não migrados, a conexão local do clone continuará existindo como ponte temporária. Isso não muda a regra de governança:

- o clone não deve receber novas regras de negócio locais;
- o clone não deve receber novas migrations de domínio;
- o clone não deve receber novas APIs paralelas;
- cada migração deve reduzir o legado, nunca expandi-lo.

Referências complementares:

- `documentacao/03-arquitetura-tecnica/frontend-sistema-hml-bff.md`
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`
- `backend/openapi.yaml`
- `backend/routes/api.php`

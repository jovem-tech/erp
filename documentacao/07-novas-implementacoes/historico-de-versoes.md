# Historico de versoes

## v3.4.11 - 2026-06-29

- a Gestão de Conhecimento recebeu o novo modelo ideal da assistência técnica em `/conhecimento/modelo-assistencia-tecnica`, com diagrama de leitura rápida para fila única, triagem, garantia, diagnóstico, orçamento, execução, qualidade, entrega e pós-venda;
- o fluxo destaca regras anti-gargalo como próxima ação obrigatória, SLA curto, prioridade por aging, WIP limitado e escalonamento de pendências;
- ramos especiais como garantia, aguardando peça, pagamento pendente e cancelada ficaram visíveis como saídas controladas, sem misturar exceção com produção normal;
- o modelo também passou a exibir um fluxo natural simulado do caso feliz usando os status atuais do catálogo, facilitando leitura e treinamento do caminho de entrada, reparo e entrega;
- nota de entrega criada em `documentacao/07-novas-implementacoes/2026-06-29-modelo-assistencia-tecnica-desktop.md` e versão global ajustada para `3.4.11`.

## v3.4.10 - 2026-06-29

- a página `Gestão de Conhecimento > Fluxo de Trabalho OS` passou a abrir com um mapa visual por macrofase, mostrando lanes, status, saídas permitidas e legenda operacional antes da matriz editável;
- o desktop continua consumindo o mesmo contrato da API central para statuses e transições, mas agora transforma esse catálogo em um diagrama de leitura rápida para operação e treinamento;
- nota de entrega criada em `documentacao/07-novas-implementacoes/2026-06-29-fluxo-trabalho-os-visual-desktop.md` e versão global ajustada para `3.4.10`.

## v3.4.9 - 2026-06-29

- a Nova OS passou a exibir a acao `Editar equipamento` quando ha equipamento selecionado e permissao de edicao, permitindo corrigir o ativo sem sair do wizard da OS;
- o link de edicao permanece sincronizado com o equipamento atualmente selecionado no Select2 e reaproveita a rota autenticada do desktop;
- nota de entrega criada em `documentacao/07-novas-implementacoes/2026-06-29-nova-os-editar-equipamento-selecionado.md` e versao global ajustada para `3.4.9`.

## v3.4.8 - 2026-06-29

- a miniatura do equipamento na Nova OS passou a usar a rota autenticada do desktop, evitando links quebrados para a API central e mantendo a foto visivel no Select2 e no resumo inicial;
- a busca e o prefill da OS continuam consumindo `photo_url`, mas agora esse campo aponta para `equipments.photos.show` no desktop;
- nota de entrega atualizada em `documentacao/07-novas-implementacoes/2026-06-29-nova-os-dropdown-equipamento-foto-marca-modelo.md` e versao global ajustada para `3.4.8`.

## v3.4.7 - 2026-06-29

- o cadastro embutido de equipamento agora devolve o equipamento criado para a tela da OS via `postMessage`, fecha o modal pai e seleciona automaticamente o novo item no wizard;
- o submit do iframe passou a usar envio multipart assíncrono com resposta JSON, preservando o fluxo principal sem navegar a tela inteira;
- nota de entrega criada em `documentacao/07-novas-implementacoes/2026-06-29-nova-os-modal-equipamento-retorno-iframe.md` e versao global ajustada para `3.4.7`.

## v3.4.6 - 2026-06-29

- o botao `Cancelar` do modal de novo equipamento passou a fechar o modal da Nova OS em vez de navegar para a listagem dentro do iframe;
- o iframe do cadastro embutido permanece dedicado ao formulario oficial de equipamento, enquanto o fechamento da janela continua controlado pela tela pai;
- nota de entrega atualizada em `documentacao/07-novas-implementacoes/2026-06-29-nova-os-modal-cadastro-equipamento.md` e versao global ajustada para `3.4.6`.

## v3.4.5 - 2026-06-29

- o modal `Novo equipamento` da Nova OS passou a abrir a pagina de cadastro em modo embutido, sem navbar, sidebar, footer, botao `Voltar` ou botao `Ajuda`;
- a URL do iframe passou a carregar `embedded=1`, garantindo que o cadastro de equipamento reaproveite a mesma tela oficial sem exibir chrome desnecessaria dentro do modal;
- nota de entrega atualizada em `documentacao/07-novas-implementacoes/2026-06-29-nova-os-modal-cadastro-equipamento.md` e versao global ajustada para `3.4.5`.

## v3.4.4 - 2026-06-29

- a Nova OS do desktop passou a exibir o resumo do cliente selecionado com telefone, contato, email, cidade e UF, mantendo o atalho de edicao direta quando a permissao existe;
- o campo de equipamento da OS agora mostra miniatura no dropdown e usa `marca / modelo` como fallback quando `resumo_tecnico` nao estiver cadastrado;
- quando o equipamento do cliente nao aparece na lista, a tela de OS oferece o botao `Novo equipamento`, abrindo um modal com o cadastro oficial de `equipamentos/novo` reaproveitado em iframe e com prefill de cliente;
- notas de entrega criadas em `documentacao/07-novas-implementacoes/2026-06-29-nova-os-resumo-cliente-editar.md`, `documentacao/07-novas-implementacoes/2026-06-29-nova-os-dropdown-equipamento-foto-marca-modelo.md` e `documentacao/07-novas-implementacoes/2026-06-29-nova-os-modal-cadastro-equipamento.md`, com a versao global atualizada para `3.4.4`.

## v3.4.3 - 2026-06-29

- a pagina de configuracoes do desktop foi dividida entre Integracoes e Configuracoes do Sistema, reduzindo a sensacao de sobrecarga visual nas transicoes;
- o novo menu `Configuracoes do Sistema` concentra aparencia, dados da empresa e sessao/seguranca em um bloco proprio da sidebar;
- a precificacao ganhou pagina dedicada dentro do Financeiro, com regras, catalogos e simuladores para pecas e servicos usando o backend central como fonte de verdade;
- a Nova OS passou a buscar clientes por Select2 remoto e paginado, deixando de depender da primeira pagina carregada no HTML e mantendo o resumo lateral sincronizado com a selecao;
- o backend central recebeu contrato novo de precificacao, com indexacao, salvamento e simulacao de peca/servico documentados em `backend/openapi.yaml`;
- nota de entrega criada em `documentacao/07-novas-implementacoes/2026-06-29-configuracoes-sistema-e-precificacao-financeiro-desktop.md` e versao global atualizada para `3.4.3`.

## v3.4.2 - 2026-06-28

- o relatorio de fluxo de caixa do desktop ganhou alternancia entre lista diaria e calendario mensal, preservando o mesmo periodo selecionado;
- o calendario do fluxo de caixa foi montado no Blade com base nas linhas diarias ja retornadas pelo payload do relatorio, sem mudar o contrato da API central;
- a visualizacao destaca dias fora do mes selecionado, entradas, saidas e saldo por dia, com navegacao mensal por query string;
- os badges de entradas e saidas passaram a ocultar valores zero; quando nao ha lancamentos no dia, o calendario mostra apenas um badge cinza "Sem lancamentos";
- a visualizacao em lista agora colore entradas em verde, saidas em vermelho e saldo realizado em azul, sem adicionar badges;
- a documentacao do desktop e a versao global do sistema foram atualizadas para registrar a nova leitura do fluxo de caixa.

## v3.4.1 - 2026-06-28

- o desktop recebeu o módulo financeiro `Cartões e Taxas`, com paridade funcional do legado visível em `/financeiro/cartoes`, incluindo abas operacionais, simulador de recebimento líquido, taxas online e ajuda local;
- o backend central foi integrado ao desktop para esse módulo, com rotas de operadoras, bandeiras, taxas por parcela e taxas online acessíveis somente via API central;
- a documentação técnica, o contrato humano da API, o inventário do Spec Kit e a versão global do sistema foram atualizados para refletir a entrega;
- Select2 permanece obrigatório em todos os selects visíveis do desktop, inclusive neste módulo financeiro.

## v3.4.0 - 2026-06-28

- o desktop ganhou um loader visual global de transição de página, exibido antes de navegações e submits reais para reduzir a sensação de travamento entre telas;
- a navbar do desktop deixou de carregar o resumo de notificações no render inicial; o badge e a lista agora são buscados sob demanda por uma rota same-origin autenticada, reduzindo o custo percebido na troca de páginas sem abrir mão da segurança;
- ao entrar em `/os` sem filtros, o desktop voltou a abrir a fila operacional de OS abertas por padrão, enviando `status_scope=open` só na listagem principal e mantendo consultas secundárias sem esse recorte implícito;

- a baixa de OS (`/os/{id}/baixa`) ganhou paridade completa com o legado: simulação automática de taxa de cartão por operadora/bandeira/parcelas (`FinanceiroCartaoService`, com despesa de taxa registrada automaticamente), cobrança agendada por WhatsApp em D+1/D+3/D+5 quando sobra saldo em aberto (novo comando `app:process-pending-os-collections`, status intermediário `entregue_pagamento_pendente`), e follow-up opcional de retorno pós-serviço via CRM (`crm_followups`, deduplicado por OS + data);
- a tela de baixa no desktop virou um assistente de 3 etapas (Encerramento → Financeiro → Confirmação), com recebimentos múltiplos, atalhos de preenchimento e pré-visualização client-side da taxa de cartão;
- a notificação por WhatsApp da baixa (manual e agendada) passou a usar `WhatsappMessagingService::sendSystemMessage`, a mesma camada de mensageria do módulo de chat/inbox;
- nova migration com `Schema::hasTable()` guard para as 6 tabelas do módulo (cartão/cobrança/CRM), já existentes em produção com dados reais — no-op lá, mas garante o mesmo schema em ambientes novos/CI/testes;
- nota técnica atualizada em `documentacao/07-novas-implementacoes/2026-06-27-acoes-edicao-baixa-os-desktop.md`, contrato atualizado em `backend/openapi.yaml`.

## v3.3.0 - 2026-06-27

- nova Central de Atendimento (Fase 1): inbox de WhatsApp com tempo real de verdade via Laravel Reverb, substituindo a futura dependência de um serviço externo (Chatwoot);
- banco de dados próprio e separado (`sistema_erp_chat`) para conversas/mensagens, sem impactar o banco principal do ERP;
- canal WhatsApp via Evolution API reaproveitando a integração já existente em `IntegrationSettingsService` (sem duplicar a chamada HTTP) e o webhook já existente em `/webhooks/whatsapp` (estendido, não substituído);
- vínculo automático do contato do WhatsApp com o cadastro de Cliente do ERP por telefone;
- nova app `frontends/chat` (Next.js, porta 3002), instalável como PWA desde o início (manifest, ícones, service worker);
- nota técnica em `documentacao/07-novas-implementacoes/2026-06-27-inbox-whatsapp-tempo-real.md`, contrato em `backend/openapi.yaml` (tag "Central de Atendimento").

## v3.2.1 - 2026-06-27

- a coluna "Ações" da listagem de Ordens de Serviço (`/os`) virou um dropdown no mesmo padrão já usado em `/equipamentos` (Detalhe sempre disponível; Editar e Baixa apenas para quem tem permissão `os:editar`; Baixa some quando a OS já está encerrada/cancelada);
- edição completa de OS chegou ao desktop (`/os/{id}/editar`), reaproveitando o endpoint `PATCH /api/v1/orders/{order}` que já existia;
- baixa de OS (MVP) chegou ao backend e ao desktop: `GET/POST /api/v1/orders/{order}/closure` encerram a OS com status final + data de entrega + lançamento financeiro (reaproveitando `OrderWorkflowService::updateStatus` e `FinanceiroService::registerMovement`) e notificação WhatsApp manual opcional (reaproveitando `IntegrationSettingsService::sendTestMessage`, com falha de envio que não desfaz a baixa);
- simulação de taxa de cartão, cobrança agendada e follow-up de retorno via CRM (presentes no `OsSettlementService` do legado) ficam fora de escopo desta entrega, documentados como trabalho futuro;
- nota técnica em `documentacao/07-novas-implementacoes/2026-06-27-acoes-edicao-baixa-os-desktop.md`, contrato atualizado em `specs/004-os-mobile-flow/contracts/orders-api.md` e `backend/openapi.yaml`.

## v3.1.25 - 2026-06-26

- a listagem de Ordens de Serviço do desktop (`/orders`) ganhou paridade operacional com o painel do legado: foto principal do equipamento, cliente com link de WhatsApp, resumo curto do equipamento, datas com cor de prazo/atraso, status do orçamento vinculado e breakdown financeiro (Total/Recebido/Saldo);
- `GET /api/v1/orders` passou a expor esses campos e os filtros `grupo_macro`, `data_abertura_de`, `data_abertura_ate`, `valor_min` e `valor_max`, resolvendo orçamento e financeiro em lote por página (sem aumentar consultas conforme a quantidade de OS exibidas);
- o desktop ganhou um bloco "Filtros avançados" colapsável para técnico, macrofase e intervalos de data/valor, com degradação segura quando o usuário não tem permissão para os catálogos auxiliares;
- nota tecnica criada em `documentacao/07-novas-implementacoes/2026-06-26-paridade-painel-os-desktop.md`, com o contrato atualizado em `specs/004-os-mobile-flow/contracts/orders-api.md` e `backend/openapi.yaml`;
- correção relacionada: `EquipmentWorkflowService::resolvePhotoAccess()` ganhou fallback para `sistema-hml/public/uploads/equipamentos_perfil/` quando a foto principal de um equipamento importado do legado não existe no storage privado novo (mesmo padrão já usado para fotos de OS).

## v3.1.24 - 2026-06-26

- o desktop Laravel ganhou o painel `Configurações > Integrações`, com foco em WhatsApp, Evolution API, gateway local/Linux e webhook de entrada;
- o backend central passou a expor o contrato de integrações em `/api/v1/configuracoes/integracoes`, incluindo teste de conexão, envio de teste, self-check inbound, status do gateway, QR code, restart, logout e start;
- o webhook de WhatsApp entrou como rota inbound autenticada por `X-Webhook-Token`, mantendo o desktop fora do acesso direto ao caminho físico dos arquivos;
- a documentação do desktop, do contrato da API central e do histórico de versões foi atualizada para registrar o novo painel e seu contrato operacional;
- a versão oficial do sistema foi elevada para `3.1.24`.

## v3.1.23 - 2026-06-26

- o modulo de orcamentos comerciais entrou como fluxo operacional real no backend central e no desktop Laravel
- o backend passou a expor `/api/v1/orcamentos` com listagem, detalhamento, criacao, edicao, exclusao e `form-data` para os catalogos do formulario
- o desktop ganhou listagem, criacao, edicao, detalhe e ajuda local para orcamentos, preservando a composicao visual do legado e usando a API central como unica fonte de dados
- a documentacao do backend, do desktop, da arquitetura tecnica e do historico de entregas foi atualizada para registrar o novo modulo

## v3.1.22 - 2026-06-26

- o menu lateral do desktop passou a ignorar rotas nao registradas antes de montar links e breadcrumbs, evitando `RouteNotFoundException` em telas que compartilham a sidebar com modulos ainda nao disponiveis
- a navegacao do desktop agora filtra itens e subitens ausentes com base em `Route::has()`, sem alterar permissoes nem a estrutura visual do menu
- atualizacao documental feita para registrar a correcao de robustez da sidebar e o ajuste de versionamento do desktop

## v3.1.21 - 2026-06-26

- módulos de serviços e estoque de peças passaram a existir como fluxos operacionais reais no backend central e no desktop Laravel
- o backend central passou a expor `/api/v1/servicos` e `/api/v1/estoque`, com listagem, detalhe, cadastro, edição, encerramento, exclusão, exportação CSV, modelo de importação e importação em lote
- o estoque também ganhou movimentações operacionais, consulta de baixo estoque e sincronização de dados para o módulo de movimentos
- o desktop recebeu telas operacionais completas para serviços e estoque, com os mesmos contratos de navegação, ações e filtros do legado
- a sidebar e a busca completa do desktop foram alinhadas para incluir os novos módulos sem quebrar a hierarquia visual
- a documentação do backend, do desktop e da arquitetura foi atualizada para refletir os novos contratos

## v3.1.20 - 2026-06-26

- modulo de fornecedores agora saiu do placeholder do desktop e virou fluxo operacional real no `backend/` e no `frontends/desktop/`
- o backend central passou a expor `/api/v1/suppliers`, incluindo listagem, cadastro, edicao, encerramento, exclusao e consulta de CNPJ via provedores publicos
- o desktop ganhou listagem, cadastro, edicao, encerramento, exclusao, busca e auto-preenchimento de CNPJ para fornecedores, integrados ao menu `Pessoas`
- a documentacao da API central, do backend e do desktop foi atualizada para refletir o novo contrato do modulo

## v3.1.19 - 2026-06-26

- `resumo_tecnico` do equipamento passou a ser truncado em 255 caracteres antes de persistir, evitando erro de `Data too long` no cadastro e na edicao sem alterar o schema
- teste de regressao adicionado para garantir que o update de equipamento continue funcionando mesmo com muitos campos tecnicos preenchidos
- validacao mantida no backend e no desktop sem impacto no fluxo de fotos ou no coletor
## v3.1.18 - 2026-06-25

- limpeza do `frontend/sistema-hml`: encerrado o processo de desenvolvimento da porta 8081 (autorizado); um segundo processo gemeo na porta 8082 foi descoberto durante a limpeza e, por decisao do responsavel pelo sistema, foi deixado rodando — o residuo vazio (`public/`) em `sistema-erp/frontend/` continua presente por causa disso, sem impacto funcional (conteudo real ja preservado em `_arquivo-sistema-hml-removido-2026-06-25/` desde a Fase 2)
- testes automatizados reais no `frontends/mobile` pela primeira vez: `vitest` + `@testing-library/react`, 18 testes cobrindo `session.ts` (sessao em `localStorage`, expiracao) e `api.ts` (`apiLogout`, `ApiError`) — inclui teste de regressao para o bug exato da duplicacao de `apiLogout` corrigido na Fase 1
- criada a skill `$sistema-erp-auditoria-independente` em `.agents/skills/`, com checklist de verificacao, institucionalizando a regra de nunca aceitar "corrigido"/"concluido" sem checar contra o codigo real — registrada em `AGENTS.md`

## v3.1.17 - 2026-06-25

- fila assincrona real no backend: criadas tabelas `jobs`/`failed_jobs`, `FrontendPasswordResetNotification` passou a `implements ShouldQueue`, template `infra/linux/supervisor-queue-worker.conf` criado para o worker em producao (`.env.production` ja usava `QUEUE_CONNECTION=redis`)
- removida a opcao `frontend=sistema-hml` da recuperacao de senha (`ForgotPasswordRequest`, `FrontendPasswordResetNotification`, `config/services.php`), consistente com a descontinuacao do app
- middleware `ForceHttps` registrado em `bootstrap/app.php` (estava escrito mas nunca executava); HSTS e redirect HTTP->HTTPS agora ativos em producao, sem efeito em ambiente local
- avaliado o indice/full-text na busca de clientes: indices em `nome_razao`/`cpf_cnpj`/`telefone1` ja existem; reescrita para full-text foi deliberadamente adiada (incompatibilidade de sintaxe MySQL x SQLite nos testes, e volume atual de ~1300 clientes nao justifica o risco agora)
- mobile: corrigida a parte segura da CSP (`style-src` sem `unsafe-inline`, confirmado via build de producao que nao ha estilo inline real); `script-src` mantido com `unsafe-inline` de proposito — o App Router do Next.js hidrata via script inline real, removendo sem nonce quebraria o app
- mobile: breakpoints `960px`/`640px` realinhados para `992px`/`768px`; removido `src/pages/` morto (so `_app.tsx`/`_document.tsx` sem rotas reais)
- achado da auditoria sobre `package-lock.json` ausente foi uma falsa alarme: o projeto usa `pnpm` (README ja documentava isso) e `pnpm-lock.yaml` esta presente

## v3.1.16 - 2026-06-25

- descontinuado o `frontend/sistema-hml/` (clone do legado evoluindo como BFF): arquivado fora do projeto ativo, junto com os 18 scripts administrativos/debug isolados na Fase 0 da auditoria, em `_arquivo-sistema-hml-removido-2026-06-25/` (fora de `sistema-erp/`)
- removidas as referencias ativas ao `sistema-hml` em `AGENTS.md`, `documentacao/04-governanca-ai/operacao-para-agentes.md`, `README.md` e nas skills locais de governanca; documentacao historica sobre o BFF foi mantida como registro, mas marcada como descontinuada
- decisao e contexto completo registrados em `documentacao/07-novas-implementacoes/2026-06-25-descontinuacao-frontend-sistema-hml.md`

## v3.1.15 - 2026-06-25

- adicionada a acao `Editar` na listagem e no detalhe de equipamentos, respeitando `equipamentos:editar`
- criada a rota operacional `/equipamentos/{id}/editar`, reaproveitando o mesmo layout e a mesma estrutura do cadastro de equipamento
- backend central passou a aceitar `PUT/PATCH /equipments/{equipment}` com sincronizacao de fotos existentes, novas e principal final em storage privado
- rotas auxiliares de formulario, sugestoes, coletor e foto privada foram ajustadas para o contexto de edicao sem ampliar indevidamente os quick-adds de catalogo
- cobertura de regressao adicionada no backend e no desktop para update multipart, fotos retidas e reaproveitamento do formulario
- documentacao, OpenAPI e nota de entrega atualizados para orientar proximas IAs no fluxo de edicao operacional

## v3.1.14 - 2026-06-25

- auditoria completa do sistema-erp (arquitetura, seguranca, escalabilidade, padronizacao e documentacao); nota geral 3,5/10, registrada em `documentacao/07-novas-implementacoes/2026-06-25-auditoria-completa-e-correcoes-de-seguranca.md`
- isolados 18 scripts administrativos/debug sem autenticacao do `frontend/sistema-hml` (5 deles dentro de `public/`, executaveis direto via HTTP)
- corrigido path Windows hardcoded nas mensagens de erro do coletor local em `backend/app/Services/EquipmentWorkflowService.php`
- reaplicada a remocao de `token_recuperacao`/`token_expiracao` do `$fillable` em `backend/app/Models/User.php`
- corrigida duplicacao de `apiLogout`/refresh em `frontends/mobile/src/lib/api.ts` e removido `api-types.ts` morto; mobile volta a compilar sem erro de type-check

## v3.1.13 - 2026-06-25

- corrigido Select2 duplo-inicializado no campo Cliente do cadastro de equipamentos (`select2Ready` nao era marcado pelo init customizado)
- corrigido fundo transparente dos modais de cadastro rapido (`.modal-content.modal-shell` com especificidade maior) e adicionada transicao de entrada/saida mais elegante
- corrigida area de recorte de foto pequena demais (`equipment-crop-image` com `height` explicito em vez de so `max-height`)
- adicionado log de erros global no desktop (`DesktopUi.logError`/`sanitizeForLog`, `window.onerror`, `unhandledrejection`) com sanitizacao de dados sensiveis antes de imprimir no console
- adicionado handler de exceção `Throwable` como fallback final em ambos os `bootstrap/app.php` (backend e desktop), evitando vazar trace/arquivo em respostas AJAX quando `APP_DEBUG=true`
- adicionados campos de contato secundario ao cadastro rapido de cliente em Equipamentos

## v3.1.12 - 2026-06-25

- foto do equipamento passou a ser obrigatoria no cadastro inicial, com bloqueio no desktop antes do submit e validacao redundante no backend
- `GET /equipments` e `GET /equipments/{equipment}` passaram a expor `primary_photo_id` e `primary_photo_url` para destacar a imagem principal do ativo
- a listagem de equipamentos agora exibe a miniatura principal na primeira coluna da tabela
- o detalhe do equipamento agora exibe a foto principal no canto superior direito do contexto operacional
- o desktop reescreve o acesso das fotos para a rota same-origin `equipamentos/{equipment}/fotos/{photo}`, evitando acesso direto do browser a URL privada da API central
- cobertura de regressao adicionada no backend e no desktop para obrigatoriedade da foto, renderizacao do destaque visual e submit multipart

## v3.1.11 - 2026-06-25

- quick-add de marca e modelo no cadastro de equipamentos passou a exigir o `tipo_id` selecionado, preservando o escopo do catalogo entre recargas e novos acessos
- `POST /equipments/brands` agora grava o vinculo `tipo -> marca` mesmo antes de existir um modelo real do usuario naquele tipo
- causa raiz identificada e documentada: a tabela legada `equipamentos_catalogo_relacoes` exige `modelo_id` nao nulo, entao o vinculo de marca ficava apenas em memoria no JavaScript e se perdia fora da sessao atual
- o backend passou a usar uma ancora tecnica inativa `__CATALOG_BRAND_SCOPE__` para manter compatibilidade com o schema legado sem exibir modelos falsos ao usuario
- `POST /equipments/models` passou a persistir explicitamente o vinculo `tipo -> marca -> modelo` no catalogo relacional
- regressões cobertas no backend e no desktop para garantir o repasse de `tipo_id` e a gravacao das relacoes
- playbook operacional criado em `documentacao/04-governanca-ai/playbooks/catalogo-equipamentos-vinculo-rapido.md`

## v3.1.10 - 2026-06-25

- correcao do cliente HTTP multipart do desktop no fluxo de criacao de equipamentos com fotos
- causa raiz identificada e documentada: `frontends/desktop/app/Services/ApiClient.php` reutilizava `baseRequest()` com `->asJson()` tambem nas requisicoes com `attach()`, produzindo corpo multipart com header `application/json`
- o backend passava a receber os campos obrigatorios como ausentes ao anexar foto, gerando erros `validation.required` para `cliente_id` e `tipo_id` mesmo com a UI preenchida
- criado `baseMultipartRequest()` dedicado, sem `->asJson()`, e aplicado ao request principal e ao retry apos refresh de token
- teste de regressao adicionado para garantir `multipart/form-data` sem `application/json` no submit com foto
- playbook criado em `documentacao/04-governanca-ai/playbooks/upload-multipart-com-header-json.md`

## v3.1.9 - 2026-06-25

- correcao do modal de cadastro rapido de cliente em `equipamentos/novo`, que aparentava estar ativo, mas nao executava nenhuma acao ao clicar em `Cadastrar cliente`
- causa raiz identificada e documentada: `@stack('modals')` estava sendo renderizado depois dos scripts no layout base do desktop, entao `equipments-create.js` tentava vincular listeners antes de `#quickClientModal`, `#quickClientForm` e `#quickClientSubmit` existirem no DOM
- `frontends/desktop/resources/views/layouts/app.blade.php` passou a renderizar `@stack('modals')` antes dos scripts globais e dos scripts especificos da pagina
- teste de regressao adicionado para garantir que o modal de cliente seja renderizado no HTML antes de `equipments-create.js` e `clients-form.js`
- playbook de diagnostico criado em `documentacao/04-governanca-ai/playbooks/modal-desktop-sem-resposta.md` para orientar futuras IAs em incidentes parecidos

## v3.1.8 - 2026-06-24

- regra de negocio: equipamento do tipo `Notebook` e sempre `OEM / fabricante`, nunca `Desktop montado` (essa modalidade so existe para o tipo `Desktop`)
- `equipamentos/novo` passou a travar o campo `Modalidade` em `OEM / fabricante` (campo desabilitado) quando o tipo selecionado e `Notebook`, tanto na renderizacao inicial quanto ao trocar o `Tipo` pela UI do Select2
- a marca/modelo genericos de `Desktop montado` deixaram de aparecer como opcao para `Notebook` na cascata `tipo -> marca -> modelo`, tanto no SSR quanto no `equipments-create.js`
- `EquipmentWorkflowService` forca `desktop_modalidade = 'oem'` para qualquer equipamento `Notebook` no backend, mesmo que o cliente envie `montado` diretamente pela API
- `StoreEquipmentRequest` passou a exigir `marca_id` e `modelo_id` reais para `Notebook` (a isencao de catalogo automatico ficou restrita ao tipo `Desktop`)
- cobertura de teste adicionada no backend (`EquipmentCreationTest`) e no frontend desktop (`DesktopFrontendTest`) para a regra de notebook sempre OEM

## v3.1.7 - 2026-06-24

- correcao do mesmo bug de vinculacao de eventos no dashboard: os filtros `Ano`, `Mes do equipamento` e `Ano do equipamento` usavam `addEventListener('change', ...)` em selects controlados por Select2 e nao recarregavam os widgets ao trocar a selecao pela UI
- `dashboard.js` passou a vincular esses filtros com `jQuery(...).on('change', ...)` quando o jQuery esta disponivel, com fallback para `addEventListener` apenas se o jQuery nao estiver carregado
- item de "Acao pendente" do registro do Select2 obrigatorio encerrado

## v3.1.6 - 2026-06-24

- correcao do bloqueio indevido do campo `Marca` no cadastro de equipamentos: ao selecionar o `Tipo` pela interface do Select2, o campo de marca permanecia desabilitado
- causa raiz identificada e documentada: o Select2 notifica alteracao de valor via `jQuery(...).trigger('change')`, evento que nao chega a listeners registrados com `addEventListener('change', ...)` da API nativa do DOM
- `equipments-create.js` passou a vincular os eventos de `tipo`, `marca`, `modalidade desktop` e `cliente` por um helper que usa `jQuery(...).on(...)` quando disponivel, restaurando a cascata `tipo -> marca -> modelo` ao usar o mouse/teclado pela UI do Select2
- regra de vinculacao de eventos em selects controlados por Select2 documentada em `07-novas-implementacoes/2026-06-24-select2-obrigatorio-desktop.md` para evitar a repeticao do mesmo erro em outros formularios

## v3.1.5 - 2026-06-24

- cascata do cadastro de equipamentos ajustada para respeitar `tipo -> marca -> modelo` usando a tabela de relacoes do backend em runtime
- quick-add e importacao de snapshot passaram a preservar o contexto do tipo selecionado sem alterar a estrutura do banco
- seletor de cliente do cadastro de equipamentos mantido em Select2 com lista pre-carregada do backend e busca local estavel
- grade de senha por desenho ficou oculta por padrao e agora aparece somente ao acionar `Mostrar desenho`
- documentacao do Select2 e do cadastro de equipamentos atualizada para refletir o novo comportamento

## v3.1.3 - 2026-06-24

- padronizacao Select2-first no `frontends/desktop` para todos os selects visiveis, com tema `Bootstrap 5` e helper compartilhado
- reinit compartilhado para selects em modais e offcanvas por meio de `window.DesktopUi.refreshSelect2()`
- mensagens de busca do Select2 ajustadas para `pt-BR`, mantendo a experiencia consistente com o idioma oficial do sistema

## v3.1.2 - 2026-06-24

- grupo `Pessoas` adicionado a secao comercial da sidebar do desktop, com submenus para `Clientes`, `Fornecedores` e `Equipe Tecnica`
- suporte a navegacao aninhada no menu lateral com expansao leve e estados ativos por subrota
- inclusao das rotas iniciais para `Fornecedores` e `Equipe Tecnica` no frontend desktop

## v3.1.1 - 2026-06-24

- alinhamento do coletor do cadastro de equipamentos ao fluxo local do legado em `C:\JovemTechBenchCollector`
- inclusao dos endpoints de leitura e execucao local do coletor no backend central
- correcao da migration `equipment_collector_pairings` para compatibilidade com o schema real de `usuarios`

## v3.1.0 - 2026-06-24

- cadastro completo de equipamentos no desktop com abas `Informacoes`, `Cor` e `Fotos`
- quick-add de cliente, marca e modelo
- integracao com camera, galeria, Cropper.js e fotos privadas


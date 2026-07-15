# Changelog — Sistema ERP Jovem Tech

## v4.8.0.0 — 2026-07-15 13:15
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Cancelar um título vinculado a uma OS já encerrada ("Equipamento Entregue") passa a exigir motivo (Reparo sem sucesso entregue ao cliente / Erro de cobrança / Fechamento inadvertido) + confirmação de administrador (reaproveitando o AdminCredentialVerifier já usado em "Cancelar baixa" e na edição de orçamento de OS encerrada), com consequência automática no status da OS conforme o motivo: devolvido_sem_reparo, entregue_pagamento_pendente (cancelando também as cobranças automáticas via WhatsApp que estavam agendadas), ou reversão completa da baixa (mesma lógica de "Cancelar baixa"). UI em duas telas dentro do mesmo modal (motivo → credenciais), com um único POST no final. Exclusão (hard delete) de qualquer lançamento passa a exigir as mesmas credenciais de administrador sempre — antes bastava um confirm() do navegador, mesmo para títulos já pagos, sem checar nada; e fica totalmente bloqueada quando a OS vinculada está encerrada (usar "Cancelar" preserva o histórico e corrige o status da OS, diferente do hard delete que não corrigia nada). Corrige a despesa "Taxa de cartão" gerada pela baixa avulsa de um título em cartão (POST /financeiro/{id}/baixar), que não herdava o os_id do título pago — por isso essas taxas nunca acionavam a trava de motivo+admin mesmo vinculadas a uma OS encerrada, diferente da taxa equivalente gerada pelo fechamento direto da OS. Corrige o bug da listagem de OS que não filtrava títulos cancelados ao somar Recebido/Saldo (diferente do detalhe da OS, que já filtrava corretamente) — OS com título estornado aparecia com saldo em aberto fantasma. Corrige dois bugs pré-existentes descobertos durante a implementação: a constante DELIVERED_STATUS (usada para exigir pagamento ao fechar a OS como "Equipamento Entregue") comparava contra "equipamento_entregue", um código que nunca existiu de fato no catálogo de status — o código real é "entregue_reparado" — tornando essa trava de pagamento morta desde que foi criada; o mesmo código errado também estava em 4 pontos da geração de documentos da OS. Corrige também a ordem de verificação de autorização em OrderClosureService::close(), que rodava depois da validação de negócio, retornando 422 em vez de 403 para um técnico sem acesso à OS.
- **Arquivos:** backend/app/Http/Controllers/Api/V1/BaseApiController.php,backend/app/Http/Controllers/Api/V1/BudgetController.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/CancelFinanceiroRequest.php,backend/app/Models/OrderStatus.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderDocumentCenterService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/resources/views/financeiro/_cancel_reason_modal.blade.php,frontends/desktop/resources/views/financeiro/_delete_admin_modal.blade.php,frontends/desktop/public/assets/js/financeiro-cancel-reason-modal.js,frontends/desktop/public/assets/js/financeiro-delete-admin-modal.js

## v4.7.15.0 — 2026-07-15 13:15
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona "trilha de origem" na listagem de lançamentos financeiros (GET /financeiro): cada linha passa a mostrar, sob a categoria, de onde aquele título veio — cliente/OS/equipamento para receita de OS, fornecedor para despesa avulsa, e para taxas de cartão (geradas automaticamente na baixa em cartão), o título a receber que originou a taxa. Substitui o antigo subtítulo genérico grupo_dre/subgrupo_dre, que era igual para todo lançamento da mesma categoria e não dizia nada sobre a origem específica daquele registro.
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.7.14.0 — 2026-07-15 01:56
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a Central Documental da OS (`/os/{id}/documentos`): as tabelas "Tipos documentais disponíveis" e "Acervo versionado do cliente" (renomeada para "Todas as versões geradas") passam a viver no mesmo card, uma logo abaixo da outra. Cada linha de "Tipos documentais disponíveis" com documento já gerado ganha um dropdown "Ações" com Visualizar A4/80mm (só aparece o formato realmente disponível), Baixar ZIP, Imprimir, Gerar link, Enviar e Arquivar/Reativar, agindo direto naquele documento — sem precisar marcar checkbox nenhum. Corrige o "Baixar ZIP" que parecia não funcionar: a causa raiz era a seleção da tabela "Tipos documentais" (usada só para gerar em lote) ser um estado desconectado da seleção da tabela do acervo (usada pelos botões de ZIP/imprimir/link/enviar) — confirmado por teste automatizado novo que o endpoint de ZIP do backend sempre funcionou corretamente. A tabela do acervo mantém seleção múltipla para combinar vários documentos num único ZIP/impressão/link/envio
- **Arquivos:** backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.13.0 — 2026-07-15 01:50
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** A listagem de lançamentos financeiros (`/financeiro`) passa a ordenar por data de pagamento/recebimento efetivo (mais recente primeiro), em vez de data de vencimento. Títulos ainda pendentes (sem data de pagamento) vão para o final da lista
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v4.7.12.0 — 2026-07-14 23:53
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona "Ver lançamentos" e "Novo lançamento" ao topo do dropdown "Mais ações" da tela de detalhes do lançamento financeiro, com divisor separando-os das demais ações. "Ver lançamentos" sempre aparece (exige apenas a permissão de visualizar já necessária para chegar nesta página); "Novo lançamento" só aparece com permissão de criar. Como consequência, o botão "Mais ações" deixa de sumir por completo quando o usuário não tem nenhuma outra ação disponível — passa a mostrar ao menos "Ver lançamentos"
- **Arquivos:** frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.7.11.0 — 2026-07-14 23:30
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Estende o padrão "Mais ações" (já usado em OS, orçamentos, documentos e lançamentos financeiros) para os detalhes de Cliente e de Equipamento. Em Cliente (`/clientes/{id}`): "Voltar" e "Nova OS" continuam como botões visíveis; "Editar cliente", "Ver OS do cliente" e "Ver equipamentos" passam para o dropdown. Em Equipamento (`/equipamentos/{id}`): "Voltar" e "Nova OS" continuam visíveis; "Editar" e "Abrir cliente" passam para o dropdown. Cada item mantém sua checagem de permissão de módulo já existente; o botão "Mais ações" some por completo quando nenhum item está disponível para o perfil do usuário
- **Arquivos:** frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.10.0 — 2026-07-14 22:51
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a tela de detalhes do lançamento financeiro (`/financeiro/{id}`) para reduzir a quantidade de cards e o scroll necessário. "Dados do lançamento" passa a concentrar também os dados de "Quem pagou"/"Para quem pagou" e de "Origem do lançamento" (antes três cards separados), com data/forma de pagamento do recebimento movidos para dentro dele; o card KPI "Recebido em/Pago em" do topo foi substituído por um card "Quem pagou"/"Para quem pagou" (nome, documento, telefone e e-mail da contraparte, no mesmo estilo compacto dos demais KPIs); "Tipo de origem" passa a ser só um campo dentro de "Dados do lançamento" (removida a subseção "Origem do lançamento" e o campo "Lançamento de origem"); "Dados do lançamento" e "OS vinculada" ficam lado a lado numa grade própria com altura igualada (bases alinhadas); "Baixas e formas de pagamento" e "Auditoria" passam a ocupar a largura inteira, abaixo desse par
- **Arquivos:** frontends/desktop/resources/views/financeiro/show.blade.php

## v4.7.9.0 — 2026-07-14 22:00
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o dropdown "Ações" da LISTAGEM de OS (não só da tela de detalhe, já corrigida antes), que não mostrava "Ver lançamento financeiro" mesmo em OS com título vinculado (ex.: OS26070002, com R$ 80,00 recebidos) — o id do título nunca era exposto na resposta da listagem, só na tela de detalhe. Backend passa a incluir `financeiro_titulo_id` em cada linha de `GET /orders` (o dado já era calculado internamente para "Recebido/Saldo", só faltava expor o id); listagem do desktop ganha o mesmo item de menu já usado no detalhe, condicionado a ter lançamento vinculado e permissão de visualizar o módulo financeiro
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.8.0 — 2026-07-14 21:38
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** O dropdown "Mais ações" da tela de detalhe da OS ganha o item "Ver lançamento financeiro" quando a OS tem um título "a receber" vinculado (não cancelado) — mesmo dado já usado no resumo financeiro da aba Valores, agora também como atalho direto para a página de detalhes do lançamento. Item some automaticamente quando a OS não tem lançamento vinculado ou o usuário não tem permissão de visualizar o módulo financeiro
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.7.7.0 — 2026-07-14 20:53
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Página de detalhes do lançamento financeiro ganha o botão "Mais ações" (mesmo padrão das telas de OS), agrupando todas as ações do lançamento: Editar lançamento; Registrar baixa (modal completo com valor total/parcial, forma de pagamento e taxas de cartão — antes só existia na listagem, agora dá para baixar direto dos detalhes); Ver OS vinculada; Ver orçamento vinculado (novo atalho — backend passa a expor o orçamento mais recente da OS no payload de detalhes); Ver cliente/fornecedor (contraparte, com id agora exposto pelo backend); Cancelar lançamento e Excluir lançamento (com as mesmas confirmações da listagem). O botão "Editar" isolado do cabeçalho foi movido para dentro do dropdown; sem nenhuma ação disponível para o perfil, o dropdown some. Baixa e cancelamento feitos a partir dos detalhes voltam para os detalhes (novo campo voltar_para), em vez de sempre caírem na listagem
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.7.6.0 — 2026-07-14 08:49
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza a tela de detalhe da OS: cabeçalho ganha pill de status ao lado do número, linha de metadados (duração 'Aberta há Xd'/'Concluída em Xd', previsão, pill de prazo/SLA com mesma paleta da listagem, técnico responsável) para leitura de relance sem abrir aba; card KPI 'Técnico responsável' (removido em ajuste anterior) segue disponível na aba Diagnóstico; histórico da OS na coluna lateral ganha faixa de filtros rolável (em vez de quebrar em várias linhas) para caber na largura reduzida da coluna; novo campo prazo (SLA) exposto em GET /orders/{id}, reaproveitando o mesmo cálculo já usado na listagem
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/openapi.yaml,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/orders/show.blade.php

## v4.7.5.0 — 2026-07-14 07:34
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige de vez o guard "fechar o navegador = deslogar" no Edge, após teste real do usuário mostrar que a v4.7.4 ainda falhava. Quatro falhas encontradas e corrigidas: (1) a detecção dependia só da idade do heartbeat (20s) — fechar e reabrir rápido (teste manual típico) passava despercebido; agora um MARCADOR DE FECHAMENTO é gravado no exato instante em que a aba fecha (evento pagehide, quando a saída não é navegação interna), tornando a detecção instantânea mesmo reabrindo em 2 segundos, não importa o que o Edge restaure; (2) o beforeunload consumia a flag de navegação interna antes de o pagehide lê-la, o que gravaria marcador falso em navegações normais — a flag agora expira sozinha (10s) em vez de ser consumida; (3) o rastreamento de navegação interna ficava dentro do if do aviso de fechamento — com o aviso desligado, toda navegação interna viraria "fechamento"; movido para fora, sempre ativo; (4) recarregar pelo botão do navegador seria tratado como fechamento — agora o tipo de navegação (PerformanceNavigationTiming: reload) isenta qualquer recarregamento com precisão. Fallback por heartbeat mantido (agora 90s, só para crash/kill, evitando falso positivo com abas em segundo plano que têm timers estrangulados pelo navegador). Logs de diagnóstico no console do navegador ([ERP Sessão]) para suporte remoto. Verificado em navegador real (Chromium headless) em 11 cenários, incluindo reabertura em 2s com aba inteira restaurada (caso Edge), reload da barra, multi-abas, crash e anti-laço
- **Arquivos:** frontends/desktop/resources/views/layouts/app.blade.php

## v4.7.4.0 — 2026-07-14 07:08
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o guard de "fechar o navegador = deslogar" para funcionar também no Microsoft Edge (e no Chrome com restauração completa de sessão). A versão anterior dependia do navegador limpar o sessionStorage ao fechar; o Edge, com "Inicialização rápida" + "Continuar de onde parei" (ligados por padrão), restaura a aba inteira — cookie E sessionStorage — deixando o guard cego. Agora o sinal principal é um "heartbeat" gravado no localStorage a cada 3 segundos por cada aba viva: ao fechar o navegador os beats param, mas o relógio continua, então na reabertura o último beat estará velho (> 20s) e a sessão restaurada é detectada e encerrada, independentemente do que o navegador restaure. Inclui verificação de escrita real do localStorage (se não persistir, o guard se desativa em vez de entrar em laço de logout) e mantém o anti-laço via sessionStorage. Verificado em navegador real (Chrome headless, mesmo motor do Edge) nos cenários: reabertura com sessionStorage restaurado → desloga; reload normal → mantém; primeiro login → mantém; nova aba legítima → mantém
- **Arquivos:** frontends/desktop/resources/views/layouts/app.blade.php

## v4.7.3.0 — 2026-07-14 01:23
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Adiciona um aviso ao fechar o navegador/aba com sessão ativa sem "Manter-me conectado": ao tentar fechar, o navegador exibe uma confirmação nativa lembrando de encerrar a sessão (útil como lembrete em computadores de clientes). O aviso é suprimido durante a navegação normal dentro do sistema (cliques em links do mesmo host, envio de formulários e recarregar a página) para não incomodar no uso do dia a dia — fica reservado ao fechamento de fato. Novo interruptor "Avisar ao fechar o navegador com sessão ativa" em Configurações do Sistema > Sessão e Segurança (ligado por padrão) controla o recurso. Observação técnica: navegadores modernos mostram a mensagem padrão do próprio navegador (não é possível personalizar o texto) e o diálogo também aparece ao recarregar pelo botão do navegador ou digitar outra URL
- **Arquivos:** frontends/desktop/database/migrations/2026_07_14_000002_add_warn_on_close_to_session_security_settings.php,frontends/desktop/app/Models/SessionSecuritySetting.php,frontends/desktop/app/Support/SessionSecuritySettings.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.2.0 — 2026-07-14 00:48
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reforça o "fechar o navegador = deslogar" para sessões sem "Manter-me conectado", corrigindo duas causas reais: (1) o cookie XSRF-TOKEN nascia com validade de 30 dias (efeito colateral do teto de sessão para o remember-me), aparecendo como um cookie "muito persistente" — agora, sem remember-me, tanto o XSRF-TOKEN quanto o cookie de sessão passam a ter validade curta (igual ao timeout de inatividade configurado), e o cookie de sessão continua morrendo ao fechar o navegador; (2) o recurso "Continuar de onde parei" do Chrome/Edge restaura o cookie de sessão ao reabrir o navegador, mantendo o usuário logado mesmo com o cookie efêmero — adicionado um guard em JS no layout autenticado (só para sessões não-lembradas) que usa sessionStorage (que o navegador limpa ao fechar a aba) mais um heartbeat em localStorage para distinguir "nova aba legítima da mesma sessão" de "navegador reaberto com sessão restaurada"; neste último caso força logout automático e volta para a tela de login. O guard não roda para sessões com "Manter-me conectado" marcado
- **Arquivos:** frontends/desktop/app/Http/Middleware/EnsureBackendToken.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.1.0 — 2026-07-14 00:28
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Torna configurável, em Configurações do Sistema > Sessão e Segurança, o que antes era fixo por `.env`: tempo de inatividade (minutos) que encerra sessões sem "Manter-me conectado" marcado, duração em dias do "Manter-me conectado", e um interruptor para desativar completamente o recurso (some o campo do login e invalida imediatamente o efeito de qualquer sessão já marcada como "lembrada", sem esperar novo login). Valores ficam guardados numa tabela local nova (`session_security_settings`, banco próprio do desktop) com fallback automático para os padrões de `.env` enquanto a tabela não existir ou estiver vazia
- **Arquivos:** frontends/desktop/database/migrations/2026_07_14_000001_create_session_security_settings_table.php,frontends/desktop/app/Models/SessionSecuritySetting.php,frontends/desktop/app/Support/SessionSecuritySettings.php,frontends/desktop/app/Support/DesktopSession.php,frontends/desktop/app/Providers/DesktopAppServiceProvider.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.7.0.0 — 2026-07-13 23:46
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Corrige vulnerabilidade de segurança: o cookie de sessão do desktop sobrevivia ao fechamento do navegador em qualquer login (config `expire_on_close` vinha `false`), permitindo que alguém que reabrisse o navegador (ex.: em um computador de cliente, após o técnico esquecer o logoff) reaproveitasse a sessão autenticada de quem usou o sistema antes. Agora o padrão é seguro: a sessão morre ao fechar o navegador e também expira após 120 minutos de inatividade (configurável). Para quem realmente precisa continuar conectado, foi criado o recurso "Manter-me conectado neste dispositivo" — um checkbox no login (com aviso para não usar em computadores compartilhados) que, quando marcado, mantém a sessão viva por até 30 dias mesmo fechando o navegador, sem enfraquecer o timeout padrão de quem não marcar. A aba "Sessão e Segurança" (Configurações do Sistema) agora mostra os valores realmente aplicados. Também corrigido um erro de digitação histórico na variável de ambiente do cookie seguro (`SESSION_SECURE_COOKIES` → `SESSION_SECURE_COOKIE`, nome que o Laravel realmente lê)
- **Arquivos:** frontends/desktop/config/session.php,frontends/desktop/.env.example,frontends/desktop/app/Support/DesktopSession.php,frontends/desktop/app/Http/Middleware/EnsureBackendToken.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/tests/Feature/Desktop/SessionSecurityTest.php

## v4.6.6.0 — 2026-07-13 14:42
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza o acesso administrativo do sistema: o antigo item de menu lateral "Níveis de Acesso" agora é acessado por um botão dentro da aba "Sessão e Segurança" de Configurações do Sistema; a visualização e o gerenciamento de "Usuários" (listagem, filtros, criação, edição, ativar/desativar) foram movidos para uma nova aba "Usuários" dentro de Configurações do Sistema (embutida de verdade, não apenas um link — reaproveitando o mesmo conteúdo/modais/JS da página própria via partials); e uma nova aba "Integrações" concentra o acesso à tela de integrações (WhatsApp, pagamentos, e-mail, Google). O menu lateral "Configurações" agora só tem "Configurações do Sistema". Rotas e páginas próprias (/usuarios, /grupos, /configuracoes/integracoes) continuam existindo e funcionando normalmente — só o ponto de acesso principal mudou. Cada aba nova respeita a permissão do módulo correspondente (usuarios/grupos), com fallback automático para a primeira aba permitida caso alguém force `?tab=` sem permissão
- **Arquivos:** frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/UserController.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/users/index.blade.php,frontends/desktop/resources/views/users/_index-content.blade.php,frontends/desktop/resources/views/users/_index-modals.blade.php,frontends/desktop/resources/views/users/_index-scripts.blade.php,frontends/desktop/tests/Feature/Desktop/ConfigurationSystemTest.php

## v4.6.5.0 — 2026-07-13 09:34
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Reorganiza o acesso às telas financeiras secundárias: removidos do menu lateral os 4 relatórios (DRE por Competência, DRE de Caixa, Fluxo de Caixa, Margem por OS) e também Cartões e Taxas, Configurações Financeiras e Precificação — a seção "Financeiro" do menu agora só tem "Lançamentos". Em vez disso, a tela de Lançamentos ganhou dois botões dropdown: "Relatórios" (os 4 relatórios) e "Mais ações" (Cartões e Taxas, Configurações Financeiras, Precificação — este último só aparece com permissão própria do módulo `precificacao`, distinta de `financeiro`). Rotas e páginas continuam as mesmas, só o ponto de acesso mudou
- **Arquivos:** frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroTest.php

## v4.6.4.0 — 2026-07-13 07:49
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a notificação do sino "Orçamento aprovado/recusado pelo cliente" (link público), que estava documentada em notificacoes-sino.md mas nunca foi implementada — o evento era gravado normalmente na timeline da OS (os_eventos), porém `BudgetApprovalService::approveByToken()`/`rejectByToken()` nunca chamavam o `NotificationDispatchService`, então nenhuma linha em mobile_notifications era criada e nada era transmitido em tempo real via Reverb. Corrigido injetando o `NotificationDispatchService` no serviço e disparando `orcamento.approved`/`orcamento.rejected` para responsável + criador do orçamento + técnico da OS vinculada (mesma lista de destinatários já documentada), tanto na aprovação quanto na recusa pelo link público
- **Arquivos:** backend/app/Services/Budgets/BudgetApprovalService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v4.6.3.0 — 2026-07-13 07:17
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige o gráfico "Tipos de Equipamento" do dashboard, que renderizava quebrado (segmentos empilhados com contorno branco sólido + cantos arredondados em todos os lados, dando aspecto de "pílulas" desconectadas em vez de uma coluna única) e com cores fora do padrão visual do sistema (12 cores arbitrárias, sem validação, com fórmula HSL gerando tons extras a partir do 13º tipo). Trocado por uma paleta categórica de 8 cores fixas e validadas (separação segura para daltonismo, contraste testado contra o fundo real do card, com um tom violeta próximo do roxo primário do sistema); do 9º tipo em diante agora entra no agregado "Outros" com uma cor neutra fixa, nunca mais uma cor gerada por índice. Só o segmento do topo da pilha recebe cantos arredondados (reto embaixo e entre segmentos), com um espaçamento fino na cor da superfície do card em vez de contorno branco chapado. Corrigido também o container do canvas, que só tinha "min-height" (sem altura explícita) — sem uma referência estável de altura o Chart.js (responsive + maintainAspectRatio:false) deixava o gráfico crescer sem limite, nunca cabendo inteiro na tela; agora usa altura explícita nas três faixas responsivas, mesmo padrão já usado no gráfico de rosca "OS por status"
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/dashboard.js

## v4.6.2.0 — 2026-07-12 22:51
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Corrige a listagem padrão de OS ocultando indevidamente ordens "Entregue - Pendência Financeira" (o escopo "aberta" filtrava também por status_final_pendente_pagamento; agora usa só os 3 status literais de OrderStatus::closureCodes(), no listing e no card "OS abertas" do dashboard — fixture de teste com grupo_macro divergente do banco real corrigido junto); corrige bug no botão "Editar" de Financeiro > Cartões e Taxas (mismatch camelCase/snake_case entre o JS e os campos do form deixava operadora, bandeira, parcelas, taxa % e taxa fixa em branco ao editar); campo "Parcelas" da baixa da OS agora respeita a faixa (min/max) realmente cadastrada para a operadora/modalidade/bandeira, com aviso da faixa liberada; e reorganiza a tela Financeiro > Cartões e Taxas (abas "Taxa por parcela" e "Taxas online"): tabela cadastrada ocupa a linha inteira e os formulários de cadastro/edição foram movidos para modais, acionados por um botão "Nova taxa"/"Nova taxa online" ou pelo "Editar" de cada linha
- **Arquivos:** .agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md,backend/app/Services/Dashboard/DashboardSummaryService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/public/assets/js/financeiro-cartoes.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/financeiro/cartoes.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/tests/Feature/Desktop/FinanceiroCartoesTest.php

## v4.6.1.0 — 2026-07-12 20:22
- **Tier:** patch
- **Autor/Agente:** Claude
- **Descrição:** Correções: cadastro rápido de cliente (erro 500 por CPF/CNPJ duplicado agora vira validação amigável; CPF/CNPJ normalizado para dígitos e com máscara progressiva 000.000.000-00 / 00.000.000/0000-00), correção de encoding mojibake nas mensagens em português do cadastro de equipamento (câmera/galeria/etc.), e regra na baixa da OS: encerrar como "Equipamento Entregue" passa a exigir ao menos algum valor recebido (pagamento parcial aceito, saldo restante segue como pendência financeira), validado no frontend e no backend
- **Arquivos:** backend/app/Http/Controllers/Api/V1/ClientController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderClosureService.php,frontends/desktop/public/assets/js/clients-form.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/orders/closure.blade.php

## v4.6.0.0 — 2026-07-12 20:10
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Implanta a Equipe da Assistência como base operacional separada de Usuários, com cadastro real de membros, vínculo opcional ao usuário do sistema, técnico da OS vindo dessa grade, contagem de documentos gerados no histórico da OS e padronização visual dos cards KPI da baixa
- **Arquivos:** backend/app/Http/Controllers/Api/V1/TeamMemberController.php,backend/app/Http/Requests/Api/V1/StoreTeamMemberRequest.php,backend/app/Http/Requests/Api/V1/UpdateTeamMemberActiveRequest.php,backend/app/Http/Requests/Api/V1/UpdateTeamMemberRequest.php,backend/app/Models/TeamMember.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_12_180000_create_equipe_membros_table.php,backend/routes/api.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Http/Controllers/PeopleController.php,frontends/desktop/app/Services/TeamMemberService.php,frontends/desktop/app/Support/DesktopNavigation.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/people/technical-team.blade.php,frontends/desktop/routes/web.php

## v4.5.0.0 — 2026-07-12 19:54
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Reformulação da Central Documental da OS (geração/envio/compartilhamento assíncrono via AJAX, com polling de fila e link público), dropdown "Mais ações" padronizado em todas as telas de OS e orçamento (edição, baixa/encerramento, documentos, orçamento), item "Ver orçamento"/"Gerar orçamento" condicional, e validação guiada de campos obrigatórios na abertura/edição de OS (botão "Próximo" navega até o campo pendente, técnico/data de previsão passam a ser obrigatórios, telefone do cliente exibido no resumo e na seleção)
- **Arquivos:** backend/app/Support/TemplateHtmlSanitizer.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/bootstrap/app.php,frontends/desktop/phpunit.xml,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/js/orders-documents-center.js,frontends/desktop/resources/views/orcamentos/create.blade.php,frontends/desktop/resources/views/orcamentos/edit.blade.php,frontends/desktop/resources/views/orcamentos/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/documents-center.blade.php,frontends/desktop/resources/views/orders/documents-center/_catalog.blade.php,frontends/desktop/resources/views/orders/documents-center/_documents-table.blade.php,frontends/desktop/resources/views/orders/documents-center/_send-history.blade.php,frontends/desktop/resources/views/orders/documents-center/_send-modal.blade.php,frontends/desktop/resources/views/orders/documents-center/_share-links.blade.php,frontends/desktop/resources/views/orders/documents-center/_share-modal.blade.php,frontends/desktop/resources/views/orders/documents-print.blade.php,frontends/desktop/resources/views/orders/edit.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.4.1.0 — 2026-07-11 20:07
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** correção do coletor dois cliques sem comando
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentCollectorController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Services/EquipmentWorkflowService.php,backend/database/migrations/2026_07_11_190000_add_submission_token_to_equipment_collector_pairings_table.php,backend/public/assets/agents/bench-collector/linux-x64/jovemtech-bench-collector.sh,backend/public/assets/agents/bench-collector/linux-x64/README.md,backend/public/assets/agents/bench-collector/win-x64/jovemtech-bench-collector.ps1,backend/public/assets/agents/bench-collector/win-x64/README.md,backend/routes/api.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/routes/web.php

## v4.4.0.0 — 2026-07-11 18:43
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** implantaçãodo coletor de harwares
- **Arquivos:** backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/EquipmentWorkflowService.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Support/Knowledge/PlaceholderCatalog.php,backend/config/services.php,backend/.env.production.example,backend/openapi.yaml,backend/public/assets/agents/bench-collector/linux-x64/jovemtech-bench-collector.sh,backend/public/assets/agents/bench-collector/linux-x64/README.md,backend/public/assets/agents/bench-collector/win-x64/jovemtech-bench-collector.ps1,backend/public/assets/agents/bench-collector/win-x64/README.md,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-11-fotos-sem-corte-e-visualizador-modal.md,documentacao/07-novas-implementacoes/2026-07-11-os-abertura-pdf-e-envio-whatsapp.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/layouts/partials/photo-viewer-modal.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,scripts/versionar.sh,VERSIONING.md

## v4.3.0.0 — 2026-07-11 16:07
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** restaura o PDF de abertura da OS, vincula o documento e adiciona envio opcional ao cliente
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderOpeningPdfService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Support/Knowledge/PlaceholderCatalog.php,backend/openapi.yaml,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/07-novas-implementacoes/2026-07-11-os-abertura-pdf-e-envio-whatsapp.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php

## v4.2.0.1 — 2026-07-11 13:03
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** restaura css dos filtros do historico da os
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v4.2.0.0 — 2026-07-11 10:36
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Fotos do desktop sem corte, visualizador modal e melhorias no versionar.sh com sincronização automática da documentação de agentes
- **Arquivos:** documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,documentacao/07-novas-implementacoes/2026-07-11-fotos-sem-corte-e-visualizador-modal.md,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/equipments-create.js,frontends/desktop/resources/views/equipments/create.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/layouts/partials/photo-viewer-modal.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,scripts/versionar.sh,VERSIONING.md

## v4.1.0.1 — 2026-07-10 22:04
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** ajuste da cor do painel administrativo em tema azul
- **Arquivos:** frontends/desktop/public/assets/css/themes/jovem-tech.css

## v4.1.0.0 — 2026-07-10 20:11
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** correção e ajustes das notificações (sino)
- **Arquivos:** backend/app/Console/Commands/NotifyOrderDeadlines.php,backend/app/Events/NotificationCreated.php,backend/app/Notifications/Channels/MobileInboxChannel.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Notifications/NotificationDispatchService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/channels.php,backend/routes/console.php,documentacao/03-arquitetura-tecnica/notificacoes-sino.md,frontends/desktop/app/Http/Controllers/NotificationController.php,frontends/desktop/app/Services/NotificationService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/resources/views/layouts/app.blade.php,frontends/desktop/resources/views/layouts/partials/navbar.blade.php,frontends/desktop/routes/web.php

## v4.0.2.0 — 2026-07-10 18:01
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Move o botao de notificacoes (sino) do lado direito para o lado esquerdo da barra superior, ficando ao lado do botao de inicio (casa)
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v4.0.1.1 — 2026-07-10 17:54
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Adiciona botao de atalho para o Dashboard (icone de casa) ao lado do toggle do menu lateral, e corrige sobreposicao entre a logo e o botao de expandir/recolher quando a sidebar esta recolhida (agora empilham verticalmente no hover)
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/layouts/partials/navbar.blade.php

## v4.0.1.0 — 2026-07-10 15:30
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste de segurança
- **Arquivos:** .agents/skills/sistema-erp-autenticacao-step-up/SKILL.md,backend/app/Http/Controllers/Api/V1/AuthController.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Web/BudgetPublicController.php,backend/app/Http/Requests/Api/V1/RevealEquipmentPasswordRequest.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/EquipmentWorkflowService.php,backend/config/services.php,backend/openapi.yaml,backend/phpunit.xml,backend/routes/api.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/BroadcastAuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/EquipmentController.php,frontends/desktop/app/Services/ConfigurationService.php,frontends/desktop/app/Services/EquipmentService.php,frontends/desktop/config/session.php,frontends/desktop/public/assets/js/configurations-integrations.js,frontends/desktop/public/assets/js/equipments-reveal-password-modal.js,frontends/desktop/public/assets/js/orders-list.js,frontends/desktop/resources/views/equipments/_reveal_password_modal.blade.php,frontends/desktop/resources/views/equipments/show.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/routes/web.php

## v4.0.0.0 — 2026-07-10 03:38
- **Tier:** major
- **Autor/Agente:** Codex
- **Descrição:** Hardening de seguranca: remove token do frontend, protege secrets de integracoes, mascara senhas de equipamentos, expira orcamentos publicos e endurece sessao/RBAC
- **Arquivos:** backend/app/Http/Controllers/Api/V1/AuthController.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/EquipmentController.php,backend/app/Http/Controllers/Web/BudgetPublicController.php,backend/app/Services/Auth/RbacAuthorizationService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/EquipmentWorkflowService.php,backend/openapi.yaml,backend/routes/api.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/BroadcastAuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ConfigurationService.php,frontends/desktop/config/session.php,frontends/desktop/public/assets/js/configurations-integrations.js,frontends/desktop/public/assets/js/orders-list.js,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/routes/web.php

## v3.21.0.0 — 2026-07-10 00:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** correções na tela de login, correções no RBCA, correções e ajustes na baixa da os
- **Arquivos:** .agents/skills/sistema-erp-os-fluxo-fechamento/references/regra-fechamento-os.md,.agents/skills/sistema-erp-os-fluxo-fechamento/SKILL.md,backend/app/Console/Commands/BackfillOsEventos.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Controllers/Api/V1/UserController.php,backend/app/Http/Requests/Api/V1/CloseOrderRequest.php,backend/app/Http/Requests/Api/V1/StoreUserRequest.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Http/Requests/Api/V1/UpdateUserRequest.php,backend/app/Models/OrderEvent.php,backend/app/Models/Order.php,backend/app/Notifications/FrontendPasswordResetNotification.php,backend/app/Providers/AppServiceProvider.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Company/CompanyProfileService.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Services/Orders/OrderEventService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/bootstrap/app.php,backend/database/migrations/2026_07_09_000001_create_os_eventos_table.php,backend/openapi.yaml,backend/routes/api.php,backend/routes/web.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,backend/tests/Feature/Api/V1/PasswordResetFlowTest.php,backend/tests/Feature/Api/V1/RbacAdministrationTest.php,documentacao/03-arquitetura-tecnica/eventos-os.md,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Http/Controllers/UserController.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/app/Services/UserService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orders-closure.js,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/orders/closure.blade.php,frontends/desktop/resources/views/orders/_event_timeline.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/users/index.blade.php,frontends/desktop/routes/web.php,frontends/desktop/tests/Unit/

## v3.20.0.1 — 2026-07-09 20:14
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Aproxima paineis do login em telas grandes
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.20.0.0 — 2026-07-09 08:22
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** modernizaçao e alinhamento do painel de login
- **Arquivos:** backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/app/Services/Company/CompanyProfileService.php,backend/routes/api.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/resources/views/layouts/guest.blade.php,frontends/desktop/routes/web.php

## v3.19.1.2 — 2026-07-09 07:46
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta login para azul institucional e layout mobile enxuto
- **Arquivos:** frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.19.1.1 — 2026-07-09 07:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Moderniza tela de login com branding da assistência técnica
- **Arquivos:** backend/app/Services/Company/CompanyProfileService.php,backend/app/Http/Controllers/Api/V1/ConfigurationController.php,backend/app/Http/Requests/Api/V1/UpdateCompanyProfileRequest.php,backend/routes/api.php,backend/tests/Feature/Api/V1/ConfigurationIntegrationsTest.php,frontends/desktop/app/Http/Controllers/AuthController.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/app/Services/ApiClient.php,frontends/desktop/app/Services/CompanyProfileService.php,frontends/desktop/resources/views/auth/login.blade.php,frontends/desktop/resources/views/layouts/guest.blade.php,frontends/desktop/resources/views/configurations/system.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/routes/web.php

## v3.19.1.0 — 2026-07-09 04:23
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** implantação de botão de detalhes em um lançamanto
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php

## v3.19.0.1 — 2026-07-09 04:19
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Adiciona detalhe operacional dos lancamentos financeiros
- **Arquivos:** backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/tests/Feature/Api/V1/FinanceiroTest.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/financeiro/show.blade.php,frontends/desktop/routes/web.php

## v3.19.0.0 — 2026-07-09 03:56
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** recuperação da base de conhecimento, ajustes na visualização da os
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/database/migrations/2026_07_09_000001_seed_conhecimento_module.php,backend/routes/api.php,backend/tests/Concerns/BuildsLegacyErpSchema.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/routes/web.php

## v3.18.2.3 — 2026-07-09 03:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Restaura modulo de conhecimento e implementa checklist de entrada operacional na OS
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpsertOrderRequest.php,backend/routes/api.php,backend/database/migrations/2026_07_09_000001_seed_conhecimento_module.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_wizard_scripts.blade.php,frontends/desktop/public/assets/js/orders-create.js,frontends/desktop/public/assets/css/desktop.css

## v3.18.2.2 — 2026-07-09 03:24
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** ajusta status e diagnostico na tela de detalhe da os
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v3.18.2.1 — 2026-07-09 02:59
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Detalhe da OS passa a exibir tipo, marca e modelo no card Equipamento, mantendo serie e resumo tecnico como complemento.
- **Arquivos:** frontends/desktop/resources/views/orders/show.blade.php

## v3.18.2.0 — 2026-07-09 02:41
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste e correção no processo de baixa da os
- **Arquivos:** backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/resources/views/orders/show.blade.php

## v3.18.1.3 — 2026-07-09 02:36
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Aba Valores da OS passa a exibir forma de pagamento resolvida pelos movimentos financeiros e alerta de peca orcada sem baixa de estoque vinculada.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/show.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v3.18.1.2 — 2026-07-09 01:42
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta status da OS conforme status do orçamento
- **Arquivos:** backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v3.18.1.1 — 2026-07-09 01:29
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Garante link publico copiavel e valor da OS em orcamentos
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,backend/app/Services/Budgets/BudgetApprovalService.php,backend/app/Services/Budgets/BudgetOrderSyncService.php,backend/tests/Feature/Api/V1/BudgetFlowTest.php

## v3.18.1.0 — 2026-07-09 01:06
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste de layout de elementos de paginas de listagem
- **Arquivos:** frontends/desktop/app/Http/Controllers/ServicoController.php,frontends/desktop/app/Http/Controllers/StockController.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/resources/views/clients/index.blade.php,frontends/desktop/resources/views/components/,frontends/desktop/resources/views/equipments/index.blade.php,frontends/desktop/resources/views/estoque/index.blade.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/resources/views/groups/index.blade.php,frontends/desktop/resources/views/orcamentos/index.blade.php,frontends/desktop/resources/views/servicos/index.blade.php,frontends/desktop/resources/views/suppliers/index.blade.php,frontends/desktop/resources/views/users/index.blade.php

## v3.18.0.0 — 2026-07-09 00:11
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** ajuste e correção de layout de graficos do dashboard
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/dashboard.js,frontends/desktop/resources/views/dashboard/index.blade.php

## v3.17.4.3 — 2026-07-09 00:05
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Protege graficos do dashboard no mobile
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.17.4.2 — 2026-07-09 00:02
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta densidade visual dos graficos do dashboard
- **Arquivos:** frontends/desktop/public/assets/css/desktop.css

## v3.17.4.1 — 2026-07-08 23:54
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Reorganiza graficos do dashboard
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/index.blade.php,frontends/desktop/public/assets/js/dashboard.js,frontends/desktop/public/assets/css/desktop.css

## v3.17.4.0 — 2026-07-08 22:57
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajuste no grafico de os entregues reparadas mes de março 2026
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,documentacao/04-governanca-ai/contexto-sistema.json,documentacao/04-governanca-ai/manifesto-do-sistema.md,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.3.1 — 2026-07-08 22:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige serie mensal de entregas reparadas do dashboard para ignorar atualizacoes de importacao legado
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.3.0 — 2026-07-08 22:00
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** ajustes no dashboard
- **Arquivos:** frontends/desktop/app/Services/DocumentationService.php,frontends/desktop/resources/views/configurations/system.blade.php

## v3.17.2.1 — 2026-07-08 21:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Alinha KPI de OS abertas do dashboard ao escopo operacional da listagem de OS
- **Arquivos:** backend/app/Services/Dashboard/DashboardSummaryService.php,backend/tests/Feature/Api/V1/DashboardSummaryTest.php,frontends/desktop/resources/views/dashboard/help.blade.php

## v3.17.2.0 — 2026-07-08 21:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** mostrar versionamento na documentaçãodo sistema nas configurações
- **Arquivos:** frontends/desktop/app/Services/DocumentationService.php

## v3.17.1.0 — 2026-07-08 21:20
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige permissao de execucao dos scripts (core.fileMode=false ignorava chmod +x) via git update-index --chmod, e corrige deploy-completo.sh lendo VERSION/CHANGELOG de develop (nao do main antigo) para a mensagem do merge
- **Arquivos:** scripts/bash/atualizar-dev.sh,scripts/bash/deploy-completo.sh,scripts/bash/deploy-producao.sh,scripts/bump-version.sh,scripts/classify-change.sh,scripts/versionar.sh

## v3.17.0.0 — 2026-07-08 21:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Adiciona scripts/versionar.sh e scripts/bash/deploy-completo.sh para versionar e publicar (dev->main) sem depender de IA
- **Arquivos:** AGENTS.md,.agents/skills/sistema-erp-deploy-producao/SKILL.md,documentacao/10-deploy/workflow-git-multiambiente.md,scripts/bash/deploy-completo.sh,scripts/versionar.sh,VERSIONING.md

## v3.16.0.1 — 2026-07-08 19:42
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige Baixa sumindo para Irreparavel/Reparo Recusado na listagem (is_encerrada ausente em mapSummary) e evita N+1 em OrderStatus::closureCodes()
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,frontends/desktop/resources/views/orders/index.blade.php,backend/tests/Feature/Api/V1/OrderFlowTest.php

## v3.16.0.0 — 2026-07-08 19:42
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Bloqueia mudanca de status em OS encerrada e adiciona Cancelar baixa com gate de administrador (step-up auth)
- **Arquivos:** backend/app/Models/OrderStatus.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Services/Orders/OrderClosureService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/CancelOrderClosureRequest.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/app/Services/Financeiro/OsMargemService.php,backend/routes/api.php,backend/bootstrap/app.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/_wizard.blade.php,frontends/desktop/resources/views/orders/_cancel_closure_modal.blade.php,frontends/desktop/public/assets/js/orders-cancel-closure-modal.js

## v3.15.2.4 — 2026-07-08 12:32
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Corrige botao Limpar da listagem de OS para remover filtros via rota limpa
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php

## v3.15.2.3 — 2026-07-08 12:27
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Indica filtros ativos e trava recolhimento do painel de filtros da listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.2 — 2026-07-08 11:58
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Ajusta altura do badge de resultados e botao Filtros na listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.1 — 2026-07-08 11:53
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Alinha contador de resultados e botao Filtros ao campo de busca na listagem de OS
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.15.2.0 — 2026-07-08 11:46
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS: sincronizacao instantanea Status/Macrofase com Select2 e limpeza sem recarregar pagina
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.15.1.0 — 2026-07-08 11:32
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS: Macrofase movida para filtros principais e sincronizada bidirecionalmente com Status
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.15.0.0 — 2026-07-08 11:15
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Filtros da listagem de OS passam a usar catalogo proprio de status autorizado por os:visualizar, restaurando Select2 de status e macrofase
- **Arquivos:** backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Services/Orders/OrderWorkflowService.php,backend/routes/api.php,backend/openapi.yaml,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/DesktopOrderStatusFlowService.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.14.2.0 — 2026-07-08 10:50
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Listagem inicial de OS passa a ocultar encerramentos canonicos e entregas com cobranca pendente, mantendo filtros explicitos para historico
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/tests/Feature/Api/V1/OrderFlowTest.php,frontends/desktop/resources/views/orders/index.blade.php,documentacao/03-arquitetura-tecnica/catalogo-status-os.md

## v3.14.1.0 — 2026-07-07 12:54
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Listagem de OS: botao 'Filtrar' ao lado do campo de busca no cabecalho + correcao do campo de busca que estava fora do <form> (nao era submetido ao filtrar). A busca agora submete o form de filtros via atributo HTML5 form=osFilterPanel, carregando status/itens por pagina/filtros avancados junto, mesmo com o painel recolhido.
- **Arquivos:** frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/public/assets/css/desktop.css

## v3.14.0.0 — 2026-07-07 12:45
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Overhaul do modal 'Alterar status da OS': card de equipamento passa a exibir tipo+marca+modelo; switch 'Notificar o cliente' movido para o rodape do modal; nova aba 'Procedimentos' em 2 colunas — registro de procedimentos executados com historico datado por tecnico (nova tabela os_procedimentos_historico) + campos de diagnostico e solucao salvos junto com o status; botao 'Salvar status' sempre habilitado (permite salvar diagnostico/solucao sem trocar o status, sem gerar historico/notificacao espuria); notificacao WhatsApp ao cliente na mudanca de status quando o switch esta ativo, com fallback de envio direto pela Evolution API quando a Central de Atendimento (banco chat) esta indisponivel; corrigido fundo transparente do modal (faltava a classe modal-shell). Novo endpoint POST /api/v1/orders/{order}/procedures; migration aditiva os_procedimentos_historico.
- **Arquivos:** backend/app/Services/Orders/OrderWorkflowService.php,backend/app/Http/Controllers/Api/V1/OrderController.php,backend/app/Http/Requests/Api/V1/UpdateOrderStatusRequest.php,backend/app/Http/Requests/Api/V1/StoreOrderProcedureRequest.php,backend/app/Models/Order.php,backend/app/Models/OrderProcedureHistory.php,backend/database/migrations/2026_07_07_000001_create_os_procedimentos_historico_table.php,backend/routes/api.php,frontends/desktop/app/Http/Controllers/OrderController.php,frontends/desktop/app/Services/OrderService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/orders/_status_modal.blade.php,frontends/desktop/resources/views/orders/index.blade.php,frontends/desktop/resources/views/orders/show.blade.php,frontends/desktop/public/assets/js/orders-status-modal.js,frontends/desktop/public/assets/css/desktop.css

## v3.13.1.0 — 2026-07-06 23:59
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige 'Ocorreu um erro inesperado' ao salvar orcamento (INSERT falhava com SQLSTATE 42S22 Unknown column desconto_tipo): a migration 2026_07_03_000001_add_adjustment_modes_to_orcamentos_tables estava marcada como executada no laravel_migrations, mas as 4 colunas de ajuste percentual (desconto_tipo, desconto_percentual, acrescimo_tipo, acrescimo_percentual) nunca existiram de fato nas tabelas orcamentos e orcamento_itens deste banco (drift de schema, mesma classe de problema ja documentada no deploy Contabo). Corrigido com ALTER aditivo direto no banco de dev (192.168.1.100), sem alterar nenhum arquivo de codigo
- **Arquivos:** (nenhum arquivo de codigo — correcao aplicada diretamente no banco sistema_hml de 192.168.1.100)

## v3.13.0.0 — 2026-07-06 23:44
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Cadastro rapido de item no orcamento: campo 'Tipo de equipamento' virou Select2 com tags (escolher existente ou digitar novo), reaproveitando o catalogo ja usado em Servicos/Estoque; corrigido bug generico de dropdown do Bootstrap dentro de tabela responsiva (abria para cima sobre a propria linha e ficava cortado em tabelas curtas) — menu agora e movido para o body enquanto aberto, corrigindo Acoes em todas as listagens (OS, equipamentos, financeiro etc.)
- **Arquivos:** backend/app/Services/Budgets/BudgetWorkflowService.php,frontends/desktop/public/assets/js/desktop.js,frontends/desktop/public/assets/js/orcamentos-form.js,frontends/desktop/resources/views/orcamentos/form.blade.php,frontends/desktop/resources/views/orcamentos/partials/quick-item-modal.blade.php

## v3.12.0.1 — 2026-07-06 12:04
- **Tier:** hotfix
- **Autor/Agente:** Claude
- **Descrição:** Documenta na armadilha do runbook Contabo o erro 'untracked working tree files would be overwritten by merge' no passo [2/5] do deploy-producao.sh (arquivos nao versionados na VPS colidindo com o commit remoto) e como resolver movendo-os para backup antes de repetir o script
- **Arquivos:** documentacao/10-deploy/deploy-producao-contabo-vps.md

## v3.12.0.0 — 2026-07-06 11:12
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Fluxo de caixa: coluna 'Entrada projetada' (dia em que o dinheiro efetivamente cai na conta para vendas em cartão, podendo cruzar de mês) e coluna 'Saldo líquido em conta' (acumulado já líquido de taxa, corrigindo também um bug pré-existente em que o saldo inicial só somava o dia anterior ao período em vez do histórico completo); botão de Detalhes por dia com modal de lançamentos (pago/recebido e previsto para cair no dia) e submodal de detalhes do cartão (operadora, bandeira, modalidade, parcelas, taxa, prazo); correção de um bug do Bootstrap 5 (modal empilhado perde o scroll-lock do modal externo ao fechar o interno)
- **Arquivos:** backend/app/Models/Financeiro.php,backend/app/Models/FinanceiroMovimento.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/tests/Feature/Api/V1/FinanceiroReportTest.php,frontends/desktop/resources/views/financeiro/relatorios/fluxo-caixa.blade.php,frontends/desktop/public/assets/css/desktop.css,frontends/desktop/public/assets/js/desktop.js

## v3.11.0.0 — 2026-07-06 11:12
- **Tier:** minor
- **Autor/Agente:** Claude
- **Descrição:** Cancelamento de lançamento financeiro: botão Cancelar no dropdown de Ações (estorna movimentos do título e, se houver, da despesa de Taxa de cartão vinculada), exclusão de títulos cancelados do DRE por competência, e taxa da operadora passa a ser registrada como despesa separada (Despesas Operacionais / Taxas e impostos) no dia do pagamento, em vez de ficar invisível no fluxo de caixa e no DRE
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Financeiro/FinanceiroReportService.php,backend/routes/api.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/routes/web.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/lang/pt_BR/validation.php,backend/tests/Feature/Api/V1/FinanceiroTest.php

## v3.10.0.0 — 2026-07-05 23:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Baixa de lancamento financeiro: botoes de valor total/parcial e forma de pagamento com campos de cartao (operadora/bandeira/modalidade/parcelas) e estimativa de taxa, no mesmo padrao da baixa da OS; backend passa a expor valor_aberto por lancamento e o catalogo de cartao, e registra FinanceiroMovimentoCartao quando a baixa e' em cartao. Corrigido tambem um bug critico pre-existente (ja presente antes desta entrega): o modal de baixa era um `<div>` filho direto de `<tbody>` (invalido em HTML), o que faz o navegador aplicar "foster parenting" e esvaziar o `<form>` — o `Confirmar baixa` submetia o formulario sem nenhum campo. Os modais agora sao renderizados num loop separado, fora de `<table>`/`<tbody>`
- **Arquivos:** backend/app/Http/Controllers/Api/V1/FinanceiroCatalogController.php,backend/app/Http/Controllers/Api/V1/FinanceiroController.php,backend/app/Http/Requests/Api/V1/RegisterFinanceiroMovementRequest.php,backend/app/Services/Financeiro/FinanceiroService.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/app/Services/FinanceiroService.php,frontends/desktop/resources/views/financeiro/index.blade.php,frontends/desktop/public/assets/js/financeiro-pay.js

## v3.9.1.0 — 2026-07-05 23:13
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige TypeError 'destroy is not a function' no Select2 da tela Financeiro (Cliente/Categoria): atributo data-select2="false" colidia com a chave interna do plugin e foi trocado por data-native-select="true"
- **Arquivos:** frontends/desktop/resources/views/financeiro/form.blade.php

## v3.9.0.0 — 2026-07-05 20:30
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Lançamentos financeiros avulsos sem OS, opcionais por cliente, com histórico protegido no cliente e bloqueio no fluxo da OS
- **Arquivos:** backend/app/Http/Requests/Api/V1/UpsertFinanceiroRequest.php,backend/app/Models/Financeiro.php,backend/app/Services/Financeiro/FinanceiroService.php,backend/app/Services/Orders/OrderClosureService.php,backend/database/migrations/2026_07_05_190000_add_avulso_to_financeiro_table.php,backend/openapi.yaml,frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/app/Http/Controllers/FinanceiroController.php,frontends/desktop/resources/views/clients/show.blade.php,frontends/desktop/resources/views/financeiro/form.blade.php,frontends/desktop/resources/views/financeiro/index.blade.php,specs/020-lancamentos-avulsos-financeiro-cliente,documentacao/07-novas-implementacoes/2026-07-05-lancamentos-avulsos-financeiro-cliente.md

## v3.8.0.0 — 2026-07-05 18:04
- **Tier:** minor
- **Autor/Agente:** jovem-tech
- **Descrição:** Sistema de desenvolvimento e deploy profissional: repositorio GitHub (jovem-tech/erp) como fonte unica da verdade, branches develop (dev 192.168.1.100) / main (producao VPS Contabo), deploy keys dedicadas por servidor, scripts de deploy git-based, XAMPP definitivamente descontinuado. AGENTS.md ganha mandato LEIA ISTO PRIMEIRO para qualquer IA.
- **Arquivos:** AGENTS.md,README.md,documentacao/10-deploy/workflow-git-multiambiente.md,scripts/bash/deploy-producao.sh,scripts/bash/atualizar-dev.sh

## v3.7.3.1 — 2026-07-05 16:37
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Documentacao em dia com a producao: notas do deploy Contabo (subdominios, copia de dados reais, fixes de schema/broadcasting/DNS) e da padronizacao de cliente; novo runbook Contabo; historico 3.7.1-3.7.3; AGENTS e skill de deploy com topologia atual

## v3.7.3.0 — 2026-07-05 16:24
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Padroniza nome (Title Case pt-BR, so pessoa fisica) e telefone (mascara (DDD) numero) no cadastro de cliente do desktop (rapido e completo): JS para UX ao vivo + ClientController autoritativo
- **Arquivos:** frontends/desktop/app/Http/Controllers/ClientController.php,frontends/desktop/public/assets/js/clients-form.js

## v3.7.2.0 — 2026-07-05 06:08
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige broadcasting/auth 403 em producao: channels.php passa a ser carregado com require (nao loadRoutesFrom) para sobreviver ao route:cache, registrando os canais de broadcasting (tempo real da Central de Atendimento e OS ao vivo)
- **Arquivos:** backend/app/Providers/AppServiceProvider.php

## v3.7.1.0 — 2026-07-04 22:48
- **Tier:** patch
- **Autor/Agente:** Codex
- **Descrição:** Corrige OrderController::jsonFailure ausente (500 na busca de clientes/Select2 da Nova OS) e reconcilia schema de clientes/usuarios/financeiro com colunas que o ERP espera (referencia etc.), aplicado em dev e VPS
- **Arquivos:** frontends/desktop/app/Http/Controllers/OrderController.php

## v3.7.0.0 — 2026-07-04 17:50
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Ambiente de desenvolvimento oficial migrado para Linux (BANCADA-02); nova topologia de portas (desktop 443, backend 8443); correcoes de auditoria: pools FPM dedicados, upload 25M, MySQL/Redis tuning, UFW+fail2ban, SSH hardening, TLS com SAN, backup diario, cookie Secure; ApiClient Guzzle 8-ready; raiz backend sem welcome page
- **Arquivos:** documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md,backend/routes/web.php,frontends/desktop/app/Services/ApiClient.php,AGENTS.md

## v3.6.0.1 — 2026-07-04 09:20
- **Tier:** hotfix
- **Autor/Agente:** Codex
- **Descrição:** Rodape do desktop passa a exibir a versao de 4 posicoes lida do arquivo VERSION (fonte unica), com fallback para shared/version.php
- **Arquivos:** frontends/desktop/app/Providers/DesktopAppServiceProvider.php

## v3.6.0.0 — 2026-07-04 08:35
- **Tier:** minor
- **Autor/Agente:** Codex
- **Descrição:** Deploy de producao em LAN Ubuntu documentado (runbook 10-deploy), aba Documentacao em Configuracoes>Sistema no desktop, correcao ForceHttps com BinaryFileResponse, adocao do protocolo de versionamento 4 posicoes
- **Arquivos:** documentacao/10-deploy/deploy-producao-lan-ubuntu.md,frontends/desktop/app/Services/DocumentationService.php,frontends/desktop/app/Http/Controllers/ConfigurationController.php,frontends/desktop/resources/views/configurations/system.blade.php,backend/app/Http/Middleware/ForceHttps.php,VERSIONING.md,VERSION,CHANGELOG.md

## v3.5.3.0 — Baseline
- **Tier:** —
- **Autor/Agente:** Otávio
- **Descrição:** Ponto de partida do novo protocolo de versionamento de 4 posições. Versão anterior era V3.5.3 (3 posições); a partir daqui todo commit deve gerar uma entrada aqui.

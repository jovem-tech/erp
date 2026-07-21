# Historico de versoes

> Fonte detalhada e autoritativa: `CHANGELOG.md`. Esta página mantém um resumo
> executivo das entregas mais relevantes e links para a documentação técnica.

## v5.5.0.0 - 2026-07-21

- formas de pagamento deixam de ser lista fixa no código e viram cadastro gerenciável em `Configurações Financeiras > Formas de Pagamento`;
- o catálogo alimenta a baixa de OS, o formulário de lançamento e as formas padrão das contas; formas de sistema são protegidas (só desativáveis) e formas já usadas não podem ser excluídas;
- a marcação "É cartão" passa a controlar operadora/bandeira/parcelas/taxas, inclusive no JS da baixa (que antes adivinhava pelo prefixo do código);
- migration aditiva: a coluna-resumo `financeiro.forma_pagamento` (ENUM do banco legado compartilhado) **não** foi alterada — formas personalizadas vivem nas colunas de detalhe (varchar), sem perda de informação;
- nota: `2026-07-21-cadastro-formas-de-pagamento.md`.

## v5.4.2.0 - 2026-07-21

- sidebar do desktop reorganizada em 7 seções por afinidade (Visão Geral, Atendimento, Cadastros, Financeiro, Conhecimento, Processos e Modelos, Administração);
- "Equipe da Assistência" sai do antigo grupo "Comercial" para "Administração"; "Aparelhos/Equip." vira Cadastro; catálogos (Serviços/Estoque) agrupados em Cadastros;
- novos grupos de atalho levam direto às sub-páginas mais usadas: Relatórios e Ferramentas (Financeiro) e Acesso e Integrações (Administração), sem substituir os dropdowns das páginas-mãe;
- mudança só de apresentação (array `DesktopNavigation::definition()`); rotas/permissões/RBAC inalterados;
- nota: `2026-07-21-sidebar-reorganizacao-e-atalhos.md`.

## v5.4.1.0 - 2026-07-20

- corrige 4 problemas da foto de perfil reportados após teste real: nome de arquivo ilegível (UUID puro → `foto-perfil-{aleatorio}.jpg`), foto antiga não aposentada no gerenciador (`ManagedFile` anterior agora vai para `trashed` ao substituir/remover), miniatura quebrada (`private/usuarios` faltava na allowlist de namespaces de `ManagedFileDeliveryService`) e logoff forçado após trocar a foto (`form.submit()` não dispara o evento `submit` que o guard de sessão depende → trocado para `form.requestSubmit()`);
- nota: `2026-07-20-foto-de-perfil-usuario.md` (seção "Correções pós-lançamento").

## v5.4.0.0 - 2026-07-20

- lixeira passa a oferecer preview seguro, detalhes, restauração e exclusão definitiva individual/em lote;
- lifecycle terminal `purged` preserva metadados, vínculos e eventos como registro-túmulo auditável;
- retenção automática configurável em 0, 7, 30 ou 90 dias, com job diário às 02:30;
- exclusão física protegida por RBAC, step-up, confirmação `EXCLUIR`, kill switch, rate limit, locks, allowlist de path/disco e retenção legal;
- migration aditiva aplicada no ambiente LAN e política inicial de 30 dias ativada após backup;
- nota: `2026-07-20-lixeira-gerenciador-arquivos.md`.

## v5.3.0.0 - 2026-07-20

- usuário passa a poder cadastrar foto de perfil em `Perfil > Configurações`, exibida na navbar no lugar da inicial;
- foto é normalizada para JPEG 512x512 e catalogada no Gerenciador de Arquivos central (categoria `user_profile_photo`, já prevista desde `specs/022-gerenciador-central-arquivos/`), vinculada ao usuário dono;
- novo `subject_type` `user` e `UserProfilePhotoFileAuthorizer`: dono sempre pode ver/baixar a própria foto, demais ações exigem `arquivos:administrar`;
- reaproveita a coluna legada `usuarios.foto`, sem migration nova;
- nota: `2026-07-20-foto-de-perfil-usuario.md`.

## v5.2.3.0 - 2026-07-20

- dropdown "Busca completa" do topbar e a tela `/buscar` passam a aceitar checkboxes, permitindo pesquisar em vários escopos ao mesmo tempo (ex.: OS + Clientes);
- marcar "Busca completa" desmarca os escopos específicos (e vice-versa); desmarcar tudo sem alternativa volta a marcar "Busca completa" sozinha;
- backend (`SearchService`/`SearchController`) aceita o escopo tanto como lista (`scope[]=os&scope[]=clientes`, checkboxes reais da tela `/buscar`) quanto como string separada por vírgula (`scope=os,clientes`, usada pelo input hidden do dropdown do topbar);
- nota: `2026-07-20-busca-global-multi-escopo.md`.

## v5.2.2.0 - 2026-07-20

- step-up da lixeira alinhado ao RBAC `arquivos:administrar`, inclusive para supervisores cujo perfil legado não é `admin`;
- operador ainda precisa de `arquivos:excluir`, preservando separação de responsabilidades;
- credencial sem permissão efetiva é recusada mesmo quando o perfil legado é `admin`;
- POST da lixeira sem retry automático e erro de log sem conversão indevida para HTTP 500;
- checkboxes com alvo maior e explicação explícita das permissões necessárias;
- nota: `2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md`.

## v5.2.1.0 - 2026-07-20

- clique na miniatura da Central Documental abre o visualizador interno;
- PDF exibido em iframe same-origin com recarregar, tela cheia e download;
- URL, nome e download acompanham a versão escolhida na linha;
- carregamento somente após o clique e limpeza do iframe ao fechar o modal;
- nota: `2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md`.

## v5.2.0.0 - 2026-07-20

- coluna Foto na Central Documental de cada OS;
- miniatura lazy da primeira página do PDF A4 da versão mais recente;
- atualização da imagem, link e texto alternativo ao selecionar outra versão;
- rota autenticada pelo domínio da OS, sem conceder acesso ao painel administrativo de arquivos;
- cache privado por SHA-256/ETag e fallback quando o documento não está disponível;
- nota: `2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md`.

## v5.1.0.0 - 2026-07-20

- sincronização automática do catálogo em segundo plano e solicitação manual deduplicada;
- biblioteca de arquivos em grade/lista com miniaturas de imagem e PDF, seleção, ZIP e lixeira lógica;
- visualização interna em modal, com controles próprios para imagens e iframe para PDFs;
- data real do documento e cliente vinculado nos cards e na tabela, sem N+1;
- seleção de permissões individual, por módulo, por coluna e global;
- criação idempotente de OS, bloqueio de clique duplo e avisos pós-commit sem falso erro de criação;
- correção do proxy autenticado de fotos privadas e da resiliência da sessão do desktop;
- nota: `2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md`.

## v5.0.0.0 - 2026-07-19

- núcleo do Gerenciador Central de Arquivos, catálogo, vínculos, aliases, auditoria e scanner;
- adapters seguros para branding, fotos, documentos, assinaturas e chat;
- painel administrativo com RBAC e operações lógicas protegidas por kill switch;
- notas: `2026-07-19-nucleo-gerenciador-central-arquivos.md` e `2026-07-19-painel-gerenciador-arquivos.md`.

## v4.24.0.0 - 2026-07-19

- cadastro versionado de assinatura por imagem ou desenho em tela, incluindo toque e Apple Pencil;
- assinatura própria, reautenticação de outro responsável sem troca de sessão, encaminhamento pendente e rubrica do cliente por link de uso único;
- separação auditável entre criador, usuário da sessão e signatário;
- bloqueio de PDFs atribuídos a usuário sem assinatura ativa;
- armazenamento privado, rasterização segura, token público em hash, expiração e locks contra processamento concorrente;
- nota: `2026-07-19-assinaturas-digitais-documentos.md`.

## v4.23.0.0 a v4.23.0.2 - 2026-07-18/19

- cabeçalho institucional padronizado em três colunas para documentos atuais, novos e clonados;
- Termo de Garantia promovido por migration idempotente, preservando personalizações do destino;
- bloco de assinatura ampliado para responsável e cliente lado a lado;
- nota: `2026-07-18-motor-central-documentos-pdf.md`.

## v4.21.0.0 a v4.22.4.0 - 2026-07-18

- unificação dos PDFs operacionais no motor central versionado;
- criação do zero e clonagem de documentos personalizados;
- publicação imutável, rascunhos, prévia A4/80 mm e geração pela Central Documental;
- editor ampliado, texto formatado, variáveis validadas e foto privada do equipamento;
- correções de paginação, rodapé, nome fantasia e data real de entrega;
- nota: `2026-07-18-motor-central-documentos-pdf.md`.

## v4.19.0.0 a v4.20.0.2 - 2026-07-18

- contas financeiras, saldos, transferências, conciliação e cartões líquidos a receber;
- módulo RBAC independente de Financeiro;
- consolidado mensal por conta e correções de vínculo de conta/collation;
- nota: `2026-07-18-gestao-contas-e-saldos-financeiros.md`.

## Consolidado operacional

Consulte `2026-07-19-consolidado-implementacoes-18-19-julho.md` para migrations,
ordem de deploy, validações, segurança, riscos e estado por ambiente.

## v3.14.1.0 - 2026-07-07

- listagem de OS (`orders/index.blade.php`): botao "Filtrar" adicionado ao lado do campo de busca no cabecalho (input-group);
- correcao de bug latente: o campo de busca estava **fora** do `<form>` de filtros (linhas do form comecam depois do cabecalho), entao nao era enviado ao filtrar — a busca so "funcionava" via URL. Agora o input e o botao usam o atributo HTML5 `form="osFilterPanel"`, submetendo o form de filtros junto (status, itens por pagina e filtros avancados, mesmo com o painel recolhido);
- sem migration, sem novo endpoint; ajuste de blade + CSS (`.os-search-block`).

## v3.14.0.0 - 2026-07-07

- overhaul do modal "Alterar status da OS":
- card de equipamento passa a exibir `tipo + marca + modelo` (eager-load de `equipment.type/brand/model`, campo `equipamento_resumo_curto`);
- switch "Notificar o cliente" movido para o rodape do modal;
- nova aba "Procedimentos" em 2 colunas: "Procedimentos executados" append-only com historico datado por tecnico (nova tabela `os_procedimentos_historico`, novo endpoint `POST /api/v1/orders/{order}/procedures`) na direita; "Diagnostico" e "Solucao" (campos unicos da OS) salvos junto com o botao "Salvar status";
- botao "Salvar status" sempre habilitado — permite salvar diagnostico/solucao sem trocar o status; um save sem troca de status nao gera historico/notificacao nem recalcula margem;
- notificacao WhatsApp ao cliente na mudanca de status (o checkbox `comunicar_cliente` era so visual e nunca era lido — conectado ponta a ponta), com fallback de envio direto pela Evolution API (`sendDirectMessage`) quando a Central de Atendimento (banco `chat`) esta indisponivel;
- corrigido fundo transparente do modal (faltava a classe `modal-shell`);
- contrato de `PATCH /api/v1/orders/{order}/status` mudou de forma retrocompativel (`status` virou `nullable`; aceita `diagnostico_tecnico`, `solucao_aplicada`, `comunicar_cliente`);
- deploy: exige `php artisan route:clear` (rota nova) + `migrate` da tabela `os_procedimentos_historico`;
- nota em `documentacao/07-novas-implementacoes/2026-07-07-modal-status-os-procedimentos-notificacao-cliente.md`.

## v3.13.1.0 - 2026-07-06

- corrigido "Ocorreu um erro inesperado" ao salvar orçamento (`Salvar e enviar para aprovação`): `INSERT` falhava com `SQLSTATE 42S22 Unknown column 'desconto_tipo'`;
- causa: a migration `2026_07_03_000001_add_adjustment_modes_to_orcamentos_tables` estava marcada como executada em `laravel_migrations`, mas as 4 colunas de ajuste percentual (`desconto_tipo`, `desconto_percentual`, `acrescimo_tipo`, `acrescimo_percentual`) nunca existiram de fato em `orcamentos`/`orcamento_itens` neste banco — mesmo drift de schema já documentado no deploy Contabo (18 colunas aditivas);
- corrigido com `ALTER` aditivo direto no banco de dev (192.168.1.100), sem alterar nenhum arquivo de código;
- nota em `documentacao/07-novas-implementacoes/2026-07-06-schema-drift-ajustes-percentuais-orcamento.md`.

## v3.13.0.0 - 2026-07-06

- cadastro rapido de servico/peca no orcamento: campo "Tipo de equipamento" deixou de ser texto livre e virou Select2 com tags (escolhe um tipo ja cadastrado ou digita um novo), reaproveitando o mesmo catalogo de tipos de equipamento ja usado em Servicos e Estoque;
- corrigido bug generico de dropdown do Bootstrap dentro de `.table-responsive`: o menu de "Acoes" abria para cima, sobre a propria linha (Popper achava que nao havia espaco abaixo), e mesmo corrigido para abrir para baixo continuava cortado quando a tabela tinha poucas linhas — corrigido reparentando o `.dropdown-menu` para o final do `<body>` enquanto aberto, restaurando a posicao original ao fechar. Corrige "Acoes" em qualquer listagem com esse padrao (OS, equipamentos, financeiro etc.);
- nota em `documentacao/07-novas-implementacoes/2026-07-06-tipo-equipamento-select2-e-dropdown-tabela.md`.

## v3.12.0.0 - 2026-07-06

- nota tecnica criada em `documentacao/07-novas-implementacoes/2026-07-06-fluxo-caixa-entrada-projetada-saldo-liquido.md`
- coluna "Entrada projetada" no fluxo de caixa: agrega pelo dia em que o dinheiro efetivamente cai na conta (imediato no dia da venda; cartao na data de credito/repasse, podendo cruzar de mes), valor bruto;
- coluna "Saldo liquido em conta": saldo acumulado de verdade em caixa, ja liquido de taxa para vendas em cartao, sem contar a taxa duas vezes;
- corrigido bug pre-existente: "Saldo inicial" so somava o dia anterior ao periodo, nao o historico completo — "Saldo realizado"/"Saldo final" agora refletem a conta real;
- botao "Detalhes" por dia com modal de lancamentos (pago/recebido e previsto para cair no dia) e submodal de detalhes do cartao (operadora, bandeira, modalidade, parcelas, taxa, prazo);
- corrigido bug generico de modal empilhado no Bootstrap 5 (perda de scroll-lock do modal externo ao fechar o interno), reutilizavel por qualquer modal-em-modal futuro.

## v3.11.0.0 - 2026-07-06

- nota tecnica criada em `documentacao/07-novas-implementacoes/2026-07-06-cancelamento-lancamento-taxa-cartao.md`
- botao "Cancelar" no dropdown de Acoes de Lancamentos: estorna os movimentos do titulo (e da despesa de taxa de cartao vinculada, se houver) sem apagar o registro;
- taxa da operadora passa a ser registrada como despesa separada (Despesas Operacionais / Taxas e impostos) no dia do pagamento, em vez de ficar invisivel no fluxo de caixa e no DRE;
- DRE por competencia passa a excluir titulos cancelados;
- corrigida mensagem crua de validacao (`validation.required_if`) no desktop por falta de arquivo de traducao.

## v3.10.0.0 - 2026-07-05

- baixa de lancamento financeiro (`Financeiro > Lancamentos > Registrar baixa`) ganhou botao `Valor total` (preenche o saldo em aberto real do titulo) e botao `Valor parcial` (limpa e foca o campo para digitacao manual, mantendo o titulo como `Parcial` com valor pago e saldo pendente calculados automaticamente);
- `Forma de pagamento` passou de texto livre para select com os mesmos campos de cartao da baixa de OS (operadora, bandeira, modalidade, parcelas) e estimativa de taxa ao vivo, reaproveitando o catalogo de `Cartoes e Taxas`;
- backend: `/financeiro` (lista) passa a expor `valor_aberto` por lancamento e `/financeiro/catalogo` passa a expor o dataset de cartao; a baixa em cartao agora persiste `FinanceiroMovimentoCartao` (taxa, valor liquido, prazo) do mesmo jeito que a baixa de OS;
- corrigido bug critico pre-existente (ja estava assim antes desta entrega): o modal de baixa era um `<div>` filho direto de `<tbody>`, HTML invalido que faz o navegador mover ("foster parenting") o conteudo para fora da tabela e esvaziar o `<form>` — na pratica, `Confirmar baixa` submetia o formulario sem nenhum campo preenchido. Os modais passaram a ser renderizados num loop separado, fora de `<table>`/`<tbody>`;
- nota em `documentacao/07-novas-implementacoes/2026-07-05-baixa-financeiro-cartao-valor-parcial.md`.

## v3.9.1.0 - 2026-07-05

- corrige `TypeError: r.GetData(...).destroy is not a function` no Select2 dos campos `Categoria` e `Cliente` da tela `Financeiro > Lancamentos > Novo`: `data-select2="false"` colidia com a chave interna do plugin (jQuery expoe `data-*` via `.data()`) e foi trocado por `data-native-select="true"`, mesmo padrao ja usado em Nova OS e Base de Conhecimento;
- mesma causa raiz documentada em `2026-06-29-select2-manual-init-collision-desktop.md`, agora reincidente no formulario de Financeiro;
- nota em `documentacao/07-novas-implementacoes/2026-07-05-select2-colisao-financeiro-desktop.md`.

## v3.7.3 - 2026-07-05

- cadastro de cliente do desktop (rapido e completo) passou a padronizar **nome** em Title Case pt-BR (conectores `de/da/do/dos/das/e` em minusculo, so pessoa fisica) e **telefone** com mascara `(DDD) numero` — celular `(21) 98061-4757`, fixo `(22) 2627-4120`;
- duas camadas: JS (`clients-form.js`) para UX ao vivo + `ClientController` autoritativo;
- nota em `documentacao/07-novas-implementacoes/2026-07-05-padronizacao-nome-telefone-cadastro-cliente-desktop.md`.

## v3.7.2 - 2026-07-05

- corrige `broadcasting/auth` 403 em producao: `channels.php` passou a ser carregado com `require` (nao `loadRoutesFrom`, que e' ignorado com `route:cache`), registrando os canais de broadcasting e mantendo o `route:cache` de producao;
- ver nota `2026-07-05-deploy-producao-contabo-subdominios-e-dados-reais.md`.

## v3.7.1 - 2026-07-04

- corrige 500 na busca de clientes (Select2 da Nova OS): metodo `OrderController::jsonFailure()` faltante, adicionado no padrao dos demais controllers;
- reconciliacao de schema entre os dados reais da VPS e o schema que o ERP espera: 18 colunas aditivas (ex.: `clientes.referencia`, `usuarios.remember_token_hash`) aplicadas na VPS e no dev;
- ver nota `2026-07-05-deploy-producao-contabo-subdominios-e-dados-reais.md`.

## v3.7.0 - 2026-07-04

- servidor `192.168.1.100` (BANCADA-02) tornou-se o **ambiente oficial de desenvolvimento**, substituindo o Windows/XAMPP; repositorio git completo publicado em `/var/www/sistema-erp`;
- nova topologia de portas: desktop na 443 (`https://192.168.1.100`), backend/API na 8443 (`https://192.168.1.100:8443`), 8444/8445 reservadas para mobile/chat;
- correcoes de auditoria: dois pools PHP-FPM dedicados, limite de upload 25M (corrige o 413 em fotos/logo), MySQL buffer pool 1G + slow log, Redis maxmemory, UFW + fail2ban, SSH endurecido, `server_tokens off`, cookie `Secure`, TLS com SAN, backup diario do banco, remocao do banco orfao;
- correcoes de codigo: `ApiClient` sem `CURLOPT_*` deprecado (Guzzle 8-ready) e raiz do backend sem welcome page;
- nota de entrega em `documentacao/07-novas-implementacoes/2026-07-04-ambiente-dev-linux-oficial-correcoes-auditoria.md`;
- licao aprendida: `ufw allow 22` antes de `ufw enable` (lockout real recuperado por acesso fisico).

## v3.6.0 - 2026-07-04

- primeiro deploy de producao real: backend em `https://192.168.1.100` e desktop em `https://192.168.1.100:8443` (Ubuntu Server 26.04, PHP 8.5, MySQL 8.4, Redis, Nginx com TLS autoassinado, Supervisor e cron);
- banco `sistema_hml` migrado de MariaDB para MySQL 8 com correcao de colunas geradas; storage privado e uploads legados copiados com `LEGACY_PUBLIC_PATH`;
- correcao de bug de producao no middleware `ForceHttps` (500 em respostas de arquivo binario — logo e fotos);
- novo runbook `documentacao/10-deploy/deploy-producao-lan-ubuntu.md` com 11 problemas reais mapeados e checklist pos-deploy;
- nova aba `Documentacao` em `Configuracoes > Sistema` no desktop para leitura da documentacao oficial dentro do sistema;
- adotado protocolo de versionamento de 4 posicoes (`VERSIONING.md`, `VERSION`, `CHANGELOG.md`, `scripts/bump-version.sh`, `scripts/classify-change.sh`);
- nota de entrega em `documentacao/07-novas-implementacoes/2026-07-04-deploy-producao-lan-documentacao-integrada-versionamento.md`.

## v3.5.3 - 2026-07-03

- o formulario de orcamento do desktop ganhou um botao `Cadastrar` ao lado da referencia do item para criar rapidamente uma peca ou servico sem sair do fluxo;
- o modal compartilhado reaproveita os endpoints autenticados do desktop para cadastro rapido de pecas e servicos, devolvendo o item criado para a linha atual;
- a referencia do item continua filtrada pelo tipo selecionado, evitando mistura entre pecas e servicos no mesmo catalogo;
- nota de entrega criada em `documentacao/07-novas-implementacoes/2026-07-03-cadastro-rapido-pecas-servicos-orcamento-desktop.md` e versao global ajustada para `3.5.3`.

## v3.5.2 - 2026-07-02

- a listagem de ordens de servico do desktop passou a exibir botao rapido para orcamento do equipamento e acoes de mudanca de status baseadas no fluxo de trabalho permitido para cada OS;
- o backend central agora expõe `proximas_etapas` no payload da listagem e no evento de nova OS, evitando consultas extras no front e mantendo a fonte de verdade do workflow;
- as rows inseridas em tempo real pelo broadcast de nova OS foram alinhadas ao mesmo menu de acoes da listagem;
- o dropdown de acoes recebeu ajuste de capacidade de rolagem para evitar overflow quando o fluxo possui muitas transicoes;
- nota de entrega criada em `documentacao/07-novas-implementacoes/2026-07-02-acoes-rapidas-listagem-os-desktop.md` e versao global ajustada para `3.5.2`.

## v3.5.2 - 2026-07-02

- a preferência de tema passou a ser **persistida por usuário** no banco de dados SQLite local do desktop (`user_preferences`), sobrevivendo a logout, reinicialização do servidor e troca de sessão;
- cada usuário tem sua preferência independente: usuário A com tema escuro, usuário B com tema Jovem Tech e usuário C com o padrão — cada um mantém seu tema ao relogar;
- `EnsureBackendToken` middleware passou a carregar a preferência do banco na primeira request autenticada da sessão (`session()->has('desktop_theme')` como sentinel — 0 queries adicionais em requests subsequentes);
- `ConfigurationController::updateAppearance()` agora persiste via `UserPreference::updateOrCreate()` além de atualizar a sessão;
- `DesktopSession::forget()` passou a limpar `desktop_theme` além de `desktop_auth` no logout, evitando que a preferência de um usuário vaze para o próximo login no mesmo browser;
- tabela `user_preferences`: `api_user_id` (unique, BigInt — ID do usuário no backend central), `desktop_theme` (string 32, default `'default'`);
- migration `2026_07_02_000001_create_user_preferences_table` aplicada no SQLite do desktop;
- versão global ajustada para `3.5.2`.

## v3.5.1 - 2026-07-02

- adicionado o **Tema Escuro** (`dark`) como terceiro tema selecionável em `Configurações > Sistema > Aparência`;
- o tema escuro usa sidebar em roxo profundo (`#1A1035 → #0E0A22`), fundo `#0D1117`, superfícies `#161B27` e primário `#7C6EFA` (roxo levemente mais claro para contraste adequado sobre escuro);
- flyout do sidebar colapsado no modo escuro usa fundo escuro (`#1A2035`) com texto claro (`#CBD5E1`), mantendo a mesma arquitetura de seletores CSS separados do tema Jovem Tech;
- alerts (warning, success, danger, info), tabelas, modais, dropdowns, inputs e scrollbar receberam overrides específicos para contraste WCAG no modo escuro;
- `ConfigurationController::updateAppearance()` passou a aceitar `dark` na lista de temas permitidos;
- card de preview do tema escuro adicionado à UI de seleção (sidebar roxa escura + conteúdo `#0D1117` com acento roxo `#7C6EFA`);
- nota de entrega em `documentacao/07-novas-implementacoes/2026-07-02-jovem-tech-design-system-tema-desktop.md` e versão global ajustada para `3.5.1`.

## v3.5.0 - 2026-07-02

- implementado o **Jovem Tech Design System v3.0.0** como segundo tema selecionável do desktop, disponível em `Configurações > Sistema > Aparência`;
- o novo tema `jovem-tech` substitui a identidade visual padrão (roxo `#6f5afc`) por azul institucional (`#3868B0`) com sidebar em gradiente azul marinho (`#254F8D → #1E4278`), fundo suave `#F4F8FF` e tipografia Aptos/Segoe UI;
- o design system é totalmente escopo-isolado via seletor CSS `[data-theme="jovem-tech"]`, sem impacto no tema padrão;
- a preferência de tema é armazenada na sessão Laravel (`desktop_theme`) sem necessidade de migração de banco;
- o layout `app.blade.php` aplica o atributo `data-theme` e carrega o CSS do tema condicionalmente via diretiva `@if`, evitando o bug de escaping de aspas com `{{ }}` dentro de atributos HTML;
- `ConfigurationController::updateAppearance()` valida o tema contra a lista de permitidos (`default`, `jovem-tech`) e persiste ou limpa a sessão;
- a rota `POST /configuracoes/aparencia` foi adicionada ao grupo de middlewares autenticados;
- a UI de seleção em `Configurações do Sistema > Aparência` foi elevada para cards visuais com preview de paleta, ícone de confirmação e feedback de `is-active`;
- sublinks do menu colapsado recebem `color: #1F2937` no flyout branco (`:not(.is-collapsed)` recebe `rgba(255,255,255,0.72)` para o sidebar navy), corrigindo legibilidade em modo retraído;
- nota de entrega criada em `documentacao/07-novas-implementacoes/2026-07-02-jovem-tech-design-system-tema-desktop.md` e versão global ajustada para `3.5.0`.

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

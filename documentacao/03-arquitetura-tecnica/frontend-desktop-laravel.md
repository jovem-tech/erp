# Frontend Desktop Laravel

## Objetivo

Entregar o canal desktop em `frontends/desktop/` como uma aplicação Laravel separada, com Blade e sessão server-side, preservando a estrutura visual do `sistema-hml` sem reutilizar acesso direto ao banco nem reimplementar regras do backend.

O layout visual do desktop segue a mesma linguagem do legado: fundo claro, sidebar branca, topbar branca, cards com faixa de acento superior e blocos de dashboard inspirados no shell atual do `sistema-hml`.

## Fronteira arquitetural

### `backend/`

- API-only
- sem Blade
- sem HTML
- sem sessão de frontend
- fonte única de autenticação, autorização e regra de negócio

### `frontends/desktop/`

- cliente Laravel/Blade do backend central
- guarda o token Bearer apenas na sessão PHP
- renderiza layout, menu, navbar, tabelas, formulários e modais
- não acessa o banco `sistema_hml`
- não possui Models de negócio

## Estrutura principal da fase

```text
frontends/desktop/
|-- app/
|   |-- Exceptions/
|   |-- Http/Controllers/
|   |-- Http/Middleware/
|   |-- Providers/
|   |-- Services/
|   `-- Support/
|-- public/assets/
|   |-- css/desktop.css
|   `-- js/desktop.js
|-- resources/views/
|   |-- layouts/
|   |-- auth/
|   |-- dashboard/
|   |-- orders/
|   |-- search/
|   |-- notifications/
|   |-- profile/
|   |-- clients/
|   |-- equipments/
|   |-- users/
|   `-- groups/
`-- tests/Feature/Desktop/
```

## Camada obrigatória de services

Nenhum controller Blade chama `Http::` diretamente.

Services entregues:

- `ApiClient.php`
- `OrderService.php`
- `ClientService.php`
- `EquipmentService.php`
- `UserService.php`
- `GroupService.php`
- `SearchService.php`
- `NotificationService.php`
- `ProfileService.php`

### Responsabilidade do `ApiClient`

- montar a URL da API central via `DESKTOP_API_BASE_URL`;
- injetar `Authorization: Bearer` com o token da sessão;
- tratar `401` limpando a sessão;
- tratar `403` redirecionando para a primeira rota permitida;
- encapsular falhas de comunicação e detalhes do backend.

## Isolamento de classes em Apache compartilhado

Como `backend/` e `frontends/desktop/` podem coexistir no mesmo Apache do XAMPP durante o desenvolvimento local, o desktop não deve reutilizar FQCNs sensíveis do backend em classes de bootstrap ou de resolução automática do container.

Regra prática:

- manter nomes exclusivos para o provider principal do desktop;
- manter nome exclusivo para o controller base do canal desktop;
- evitar duplicar no desktop nomes de services já expostos pelo backend com o mesmo namespace `App\`.
- recarregar a `.env` local apenas em web SAPI, para impedir que valores do backend contaminem o desktop e vice-versa no mesmo worker Apache.

Essa proteção evita colisões de classe no mesmo processo PHP, que podem fazer o backend cair em configuração padrão incorreta durante chamadas internas do desktop para a API central.

## Sessão e autenticação

### Fluxo

1. o usuário autentica em `/login`;
2. o desktop envia credenciais para `POST /api/v1/auth/login`;
3. o token Bearer retorna ao desktop;
4. o token é salvo em `desktop_auth` na sessão do Laravel desktop;
5. cada request protegida usa o token a partir da sessão server-side;
6. o navegador nunca recebe esse token diretamente.

### Armazenamento local

- `SESSION_DRIVER=file` em desenvolvimento
- pronto para migrar para `database` ou outro driver no ambiente produtivo

## Middlewares do desktop

### `desktop.auth`

- valida presença de token na sessão;
- reidrata o perfil via `auth/me` com TTL configurável;
- ao falhar, limpa a sessão local e redireciona para login sem toast de expiração.

### `desktop.permission`

- recebe `modulo,acao` na rota;
- consulta as permissões efetivas vindas de `auth/me`;
- se negar acesso, redireciona para a primeira rota permitida do usuário;
- não envia o usuário para login em caso de `403`.

## Navbar funcional

A navbar do desktop já transpôs as funções principais do topo do legado:

- busca completa com autocomplete e filtro por escopo, incluindo texto livre em OS, clientes, equipamentos e orçamentos;
- ação rápida `Nova OS`, visível apenas com `os:criar`;
- notificações com contador, abertura do item, marcação individual e marcação geral, carregadas sob demanda para não travar o render inicial;
- loader visual de transição de página no desktop, exibido antes de navegações e submits para reduzir a sensação de travamento;
- menu do usuário com `Meu Perfil`, `Configurações do perfil`, `Sair` e `Sair e Esquecer Login`.
- recuperação de senha pública com envio de link para o e-mail cadastrado e tela de redefinição no desktop, sempre dependente de um canal seguro de e-mail configurado no backend central.

### Itens adiados

- `Configurações do sistema` foi deixado fora desta fase e volta apenas quando o módulo `Empresa` entrar no desktop.

## Configurações > Integrações

O primeiro painel de configurações entregue no desktop é `Configurações > Integrações`, ligado ao backend central e à ponte operacional do WhatsApp.

### Funcionalidades entregues

- ativar e desativar o canal WhatsApp no desktop;
- escolher o provedor direto e o provedor em massa;
- configurar Evolution API, gateway local e gateway Linux;
- configurar webhook de entrada;
- testar conexão, enviar mensagem de teste e executar self-check inbound;
- consultar status do gateway, QR code, reiniciar, fazer logout e iniciar o serviço;
- acessar a ajuda local do módulo sem sair do desktop;
- exibir estado e feedback visual com chips de conexão e segurança.

### Contrato consumido

- `GET /api/v1/configuracoes/integracoes`
- `PUT /api/v1/configuracoes/integracoes`
- `POST /api/v1/configuracoes/integracoes/testar-conexao`
- `POST /api/v1/configuracoes/integracoes/enviar-teste`
- `POST /api/v1/configuracoes/integracoes/self-check-inbound`
- `GET /api/v1/configuracoes/integracoes/gateway/status`
- `GET /api/v1/configuracoes/integracoes/gateway/qr`
- `POST /api/v1/configuracoes/integracoes/gateway/restart`
- `POST /api/v1/configuracoes/integracoes/gateway/logout`
- `POST /api/v1/configuracoes/integracoes/gateway/start`
- `POST /webhooks/whatsapp`

O desktop continua sem acesso direto ao banco. Toda interação com integrações passa pela camada de services e pelo backend central.

## Padrão Select2 do desktop

Todo `select` visível e interativo do `frontends/desktop` usa `Select2` com tema `Bootstrap 5` e inicialização compartilhada em `frontends/desktop/public/assets/js/desktop.js`.

- o helper global aplica `theme: 'bootstrap-5'`, `width: '100%'` e mensagens em `pt-BR`;
- `dropdownParent` é definido automaticamente para `modal` e `offcanvas`, evitando clipping visual e problemas de z-index;
- o helper é idempotente e expõe `window.DesktopUi.refreshSelect2(container)` para reconfigurar conteúdo renderizado dinamicamente;
- as telas de cadastro usam o helper compartilhado para reconstruir cascatas de catálogo em runtime, como `tipo -> marca -> modelo` no fluxo de equipamentos, reaplicando Select2 depois de atualizar as opções;
- o campo de cliente do cadastro de equipamentos usa Select2 com lista pré-carregada do backend, busca local e label operacional sincronizado com o valor selecionado;
- exceções devem ser explícitas com `data-native-select="true"` ou `data-select2="false"` apenas para controles técnicos, ocultos ou não destinados ao usuário final.

## Regra de ciclo de vida para modais e scripts

No layout base do desktop, todo modal renderizado via `@push('modals')` deve existir no DOM antes da execução de scripts de página que façam bind direto com `document.getElementById(...)`, `querySelector(...)` ou inicialização imediata equivalente.

Regra prática:

- `@stack('modals')` pertence ao layout base e precisa ser renderizado antes de `@yield('scripts')`;
- se um script depende de elementos do modal, ele pode assumir a existência desses elementos somente depois dessa ordem estar garantida, ou então deve aguardar `DOMContentLoaded`;
- quando um botão de modal parecer “morto”, sem submit e sem mensagem, verificar primeiro a existência do elemento no momento do bind JS, antes de suspeitar de `z-index`, `pointer-events` ou backdrop.

Essa regra ficou crítica após um incidente real no cadastro rápido de cliente em `equipamentos/novo`, onde o problema não era visual: os listeners nunca eram vinculados porque o modal estava sendo renderizado depois do script da página.

## Menu e autorização visual

O menu lateral é montado por `App\Support\DesktopNavigation`, usando os módulos e permissões efetivos do usuário.

A sidebar do desktop foi mantida intencionalmente enxuta: exibe marca, navegação e rodapé operacional, sem o card dedicado de nome/perfil do usuário. A identidade do usuário continua disponível na topbar e nas rotas de perfil, reduzindo ruído visual na lateral. O rodapé inclui um controle de versão discreto e centralizado, lido de `shared/version.php` e exposto via `config('app.version')`.
Na listagem de OS, a sidebar entra retraida por padrao para ampliar a area util da tabela operacional.

Como reforço de robustez, o `DesktopNavigation` descarta rotas que ainda nao existem no canal desktop antes de renderizar a sidebar. Isso evita `RouteNotFoundException` em paginas que compartilham o layout com modulos ainda nao liberados, sem alterar permissoes nem a hierarquia visual do menu.

O resumo de notificações da topbar é buscado sob demanda via rota same-origin autenticada, depois do carregamento inicial da página. Isso mantém o contador e a lista disponíveis sem obrigar cada troca de página a pagar o custo de consultar a API central no render do servidor.

O desktop também exibe um overlay de carregamento leve quando o usuário dispara navegações reais ou submits de formulário. O objetivo é dar feedback imediato de progresso sem alterar permissões, sessão ou contratos de API.

Como padrão de interface, todos os dropdowns do desktop usam `Select2`, com a mesma linguagem visual das telas do ERP e com exceções técnicas apenas quando documentadas de forma explícita.

Na seção `Comercial`, o grupo `Pessoas` foi transposto para o desktop com submenus próprios:

- `Clientes`
- `Fornecedores`
- `Equipe Técnica`

O grupo foi estruturado para ficar visualmente próximo ao padrão do legado, com expansão leve e sem mudar as permissões já aplicadas pelo backend central.

Módulos entregues nesta fase:

- dashboard
- ordens de serviço
- orçamentos
- busca completa
- notificações
- perfil do usuário
- serviços
- estoque de peças
- clientes
- equipamentos
- usuários
- grupos
- configurações > integrações
- pessoas, como agrupamento visual de `Clientes`, `Fornecedores` e `Equipe Técnica` na seção comercial da sidebar

## Módulos entregues

### Dashboard

- cabeçalho com `Dashboard` e `Ajuda do dashboard`
- 4 KPI cards com os dados principais do painel
- gráfico mensal `OS abertas x entregues reparadas por mês`
- gráfico de status em doughnut, com cores normalizadas a partir dos nomes semânticos do legado para valores válidos no Chart.js
- gráfico horizontal de tipos de equipamento com filtros de mês e ano
- card contextual financeiro ou técnico conforme o perfil
- tabela de últimas OS com modal de pré-visualização e link para página cheia
- bloco de estoque baixo condicionado ao retorno da API central
- rota local `GET /dashboard/ajuda` com documentação contextual do painel
- consumo do summary expandido via `GET /api/v1/dashboard/summary`
- atualização assíncrona dos widgets pela rota local `GET /dashboard/dados`
- uso de Chart.js via CDN, sem pipeline novo de frontend

### Ordens de serviço

- listagem com filtros
- detalhe completo
- criação de OS
- atualização de status
- acesso controlado a fotos e documentos
- preservação de filtros contextuais por cliente e equipamento ao navegar a partir dos módulos relacionados

### Busca completa

- autocomplete no topo
- resultados agrupados por domínio
- varredura em texto livre dos campos operacionais de OS, clientes, fornecedores, serviços, estoque, equipamentos e orçamentos
- resposta limitada aos domínios já suportados pelo backend central

### Notificações

- lista resumida na navbar
- página completa de notificações
- abertura do item com marcação automática como lida
- marcação geral de todas as notificações

### Perfil do usuário

- visão de leitura do `auth/me`
- atualização do nome
- troca de senha com retorno obrigatório ao login

### Serviços

- listagem com busca, filtros, exportação CSV e importação em lote
- cadastro e edição operacional
- encerramento e exclusão controlados por permissão
- modelo de importação disponível para leitura rápida do catálogo

### Estoque de peças

- listagem com busca, filtros e destaque operacional para estoque mínimo
- cadastro e edição de peças
- movimentações de entrada, saída e ajuste com histórico dedicado
- exportação CSV, modelo de importação e importação em lote

### Clientes

- listagem com busca, filtro, ordenação e menu único de ações por linha
- nome do cliente destacado com chips de quantidade de OS e equipamentos, aproximando a organização visual do legado
- cadastro de novo cliente com composição visual alinhada ao legado, incluindo blocos `DADOS PESSOAIS`, `CONTATO ADICIONAL (opcional)` e `ENDEREÇO`
- edição de cliente existente
- detalhe cadastral com OS e equipamentos relacionados, além de ação de nova OS contextual
- vínculo operacional com o fluxo de atendimento, usando o cliente como ponto de partida para OS e equipamentos
- cadastro rápido de cliente em modal a partir da tela de nova OS, preservando o fluxo operacional sem troca de página
- `status_cadastro` segue como contrato com o backend, porém permanece oculto na tela de criação para manter a paridade visual com o legado

### Equipamentos

- listagem com busca, filtro e menu único de ações por linha
- listagem com acao `Editar` no menu de acoes quando o usuario possui `equipamentos:editar`
- nome do equipamento destacado com chip de quantidade de OS e contexto de modalidade
- primeira coluna da listagem mostra a miniatura da foto principal via rota same-origin autenticada do desktop
- detalhe com relacionamento de cliente, lista de OS vinculadas e navegação filtrada por cliente e por equipamento
- detalhe com foto principal no canto superior direito do contexto do equipamento, sem acesso direto do browser a URL privada da API central
- detalhe com botao `Editar` quando o usuario possui `equipamentos:editar`

### Orçamentos

- listagem comercial com número, cliente, tipo, origem, vínculos, status, validade e total
- formulário em abas com `Dados do cliente`, `Dados do equipamento`, `Dados operacionais`, `Pacotes de serviço` e `Orçamento e financeiro`
- catálogo operacional carregado via API central para clientes, equipamentos, OS, serviços e peças
- detalhe com cards, itens, histórico, envios, aprovações e ações de edição/exclusão conforme permissão
- ajuda local dedicada ao módulo para orientar o uso do fluxo comercial
- cadastro completo em `/equipamentos/novo` com abas `Informações`, `Cor` e `Fotos`
- edicao completa em `/equipamentos/{id}/editar`, reutilizando o mesmo Blade e o mesmo JavaScript operacional do cadastro
- na aba `Informações`, o layout operacional fica em um único bloco: `Cliente` em largura total, linha corrida com `Tipo`, `Marca`, `Modelo` e `Nº Série ou IMEI`, senha logo abaixo, `Acessórios` e `Estado físico` lado a lado, e `Observações` fechando a seção
- em viewport móvel, os campos inline com ação rápida (`select/input + botão +`) continuam ocupando largura útil do container, sem colapsar o Select2 nem empurrar o input para largura zero
- criação exige ao menos uma foto no submit local e no backend, mantendo placeholder seguro para equipamentos legados sem imagem
- edicao exige ao menos uma foto no estado final, combinando fotos existentes e novas no mesmo preview antes do submit
- quick-add same-origin de cliente, marca e modelo sem sair do formulário
- quick-add de cliente, marca e modelo permanece condicionado as permissoes de criacao corretas, mesmo dentro da tela de edicao
- quick-add de marca e modelo sempre envia o `tipo_id` atual para o backend central, preservando o vínculo operacional do catálogo mesmo após recarregar a tela
- seletor de cliente em Select2 com lista pré-carregada do backend e busca local, preservando o label operacional no retorno de validação
- o modal compartilhado de cliente depende da ordem correta entre `@stack('modals')` e `@yield('scripts')`; sem isso, o botão pode ficar sem bind e falhar silenciosamente
- cascade de catálogo `tipo -> marca -> modelo` baseada na tabela `equipamentos_catalogo_relacoes`
- como a tabela legada exige `modelo_id` não nulo, o backend usa uma âncora técnica inativa para manter o vínculo `tipo -> marca` quando a marca é criada antes do primeiro modelo real, sem poluir os dropdowns do formulário
- seletor único `Nº Série ou IMEI`, mantendo a UX visível do legado e normalizando o envio pelo BFF
- senha por `Desenho` ou `Texto`, com persistência server-side consistente e grade de desenho exibida apenas ao acionar `Mostrar desenho`
- painel técnico condicional para desktop e notebook, com defaults de `Desktop montado`
- aba de cor com preview, catálogo rápido e atualização de `hex` e `rgb`
- aba de fotos com galeria, câmera, Cropper.js, preview local, remoção antes do envio, definição da foto principal, bloqueio do submit sem imagem e sincronizacao entre fotos existentes e novas no modo de edicao
- fluxo principal de coletor local legado, lendo `C:\JovemTechBenchCollector` e tentando executar o agente automaticamente quando o ERP estiver na mesma máquina Windows; o cartão do coletor só aparece quando o tipo selecionado pertence à família `desktop` ou `notebook`, e o pareamento remoto continua disponível como capacidade de apoio
- leitura de fotos sempre mediada pela rota autenticada do backend central, com reescrita para proxy same-origin no desktop antes de renderizar listagem, detalhe e a propria tela de edicao

### Usuários

- listagem
- criação
- edição
- ativação e desativação

### Grupos

- listagem
- criação
- edição
- exclusão
- matriz de permissões
- bloqueio visual e operacional para grupos `sistema = 1`

## Segurança aplicada

- token fora do navegador
- nenhum acesso direto ao banco pelo desktop
- `401` limpa sessão e retorna ao login sem aviso intrusivo
- `403` não derruba a sessão, apenas redireciona com mensagem
- RBAC do menu e das rotas usa o mesmo `auth/me`
- fotos, PDFs e notificações continuam mediados pelo backend central
- saída da API é tratada e escapada antes de entrar na navbar
- `Sair e Esquecer Login` limpa a sessão do desktop e qualquer estado lembrado do topo

## Validação executada

- `php artisan route:list`
- `php artisan test`
- smoke test de `GET /login` via Apache/XAMPP em `http://127.0.0.1:8080`
- login HTTP real com redirecionamento para `/dashboard`
- `php artisan test tests/Feature/Api/V1/EquipmentCreationTest.php` no backend
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter equipment` no desktop

## Próximo passo natural

Expandir o desktop para edição completa de OS e progressivamente migrar os demais módulos do legado usando o mesmo padrão: Blade + services + API central.

### Cartões e taxas

- painel financeiro com abas `Operadoras`, `Bandeiras`, `Taxa por parcela`, `Simulador de faturamento líquido` e `Taxas online`
- cadastro, edição e desativação dos catálogos financeiros sem acesso direto ao banco
- simulador de recebimento com retorno de taxa total, valor líquido e previsão de repasse
- ajuda local com foco operacional para uso no desktop
- todos os selects visíveis da tela usam Select2 com o helper compartilhado do canal desktop

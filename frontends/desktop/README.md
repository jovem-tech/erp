# Sistema ERP Desktop

Frontend desktop Laravel/Blade do `sistema-erp`, preservando a estrutura visual do legado `sistema-hml` sem acesso direto ao banco de dados.

O shell visual do desktop segue o padrao claro do legado: sidebar branca e recolhivel, topbar branca, cards com acento superior e blocos amplos para leitura rapida em desktop e em telas reduzidas. O rodape institucional do sistema aparece no fim da pagina em formato minimalista, exibindo versao, credito de desenvolvimento e copyright a partir da configuracao central.
Na listagem de OS, o shell abre com a sidebar retraida por padrao para ampliar a area util da tabela operacional.

## Responsabilidade deste projeto

- renderizar HTML, Blade, navegação e formulários do canal desktop;
- manter sessão server-side no próprio Laravel desktop;
- guardar o token do backend central apenas na sessão PHP;
- consumir a API do `backend/` exclusivamente pela camada de services;
- montar menu, navbar, busca completa e proteção de rotas a partir do payload de `GET /api/v1/auth/me`;
- transpor para o desktop as funções do topo do legado: busca completa, `Nova OS`, notificações, `Meu Perfil`, `Configurações do perfil`, `Sair` e `Sair e Esquecer Login`.
- oferecer recuperação de senha por e-mail com link temporário apontando para a tela de redefinição do desktop.
- padronizar todos os dropdowns do canal desktop com `Select2` + tema `Bootstrap 5`, usando helper compartilhado e `dropdownParent` automático em modais e offcanvas.
- organizar a seção comercial da sidebar com o grupo `Pessoas`, contendo `Clientes`, `Fornecedores` e `Equipe Técnica`, no mesmo padrão de agrupamento do legado;
- proteger a sidebar contra rotas inexistentes: itens e subitens sem registro em `Route::has()` sao descartados antes da renderizacao, evitando falha de tela por `RouteNotFoundException`.

## Dashboard com paridade visível do legado

O dashboard do desktop agora espelha o que é visível no `/dashboard` do `sistema-hml`:

- cabeçalho com `Dashboard` e `Ajuda do dashboard`;
- 4 cards principais com KPI e resumo geral;
- gráfico mensal `OS abertas x entregues reparadas por mês`;
- gráfico de status em doughnut, com cores normalizadas a partir dos nomes semânticos do legado para evitar renderização inválida;
- gráfico horizontal de tipos de equipamento com filtros de mês e ano;
- card contextual financeiro ou técnico conforme o perfil;
- tabela de últimas OS com ação de pré-visualização em modal e link para página cheia;
- bloco de estoque baixo somente quando a API central retornar itens.

O dashboard consome `GET /api/v1/dashboard/summary` em formato expandido e também expõe `GET /dashboard/dados` para atualizar os widgets sem reload completo da página.

## O que este frontend não faz

- não acessa o banco `sistema_hml` diretamente;
- não possui Models de negócio para OS, clientes, equipamentos, usuários ou grupos;
- não reimplementa autenticação ou RBAC fora do backend central;
- não duplica regra de negócio do legado;
- não exibe `Configurações do sistema` nesta fase, porque isso foi adiado para o módulo `Empresa`.

## Requisitos

- PHP 8.3+
- Composer
- backend central disponível em `http://127.0.0.1:8000/api/v1` via Apache/XAMPP

## Como executar localmente

- Garanta que o Apache do XAMPP esteja em execução.
- Acesse `http://127.0.0.1:8080`.
- O vhost da porta `8080` aponta para `frontends/desktop/public`.

```bash
cd frontends/desktop
composer install
copy .env.example .env
php artisan key:generate
```

## Variáveis de ambiente principais

- `APP_URL=http://127.0.0.1:8080`
- `SESSION_DRIVER=file`
- `DESKTOP_API_BASE_URL=http://127.0.0.1:8000/api/v1`
- `DESKTOP_API_TIMEOUT=15`
- `DESKTOP_PROFILE_SYNC_TTL=300`

## Fluxo de autenticação

1. o usuário faz login no desktop;
2. o desktop envia as credenciais para `POST /api/v1/auth/login`;
3. o backend central devolve o token Bearer;
4. o token é salvo apenas na sessão do Laravel desktop;
5. a cada request protegida, o `ApiClient` injeta o token no header;
6. `401` limpa a sessão e redireciona para login sem toast automático de sessão expirada;
7. `403` redireciona para a primeira rota permitida com mensagem de acesso negado.

## Isolamento de classes no Apache compartilhado

Quando `backend/` e `frontends/desktop/` rodam no mesmo Apache do XAMPP, classes runtime com o mesmo FQCN entre os dois Laravel podem colidir dentro do mesmo processo PHP.

Para evitar isso, o desktop mantém nomes exclusivos nas classes base que participam do bootstrap e do fluxo operacional compartilhado, como o service provider principal, o controller base e services de domínio carregados pelo container.

O bootstrap web do desktop tambem recarrega a propria `.env` somente no SAPI de servidor web, para neutralizar vazamento de configuracao entre o backend central e o canal desktop quando ambos compartilham o mesmo worker PHP.

## Navbar funcional

### Itens já portados do legado

- busca completa com autocomplete e escopo por domínio, incluindo texto livre em OS, clientes, fornecedores, serviços, estoque, equipamentos e orçamentos;
- ação rápida `Nova OS`, visível somente com permissão `os:criar`;
- notificações com contador, lista, abertura do item e marcação geral;
- menu do usuário com `Meu Perfil`, `Configurações do perfil`, `Sair` e `Sair e Esquecer Login`.
- rodape global minimalista do sistema no fim da pagina com versao, copyright e credito de desenvolvimento.

### Itens adiados

- `Configurações do sistema` ficou fora desta fase e será retomado quando o módulo `Empresa` entrar no desktop.

## Configurações > Integrações

O primeiro painel de configurações entregue no desktop é `Configurações > Integrações`, conectado ao backend central e à ponte do WhatsApp.

### O que esta tela entrega

- ativação e desativação do canal WhatsApp no desktop;
- seleção do provedor direto e do provedor em massa;
- configuração da Evolution API;
- configuração do gateway local e do gateway Linux;
- configuração do webhook de entrada;
- status da conexão, QR code, reinício, logout e start do gateway;
- envio de teste, teste de conexão e self-check inbound;
- ajuda local dedicada ao módulo;
- visualização de estado com chips e feedback visual para conexão saudável.

### Contrato operacional

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

O desktop continua sem acesso direto ao banco e sem lógica de integração embutida no controller; toda comunicação passa pela camada de services.

## Módulos entregues nesta fase

- dashboard com paridade visual do legado visível e gráficos reais via Chart.js CDN
- ordens de serviço: listagem, detalhe, criação, atualização de status, fotos e documentos, com preservação de filtros contextuais por cliente e equipamento; na rota `/os`, a sidebar abre retraida por padrao para priorizar a area util da tabela
- busca completa
- notificações
- perfil do usuário
- serviços: listagem, cadastro, edição, encerramento, exclusão, exportação CSV, modelo de importação e importação em lote
- estoque de peças: listagem, cadastro, edição, encerramento, exclusão, exportação CSV, modelo de importação, importação em lote e movimentações operacionais
- fornecedores: listagem, cadastro, edição, encerramento, exclusão, ajuda local e consulta de CNPJ, com preenchimento automático dos campos principais a partir da API central
- clientes: listagem, cadastro, edição e detalhe operacional com OS, equipamentos e ações de contato; a listagem agora segue o padrão do legado com nome, chips de quantidade, colunas operacionais e menu único de ações por linha; o formulário de novo cliente foi reorganizado em blocos operacionais (`DADOS PESSOAIS`, `CONTATO ADICIONAL (opcional)` e `ENDEREÇO`) para seguir a leitura visual do legado; a OS passou a oferecer cadastro rápido de cliente em modal, com atualização imediata do seletor sem sair do fluxo; a sidebar comercial passou a agrupar este domínio sob `Pessoas`, junto de `Fornecedores` e `Equipe Técnica`
- equipamentos: listagem e detalhe com organização operacional, chips de quantidade de OS, menu único de ações, botão `Editar` condicionado a `equipamentos:editar` e navegação contextual para cliente e OS
- equipamentos: cadastro e edição completos, reaproveitando o mesmo formulário operacional em `/equipamentos/novo` e `/equipamentos/{id}/editar`, com abas `Informações`, `Cor` e `Fotos`, quick-add de cliente/marca/modelo, seletor de cliente em Select2 com lista pré-carregada do backend e busca local, cascade de catálogo `tipo -> marca -> modelo` baseado na tabela de relações do backend, senha por desenho ou texto, preview de cor, até 4 fotos com câmera/galeria/cropper e integração principal com coletor local legado em `C:\JovemTechBenchCollector`, mantendo o pareamento remoto como capacidade de apoio; o cartão do coletor local só aparece quando o tipo selecionado pertence à família `desktop` ou `notebook`; a modalidade `Desktop montado` é exclusiva do tipo `Desktop` — para `Notebook` o campo `Modalidade` fica travado em `OEM / fabricante` e marca/modelo reais do catálogo são sempre exigidos; na edição, fotos existentes e novas convivem no mesmo preview, com troca da principal e remoção segura antes do submit
- orçamentos: listagem, criação, edição, detalhe, ajuda local, catálogo operacional e tabela de itens com histórico, envios e aprovações, mantendo o fluxo visual do legado
- usuários: listagem, criação, edição e ativação
- grupos: listagem, criação, edição, exclusão e matriz de permissões
- configurações > integrações: painel operacional de WhatsApp, Evolution, gateway local/Linux e webhook de entrada
- cartões e taxas: painel financeiro com operadoras, bandeiras, taxas por parcela, simulador de recebimento e taxas online, mantendo Select2 nos selects visíveis e ajuda local dedicada

## Validação executada na fase

- `php artisan route:list`
- `php artisan test`
- smoke test real em `http://127.0.0.1:8080/login` via Apache/XAMPP
- login HTTP com redirecionamento para `http://127.0.0.1:8080/dashboard`
- `php artisan test tests/Feature/Api/V1/EquipmentCreationTest.php` no backend central
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter equipment` no desktop
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter orcamento` no desktop

## Próximo passo natural

Expandir o desktop para uma paridade mais completa dos módulos do legado, mantendo o mesmo padrão: Blade + services + API central, sem acesso direto ao banco.

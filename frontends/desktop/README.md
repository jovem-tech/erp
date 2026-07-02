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
- expor o fluxo de trabalho visual de OS em `Gestão de Conhecimento`, com diagrama por macrofase e matriz de transições editável;
- expor também o modelo ideal da assistência técnica em `Gestão de Conhecimento`, com diagrama operacional focado em fila saudável, SLA, WIP e saídas controladas, incluindo um fluxo natural simulado com os status atuais.
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
- gestão de conhecimento: fluxo de trabalho de OS e modelo ideal da assistência técnica, ambos com leitura visual e foco operacional para reduzir gargalos, incluindo a simulação do caminho feliz da OS
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

## Manutenção do sistema visual

### Design system consolidado
Desde 2026-07-01, o frontend desktop opera com um **design system tokenizado**:

#### Paleta de cores semântica
- **Primário:** `#6f5afc` (roxo, gradiente active state até `#8b5cf6`)
- **Títulos/Headings:** `var(--desktop-heading)` = `#1f2a44` (navy escuro, padroniza 29 ocorrências)
- **Sucesso (positivo):** `var(--desktop-success-text)` = `#0e8f5d` + `var(--desktop-success-rgb)` = `22, 163, 74`
- **Perigo (negativo):** `var(--desktop-danger-text)` = `#b91c1c` + `var(--desktop-danger-rgb)` = `239, 68, 68`
- **Texto mutado:** `var(--desktop-text-muted)` = `#6b7280` (contraste ≥4.5:1 WCAG AA sobre fundo branco)
- **Superfícies:** `var(--desktop-surface)` (fundo de cards), `var(--desktop-surface-soft)` (fundos suaves)

#### Bootstrap 5.3.3 alineado
Variáveis de utilitário Bootstrap (`--bs-*`) são sobrescritas no `:root` para alinhar automáticamente com a paleta do sistema:
- `--bs-success-rgb`, `--bs-danger-rgb`, `--bs-secondary-color` mapeiam para tokens desktop
- Afeta `.text-danger`, `.text-success`, `.text-muted` e variações sem necessidade de editar as 150+ ocorrências nas views

#### Tipografia
- **Fonte:** Plus Jakarta Sans, pesos 400/500/600/700/800 (self-hosted, woff2)
- **Sem `font-weight: 900`:** todos os 23 usos normalizados para 800 (a fonte não possui 900; a renderização anterior era faux-bold)
- **Tamanhos base:** definidos em tokens `--desktop-text-*` e aplicados via classes semânticas

#### Escala de border-radius semântica
- `--desktop-radius-control` = `14px` (campos de formulário, botões pequenos)
- `--desktop-radius-md` = `12px` (cards, pills médias)
- `--desktop-radius-lg` = `20px` (cards grandes, superfícies principais)
- `--desktop-radius-full` = `999px` (pills, avatares)

**Impacto visual:** consolidação resulta em **zero mudança perceptível** na aparência; apenas elimina fragmentação, duplicação e intenções mortas no CSS.

### Temas e identidade visual (desde 2026-07-02)

O desktop suporta **múltiplos temas** selecionáveis pelo usuário em `Configurações > Sistema > Aparência`.

#### Temas disponíveis

| Tema | Slug | Primário | Sidebar | Background |
|------|------|----------|---------|------------|
| Padrão | `default` | `#6f5afc` (roxo) | Branca | `#f5f7fc` |
| Jovem Tech | `jovem-tech` | `#3868B0` (azul) | Navy gradient `#254F8D → #1E4278` | `#F4F8FF` |

#### Arquitetura dos temas

- **Arquivo de tema:** `public/assets/css/themes/{slug}.css`
- **Escopo CSS:** bloco `[data-theme="{slug}"]` — sem impacto no tema padrão
- **Aplicação:** atributo `data-theme` no `<html>` via diretiva `@if` no `app.blade.php`
- **Preferência:** armazenada em sessão Laravel (`desktop_theme`) — sem migração de banco
- **Carregamento:** CSS do tema é incluído condicionalmente após `desktop.css`, sobrescrevendo apenas as propriedades necessárias

#### Adicionar um novo tema

1. Criar `public/assets/css/themes/{slug}.css` com bloco `[data-theme="{slug}"] { ... }`
2. Adicionar `{slug}` à lista `$allowed` em `ConfigurationController::updateAppearance()`
3. Adicionar um card de preview na view `configurations/system.blade.php`

#### Sublinks em modo colapsado

O flyout do sidebar colapsado usa fundo branco (`--desktop-surface`). O seletor de cor dos sublinks deve ser separado:
- Sidebar **expandida:** `color: rgba(255,255,255,0.72)` (via `:not(.is-collapsed)`)
- Flyout **colapsado:** `color: #1F2937` (texto escuro sobre fundo branco)

### Robustez offline
Todas as bibliotecas JavaScript e CSS, incluindo ícones e fontes web, são **self-hosted** em `public/assets/libs/` e `public/assets/fonts/`:
- ✅ Bootstrap 5.3.3 (CSS + JS)
- ✅ Bootstrap Icons (CSS + woff2 fonts)
- ✅ Select2 + tema Bootstrap 5
- ✅ SweetAlert2
- ✅ jQuery 3.7.1
- ✅ Chart.js
- ✅ CropperJS
- ✅ Plus Jakarta Sans (local `@font-face`)

**Benefício:** aplicação funciona em modo offline ou em LAN desconectada da internet; não há ponto único de falha via CDN (googleapis.com, jsDelivr, etc.).

### Responsividade em mobile
Abas de detalhes de OS (`.os-tabs-card`) em devices ≤480px renderizam como **grid 2-coluna** (6 botões em layout 2×3) em vez de carousel horizontal, evitando transbordamento:
```css
@media (max-width: 480px) {
    .os-tabs-card .equipment-tabs {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }
    .os-tabs-card .equipment-tab {
        width: 100%;
        font-size: 0.8rem;
        padding: 0.55rem 0.8rem;
    }
}
```

### Sidebar em modo retraído (collapsed)
A navegação lateral em modo retraído (80px) oferece experiência de usuário robusta:

#### ✅ Acessibilidade
- **Zona de clique**: 44x44px mínimo (atende WCAG AAA para mobile)
- **Separadores visuais**: Linhas entre seções mesmo em collapsed, mantendo hierarquia

#### ✅ Usabilidade
- **Tooltips ao hover**: Mostram o nome completo do link em navy com animação suave (puro CSS, sem JS)
- **Feedback visual**: Hover com sombra inset 1.5px + background roxo 0.06
- **Active state**: Triple feedback — sombra interna + glow externo + cor primária + background

#### ✅ Clareza
- **Ícones únicos**: Sem duplicatas (`bi-truck` para Fornecedores, `bi-plug` para Integrações)
- **Semântica visual**: Cada ícone possui significado claro em contexto colapsado

**Mais detalhes**: `SIDEBAR_IMPROVEMENTS.md`

## Próximo passo natural

Expandir o desktop para uma paridade mais completa dos módulos do legado, mantendo o mesmo padrão: Blade + services + API central, sem acesso direto ao banco.

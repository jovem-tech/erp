# Fase 7 - Frontend Desktop Laravel com Paridade Visual do Legado

## Data

2026-06-22

## Entrega

Foi criado o novo canal desktop em `frontends/desktop/` como uma aplicação Laravel separada, consumindo apenas a API do backend central e preservando a organização visual do `sistema-hml`.

## O que entrou no código

### Base do projeto desktop

- scaffold Laravel 13.x em `frontends/desktop/`
- `.env` e `.env.example` ajustados para o canal desktop
- `APP_KEY` do projeto desktop
- `SESSION_DRIVER=file` em desenvolvimento

### Integração com o backend central

- `config/services.php` com `erp_api`
- `App\Services\ApiClient`
- services por domínio:
  - `OrderService`
  - `ClientService`
  - `EquipmentService`
  - `UserService`
  - `GroupService`
  - `SearchService`
  - `NotificationService`
  - `ProfileService`

### Sessão e permissão

- `DesktopSession`
- `DesktopNavigation`
- middleware `desktop.auth`
- middleware `desktop.permission`
- tratamento centralizado de `401`, `403` e erros de request no bootstrap do app
- filtro defensivo de rotas inexistentes na sidebar antes da renderizacao, evitando `RouteNotFoundException` em telas que compartilham o layout com modulos ainda nao expostos

### Interface entregue

- layout base Blade
- sidebar
- topbar
- busca completa com autocomplete
- menu do usuário com `Meu Perfil`, `Configurações do perfil`, `Sair` e `Sair e Esquecer Login`
- painel de notificações com contador e ações
- formulário de criação de OS
- feedback com SweetAlert2
- dashboard com paridade visível do legado
- ordens de serviço
- busca completa
- notificações
- perfil do usuário
- clientes
- equipamentos
- usuários
- grupos
- matriz de permissões

### Ajuste visual de paridade

- o shell do desktop foi alinhado ao padrão claro do `sistema-hml`
- a sidebar foi redesenhada com aparência branca e estados ativos iguais ao legado
- o card dedicado de nome/perfil foi removido da sidebar para deixar a navegação lateral mais limpa e compacta
- o rodapé da sidebar passou a exibir um controle de versão discreto e centralizado, sem misturar essa informação com a rota atual
- a topbar ganhou a busca completa funcional, o atalho `Nova OS`, notificações e menu do perfil
- o login passou a exibir a opção `Esqueci minha senha`, com fluxo completo de recuperação por e-mail e redefinição no desktop
- o dashboard passou a usar cards com acento superior, gráficos reais via Chart.js CDN, modal de pré-visualização da OS e ajuda local
- o gráfico de status do dashboard passou a normalizar cores semânticas do legado antes de renderizar no Chart.js, evitando anéis pretos ou cores inválidas
- o gráfico de tipos de equipamento passou a usar o agrupamento por tipo do legado e orientação horizontal, como no dashboard original
- o `GET /api/v1/dashboard/summary` foi expandido para entregar todos os blocos visíveis do painel legado
- o desktop passou a expor `GET /dashboard/dados` e `GET /dashboard/ajuda` para atualização assíncrona e documentação contextual

## Decisões preservadas

- o desktop continua sem acesso direto ao banco
- o token do backend não vai para `localStorage`, `sessionStorage` ou cookie do navegador
- o backend segue como fonte única de auth, RBAC e arquivos
- `sistema_hml` permanece intacto como referência visual e funcional
- `Configurações do sistema` foi adiado para o módulo `Empresa`, como pedido
- a expiração de sessão no desktop volta ao login de forma silenciosa, sem toast automático de aviso

## Validação executada

### Desktop

- `php artisan route:list`
- `php artisan optimize:clear`
- `php artisan test`

### Smoke test real

- `GET http://127.0.0.1:8080/login` retornando `200`
- `POST http://127.0.0.1:8080/login` com redirecionamento para `/dashboard`
- dashboard renderizando com menu, navbar funcional e shell do desktop

## Impacto arquitetural

Esta fase fecha a primeira versão funcional do desktop como cliente do backend central e consolida a estratégia multicanal:

- mobile via Next.js
- desktop via Laravel/Blade
- backend único em Laravel
- banco único da nova plataforma

## Próximo passo natural

Expandir a paridade funcional do desktop nos fluxos de criação e edição completa de OS, mantendo o mesmo padrão de arquitetura sem acesso direto ao banco.


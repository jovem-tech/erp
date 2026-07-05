# Sistema ERP

Base física da nova plataforma modular.

## Repositório e ambientes oficiais

- **Repositório:** `https://github.com/jovem-tech/erp` (privado). Branches `develop`
  (trabalho ativo) e `main` (produção). Ver
  [workflow-git-multiambiente.md](documentacao/10-deploy/workflow-git-multiambiente.md).
- **Desenvolvimento oficial:** servidor Linux `192.168.1.100` (clone git da branch
  `develop`). Ver
  [ambiente-dev-linux-bancada.md](documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md).
- **Produção oficial:** VPS Ubuntu (Contabo, `161.97.93.120`, clone git da branch
  `main`). Ver
  [deploy-producao-contabo-vps.md](documentacao/10-deploy/deploy-producao-contabo-vps.md).

**Windows/XAMPP está definitivamente descontinuado para desenvolvimento.** Não editar
código neste projeto a partir de uma cópia local em XAMPP — o ambiente oficial é o
servidor Linux acima, via SSH. Toda decisão de arquitetura, automação, segurança,
filesystem, paths e deploy deve priorizar compatibilidade com Linux/Ubuntu.

## Estrutura principal

```text
C:\xampp\htdocs\sistema-erp
|-- backend/
|   |-- app/
|   |   |-- Http/Controllers/Api/V1/
|   |   |-- Http/Requests/Api/V1/
|   |   `-- Models/
|   |-- config/
|   |-- database/
|   |   `-- migrations/
|   |-- public/
|   `-- storage/
|       |-- app/private/
|       `-- logs/
|-- frontends/
|   |-- desktop/
|   |-- mobile/
|   |-- totem/
|   `-- tv/
|-- shared/
|-- documentacao/
|-- specs/
|-- infra/
`-- scripts/
```

> O antigo `frontend/sistema-hml/` (clone do legado evoluindo como BFF) foi
> descontinuado em 2026-06-25 e arquivado fora do projeto ativo. Ver
> `documentacao/07-novas-implementacoes/2026-06-25-descontinuacao-frontend-sistema-hml.md`
> para o histórico da decisão.

## Decisão adotada

- `backend/` é o backend central da plataforma.
- `frontends/` reúne os clientes por canal de uso.
- `backend/storage/app/private/` guarda fotos, PDFs e anexos sem exposição pública direta.
- `backend/storage/logs/` concentra os registros internos da aplicação.
- `documentacao/` é a fonte de verdade da arquitetura e da implantação.
- `shared/version.php` é a fonte única da versão exibida no backend e no desktop.

## Fase atual

Fase 7 concluída:

- backend Laravel 13.x validado como backend central;
- API v1 preservada e ampliada para uso administrativo;
- Sanctum com token Bearer e expiração explícita;
- `auth/me` enriquecido com `group`, `modules` e `permissions`;
- RBAC central consumindo `usuarios`, `grupos`, `modulos`, `permissoes` e `grupo_permissoes`;
- cache de permissões por usuário com invalidação explícita;
- fallback legado `perfil = admin` mantido como ponte auditada;
- listagem mobile de OS preservada para técnico;
- listagem geral, criação e edição administrativa de OS entregues;
- leitura administrativa de clientes e equipamentos entregue;
- gestão de usuários, grupos, módulos e permissões entregue;
- grupos `sistema = 1` protegidos contra edição, alteração de permissões e exclusão;
- frontend desktop Laravel criado em `frontends/desktop/`;
- sessão server-side do desktop validada com token guardado apenas no servidor;
- camada obrigatória de services entre controllers Blade e backend central;
- dropdowns visíveis do desktop padronizados com `Select2` + tema `Bootstrap 5`, helper compartilhado e `dropdownParent` em modais/offcanvas;
- middlewares próprios para sessão e permissão por rota;
- dashboard, OS, clientes, equipamentos, usuários e grupos navegáveis no desktop;
- testes do backend e do desktop completos e verdes.

## Acesso local

- Backend: `http://127.0.0.1:8000` via Apache/XAMPP
- Health: `http://127.0.0.1:8000/api/v1/health`
- API v1: `http://127.0.0.1:8000/api/v1`
- Frontend desktop: `http://127.0.0.1:8080` via Apache/XAMPP
- Frontend mobile: `http://127.0.0.1:3001` (ou a próxima porta livre informada pelo terminal do Next no ambiente local)
- Frontend chat: `http://127.0.0.1:3002`
- Reverb/WebSocket do chat: `ws://127.0.0.1:8090`

## Como subir o backend

```bash
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --force
```

> No ambiente de desenvolvimento oficial (`192.168.1.100`, Linux), o backend sobe via
> Nginx + PHP-FPM. Consulte `documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md`.
> O manual de XAMPP (`documentacao/10-deploy/manual-inicializacao-local-windows-xampp.md`)
> é apenas histórico — não usar para desenvolvimento novo.

## Como subir o frontend mobile

```bash
cd frontends/mobile
pnpm install
pnpm dev
```

> Observação: a validação desta fase foi feita com Node.js 24 no runtime empacotado do workspace. O frontend mobile exige um Node compatível com Next.js 15. Se a porta `3001` estiver ocupada no ambiente local, `pnpm dev` inicia automaticamente na próxima porta livre e informa a URL no terminal.

## Como subir o frontend desktop

```bash
cd frontends/desktop
composer install
copy .env.example .env
php artisan key:generate
```

> No ambiente de desenvolvimento oficial (`192.168.1.100`, Linux), o desktop sobe via
> Nginx + PHP-FPM. Consulte `documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md`.
> O manual de XAMPP é apenas histórico — não usar para desenvolvimento novo.

## Como subir o frontend chat

```bash
cd frontends/chat
npm install
copy .env.example .env
npm run dev
```

## Como subir o Reverb do chat

```bash
cd backend
php artisan reverb:start --host=127.0.0.1 --port=8090
```

## Referências

- [Documentação principal](documentacao/README.md)
- [Fluxo Git multiambiente (develop → main → VPS)](documentacao/10-deploy/workflow-git-multiambiente.md)
- [Ambiente de desenvolvimento oficial (Linux)](documentacao/02-infraestrutura-ambientes/ambiente-dev-linux-bancada.md)
- [Deploy de produção na VPS Contabo](documentacao/10-deploy/deploy-producao-contabo-vps.md)
- [Manual de inicialização local (Windows/XAMPP, histórico/descontinuado)](documentacao/10-deploy/manual-inicializacao-local-windows-xampp.md)
- [Contrato de ambiente](documentacao/01-fundacao/contrato-de-ambiente.md)
- [Contrato da API do backend central](documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md)
- [Backend central mínimo](documentacao/03-arquitetura-tecnica/backend-central-minimo.md)
- [Backend administrativo e RBAC](documentacao/03-arquitetura-tecnica/backend-administrativo-rbac.md)
- [Frontend desktop Laravel](documentacao/03-arquitetura-tecnica/frontend-desktop-laravel.md)
- [Fluxo de OS mobile](documentacao/03-arquitetura-tecnica/ordens-mobile.md)
- [Frontend mobile](frontends/mobile/README.md)
- [Frontend desktop](frontends/desktop/README.md)

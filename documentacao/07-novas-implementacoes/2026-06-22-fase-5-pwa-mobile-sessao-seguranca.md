# Fase 5 - PWA Mobile, Sessão e Segurança

## Resumo executivo

A Fase 5 consolidou o fluxo real do canal mobile em cima do backend central Laravel:

- login com credenciais do ERP;
- token Bearer com `expires_at` explícito;
- refresh que revoga o token anterior e emite outro;
- sessão persistida no navegador;
- proteção por CSP no frontend mobile;
- listagem de OS do técnico autenticado;
- detalhe da OS com cliente, equipamento, histórico recente, status disponíveis e anexos controlados;
- atualização de status com sincronismo de `status` e `estado_fluxo`;
- acesso a fotos e PDFs apenas por endpoint controlado.

## Implementação entregue

### Backend

- `AuthController` passou a gravar expiração explícita ao criar token.
- `Sanctum` foi configurado com expiração base.
- O scheduler do Laravel passou a limpar tokens expirados.
- O fluxo de OS agora expõe `status_disponiveis` no detalhe da OS.

### Frontend mobile

- Foi criado o scaffold Next.js em `frontends/mobile`.
- A sessão foi centralizada em `localStorage` com reidratação e expiração.
- O cliente HTTP envia `Authorization: Bearer` para a API.
- A CSP foi configurada para desenvolvimento e produção.
- A tela de login foi protegida com `Suspense` para compatibilidade com o App Router.
- A tela de listagem e o detalhe da OS consomem a API real.
- Anexos protegidos são abertos por `blob URL`, sem exposição direta do arquivo.

## Validação executada

- `npm install` não foi usado no ambiente de validação final porque o runtime do sistema estava em Node 14.
- A instalação e a build do frontend foram validadas com o Node empacotado do workspace e `pnpm`.
- `pnpm lint` no `frontends/mobile` passou.
- `pnpm build` no `frontends/mobile` passou.
- `php artisan test --filter=AuthFlowTest` passou.
- `php artisan test --filter=OrderFlowTest` passou.
- `php artisan test` passou.

## Critérios de aceite atendidos

- Login funcional com token Bearer.
- Refresh funcional sem criar endpoint novo.
- Expiração explícita de sessão.
- Lista e detalhe de OS funcionando no mobile.
- Fotos e PDFs protegidos por backend.
- Status de OS atualizado com segurança.
- Documentação atualizada para o fluxo implementado.

## Observação final

O `sistema-hml` permaneceu intacto. Toda a evolução desta fase ficou concentrada em `C:\xampp\htdocs\sistema-erp`.

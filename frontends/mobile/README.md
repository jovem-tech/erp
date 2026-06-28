# Sistema ERP Mobile

PWA mobile do `sistema-erp`, consumindo o backend central Laravel via `Bearer token` com sessão persistida no navegador.

## Requisitos

- Node.js 18 ou superior;
- gerenciador de pacotes compatível com o projeto;
- backend central disponível em `http://127.0.0.1:8000/api/v1` no ambiente local.

## Como executar

```bash
cd frontends/mobile
pnpm install
pnpm dev
```

> O script `pnpm dev` prioriza `http://127.0.0.1:3001`. Antes de escolher essa porta, ele valida conflito no bind IPv4 local (`127.0.0.1`) e no bind IPv6 usado pelo Next (`::`) para evitar o falso positivo comum no Windows/XAMPP. Se a porta estiver ocupada no ambiente local, ele sobe o Next automaticamente na próxima porta livre e informa a URL correta no terminal.
>
> A fase 5 foi validada com o runtime Node empacotado no workspace e com `pnpm`. Se você preferir outro gerenciador, mantenha a compatibilidade com Node 18+ e com o lockfile do projeto.

## Testes

```bash
pnpm test          # roda a suíte uma vez (vitest run)
pnpm test:watch    # modo watch durante desenvolvimento
```

Suíte criada em 2026-06-25 (`vitest` + `@testing-library/react`, ambiente
`jsdom`). Cobertura inicial em `src/lib/__tests__/`: `session.ts` (sessão em
`localStorage`, expiração) e `api.ts` (`apiLogout`, `ApiError`). Adicione
testes nesta pasta ao alterar lógica de sessão/autenticação ou ao introduzir
novos helpers de API.

## Variáveis de ambiente

- `NEXT_PUBLIC_API_BASE_URL=http://127.0.0.1:8000/api/v1`
- `NEXT_PUBLIC_APP_URL=http://127.0.0.1:3001` (porta preferencial; no dev local o terminal pode informar outra porta livre)
- `NEXT_PUBLIC_APP_NAME="Sistema ERP Mobile"`
- `NEXT_PUBLIC_CHANNEL=mobile`

## O que este frontend faz

- login com credenciais do ERP;
- persistência de sessão no navegador com expiração;
- validação de sessão ao abrir o app;
- listagem de OS atribuídas ao técnico;
- detalhe da OS com histórico recente, fotos e PDFs controlados;
- atualização de status com retorno ao backend central;
- nav bar autenticada com sino de notificações, menu do usuário e alternância de tema claro/escuro;
- edição do nome de perfil e troca de senha para o usuário autenticado;
- notificações operacionais em dropdown, atualizadas dinamicamente, com ações de leitura individual e em lote;
- logout com revogação no backend;
- CSP específica para desenvolvimento e produção.

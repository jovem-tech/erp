# Tasks: Sessão e segurança do PWA mobile

## Phase 1: Fundação

Story goal: preparar o canal mobile e fechar o contrato de ambiente para que a autenticação e a navegação comecem sem ambiguidade.

Independent test criteria:
- o app mobile sobe localmente;
- o backend emite token com validade real;
- o contrato de ambiente aponta para a API correta.

- [X] T001 [P] Criar o scaffold inicial do frontend mobile em `frontends/mobile/package.json`, `frontends/mobile/tsconfig.json`, `frontends/mobile/next.config.ts`, `frontends/mobile/src/app/layout.tsx` e `frontends/mobile/src/app/page.tsx`
- [X] T002 [P] Atualizar o contrato de ambiente em `frontends/mobile/.env.example` e `documentacao/01-fundacao/contrato-de-ambiente.md` para refletir o canal mobile e a URL da API
- [X] T003 Ajustar a expiração do token no backend em `backend/app/Http/Controllers/Api/V1/AuthController.php`, `backend/config/sanctum.php` e `backend/routes/console.php`, definindo `expiresAt` explicitamente no `createToken` com validade de 7 dias
- [X] T004 Ampliar a cobertura de autenticação em `backend/tests/Feature/Api/V1/AuthFlowTest.php` para cobrir login, refresh, logout e expiração

## Phase 2: Base de sessão

Story goal: garantir que o app consiga guardar, reidratar e invalidar a sessão de forma centralizada.

Independent test criteria:
- o app salva a sessão após login;
- ao reabrir, o app restaura o estado enquanto o token é válido;
- respostas `401` limpam a sessão e levam ao login.

- [X] T005 [P] Criar o cliente HTTP central em `frontends/mobile/src/lib/api.ts` com envio padrão de `Authorization: Bearer`
- [X] T006 [P] Criar o armazenamento de sessão em `frontends/mobile/src/lib/session.ts` para salvar, ler e limpar token, expiração e dados mínimos do usuário
- [X] T007 [P] Configurar a política de segurança do navegador em `frontends/mobile/next.config.ts` com `Content-Security-Policy` restritiva, usando `process.env.NODE_ENV` para diferenciar desenvolvimento e produção e liberando `connect-src` para `localhost:8000` no ambiente local
- [X] T008 Implementar o bootstrap da sessão em `frontends/mobile/src/app/layout.tsx` e `frontends/mobile/src/components/auth-guard.tsx`

## Phase 3: US1 - Entrar e manter a sessão

Story goal: permitir login com as credenciais já existentes e manter o app autenticado enquanto a sessão estiver válida.

Independent test criteria:
- o login funciona com as credenciais do ERP;
- a sessão fica disponível após recarregar a página ou reabrir o app;
- o usuário não precisa autenticar de novo antes do prazo de expiração.

- [X] T009 [US1] Implementar a tela de login em `frontends/mobile/src/app/login/page.tsx` e o fluxo de submissão de credenciais
- [X] T010 [US1] Persistir token, expiração e perfil do usuário em `frontends/mobile/src/lib/session.ts` após login bem-sucedido
- [X] T011 [US1] Conectar o retorno do login ao cliente HTTP em `frontends/mobile/src/lib/api.ts` e mostrar mensagens de erro padronizadas no app

## Phase 4: US2 - Retomar e expirar com clareza

Story goal: reabrir o app com segurança e tratar expiração ou revogação sem quebrar a experiência.

Independent test criteria:
- ao abrir o app com token válido, a sessão é restaurada;
- ao abrir o app com token expirado ou revogado, o usuário vai para login;
- toda resposta `401` derruba apenas a sessão local e não a aplicação inteira.

- [X] T012 [US2] Implementar a validação de sessão na inicialização em `frontends/mobile/src/app/layout.tsx` e `frontends/mobile/src/lib/api.ts`
- [X] T013 [US2] Implementar o tratamento de `401` e o redirecionamento seguro para login em `frontends/mobile/src/components/auth-guard.tsx` e `frontends/mobile/src/lib/api.ts`
- [X] T014 [US2] Ajustar o refresh para renovar a validade da sessão e atualizar o estado local em `frontends/mobile/src/lib/session.ts`, consumindo apenas o endpoint existente `POST /api/v1/auth/refresh` sem criar novo endpoint

## Phase 5: US3 - Operar OS no mobile

Story goal: entregar o primeiro fluxo real de campo com lista, detalhe, atualização de status e anexos controlados.

Independent test criteria:
- o técnico vê apenas as OS atribuídas a ele;
- o detalhe da OS abre com contexto suficiente;
- fotos e PDFs são acessados apenas pelos endpoints controlados;
- o status é atualizado com sessão válida.

- [X] T015 [P] Criar a listagem de OS em `frontends/mobile/src/app/os/page.tsx`
- [X] T016 [P] Criar o detalhe da OS em `frontends/mobile/src/app/os/[id]/page.tsx`
- [X] T017 [US3] Integrar o cliente de OS em `frontends/mobile/src/lib/orders.ts` e os componentes de status, anexos e feedback em `frontends/mobile/src/components/orders/`

## Phase 6: US4 - Encerrar sessão e fechar segurança

Story goal: permitir logout limpo, reduzir exposição e deixar a plataforma pronta para continuidade segura.

Independent test criteria:
- o logout encerra a sessão no backend e limpa o estado local;
- a documentação explica o modelo de sessão e recuperação;
- a validação local confirma que backend e frontend funcionam juntos.

- [X] T018 [US4] Implementar o logout completo em `frontends/mobile/src/components/logout-button.tsx` e `frontends/mobile/src/lib/session.ts`
- [X] T019 [US4] Atualizar a documentação operacional em `README.md`, `documentacao/03-arquitetura-tecnica/README.md`, `documentacao/03-arquitetura-tecnica/backend-central-minimo.md`, `documentacao/01-fundacao/contrato-de-ambiente.md` e `frontends/mobile/README.md`
- [X] T020 [US4] Criar a nota de implementação da fase em `documentacao/07-novas-implementacoes/2026-06-22-fase-5-pwa-mobile-sessao-seguranca.md`
- [X] T021 [US4] Executar a validação final com `php artisan test --filter=AuthFlowTest` em `backend/`, `php artisan test` em `backend/` e `npm run lint && npm run build` em `frontends/mobile/`

## Dependências

- A Phase 1 precisa terminar antes de qualquer outra fase.
- A Phase 2 depende da Phase 1.
- A Phase 3 depende da Phase 2.
- A Phase 4 depende da Phase 3.
- A Phase 5 depende da Phase 4.
- A Phase 6 fecha a entrega e pode começar assim que o fluxo de OS estiver estável.

## Execução Paralela

- T001 e T002 podem seguir em paralelo.
- T005, T006 e T007 podem seguir em paralelo depois da Phase 1.
- T015 e T016 podem seguir em paralelo.
- T019 e T020 podem seguir em paralelo depois da implementação.

## Estratégia de MVP

O MVP desta fase é concluir T001 a T018 com o fluxo login -> sessão persistente -> listagem de OS -> detalhe -> logout funcionando de ponta a ponta.

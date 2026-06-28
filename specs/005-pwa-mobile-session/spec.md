# Spec: Sessão e segurança do PWA mobile

**Feature Branch**: `005-pwa-mobile-session`  
**Status**: Ready for implementation

## Resumo

O PWA mobile passa a usar o backend central como fonte única de autenticação e autorização, mantendo uma sessão persistente enquanto o token estiver válido, com expiração clara, renovação controlada, revogação no logout e endurecimento do navegador para reduzir a exposição de dados sensíveis.

## Objetivos

- Permitir que o técnico faça login uma vez e continue autenticado enquanto a sessão for válida.
- Revalidar a sessão automaticamente ao reabrir o app.
- Encerrar a sessão de forma confiável quando o usuário pedir logout.
- Bloquear o uso do app quando a sessão expirar ou for revogada.
- Manter o acesso ao fluxo operacional de OS sem dependência do legado.
- Reduzir a superfície de ataque do canal mobile contra scripts externos e vazamento de credenciais.

## Histórias de Usuário

### US1 - Entrar e manter a sessão

Como técnico autenticado, quero entrar no app mobile e permanecer autenticado enquanto minha sessão estiver válida, para não precisar informar credenciais o tempo todo.

### US2 - Retomar o app com segurança

Como técnico autenticado, quero abrir o app novamente e encontrar minha sessão restaurada quando ela ainda estiver válida, para continuar o atendimento sem interrupções.

### US3 - Ser redirecionado quando a sessão não for mais válida

Como técnico autenticado, quero ser levado de volta para a tela de login quando minha sessão expirar ou for revogada, para entender claramente que preciso autenticar novamente.

### US4 - Operar OS no mobile

Como técnico autenticado, quero listar, abrir e atualizar as OS atribuídas a mim no mobile, para executar o trabalho em campo com contexto suficiente e acesso controlado a anexos.

### US5 - Encerrar a sessão

Como técnico autenticado, quero sair do app de forma segura, para que minha sessão seja encerrada no backend e o dispositivo não mantenha acesso indevido.

### US6 - Manter o canal endurecido

Como responsável pela plataforma, quero que o app mobile opere com regras de segurança restritivas, para reduzir risco de exposição de dados, credenciais e scripts não autorizados.

## Requisitos Funcionais

- **FR-001** - O sistema deve permitir login usando as credenciais já existentes do ERP.
- **FR-002** - O sistema deve manter a sessão ativa entre aberturas do app enquanto o token estiver válido.
- **FR-003** - O sistema deve restaurar a sessão automaticamente ao carregar o app, sem exigir login novamente quando a sessão ainda estiver válida.
- **FR-004** - O sistema deve recusar o acesso quando a sessão expirar ou for revogada.
- **FR-005** - O sistema deve permitir listar apenas as OS atribuídas ao técnico autenticado.
- **FR-006** - O sistema deve permitir abrir o detalhe da OS e acessar fotos e PDFs por meio de endpoints controlados.
- **FR-007** - O sistema deve permitir atualizar o status da OS com a sessão válida.
- **FR-008** - O sistema deve encerrar a sessão de forma explícita ao realizar logout.
- **FR-009** - O sistema deve limpar a sessão local do app quando ocorrer revogação, erro de autenticação ou logout.
- **FR-010** - O sistema deve aplicar restrições de segurança do navegador para impedir fontes externas não aprovadas.
- **FR-011** - O sistema deve manter o contrato da API estável para permitir o uso por outros canais no futuro.
- **FR-012** - O sistema deve documentar o fluxo de sessão, expiração e recuperação para facilitar onboarding.

## Requisitos Não Funcionais

- **NFR-001** - O canal mobile não deve depender de cookie de sessão do navegador como mecanismo principal de autenticação.
- **NFR-002** - O app não deve expor credenciais, tokens ou dados sensíveis em logs, URLs ou mensagens de erro.
- **NFR-003** - O app deve continuar utilizável em telas pequenas e também em desktop, sem quebra de layout.
- **NFR-004** - O app deve bloquear scripts de terceiros por padrão, salvo exceção explícita e documentada.
- **NFR-005** - O tempo de reautenticação após expiração deve ser previsível e claro para o técnico.
- **NFR-006** - O acesso a anexos deve seguir o mesmo princípio de controle do backend central, sem exposição pública direta.

## Critérios de Aceite

- O técnico faz login, fecha o app e consegue reabrir e continuar autenticado enquanto a sessão estiver válida.
- Se a sessão expirar ou for revogada, o app volta para login de forma clara e sem telas quebradas.
- O técnico consegue listar suas OS, abrir o detalhe e acessar anexos controlados no mobile.
- O técnico consegue atualizar o status de uma OS atribuída a ele.
- O logout encerra a sessão no backend e limpa o estado local do app.
- O navegador opera com política de segurança restritiva e sem dependências externas desnecessárias.
- A documentação da fase permite que um novo dev entenda como subir backend, frontend e validar o fluxo.

## Premissas

- O backend central já expõe o contrato base de autenticação e OS em `/api/v1`.
- O banco compartilhado continua sendo a fonte única de dados durante a transição.
- O PWA mobile é um frontend separado, mas continua consumindo o backend central.
- Não será criado um novo modelo persistido para sessão além do que o backend já utiliza para tokens.
- O fluxo de renovação é de revogação e reemissão de token, não de cookie de navegador nem de JWT com refresh token dedicado.
- A expiração do token precisa ser definida explicitamente no backend para ter efeito real.

## Fora de Escopo

- Autenticação baseada em cookie httpOnly para o canal mobile.
- Proxy/BFF obrigatório no Next.js para ler ou esconder token do navegador.
- Reescrita do backend central ou troca de framework nesta fase.
- Novos domínios de negócio fora do fluxo de sessão e OS.
- Canal desktop, TV ou totem nesta etapa.

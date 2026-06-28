# Spec 007 - Frontend Desktop Laravel

## Resumo

Criar o canal desktop em `frontends/desktop/` como um cliente Laravel/Blade do backend central, com sessão server-side, camada obrigatória de services, proteção por permissões e paridade visual progressiva com o `sistema-hml`.

## Objetivos

- preservar a estrutura visual do legado;
- não permitir acesso direto ao banco;
- manter o token apenas na sessão do servidor;
- consumir apenas a API do backend central;
- usar o `auth/me` como fonte de verdade para menu e rotas.

## Histórias principais

### US1 - Login e sessão do desktop

Como usuário do canal desktop,
quero autenticar usando a API central,
para operar o sistema sem expor o token ao navegador.

### US2 - Navegação protegida

Como usuário autenticado,
quero ver apenas os módulos permitidos,
para que a experiência respeite o RBAC central.

### US3 - Módulos principais do legado

Como equipe administrativa,
quero acessar dashboard, OS, clientes, equipamentos, usuários e grupos,
para iniciar a migração do desktop sem depender do legado para lógica.

## Critérios de aceite

- login funcional com redirecionamento para a primeira rota permitida;
- token salvo apenas na sessão do Laravel desktop;
- `401` limpa sessão e volta para login;
- `403` redireciona para a primeira rota permitida com mensagem;
- controllers do desktop não chamam `Http::` diretamente;
- não existem Models de negócio no desktop;
- os módulos principais renderizam via services consumindo a API;
- a documentação da fase é atualizada em paralelo ao código.

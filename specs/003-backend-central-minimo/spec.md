# Spec: Backend Central Mínimo

**Feature Branch**: `003-backend-central-minimo`  
**Status**: Draft

## Resumo

A Fase 3 instala o backend Laravel de verdade em `backend/`, publica a base da API em `/api/v1`, entrega um health check funcional e estrutura a autenticação mobile com Sanctum em token Bearer.

## Objetivos

- Instalar o Laravel via Composer de forma reproduzível.
- Criar um health check real em `GET /api/v1/health`.
- Habilitar API routes com Sanctum.
- Entregar login, logout e `me` com token Bearer.
- Deixar o backend pronto para consumo pelo frontend mobile.

## Historias de Usuario

### US1 - Backend instalado e operante
Como desenvolvedor, quero que o Laravel esteja instalado e executando, para que o backend central exista de verdade e não só como estrutura de pastas.

### US2 - Health check confiavel
Como operador, quero consultar `GET /api/v1/health` e receber `200 ok`, para validar rapidamente que a aplicação está viva.

### US3 - Autenticacao token para mobile
Como usuario mobile, quero autenticar com token Bearer via Sanctum, para usar o PWA sem depender de sessão web tradicional.

### US4 - Consulta de sessão autenticada
Como frontend, quero consultar `GET /api/v1/auth/me` com o token, para carregar o usuário logado e o estado da aplicação.

## Requisitos Funcionais

- **FR-001** - O backend deve ser criado com Composer.
- **FR-002** - O backend deve responder em `/api/v1/health`.
- **FR-003** - O backend deve expor rotas de API em `routes/api.php`.
- **FR-004** - O comando `php artisan install:api` deve ser usado para habilitar Sanctum e rotas de API.
- **FR-005** - O login deve retornar token Bearer.
- **FR-006** - O endpoint `GET /api/v1/auth/me` deve funcionar com token válido.
- **FR-007** - O logout deve invalidar o token atual.
- **FR-008** - Os arquivos privados e logs continuam sob `backend/storage`.
- **FR-009** - A API deve usar envelope consistente e mensagens em `pt-BR`.

## Requisitos Nao Funcionais

- **NFR-001** - Sem exposicao publica da raiz do projeto.
- **NFR-002** - Sem dependencia de sessão para o mobile.
- **NFR-003** - Sem mistura com o legado `sistema-hml`.
- **NFR-004** - Documentação sincronizada com a implementação.

## Criterios de Aceite

- `GET /api/v1/health` retorna `200 ok`.
- `POST /api/v1/auth/login` retorna token Bearer.
- `GET /api/v1/auth/me` funciona com o token recebido.
- O backend roda dentro de `C:\xampp\htdocs\sistema-erp\backend`.
- A documentação registra os passos de instalação e autenticação.


# Spec: Infraestrutura de Desenvolvimento e Produção

**Feature Branch**: `002-infraestrutura-ambientes`  
**Status**: Draft

## Resumo

A Fase 2 fecha a compatibilidade operacional entre `Windows + XAMPP` e `VPS + Linux`, evitando `path hardcoded`, divergencia de URL e dependencia de comportamento especifico do Windows. Esta fase prepara o terreno para o backend Laravel rodar com o mesmo contrato em ambos os ambientes.

## Objetivos

- Padronizar URLs, CORS e variaveis de ambiente.
- Documentar e materializar o uso de logs, filas e scheduler em ambos os ambientes.
- Definir os templates de virtual host e reverse proxy.
- Criar validacoes basicas de ambiente para desenvolvimento e producao.

## Historias de Usuario

### US1 - Paridade entre ambientes
Como desenvolvedor, quero que o backend rode nos dois ambientes com a mesma configuracao logica, para nao depender de caminhos absolutos espalhados pelo codigo.

### US2 - Contrato de rede e CORS
Como frontend, quero uma configuracao clara de URLs e CORS, para consumir a API sem ajustes manuais por tela ou canal.

### US3 - Operacao de logs, filas e scheduler
Como operador, quero que logs, filas e tarefas agendadas funcionem de forma previsivel em ambos os ambientes, para evitar falhas escondidas em producao.

### US4 - Deploy basico e acesso local
Como responsavel pelo ambiente, quero templates de vhost, reverse proxy e bootstrap, para subir a plataforma sem improviso.

## Requisitos Funcionais

- **FR-001** - O backend deve usar `APP_URL`, `API_PREFIX` e variaveis de ambiente para formar URLs.
- **FR-002** - O CORS deve trabalhar por allowlist de origens, nao por wildcard em producao.
- **FR-003** - O backend deve registrar logs em `backend/storage/logs`.
- **FR-004** - O backend deve tratar arquivos privados em `backend/storage/app/private`.
- **FR-005** - O queue driver inicial deve ser configuravel por ambiente.
- **FR-006** - O scheduler deve ter instrucoes de execucao para Windows e Linux.
- **FR-007** - O ambiente Windows deve usar Apache/XAMPP com document root em `backend/public`.
- **FR-008** - O ambiente Linux deve usar reverse proxy/vhost apontando para `backend/public`.
- **FR-009** - O bootstrap local deve validar existencia de caminhos e variaveis criticas.
- **FR-010** - A documentacao deve explicar como o mesmo sistema se comporta nos dois ambientes.

## Requisitos Nao Funcionais

- **NFR-001** - Sem caminhos absolutos hardcoded no codigo.
- **NFR-002** - Sem dependencia de comportamento exclusivo do Windows.
- **NFR-003** - Sem exposicao publica de logs ou arquivos privados.
- **NFR-004** - Textos e docs em `pt-BR` e `UTF-8`.

## Criterios de Aceite

- O mesmo contrato de ambiente cobre Windows e Linux.
- Os templates de infra apontam para `backend/public`.
- CORS, filas e scheduler estao documentados e validaveis.
- A documentacao orienta o bootstrap sem depender de instrucoes orais.


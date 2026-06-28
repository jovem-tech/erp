# Plan: Infraestrutura de Desenvolvimento e Produção

## Contexto Tecnico

- Backend alvo: Laravel 13.x
- Linguagem: PHP 8+
- Banco compartilhado: MySQL/MariaDB
- Ambiente local: Windows + XAMPP
- Produção: VPS Linux
- Servico web local: Apache 2.4
- Servico web producao: Apache ou Nginx com reverse proxy
- Filas: `sync` no curto prazo, com possibilidade de evolucao para `database` ou `redis` quando jobs assíncronos existirem
- Scheduler: Task Scheduler no Windows e cron no Linux

## Decisoes

- A raiz publica do backend sera apenas `backend/public`.
- Paths sensiveis devem vir do framework ou do `.env`.
- Logs e arquivos privados ficam sob `backend/storage`.
- CORS deve ser controlado por allowlist.
- URLs de frontend e API devem ser definidas por ambiente.
- O deploy basico deve ser documentado antes da migracao de modulos.

## Artefatos da Fase

- Especificacao formal da infraestrutura
- Template de vhost Windows
- Template de reverse proxy Linux
- Template de cron/scheduler Linux
- Scripts simples de validacao de ambiente
- Documentacao de instalacao e deploy basico
- Atualizacao do contrato de ambiente

## Restricoes

- Nao instalar dependencia de framework ainda.
- Nao migrar modulos de negocio nesta fase.
- Nao expor `backend/storage` como webroot.

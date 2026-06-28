# Tasks: Infraestrutura de Desenvolvimento e Produção

**Input**: Artefatos da Fase 2 em `/specs/002-infraestrutura-ambientes/`

## Phase 1: Setup

- [X] T001 Consolidar a especificacao da infraestrutura em `specs/002-infraestrutura-ambientes/spec.md`
- [X] T002 Consolidar o plano tecnico em `specs/002-infraestrutura-ambientes/plan.md`

## Phase 2: Infraestrutura base

- [X] T003 Atualizar o contrato de ambiente em `backend/.env.example`
- [X] T004 Criar template de vhost Apache para Windows em `infra/windows/apache-vhost.conf`
- [X] T005 Criar template de reverse proxy/servidor Linux em `infra/linux/nginx-site.conf`
- [X] T006 Criar template de cron do scheduler em `infra/linux/cron-scheduler.example`
- [X] T007 Criar script de validacao local em `scripts/powershell/validate-dev-env.ps1`
- [X] T008 Criar script de validacao em VPS em `scripts/bash/validate-prod-env.sh`

## Phase 3: Documentacao

- [X] T009 Criar o indice da fase 2 em `documentacao/02-infraestrutura-ambientes/README.md`
- [X] T010 Documentar Windows/XAMPP em `documentacao/02-infraestrutura-ambientes/windows-xampp.md`
- [X] T011 Documentar VPS/Linux em `documentacao/02-infraestrutura-ambientes/linux-vps.md`
- [X] T012 Documentar CORS, URLs, logs, filas e scheduler em `documentacao/02-infraestrutura-ambientes/cors-urls-logs-filas-scheduler.md`
- [X] T013 Atualizar `documentacao/README.md` e `README.md` do projeto

## Phase 4: Validacao

- [X] T014 Validar consistencia de caminhos, URLs e permissões
- [X] T015 Validar consistencia de pt-BR, UTF-8 e referencias cruzadas

# Documentação do Sistema ERP

Este índice organiza a documentação da nova plataforma `sistema-erp`.

## Categorias

- `00-visao-geral/`: arquitetura de alto nível e direção da plataforma.
- `01-fundacao/`: base física inicial, contratos de ambiente e acesso seguro.
- `02-infraestrutura-ambientes/`: paridade operacional entre Windows/XAMPP e VPS/Linux.
- `03-arquitetura-tecnica/`: backend central, API v1, autenticação, RBAC, storage e fluxos operacionais.
- `07-novas-implementacoes/`: notas de entrega por fase e histórico executivo.
- `10-deploy/`: orientações de instalação, publicação e operação.

## Operação local

1. [Índice de deploy e operação](10-deploy/README.md)
2. [Manual de inicialização local no Windows com XAMPP](10-deploy/manual-inicializacao-local-windows-xampp.md)

## Leitura recomendada na Fase 1

1. [Arquitetura alvo](00-visao-geral/arquitetura-alvo.md)
2. [Estrutura física](01-fundacao/estrutura-fisica.md)
3. [Contrato de ambiente](01-fundacao/contrato-de-ambiente.md)
4. [Acesso seguro a arquivos](01-fundacao/acesso-seguro-a-arquivos.md)

## Leitura recomendada na Fase 2

1. [Índice da fase 2](02-infraestrutura-ambientes/README.md)
2. [Windows/XAMPP](02-infraestrutura-ambientes/windows-xampp.md)
3. [VPS/Linux](02-infraestrutura-ambientes/linux-vps.md)
4. [CORS, URLs, logs, filas e scheduler](02-infraestrutura-ambientes/cors-urls-logs-filas-scheduler.md)

## Leitura recomendada na Fase 3

1. [Índice da fase 3](03-arquitetura-tecnica/README.md)
2. [Backend central mínimo](03-arquitetura-tecnica/backend-central-minimo.md)
3. [Contrato de ambiente](01-fundacao/contrato-de-ambiente.md)

## Leitura recomendada na Fase 4

1. [Índice da fase 4](03-arquitetura-tecnica/README.md)
2. [Fluxo de OS mobile](03-arquitetura-tecnica/ordens-mobile.md)
3. [Backend central mínimo](03-arquitetura-tecnica/backend-central-minimo.md)
4. [Contrato de ambiente](01-fundacao/contrato-de-ambiente.md)

## Leitura recomendada na Fase 5

1. [Fluxo de OS mobile](03-arquitetura-tecnica/ordens-mobile.md)
2. [Backend central mínimo](03-arquitetura-tecnica/backend-central-minimo.md)
3. [Frontend mobile](../frontends/mobile/README.md)
4. [Nota da Fase 5](07-novas-implementacoes/2026-06-22-fase-5-pwa-mobile-sessao-seguranca.md)

## Leitura recomendada na Fase 6

1. [Índice técnico das fases 4, 5 e 6](03-arquitetura-tecnica/README.md)
2. [Backend administrativo e RBAC](03-arquitetura-tecnica/backend-administrativo-rbac.md)
3. [Backend central mínimo](03-arquitetura-tecnica/backend-central-minimo.md)
4. [Fluxo de OS mobile](03-arquitetura-tecnica/ordens-mobile.md)
5. [Nota da Fase 6](07-novas-implementacoes/2026-06-22-fase-6-backend-administrativo-rbac.md)

## Leitura recomendada na Fase 7

1. [Índice técnico atualizado](03-arquitetura-tecnica/README.md)
2. [Frontend desktop Laravel](03-arquitetura-tecnica/frontend-desktop-laravel.md)
3. [Backend administrativo e RBAC](03-arquitetura-tecnica/backend-administrativo-rbac.md)
4. [README do frontend desktop](../frontends/desktop/README.md)
5. [Nota da Fase 7](07-novas-implementacoes/2026-06-22-fase-7-frontend-desktop-laravel.md)

## Leitura complementar - Frontend legado/BFF

1. [PRD - Frontend sistema-hml como BFF](00-visao-geral/prd-frontend-sistema-hml-bff.md)
2. [Frontend sistema-hml como BFF](03-arquitetura-tecnica/frontend-sistema-hml-bff.md)
3. [Mapa completo de migração e limpeza do frontend sistema-hml](03-arquitetura-tecnica/mapa-migracao-legado-frontend-sistema-hml.md)
4. [Contrato da API do backend central](03-arquitetura-tecnica/contrato-api-backend-central.md)
5. [README do clone sistema-hml](../frontend/sistema-hml/README.sistema-erp.md)
6. [Spec 008 - Governança do BFF sistema-hml](../specs/008-governanca-bff-sistema-hml/spec.md)
7. [Nota de implementação - Governança do BFF sistema-hml](07-novas-implementacoes/2026-06-24-governanca-bff-sistema-hml.md)
8. [Nota de implementação - Autenticação BFF do sistema-hml](07-novas-implementacoes/2026-06-24-frontend-sistema-hml-auth-bff.md)
9. [Nota de implementação - Dashboard BFF do sistema-hml](07-novas-implementacoes/2026-06-24-frontend-sistema-hml-dashboard-bff.md)
10. [Nota de implementação - Notificações BFF do sistema-hml](07-novas-implementacoes/2026-06-24-frontend-sistema-hml-notificacoes-bff.md)

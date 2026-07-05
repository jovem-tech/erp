# Ambientes e Segurança

## Ambiente-alvo

Producao oficial: servidor `Ubuntu` (LAN interna ou VPS). Deploy de referencia
em `192.168.1.100` (2026-07-03/04) com PHP 8.5, MySQL 8.4, Redis com senha,
Nginx com TLS e Supervisor — runbook em
`documentacao/10-deploy/deploy-producao-lan-ubuntu.md` e problemas conhecidos
em `$sistema-erp-deploy-producao`.

Checklist mental para qualquer mudanca:

1. Funciona em filesystem case-sensitive?
2. Evita path hardcoded de Windows?
3. Respeita publicacao apenas de diretorios publicos aprovados?
4. Mantem segredos fora do repositorio?
5. Preserva storage privado, logs e trilha de auditoria?

## Regras obrigatorias

- `Windows/XAMPP` e apenas ambiente local.
- `Nginx`, `cron` e `Supervisor` ou equivalente devem ser considerados no desenho operacional.
- uploads, anexos e documentos precisam permanecer atras de autenticacao.
- toda mudanca com impacto em deploy ou seguranca deve atualizar `documentacao/02-infraestrutura-ambientes/` e `documentacao/10-deploy/` (e `documentacao/03-arquitetura-tecnica/` quando aplicavel).
- toda alteracao de codigo deve ser registrada conforme `VERSIONING.md` na raiz (`./scripts/bump-version.sh`).

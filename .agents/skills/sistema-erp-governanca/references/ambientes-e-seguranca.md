# Ambientes e Segurança

## Ambiente-alvo

Producao oficial: `Ubuntu VPS`

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
- toda mudanca com impacto em deploy ou seguranca deve atualizar `documentacao/02-infraestrutura-ambientes/` e `documentacao/03-arquitetura-tecnica/` quando aplicavel.

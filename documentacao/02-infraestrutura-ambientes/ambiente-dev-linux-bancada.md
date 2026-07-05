# Ambiente Oficial de Desenvolvimento — Linux (BANCADA-02, 192.168.1.100)

**Status:** ambiente oficial de desenvolvimento do `sistema-erp` desde 2026-07-04.
**Substitui:** Windows/XAMPP (descontinuado para desenvolvimento).

## Por que Linux e nao mais XAMPP

O desenvolvimento migrou do XAMPP para um servidor Ubuntu real para eliminar a
classe de incompatibilidades que so aparecia no deploy. Tres bugs do primeiro
deploy existiram exclusivamente pela diferenca XAMPP↔Linux:

- case-sensitivity de arquivos/classes;
- MariaDB 10.4 (XAMPP) vs MySQL 8.4 (producao), incluindo colunas geradas;
- o bug do middleware `ForceHttps` com `BinaryFileResponse`, invisivel em local.

Desenvolver no mesmo SO/stack da producao (Contabo Ubuntu) faz esses problemas
aparecerem em desenvolvimento, nao em producao.

## Topologia de rede (BANCADA-02)

| Camada | URL | Porta | Socket FPM |
|---|---|---|---|
| Frontend desktop | `https://192.168.1.100` | 443 (80→443) | `/run/php/erp-desktop.sock` |
| Backend / API | `https://192.168.1.100:8443` | 8443 (8080→8443) | `/run/php/erp-backend.sock` |
| WebSocket (Reverb) | `wss://192.168.1.100/app/` | proxy 443→8090 | — |
| Futuro mobile / outros | `https://192.168.1.100:8444+` | reservadas 8444/8445 | a definir |

O IP principal (443) e do **desktop** por ser a interface que o usuario abre no
navegador. O backend fica na 8443. Portas 8444/8445 ja liberadas no UFW para os
proximos canais (mobile, chat, etc.).

## Stack instalada

- PHP 8.5 (FPM com **dois pools dedicados**: `erp-backend` e `erp-desktop`);
- MySQL 8.4 (buffer pool 1 GB, slow query log em `/var/log/mysql/mysql-slow.log`);
- Redis 8 com senha e `maxmemory 1gb`;
- Nginx com TLS (certificado autoassinado com SAN `IP:192.168.1.100`);
- Supervisor (2 queue workers + Reverb), cron do scheduler;
- Node 20, Composer 2 (com dev-dependencies instaladas).

## Pools PHP-FPM (por que dois)

Backend e desktop tem pools separados para nao competirem pelos mesmos workers —
o desktop faz chamadas server-to-server para a API, e um pool unico pequeno
(`pm.max_children=5`, default) causava risco de travamento mutuo. Configuracao:

- `erp-backend`: `pm.max_children=20`, upload 20M/post 25M, `max_execution_time=60`;
- `erp-desktop`: `pm.max_children=12`, mesmos limites de upload/execucao;
- slowlog por pool em `/var/log/php8.5-fpm-erp-*-slow.log`.

## Seguranca aplicada

- **UFW ativo** com 22, 80, 443, 8080, 8443, 8444, 8445 (a 22 **deve** ser
  liberada antes de qualquer `ufw enable` — ver licao aprendida abaixo);
- fail2ban ativo (jail sshd);
- SSH: `PermitRootLogin no`, `X11Forwarding no`, `MaxAuthTries 4`
  (senha ainda permitida na LAN; desabilitar na VPS publica);
- `server_tokens off` no Nginx;
- `SESSION_SECURE_COOKIES=true`; cookies `Secure + HttpOnly`;
- `.env` com permissao 640 (grupo www-data); `storage/app/private` em 750;
- backup diario do banco em `/var/backups/sistema-erp` (cron 02:00, retencao 7 dias),
  credencial isolada em `/etc/sistema-erp/backup.cnf`.

## Fluxo de desenvolvimento

- editar via **VS Code Remote-SSH** conectado em `administrador@192.168.1.100`
  (a GUI local do Ubuntu deixa de ser necessaria);
- o codigo esta em `/var/www/sistema-erp` como repositorio git completo;
- rodar testes: `cd backend && php artisan test` (dev-deps instaladas);
- lint: `./vendor/bin/pint`;
- versionamento: `./scripts/bump-version.sh` conforme `VERSIONING.md`.

## Licao aprendida — UFW e a porta 22

Ao ativar o UFW pela primeira vez sem uma regra explicita para a porta 22, o
acesso SSH e cortado imediatamente (novas conexoes sofrem timeout). **Sempre**
rodar `sudo ufw allow 22/tcp` (ou `OpenSSH`) **antes** de `sudo ufw enable`.
Na VPS Contabo isso e critico: sem acesso fisico, a unica rede de seguranca e o
console web da Contabo. O runbook de deploy ja incorpora essa regra.

## Deploy para a Contabo (VPS Ubuntu)

Este ambiente e o espelho de homologacao. O caminho para producao usa o runbook
[`10-deploy/deploy-producao-lan-ubuntu.md`](../10-deploy/deploy-producao-lan-ubuntu.md),
com as diferencas obrigatorias na VPS publica:

- dominio real + Let's Encrypt (elimina o certificado autoassinado);
- `PasswordAuthentication no` no SSH (apenas chave);
- restringir `/up` e paginas administrativas por IP quando aplicavel;
- `ufw allow 22` antes do enable, sempre.

# Ambiente de desenvolvimento oficial em Linux e correcoes da auditoria de infraestrutura

**Data:** 2026-07-04
**Versao:** 3.7.0
**Modulo:** infraestrutura + `backend` + `frontends/desktop`

## Contexto

Apos o primeiro deploy em LAN, uma auditoria do servidor `192.168.1.100`
apontou gargalos, inconsistencias e falhas de seguranca. Em paralelo, decidiu-se
**migrar o desenvolvimento do Windows/XAMPP para esse servidor Linux**, tornando-o
o ambiente oficial de desenvolvimento e espelho de homologacao para a VPS Contabo.
Esta entrega corrige a auditoria e profissionaliza o ambiente.

## Entrega

### Ambiente de desenvolvimento Linux oficial

- repositorio git completo publicado em `/var/www/sistema-erp` (com historico);
- **nova topologia de portas**: desktop na 443 (`https://192.168.1.100`),
  backend/API na 8443 (`https://192.168.1.100:8443`); 8444/8445 reservadas;
- dependencias de desenvolvimento instaladas (Composer com dev-deps, Pint,
  PHPUnit, Pail); assets do desktop recompilados;
- Windows/XAMPP descontinuado para desenvolvimento.

### Correcoes de gargalo e desempenho

- **dois pools PHP-FPM dedicados** (`erp-backend` e `erp-desktop`) — elimina o
  risco de travamento mutuo do pool unico `pm.max_children=5`;
- **limite de upload corrigido** — `client_max_body_size 25M` no Nginx +
  `upload_max_filesize=20M`/`post_max_size=25M` nos pools (antes falhava com 413
  em qualquer arquivo acima de 1 MB, quebrando foto de equipamento e logo);
- MySQL com `innodb_buffer_pool_size=1G` e slow query log habilitado;
- Redis com `maxmemory 1gb`.

### Correcoes de seguranca

- UFW ativo (com a porta 22 preservada); fail2ban ativo;
- SSH endurecido (`PermitRootLogin no`, `X11Forwarding no`, `MaxAuthTries 4`);
- `server_tokens off` no Nginx; raiz do backend nao expoe mais a welcome page
  do Laravel (retorna JSON de servico);
- `SESSION_SECURE_COOKIES=true` (cookie do backend agora `Secure`);
- certificado TLS regenerado com SAN `IP:192.168.1.100`;
- `storage/app/private` em 750; `.env` em 640;
- backup diario do banco (cron 02:00, retencao 7 dias) com credencial isolada;
- banco orfao `sistema_erp` (vazio) removido.

### Correcoes de codigo

- `ApiClient` do desktop deixou de passar `CURLOPT_*` cru em
  `withOptions(['curl' => ...])` (deprecado no Guzzle 7.11, rejeitado no 8) —
  usa apenas `timeout()`/`connectTimeout()` nativos;
- rota `/` do backend retorna JSON de servico em vez de `view('welcome')`.

## Validacao (executada contra 192.168.1.100)

- backend 8443 raiz → `{"service":"sistema-erp-api","status":"ok"}`;
- desktop 443 `/login` → 200; login invalido → `AUTH_INVALID_CREDENTIALS`
  (atravessa desktop→API 8443);
- CORS preflight `broadcasting/auth` → `Access-Control-Allow-Origin: https://192.168.1.100`;
- upload 3 MB → 419/422 (passou do limite de body; antes era 413);
- server-to-server desktop→API sobre TLS com SAN → 401 (sem erro de certificado);
- pools `erp-backend.sock` e `erp-desktop.sock` atendendo; Supervisor RUNNING;
- cookie do backend `Secure + HttpOnly`; backup gerando 44 MB.

## Licao aprendida

Ativar o UFW sem `allow 22` corta o SSH imediatamente (lockout real ocorrido
durante esta entrega, recuperado por acesso fisico a BANCADA-02). A regra
`ufw allow 22/tcp` antes de `ufw enable` foi incorporada ao runbook e a skill
`$sistema-erp-deploy-producao`.

## Pendencias para o deploy na Contabo

- dominio real + Let's Encrypt (elimina o certificado autoassinado);
- `PasswordAuthentication no` no SSH (apenas chave);
- SMTP, S3 e Sentry ainda nao configurados;
- publicar frontends `chat` e `mobile` quando aplicavel.

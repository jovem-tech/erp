# Manual de Inicializacao Local no Windows com XAMPP

## Quando usar este guia

Use este manual quando precisar ligar o ambiente local do `sistema-erp` no Windows/XAMPP ou quando o navegador mostrar erro como `ERR_CONNECTION_REFUSED` em `127.0.0.1`.

O caso da imagem enviada pelo usuario corresponde ao `frontends/chat`, que roda em `http://127.0.0.1:3002`. Se essa URL recusar conexao, o servidor do chat ainda nao foi iniciado.

## Mapa rapido de portas

- backend central Laravel: `http://127.0.0.1:8000`
- health check do backend: `http://127.0.0.1:8000/api/v1/health`
- frontend desktop Laravel/Blade: `http://127.0.0.1:8080`
- frontend mobile Next.js: `http://127.0.0.1:3001` ou a proxima porta livre
- Central de Atendimento (`frontends/chat`): `http://127.0.0.1:3002`
- Laravel Reverb (tempo real do chat): `ws://127.0.0.1:8090`
- MySQL do XAMPP: `127.0.0.1:3306`

## O que precisa estar instalado

- XAMPP com `Apache` e `MySQL`
- PHP `8.3+`
- Composer
- Node.js `18+`
- `npm` para `frontends/chat`
- `pnpm` para `frontends/mobile`

## Bancos esperados no ambiente local

- `sistema_erp`
- `sistema_erp_chat`
- `sistema_hml`

O backend usa:

- `sistema_erp` como banco principal do ERP
- `sistema_erp_chat` para conversas, mensagens e anexos da Central de Atendimento
- `sistema_hml` como leitura de clientes para o chat nesta fase

## 1. Validar a base do ambiente

No PowerShell, a partir da raiz do projeto:

```powershell
Set-Location C:\xampp\htdocs\sistema-erp
.\scripts\powershell\validate-dev-env.ps1
```

Se aparecer aviso de conflito na porta `3001`, o `frontends/mobile` pode subir em outra porta. Isso nao impede backend, desktop ou chat.

## 2. Configurar o backend central

```powershell
Set-Location C:\xampp\htdocs\sistema-erp\backend
composer install
Copy-Item .env.example .env -Force
php artisan key:generate
```

Confira no `backend/.env` pelo menos estas chaves:

```dotenv
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_erp
DB_USERNAME=root
DB_PASSWORD=

CHAT_DB_DATABASE=sistema_erp_chat
SISTEMA_HML_DB_DATABASE=sistema_hml

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=erp-local
REVERB_APP_KEY=erp-local-key
REVERB_APP_SECRET=erp-local-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8090
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8090
REVERB_SCHEME=http
```

Depois rode as migrations:

```powershell
php artisan migrate --force
```

Observacao: as migrations do chat tambem entram nesse comando, desde que `CHAT_DB_DATABASE` esteja configurado corretamente.

## 3. Ligar Apache e MySQL no XAMPP

No XAMPP Control Panel:

1. Inicie `Apache`.
2. Inicie `MySQL`.

O fluxo local oficial deste projeto no Windows e:

- backend via Apache/XAMPP em `http://127.0.0.1:8000`
- desktop via Apache/XAMPP em `http://127.0.0.1:8080`

Nao use `php artisan serve` como fluxo principal para backend e desktop no ambiente Windows/XAMPP deste repositorio.

## 4. Testar o backend antes de abrir os frontends

Abra no navegador:

- `http://127.0.0.1:8000/api/v1/health`
- `http://127.0.0.1:8080/login`

Se o health check nao responder, resolva backend/XAMPP antes de subir mobile ou chat.

## 5. Ligar o Reverb para o tempo real do chat

Em outro terminal:

```powershell
Set-Location C:\xampp\htdocs\sistema-erp\backend
php artisan reverb:start --host=127.0.0.1 --port=8090
```

Deixe esse terminal aberto enquanto estiver validando o chat em tempo real.

## 6. Ligar a Central de Atendimento em `3002`

Em outro terminal:

```powershell
Set-Location C:\xampp\htdocs\sistema-erp\frontends\chat
npm install
Copy-Item .env.example .env -Force
```

Confirme no `frontends/chat/.env`:

```dotenv
NEXT_PUBLIC_API_BASE_URL=http://127.0.0.1:8000/api/v1
NEXT_PUBLIC_APP_URL=http://127.0.0.1:3002
NEXT_PUBLIC_REVERB_APP_KEY=erp-local-key
NEXT_PUBLIC_REVERB_HOST=127.0.0.1
NEXT_PUBLIC_REVERB_PORT=8090
NEXT_PUBLIC_REVERB_SCHEME=http
```

Suba o servidor:

```powershell
npm run dev
```

Depois abra:

- `http://127.0.0.1:3002`

Se quiser testar uma rota especifica como `http://127.0.0.1:3002/conversas/1`, primeiro confirme que a app raiz abriu corretamente e que a autenticacao no backend esta funcionando.

## 7. Ligar o mobile em `3001`

Em outro terminal:

```powershell
Set-Location C:\xampp\htdocs\sistema-erp\frontends\mobile
pnpm install
Copy-Item .env.example .env -Force
pnpm dev
```

Abra a URL informada no terminal. O projeto prioriza `http://127.0.0.1:3001`, mas pode usar outra porta livre se `3001` estiver ocupada.

## 8. Checklist rapido de ambiente ligado

Considere o ambiente pronto quando:

- `http://127.0.0.1:8000/api/v1/health` responder
- `http://127.0.0.1:8080/login` abrir
- `http://127.0.0.1:3002` abrir sem `ERR_CONNECTION_REFUSED`
- o terminal do Reverb continuar ativo em `8090`
- o terminal do chat mostrar o Next.js rodando em `3002`

## Solucao rapida de problemas

### `ERR_CONNECTION_REFUSED` em `127.0.0.1:3002`

O `frontends/chat` nao esta rodando. Execute:

```powershell
Set-Location C:\xampp\htdocs\sistema-erp\frontends\chat
npm run dev
```

### O chat abre, mas nao atualiza em tempo real

Revise estes pontos:

- o comando `php artisan reverb:start --host=127.0.0.1 --port=8090` esta rodando
- `NEXT_PUBLIC_REVERB_APP_KEY` no chat e igual a `REVERB_APP_KEY` do backend
- `NEXT_PUBLIC_REVERB_HOST` e `REVERB_HOST` apontam para o mesmo host
- `REVERB_PORT` e `REVERB_SERVER_PORT` estao alinhados com `8090`

### `500` ao listar conversas no chat

Normalmente indica problema de banco ou migration do modulo de atendimento. Verifique:

- o banco `sistema_erp_chat` existe
- `CHAT_DB_DATABASE=sistema_erp_chat` no `backend/.env`
- `php artisan migrate --force` foi executado no `backend`

### O mobile nao abre em `3001`

O `frontends/mobile` pode mudar automaticamente para outra porta livre. Use a URL impressa no terminal do `pnpm dev`.

### Erro estranho de runtime no chat depois de atualizar arquivos

O `frontends/chat` limpa `.next` automaticamente antes de `dev` e `build`, mas voce pode forcar:

```powershell
Set-Location C:\xampp\htdocs\sistema-erp\frontends\chat
npm run clean
npm run dev
```

## Referencias internas

- [Windows + XAMPP](../02-infraestrutura-ambientes/windows-xampp.md)
- [CORS, URLs, logs, filas e scheduler](../02-infraestrutura-ambientes/cors-urls-logs-filas-scheduler.md)
- [README do frontend desktop](../../frontends/desktop/README.md)
- [README da Central de Atendimento](../../frontends/chat/README.md)
- [README do frontend mobile](../../frontends/mobile/README.md)

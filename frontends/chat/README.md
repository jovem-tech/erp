# Sistema ERP Chat

Central de Atendimento do `sistema-erp`, implementada em Next.js 15 com tempo real via Laravel Echo + Reverb.

## Dependencias deste frontend

- backend central ativo em `http://127.0.0.1:8000/api/v1`
- Reverb ativo em `ws://127.0.0.1:8090`
- Node.js `18+`
- `npm`

## Como executar localmente

```powershell
Set-Location C:\xampp\htdocs\sistema-erp\frontends\chat
npm install
Copy-Item .env.example .env -Force
npm run dev
```

Depois abra `http://127.0.0.1:3002`.

## Variaveis de ambiente principais

```dotenv
NEXT_PUBLIC_APP_NAME="Sistema ERP Chat"
NEXT_PUBLIC_CHANNEL=chat
NEXT_PUBLIC_API_BASE_URL=http://127.0.0.1:8000/api/v1
NEXT_PUBLIC_APP_URL=http://127.0.0.1:3002
NEXT_PUBLIC_REVERB_APP_KEY=erp-local-key
NEXT_PUBLIC_REVERB_HOST=127.0.0.1
NEXT_PUBLIC_REVERB_PORT=8090
NEXT_PUBLIC_REVERB_SCHEME=http
```

`NEXT_PUBLIC_REVERB_APP_KEY` deve usar a mesma chave definida em `REVERB_APP_KEY` no `backend/.env`.

## Comandos uteis

```bash
npm run dev
npm run build
npm test
```

O `package.json` limpa `.next` automaticamente antes de `dev` e `build`, reduzindo o risco de artefato stale quebrar a app.

## Troubleshooting rapido

- erro `ERR_CONNECTION_REFUSED` em `3002`: o servidor Next.js ainda nao foi iniciado
- login funciona, mas realtime nao: o Reverb nao esta rodando ou a chave do Reverb nao bate com a do backend
- `500` em conversas: revise o banco `sistema_erp_chat` e rode `php artisan migrate --force` no backend

## Guia completo

Para o passo a passo completo com XAMPP, backend, desktop, mobile e chat:

- [Manual de inicializacao local no Windows com XAMPP](../../documentacao/10-deploy/manual-inicializacao-local-windows-xampp.md)

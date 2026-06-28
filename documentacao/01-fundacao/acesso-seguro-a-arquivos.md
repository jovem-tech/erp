# Acesso Seguro a Arquivos

Fotos, PDFs e logs ficam dentro do projeto, mas **nunca** devem ficar acessiveis por URL publica direta.

## Estrutura base

```text
backend/storage/app/private/
├── fotos/
│   ├── equipamentos/
│   └── os/
├── pdfs/
│   ├── os/
│   └── orcamentos/
└── tmp/
```

```text
backend/storage/logs/
├── app/
└── auditoria/
```

## Fluxo de acesso

1. O frontend solicita o arquivo ao backend.
2. O backend valida autenticacao e permissao.
3. O backend localiza o arquivo em `backend/storage/app/private/` ou, durante a transicao, na raiz configurada de leitura do legado (`LEGACY_PUBLIC_PATH`).
4. O backend entrega o conteudo por stream controlado ou URL assinada.

## Regras obrigatorias

- o document root do servidor deve apontar apenas para `backend/public` quando o backend Laravel existir
- a raiz `C:\xampp\htdocs\sistema-erp` nao deve ser servida como pasta publica
- o codigo deve resolver caminhos com `storage_path('app/private')` e `Storage::disk()` sempre que possivel
- nao expor arquivos sensiveis em `public/`
- nao depender de link fixo para fotos ou PDFs privados
- tratar a leitura do legado apenas como ponte transitória, sem reutilizar o caminho publicamente no frontend
- nao servir logs por rota publica
- nao versionar conteudo operacional de `backend/storage/app/private/` nem de `backend/storage/logs/`
- manter trilha de auditoria quando houver acesso sensivel

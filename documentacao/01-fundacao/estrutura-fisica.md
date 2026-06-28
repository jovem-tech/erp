# Estrutura Fisica Inicial

O ponto de partida da plataforma em `C:\xampp\htdocs\sistema-erp` e a seguinte arvore:

```text
C:\xampp\htdocs\sistema-erp
├── backend/
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   │   ├── app/
│   │   │   └── private/
│   │   │       ├── fotos/
│   │   │       │   ├── equipamentos/
│   │   │       │   └── os/
│   │   │       ├── pdfs/
│   │   │       │   ├── orcamentos/
│   │   │       │   └── os/
│   │   │       └── tmp/
│   │   └── logs/
│   │       ├── app/
│   │       └── auditoria/
│   └── tests/
├── frontends/
│   ├── desktop/
│   ├── mobile/
│   ├── totem/
│   └── tv/
├── shared/
├── documentacao/
├── specs/
├── infra/
└── scripts/
```

## Responsabilidades

- `backend/`: backend central da plataforma, com API, autenticacao, autorizacao e regras de negocio.
- `frontends/mobile/`: interface PWA prioritaria para uso no celular.
- `frontends/desktop/`: interface de escritorio, responsiva e reutilizando o mesmo backend.
- `frontends/tv/` e `frontends/totem/`: canais futuros sem retrabalho na API.
- `shared/`: componentes ou utilitarios comuns entre canais, quando fizer sentido.
- `backend/storage/app/private/`: fotos, PDFs e anexos sensiveis sem exposicao publica direta.
- `backend/storage/logs/`: saida interna de auditoria, aplicacao e integracoes.
- `documentacao/`: visao geral, fundacao, arquitetura tecnica e deploy.
- `specs/`: rastreio de especificacoes e evolucao por fase.
- `infra/`: vhosts, regras de servidor, templates de deploy e ambiente.
- `scripts/`: automatizacoes de bootstrap, manutencao e validacao.

## Regra de seguranca

Os arquivos em `backend/storage/app/private/` e os logs em `backend/storage/logs/` permanecem dentro do projeto, mas fora de qualquer exposicao publica direta.

# Hardening das dependencias de producao do PWA

- data: `2026-07-26`;
- versao: `5.20.2.0`;
- modulo: `frontends/mobile`;
- natureza: correcao de seguranca em dependencias de runtime.

## Contexto

A auditoria posterior ao deploy da instalacao mobile identificou alertas de
alta severidade no grafo de producao:

- `next 15.5.19`: advisories de SSRF e negacao de servico, corrigidos em
  `15.5.21`;
- `sharp 0.34.5`: vulnerabilidades herdadas do libvips, corrigidas a partir de
  `0.35.0`.

## Correcao

- o piso de `next` e `eslint-config-next` foi elevado para `15.5.21`;
- um override controlado exige `sharp >=0.35.0`;
- o lockfile registra `next 15.5.21` e `sharp 0.35.3`;
- o processo de build continua usando `pnpm 10.15.0` e lockfile congelado na
  VPS.

## Seguranca

`pnpm audit --prod` passou sem vulnerabilidades conhecidas apos a atualizacao.
O PWA nao usa Server Actions nem rewrites dinamicos, o que reduzia a
explorabilidade de parte dos advisories, mas a atualizacao foi aplicada para
eliminar a dependencia vulneravel em vez de depender apenas dessa mitigacao.

## Compatibilidade e performance

As atualizacoes permanecem na mesma linha compativel do Next 15 e nao alteram
API, manifest, Service Worker, rotas, autenticacao ou banco. O Sharp continua
opcional e e usado pelo pipeline de imagens do Next; nao houve crescimento
material dos bundles do cliente.

## Validacao

- 20 arquivos e 100 testes aprovados;
- lint e checagem de tipos aprovados;
- build de producao aprovado com Next `15.5.21`;
- `pnpm audit --prod`: zero vulnerabilidades conhecidas.

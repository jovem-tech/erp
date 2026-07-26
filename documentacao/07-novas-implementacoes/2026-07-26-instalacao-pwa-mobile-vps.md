# Instalacao do PWA mobile na VPS

- data: `2026-07-26`;
- versao: `5.20.1.0`;
- modulo: `frontends/mobile`;
- natureza: correcao de instalabilidade e orientacao multiplataforma.

## Problema

O PWA publicado em `https://app.jovemtech.eco.br` abria normalmente, mas o
botao permanecia como `Instalar app` e alguns celulares nao apresentavam a
instalacao nativa.

A auditoria de producao confirmou HTTPS valido, manifest, Service Worker e
icones com status e MIME corretos. O manifesto tambem foi aprovado pela
auditoria de instalabilidade do Chromium. A falha funcional estava na camada de
experiencia:

- o navegador podia emitir `beforeinstallprompt` antes da hidratacao do React;
- Safari no iOS nao oferece esse evento e usa o fluxo da folha Compartilhar;
- navegadores internos de WhatsApp e Instagram podem ocultar a instalacao;
- o icone de 512 px estava declarado apenas como `maskable`, reduzindo a
  compatibilidade com consumidores que procuram um icone de uso geral.

## Solucao

- um bootstrap carregado antes da hidratacao captura e preserva o prompt
  nativo, sem disparar instalacao sem gesto do usuario;
- o componente consome o evento preservado e continua ouvindo eventos futuros;
- iPhone/iPad recebem instrucoes para Safari, Compartilhar e
  `Adicionar a Tela de Inicio`;
- Android recebe instrucoes para Chrome e para sair de navegadores internos;
- o manifesto declara icones gerais de 192 e 512 px e mantem a variante
  `maskable`;
- o metadado legado `apple-mobile-web-app-capable=yes` complementa a
  configuracao moderna do Next.js;
- o painel de ajuda foi centralizado para nao ser cortado em telas de 390 px.

## Arquitetura e seguranca

O prompt fica somente em memoria no objeto `window` e e descartado apos
aceitacao, recusa ou evento `appinstalled`. Nao ha persistencia, token,
telemetria nova, alteracao de API, banco, RBAC ou Service Worker. A instalacao
continua dependendo de gesto explicito do usuario e da decisao do navegador.

## Performance e escalabilidade

O bootstrap tem tamanho constante, nao executa chamadas de rede e registra
apenas dois listeners. Os icones existentes foram reutilizados; nao ha aumento
material de armazenamento ou trafego. O deploy continua atomico, com artefatos
Next.js versionados pelo commit e Service Worker sem cache na borda.

## Testes e validacao

- 20 arquivos e 100 testes automatizados aprovados;
- lint, checagem de tipos e build de producao Next.js aprovados;
- teste unitario do prompt capturado antes da hidratacao;
- testes de orientacao especifica para iOS e Android;
- teste do manifesto para icones gerais de 192 e 512 px;
- inspecao visual aprovada em `390 x 844`;
- auditoria Chromium da URL de producao confirmou HTTPS e manifesto instalavel.

## Operacao

O endereco oficial e `https://app.jovemtech.eco.br`.

- Android/Chrome: tocar em `Instalar` quando o prompt estiver disponivel; como
  contingencia, usar o menu do Chrome.
- iPhone/iPad: abrir no Safari, tocar em Compartilhar e escolher
  `Adicionar a Tela de Inicio`.
- WhatsApp/Instagram: primeiro escolher `Abrir no Chrome` ou `Abrir no Safari`.

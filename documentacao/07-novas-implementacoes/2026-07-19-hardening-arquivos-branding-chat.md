# Hardening de arquivos: branding e anexos do chat

## Objetivo

Esta entrega é o Release A do projeto do Gerenciador Central de Arquivos. Ela reduz os riscos imediatos antes da criação do catálogo central, sem alterar URLs, envelopes JSON ou regras de RBAC existentes.

## Branding

- novos uploads de logo e fundo de login aceitam apenas JPG/JPEG, PNG e WebP;
- SVG foi removido do contrato de upload e SVG legado deixa de ser servido;
- o MIME é confirmado pelo backend e precisa corresponder à extensão;
- respostas usam `nosniff`, CSP restritiva, bloqueio de frame e `Content-Disposition` gerado pelo Symfony;
- a troca usa create-before-swap: grava, otimiza, valida, atualiza a configuração e só então remove a versão anterior;
- falha de disco, processamento ou banco remove apenas o candidato e preserva a configuração anterior;
- a remoção limpa primeiro a referência transacional e trata eventual arquivo órfão como reconciliação posterior.

## Chat e WhatsApp

A policy `ChatAttachmentPolicy` concentra:

- limite de 25 MiB por arquivo;
- pares permitidos de MIME detectado e extensão;
- validação de uploads e bytes inbound com `finfo`;
- normalização de nomes, remoção de controles/CRLF e limite de comprimento;
- allowlist do disco de leitura;
- inline somente para JPEG, PNG, WebP e GIF validados.

HTML, SVG, XML, executáveis e conteúdo disfarçado são rejeitados. Quando um registro antigo aponta para conteúdo desconhecido, o endpoint responde com `application/octet-stream` e `attachment`, nunca inline. Downloads aplicam `nosniff`, CSP sandbox e `private, no-store`.

Mídia inbound rejeitada permanece como placeholder `failed` para auditoria, sem caminho de storage. Falha de escrita também não marca o anexo como disponível.

## Segurança e trade-offs

- a allowlist é deliberadamente conservadora e pode exigir extensão futura para formatos operacionais comprovados;
- GIF legado do branding continua legível para compatibilidade, mas novos uploads animados não são aceitos;
- PDF e documentos do chat são download, não preview inline, reduzindo stored XSS e comportamento dependente de plugins;
- a validação pelo conteúdo adiciona custo linear no tamanho do upload, limitado a 25 MiB e executado uma vez na entrada;
- nenhum arquivo foi movido e nenhuma rota foi migrada para o núcleo central nesta release.

## Evidência automatizada

Os testes cobrem SVG de branding, preservação do arquivo anterior, falha de storage, headers seguros, HTML disfarçado de PNG, mídia inbound incompatível, nome com CRLF, download forçado e preview de raster validado.

O baseline ampliado ainda contém quatro falhas pré-existentes em `OrderFlowTest`, relacionadas à geração automática do documento de abertura; elas não foram causadas por este hardening e permanecem rastreadas separadamente.

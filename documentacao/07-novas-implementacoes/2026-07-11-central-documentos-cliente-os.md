# OS — central de documentos do cliente

Data: 2026-07-11

## Objetivo

Criar uma central única, por ordem de serviço, para:

- gerar novas versões dos documentos do cliente;
- consultar o acervo já emitido;
- arquivar/reabilitar versões sem exclusão física;
- reenviar por WhatsApp ou e-mail;
- gerar links públicos temporários;
- baixar ZIP;
- imprimir em `A4` e `80mm`.

## Escopo implementado

### Backend central

Foi criado um serviço unificado `OrderDocumentCenterService` para concentrar:

- catálogo de tipos documentais;
- pré-requisitos de geração manual;
- versionamento seguro por `max(versao)+1` com `lockForUpdate`;
- persistência de arquivos por formato;
- criação de links públicos seguros;
- enfileiramento e processamento de envios;
- compatibilidade com o acervo legado em `os_documentos`.

Tipos cobertos:

- `abertura`
- `orcamento`
- `laudo`
- `cobranca_manutencao`
- `entrega`
- `devolucao_sem_reparo`

### Desktop

Foi adicionada a central em rota própria:

- `GET /os/{order}/documentos`

Também foram adicionados atalhos em:

- menu de ações da listagem de OS;
- menu `Mais ações` do detalhe da OS;
- aba `Documentos` do detalhe.

### Fluxos automáticos conectados

- criação da OS continua gerando `abertura`;
- envio de orçamento para aprovação agora também registra a versão `orcamento` no acervo documental da OS;
- baixa e mudanças de status técnicas passam a sincronizar `entrega`, `devolucao_sem_reparo`, `cobranca_manutencao` e `laudo` quando aplicável.

## Evolução de armazenamento

`os_documentos` foi mantida por compatibilidade e recebeu evolução aditiva:

- `template_codigo`
- `hash_sha256`
- `idempotency_key`
- `metadados_json`
- `arquivado_em`
- `arquivado_por`

Novas tabelas auxiliares:

- `os_documento_arquivos`
- `os_documento_envios`
- `os_documento_links`
- `os_documento_link_itens`

### Compatibilidade preservada

- a coluna legada `arquivo` continua apontando para o PDF `A4`;
- documentos legados continuam válidos;
- não foi criada restrição destrutiva de unicidade em `(os_id, tipo_documento, versao)`;
- novas versões são calculadas com trava transacional para conviver com duplicidades antigas.

## Segurança aplicada

### Templates PDF

O HTML dos modelos agora passa por sanitização forte antes do DomPDF:

- remove `script`, `iframe`, `object`, `embed`, `form`, `meta`, `link`, `base`;
- bloqueia `<?php ?>`, `<% %>`, URLs externas e estilos perigosos;
- mantém somente allowlist de tags, atributos e CSS seguro.

### Links públicos

Os links compartilhados:

- usam token opaco de 256 bits;
- persistem somente o `sha256` do token;
- suportam expiração configurável (`24h`, `7d`, `30d`);
- podem ser revogados manualmente;
- respondem `410` quando expirados ou revogados;
- registram auditoria de acesso e eventos da OS.

## Canais e fila

Os envios documentais passaram a ser assíncronos via job:

- `ProcessOrderDocumentSendJob`

Histórico persistido em `os_documento_envios` com:

- canal;
- destino mascarado;
- destino criptografado;
- template;
- mensagem final;
- status;
- referência externa;
- erro sanitizado;
- usuário responsável.

## Operação em ambiente develop

Para reduzir falhas de implantação “código subido, fila parada”, o script:

- `scripts/bash/atualizar-dev.sh`

agora tenta:

- `php artisan queue:restart`
- `sudo supervisorctl restart all`

de forma tolerante (`|| true`) quando a infraestrutura existir no servidor de desenvolvimento.

## Contrato técnico novo

### API autenticada

- `GET /api/v1/orders/{order}/documents`
- `POST /api/v1/orders/{order}/documents/generate`
- `POST /api/v1/orders/{order}/documents/send`
- `POST /api/v1/orders/{order}/documents/share-links`
- `PATCH /api/v1/orders/{order}/documents/share-links/{link}/revoke`
- `GET /api/v1/orders/{order}/documents/download`
- `GET /api/v1/orders/{order}/documents/print`
- `GET /api/v1/orders/{order}/documents/{document}/files/{format}`
- `PATCH /api/v1/orders/{order}/documents/{document}/archive`
- `PATCH /api/v1/orders/{order}/documents/{document}/unarchive`

### Web pública controlada por token

- `GET /documentos/compartilhados/{token}`
- `GET /documentos/compartilhados/{token}/arquivos/{document}/{format}`

## Cobertura adicionada

Foram adicionados testes de fumaça para:

- listagem da central documental autenticada;
- criação e acesso a link público seguro;
- registro do PDF de orçamento em `os_documentos` após envio para aprovação;
- renderização da central no desktop.

## Limitações conhecidas desta implantação

- a impressão continua visual, abrindo o navegador, sem spool local silencioso;
- o worker supervisionado depende da infraestrutura já existente no servidor;
- o envio usa os serviços já disponíveis no backend atual e não cria novo provedor.

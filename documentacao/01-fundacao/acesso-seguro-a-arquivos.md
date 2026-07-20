# Acesso seguro a arquivos

Fotos, PDFs, anexos, assinaturas e logs devem permanecer fora do acesso público direto. O backend central é responsável por validar o contexto, autorizar o usuário e entregar o conteúdo por stream controlado.

## Estrutura base

```text
backend/storage/app/private/
├── empresa/
├── fotos/
│   ├── equipamentos/
│   └── os/
├── pdfs/
│   ├── os/
│   └── orcamentos/
└── tmp/

backend/storage/app/managed-files/
└── <categoria>/<AAAA>/<MM>/<DD>/<uuid>.<extensão>

backend/storage/logs/
├── app/
└── auditoria/
```

`managed-files-staging/` é transitório e não pode ser servido. O namespace `managed-files/` usa chaves imutáveis; uma substituição cria outro blob e altera somente o vínculo atual.

## Fluxo de acesso

1. O frontend solicita o arquivo ao backend.
2. O backend autentica a sessão ou valida o contrato público restrito.
3. O authorizer do domínio verifica o vínculo e a permissão; conhecer o UUID ou o caminho não concede acesso.
4. O backend resolve o blob central ou, durante a transição, um alias legado autorizado.
5. A resposta usa MIME detectado/controlado, nome sanitizado, `X-Content-Type-Options: nosniff` e política explícita de `inline` ou `attachment`.

## Modos de adoção

O gerenciador inicia em `off` e é ativado por categoria:

- `off`: somente o contrato legado é usado;
- `observe`: registra métricas sem catalogar nem alterar o fluxo legado;
- `shadow`: cataloga o path legado em paralelo; falhas centrais são fail-open;
- `hybrid`: a categoria allowlisted passa a exigir catálogo central; falha antes da publicação restaura a referência anterior.

O modo `hybrid` exige simultaneamente `FILE_MANAGER_ALLOW_WRITES=true`, categoria habilitada em `FILE_MANAGER_ENABLED_CATEGORIES` e aprovação adicional em `FILE_MANAGER_HYBRID_WRITE_CATEGORIES`. A segunda allowlist começa apenas com branding; fotos, documentos e chat ficam limitados a observação/shadow até concluírem seus próprios gates. Scanner e reconciliação mutável possuem kill switches independentes.

## Regras obrigatórias

- o document root deve apontar apenas para `backend/public`;
- nenhuma raiz do projeto, storage privado, backup ou log pode ser servida pelo Nginx;
- resolver caminhos com `Storage::disk()` e aceitar somente discos e roots cadastrados;
- rejeitar caminho absoluto, `..`, byte NUL, symlink e arquivo especial nos scanners;
- validar extensão, MIME por conteúdo, tamanho, arquivo vazio e decoder específico antes de catalogar;
- não confiar em MIME, nome ou extensão enviados pelo cliente;
- não expor caminhos absolutos, nomes de clientes ou conteúdo em logs e métricas;
- usar paginação para catálogo/eventos e stream para blobs; não carregar arquivos grandes integralmente em memória;
- preservar paths e colunas legadas enquanto qualquer consumidor depender deles;
- não excluir versões anteriores, lixo físico ou duplicados sem política de retenção aprovada;
- manter auditoria append-only para registro, vínculo e mudança de estado;
- tratar banco principal, banco `chat` e storage como uma unidade de backup e restauração;
- nunca corrigir permissão com `0777`; alinhar usuário/grupo ou ACL pelo princípio do menor privilégio.

## Consistência entre banco e filesystem

Banco e filesystem não formam uma transação distribuída. A escrita nativa usa staging, promoção para chave imutável, hash SHA-256 por stream e catálogo idempotente por `operation_key`.

Se o catálogo confirmar que nenhuma linha foi criada, o blob candidato pode ser compensado. Se o resultado do commit estiver ambíguo e o banco continuar indisponível, o blob é preservado e identificado depois pelo scanner/reconciliador. Isso evita apagar um arquivo que já tenha uma referência confirmada.

## Compatibilidade e rollback

O primeiro piloto mantém o arquivo novo no mesmo namespace `private/empresa/...` já lido pelo contrato legado e registra esse path como alias. Desativar o modo central não exige mover nem recriar o arquivo. Tabelas e colunas aditivas permanecem durante rollback; não se usa `DROP` como resposta a incidente.

O processo operacional completo está em `documentacao/10-deploy/operacao-gerenciador-central-arquivos.md`.

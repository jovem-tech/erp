# Quickstart: Gerenciador Central de Arquivos

## Estado atual

O núcleo, os adapters, o scanner, a sincronização automática/manual, a API e o
painel desktop estão implementados. No ambiente LAN, a operação recomendada é
`shadow`: o catálogo observa e registra arquivos sem assumir a escrita dos
módulos nem mover os binários de origem.

Produção externa, modo `hybrid`, mutações administrativas e purga física não são
autorizados automaticamente por este documento.

## Leitura obrigatória

1. `AGENTS.md`;
2. `VERSIONING.md`;
3. `documentacao/07-novas-implementacoes/2026-07-20-consolidado-gerenciador-arquivos-permissoes-os.md`;
4. `documentacao/03-arquitetura-tecnica/gerenciador-central-arquivos.md`;
5. `documentacao/10-deploy/operacao-gerenciador-central-arquivos.md`;
6. `specs/022-gerenciador-central-arquivos/FILE_MANAGER_COMPATIBILITY_AND_ROLLBACK.md`.

## Configuração inicial segura

```dotenv
FILE_MANAGER_MODE=off
FILE_MANAGER_ENABLED_CATEGORIES=
FILE_MANAGER_ALLOW_WRITES=false
FILE_MANAGER_ALLOW_SCANNER=false
FILE_MANAGER_ALLOW_MUTATING_RECONCILE=false
FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS=false
FILE_MANAGER_AUTOMATIC_SYNC_ENABLED=false
FILE_MANAGER_PDF_THUMBNAILS_ENABLED=false
```

Depois do deploy, validar migrations, storage, cache e comandos antes de mudar
qualquer switch.

## Ativação controlada em shadow

```dotenv
FILE_MANAGER_MODE=shadow
FILE_MANAGER_ENABLED_CATEGORIES=company_login_background,company_logo,equipment_photo,order_photo,order_pdf,budget_pdf,user_signature,user_profile_photo,chat_attachment
FILE_MANAGER_ALLOW_WRITES=false
FILE_MANAGER_ALLOW_SCANNER=true
FILE_MANAGER_ALLOW_MUTATING_RECONCILE=true
FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS=false
FILE_MANAGER_AUTOMATIC_SYNC_ENABLED=true
FILE_MANAGER_AUTOMATIC_SYNC_INTERVAL_MINUTES=5
FILE_MANAGER_PDF_THUMBNAILS_ENABLED=true
FILE_MANAGER_PDF_THUMBNAIL_RENDERER=/usr/bin/pdftocairo
```

`shadow` cataloga metadados e mantém o path original. Ele não concede permissão
para escrita central nem para transições de estado.

## Comandos disponíveis

```bash
cd /var/www/sistema-erp/backend

php artisan file-manager:diagnose --json
php artisan file-manager:scan --root=order_photos --limit=1000
php artisan file-manager:catalog-legacy --limit=500
php artisan file-manager:catalog-legacy --apply --limit=500
php artisan file-manager:check-integrity --limit=500
php artisan file-manager:reconcile --limit=500
php artisan file-manager:reconcile-chat
php artisan file-manager:sync --status
php artisan file-manager:sync --root=order_photos
php artisan schedule:list
```

O scanner e o catálogo sem `--apply` são dry-run. Aplicações e reconciliação
mutável dependem do kill switch correspondente.

## Sincronização manual pelo painel

O usuário precisa de `arquivos:administrar`. O botão **Sincronizar agora**
registra uma solicitação no cache. O scheduler `file-manager:sync --pending`
consome o pedido em até um minuto; a requisição web não executa o scan.

## Smoke test mínimo

1. abrir `/arquivos` com usuário autorizado;
2. conferir total e pastas por categoria;
3. alternar grade/lista;
4. confirmar colunas Foto, Cliente e Criado em;
5. abrir uma imagem no modal e testar zoom/rotação;
6. abrir um PDF no modal e confirmar controles do visualizador;
7. baixar um arquivo;
8. solicitar sincronização manual e verificar `file-manager:sync --status`;
9. testar um usuário sem `arquivos:baixar`;
10. confirmar que ações de estado permanecem bloqueadas com o kill switch off.

## Testes automatizados

```bash
cd /var/www/sistema-erp/backend
php artisan test tests/Feature/Files
php artisan test tests/Unit/Services/Files
php artisan test tests/Feature/Api/V1/OrderFlowTest.php

cd /var/www/sistema-erp/frontends/desktop
php artisan test tests/Feature/Desktop/FileManagerTest.php
php artisan test tests/Feature/Desktop/GroupPermissionsTest.php
php artisan view:cache
```

## Stop conditions

Interromper a ativação e voltar a `off` diante de:

- diferença de conteúdo/hash;
- IDOR ou autorização divergente;
- scanner fora de root allowlisted;
- perda de arquivo, path ou vínculo;
- lock/scheduler inoperante;
- volume de findings sem explicação;
- espaço em disco baixo ou permission denied recorrente;
- backup/restore conjunto não comprovado;
- mutação administrativa habilitada por engano.

## Rollback rápido

```dotenv
FILE_MANAGER_AUTOMATIC_SYNC_ENABLED=false
FILE_MANAGER_MODE=off
FILE_MANAGER_ALLOW_WRITES=false
FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS=false
```

Limpar o cache de configuração e validar os endpoints legados. Não apagar
catálogo, aliases, eventos ou binários durante o incidente.

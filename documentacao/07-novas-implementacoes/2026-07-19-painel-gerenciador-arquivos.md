# Painel do Gerenciador Central de Arquivos

**Status:** implementado e testado no ambiente de desenvolvimento; ações de estado permanecem desligadas por padrão e nada foi promovido para produção.

## Entrega

- módulo RBAC `arquivos` com permissões independentes de listagem, metadados, download, quarentena, restauração e administração;
- API paginada de catálogo, dashboard, scan runs, findings, detalhe, download e preview;
- desktop responsivo consumindo exclusivamente a API central por BFF/service;
- filtros Select2 por categoria e estados, sem carregar binários;
- detalhe com vínculos, eventos e findings mascarados;
- archive, restore, quarantine e release com justificativa, auditoria e reautenticação de administrador;
- kill switch `FILE_MANAGER_ALLOW_ADMIN_STATE_MUTATIONS=false` e ausência deliberada de exclusão física.

## Segurança

O painel não confia no UUID. Entrega e mutações confirmam o vínculo de negócio por authorizer. O serviço de entrega canonicaliza o path, rejeita escape por symlink, bloqueia estados inseguros e aplica `nosniff`, `no-store`, filename seguro e CSP sandbox.

A confirmação administrativa usa rate limit e registra separadamente quem executou e quem autorizou. Senhas não entram em old input, logs ou eventos. Falhas de credencial retornam `422` para preservar a sessão do operador.

## Validação

- backend: 4 testes e 51 asserções para catálogo, ausência de path, download, IDOR, step-up, auditoria e kill switch;
- desktop: 3 testes e 17 asserções para BFF, renderização, erro inline sem logout e encaminhamento seguro;
- views Blade compiladas e rotas backend/desktop enumeradas com sucesso;
- OpenAPI oficial atualizado para `1.4.0` e contrato específico promovido para `1.0.0`.

Durante a validação HTTP foi identificado e corrigido um ciclo `/login -> /dashboard -> /login`: diretórios novos estavam sem permissão de travessia para o PHP-FPM e a sincronização periódica do perfil voltava ao login ao receber erro transitório da API. Os diretórios de aplicação foram normalizados para `0755`; a sincronização agora preserva somente snapshots de autorização já válidos, enquanto sessões incompletas falham fechadas e são invalidadas. A API continua sendo a autoridade final em todas as operações.

Os canais diários de log do backend e do desktop passaram a criar arquivos com `0664`, permitindo escrita apenas ao owner e ao grupo operacional compartilhado. Não foi aplicada permissão pública nem `0777`.

## Limitações deliberadas

Trash físico, empty trash, retenção destrutiva, deduplicação e upload genérico não fazem parte desta entrega. Ativação produtiva depende de backup/restore conjunto, alertas externos, teste de carga e janela operacional aprovada.

## Evolução para biblioteca de arquivos

- pastas por categoria e visualização alternável em grade/lista;
- miniaturas lazy, preview seguro e download individual;
- seleção individual ou da página;
- download de até 50 arquivos em ZIP temporário, limitado a 100 MiB;
- permissão `arquivos:excluir` e exclusão recuperável para `trashed`;
- exclusão individual/em lote com uma única confirmação administrativa auditada;
- fallback de download de legado sem vínculo restrito a administradores do módulo;
- OpenAPI `1.5.0`, testes de ZIP/lixeira/CSRF e compilação Blade validados no servidor.

Não há remoção física de binários nesta interface. Uma futura purga deve exigir retenção vencida, ausência de vínculos ativos, dupla autorização e backup comprovado.

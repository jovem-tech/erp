# Lixeira operacional e retenção do Gerenciador de Arquivos

**Data:** 20/07/2026  
**Release:** `5.4.0.0`  
**Tipo:** evolução minor  
**Módulo:** Gerenciador Central de Arquivos

## Resultado

A lixeira deixou de ser um estado sem saída. Arquivos nela podem ser selecionados,
visualizados no modal, consultados em detalhes, restaurados ou excluídos
definitivamente. A política automática pode ser desativada ou definida em 7, 30 ou
90 dias.

## Arquitetura e decisões

- `trashed` continua recuperável e preserva o binário;
- `purged` é terminal e preserva somente o registro-túmulo auditável;
- restauração e exclusão definitiva aceitam lotes limitados;
- a mesma `ManagedFilePurgeService` é usada pela ação manual e pelo scheduler;
- a política é persistida em `configuracoes`, com fallback de ambiente;
- o scheduler roda às 02:30 com `onOneServer` e `withoutOverlapping`.

Separar `trashed` de `purged` evita confundir exclusão lógica com destruição física.
Preservar a linha do catálogo, seus vínculos e eventos mantém rastreabilidade sem
permitir nova entrega do conteúdo.

## Segurança

- RBAC específico: `restaurar`, `excluir` e `administrar`;
- reautenticação administrativa e motivo obrigatório;
- confirmação exata `EXCLUIR` para a ação irreversível;
- kill switch independente, desligado por padrão;
- rate limit nos endpoints destrutivos;
- recusa de arquivos fora da allowlist de discos/namespaces;
- normalização de caminho, verificação de `realpath` e recusa de symlink;
- bloqueio de itens em retenção legal;
- senha administrativa nunca retorna em old input, sessão ou log;
- preview da lixeira é autenticado, mas download permanece bloqueado.

## Performance e escalabilidade

A seleção automática usa o índice `(lifecycle_status, trashed_at)`, lote configurável
e processamento item a item. Locks distribuídos por UUID e bloqueio de linha evitam
dupla exclusão em múltiplos workers. A solução não carrega binários em memória e
remove miniaturas derivadas após o expurgo.

## Operação

Variáveis disponíveis:

```dotenv
FILE_MANAGER_ALLOW_PERMANENT_DELETION=false
FILE_MANAGER_TRASH_RETENTION_DAYS=30
FILE_MANAGER_TRASH_PURGE_BATCH_SIZE=250
```

O prazo `0` desativa o job destrutivo. Os prazos `7`, `30` e `90` são os únicos
aceitos. A instalação precisa manter o Laravel scheduler executando a cada minuto.

## Banco de dados

A migration `2026_07_20_000002_add_managed_file_purge_state.php` adiciona `purged_at`
e o índice composto usado pelo job. Nenhuma coluna ou arquivo legado é removido.

## Testes

- regressão do núcleo/backend de arquivos: 64 testes, 353 asserções;
- cenários específicos da API da lixeira: 16 testes, 153 asserções;
- desktop: 14 testes, 104 asserções;
- coberturas novas: preview sem download, restauração em lote, expurgo manual,
  registro-túmulo, retenção/cutoff, kill switch, modais e proxy sem retry.

## Rollback

1. definir `FILE_MANAGER_ALLOW_PERMANENT_DELETION=false`;
2. limpar o cache de configuração;
3. manter a migration aplicada enquanto existirem registros `purged`;
4. restaurar binários somente por backup, pois o expurgo é irreversível na aplicação.

Reverter o código sem desativar primeiro o kill switch é proibido. O `down` da migration
só deve ser usado quando não houver registros `purged` e após validação de backup.

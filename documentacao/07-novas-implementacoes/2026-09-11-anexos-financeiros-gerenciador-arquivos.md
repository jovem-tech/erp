# Anexos financeiros no Gerenciador de Arquivos

## Objetivo

O Gerenciador de Arquivos passa a ser a fonte oficial do ciclo de vida dos
anexos financeiros, preservando a disponibilidade do upload quando a
catalogação central estiver temporariamente indisponível.

O modo global continua sendo **shadow**. A autoridade sobre exclusão,
restauração e purge é habilitada somente para a categoria
**financeiro_anexo**.

## Arquitetura

Cada linha ativa de **financeiro_anexos** referencia no máximo um
**managed_files.uuid** por **managed_file_uuid**, protegido por índice único. A
integridade do domínio também exige:

- um vínculo atual com **subject_type=financeiro**, o lançamento correto e a
  relação **anexo:{id}**;
- um alias ativo para **financeiro_anexos.arquivo** e o mesmo registro de origem;
- caminho normalizado e fisicamente contido em
  **storage/app/private/financeiro/{financeiro_id}**;
- MIME real, extensão, tamanho e SHA-256 compatíveis com a policy da categoria.

Não existe chave estrangeira entre os dois módulos. Isso mantém o
desacoplamento dos ciclos de vida; a consistência é garantida por locks,
restrições únicas, chaves idempotentes e reconciliação periódica.

## Upload e consistência eventual

O binário é gravado no storage privado e o registro financeiro é confirmado
antes da catalogação. O observer só sincroniza após o commit.

Se o catálogo falhar, o upload continua válido com
**managed_file_uuid = null** e estado público **pending**. A sincronização
automática executada a cada cinco minutos chama o reconciliador financeiro e
tenta novamente. Reexecuções usam a mesma **operation_key** e não criam
duplicidades.

Os estados expostos pela API são:

- **pending**: upload preservado, aguardando catálogo;
- **active**: catálogo, vínculo e alias ativos;
- **trashed**: removido logicamente e retido na lixeira;
- **purged**: binário eliminado após a retenção;
- **integrity_error**: catálogo ausente ou divergência de integridade.

Caminho físico, SHA-256 e UUID interno do catálogo não são serializados nas
respostas.

## Ciclo de vida

No modo autoritativo, excluir pelo Financeiro ou pelo Gerenciador executa em
uma transação:

1. transição do arquivo gerenciado para **trashed**;
2. soft delete da linha em **financeiro_anexos**;
3. evento de auditoria com ator, autorizador, origem e motivo.

O binário permanece intacto durante 30 dias. A restauração existe somente no
Gerenciador e exige RBAC, confirmação administrativa e motivo. Quando o
lançamento pai não existe, a API retorna conflito HTTP 409 e mantém o arquivo
na lixeira.

Excluir o lançamento inteiro move todos os anexos para a lixeira antes do hard
delete do pai. A operação inteira é transacional; se um anexo não puder ser
preservado, nada é excluído.

O purge diário apaga o binário somente após o prazo, remove definitivamente a
linha financeira e mantém **managed_files** e seus eventos como tombstone
**purged**. Legal hold continua bloqueando a eliminação física.

## Segurança

- RBAC do lançamento e do painel central é aplicado antes de cada operação;
- IDs do lançamento e anexo precisam corresponder, evitando IDOR;
- caminhos absolutos, URLs, segmentos vazios e dois pontos consecutivos são recusados;
- resolução física impede escape da raiz e links simbólicos;
- o MIME é detectado pelo conteúdo no upload e novamente no download;
- nomes de arquivo são normalizados antes de armazenamento e entrega;
- downloads usam **private, no-store**, CSP sandbox e **nosniff**;
- extensões executáveis e conteúdo disfarçado são recusados;
- logs registram IDs e tipos de erro, sem caminhos completos ou dados
  sensíveis.

## Reconciliação

O comando é somente leitura por padrão:

    php artisan file-manager:reconcile-financeiro-anexos

Depois de revisar o relatório:

    php artisan file-manager:reconcile-financeiro-anexos --apply

O resultado JSON informa processados, corrigidos, consistentes, pendentes,
ausentes, erros de integridade, órfãos, aliases retirados, vínculos
desativados, duplicidades e falhas.

No primeiro rollout, o PDF real do lançamento 155 será catalogado e o
**debug-test.pdf**, cujo alias não possui origem financeira, será movido para a
lixeira sem apagar o binário.

## Implantação e rollback

Sequência segura:

1. gerar backup completo e inventário do namespace financeiro;
2. publicar código sem alterar outros arquivos pendentes no servidor;
3. aplicar apenas a migration aditiva desta entrega;
4. limpar os caches de configuração e aplicação;
5. executar o comando em dry-run e revisar os totais;
6. executar com **--apply**;
7. repetir o dry-run e validar zero pendências/duplicidades inesperadas;
8. verificar o lançamento 155 e a categoria Anexos financeiros pela interface.

Em rollback de aplicação, o código anterior pode ser restaurado mantendo as
colunas aditivas. O **down** da migration deve ser reservado para rollback
controlado, pois remove os marcadores de sincronização.

## Validação automatizada

Há cobertura para upload normal e pendente, idempotência, exclusão nas duas
origens, restauração, purge, exclusão do lançamento pai, rollback atômico,
RBAC, IDOR, path traversal, MIME falso, hash divergente, retenção, legal hold
e não exposição de caminhos físicos.

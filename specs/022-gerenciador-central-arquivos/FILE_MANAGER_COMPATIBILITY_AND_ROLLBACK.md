# File Manager — Compatibilidade e Rollback

## Objetivo

Garantir continuidade do ERP durante a implantação do Gerenciador Central de Arquivos. Este runbook define pré-condições, ativação, validação, rollback e tratamento de arquivos criados após a escrita central ser habilitada.

## Regra principal

Rollback não significa apagar o que o novo gerenciador criou. Significa restaurar o caminho operacional anterior, preservar arquivos/metadados/checkpoints e reconciliar o estado depois.

Migrations centrais são aditivas e permanecem no banco. Em incidente de produção, o primeiro rollback é por configuração, não `migrate:rollback`.

## Contratos que não podem quebrar

- URLs e route names existentes;
- parâmetros e payloads públicos;
- nomes apresentados para download;
- campos/caminhos legados ainda lidos pelo `sistema-hml` ou backend;
- geração A4/80mm e hashes de documentos;
- links públicos, tokens, expiração e revogação;
- autorização de OS, conversa, equipamento e assinatura;
- envio por WhatsApp/e-mail;
- imports/exports e arquivos temporários existentes.

Correções de segurança podem mudar `inline` para `attachment` e rejeitar tipos perigosos, mas isso deve ser documentado como mudança deliberada.

## Pré-condições para ativar um módulo

- inventário e mapa de dependências concluídos;
- testes de caracterização verdes;
- backup conjunto de banco e storage concluído e restaurável;
- permissões de filesystem validadas para PHP-FPM e workers;
- métricas/alertas do módulo visíveis;
- fallback habilitado;
- operations destrutivas desabilitadas;
- namespace/alias compatível definido para arquivos criados no híbrido;
- owner técnico e janela de observação definidos;
- procedimento de smoke e rollback ensaiado em homologação.

## Compatibilidade de leitura

Ordem padrão:

1. Autorizar o usuário pelo registro de negócio.
2. Resolver vínculo central atual.
3. Confirmar `security_status`, `integrity_status` e lifecycle.
4. Se não houver arquivo central elegível, consultar alias/campo legado allowlisted.
5. Canonicalizar e confirmar que o path está dentro da raiz autorizada.
6. Emitir métrica/evento de fallback.
7. Entregar com headers definidos pela policy.

O fallback não pode transformar path recebido do usuário em caminho físico. O path nasce do registro de domínio/alias interno.

## Compatibilidade de escrita

### Antes do modo híbrido

- legado continua sendo a única fonte de escrita;
- observe/shadow registra metadados e divergências;
- falha central não falha a operação legada.

### No primeiro modo híbrido de um módulo

- novo conteúdo é gravado, validado e catalogado antes da troca;
- campo/path legado continua preenchido com valor legível pelo resolver anterior;
- quando a chave canônica ainda não é aceita pelo leitor antigo, o piloto usa namespace físico compatível;
- arquivo anterior permanece disponível durante a janela de rollback;
- cleanup definitivo é proibido.

### Depois da canonicidade

Mover para `files/...` só é permitido quando:

- leitor legado entende alias central ou existe cópia compatível;
- todos os consumidores foram inventariados;
- rollback materializa/recupera o caminho legado;
- hashes antes/depois coincidem;
- restore de backup foi testado.

## Matriz de modos

| Origem | Destino | Ação | Evidência obrigatória |
|---|---|---|---|
| `off` | `observe` | habilitar instrumentação | smoke legado + métricas |
| `observe` | `shadow` | catalogar em paralelo | zero impacto e fila saudável |
| `shadow` | `hybrid` | escrita central por allowlist | paridade + rollback de arquivo novo |
| `hybrid` | `primary` | central como fonte principal | fallback baixo e gates do módulo |
| qualquer | `off` | rollback operacional | smoke legado + preservação dos novos |

Nunca saltar de `off` para `hybrid/primary`.

## Ativação segura

1. Confirmar commit/versão implantada e backup.
2. Confirmar migrations aditivas aplicadas.
3. Manter `FILE_MANAGER_MODE=off` e limpar/recarregar config de forma controlada.
4. Executar `file-manager:diagnose`.
5. Executar smoke do módulo no legado.
6. Alterar para `observe` e repetir smoke.
7. Observar erros/latência/fila pelo período definido.
8. Alterar para `shadow` somente para o módulo allowlisted.
9. Reconciliar divergências; nenhuma divergência crítica fica sem explicação.
10. Alterar para `hybrid` em janela acompanhada.
11. Criar pelo menos um arquivo novo e testar leitura central e fallback simulado.
12. Manter rollback disponível até o fim da janela de estabilização.

## Smoke tests mínimos

### Comuns

- autenticação e autorização;
- upload/geração válida;
- rejeição inválida;
- preview/download;
- nome e headers;
- arquivo legado;
- arquivo novo;
- usuário sem acesso;
- fallback e evento;
- falha de storage simulada em homologação.

### Branding

- login público carrega fundo;
- logo autenticado/público conforme contrato;
- sidebar e PDFs usam logo;
- troca inválida mantém imagem anterior.

### Fotos

- criar/editar equipamento/OS;
- preview e visualizador;
- PDF que incorpora a foto;
- exclusão lógica/substituição sem perda.

### Documentos

- geração A4/80mm;
- versão atual e histórica;
- ZIP, impressão e download;
- link público válido/expirado/revogado;
- WhatsApp/e-mail;
- assinatura e preview revisado.

### Chat

- enviar/receber cada tipo permitido;
- autorização por conversa/conta;
- provider indisponível;
- banco `chat` indisponível;
- conteúdo ativo forçado como attachment ou rejeitado.

## Procedimento de rollback operacional

1. Declarar incidente e registrar horário, versão, módulo e sintoma.
2. Desabilitar o módulo na allowlist ou definir `FILE_MANAGER_MODE=off`.
3. Garantir `FILE_MANAGER_LEGACY_FALLBACK_ENABLED=true`.
4. Manter `FILE_MANAGER_DESTRUCTIVE_OPERATIONS_ENABLED=false`.
5. Recarregar config/cache e reiniciar workers apenas se o deploy/runbook exigir.
6. Interromper scanners/migrações mutáveis; não matar jobs sem registrar checkpoints.
7. Preservar logs, métricas, outbox, scan runs e correlation IDs.
8. Executar smoke do fluxo legado.
9. Verificar arquivos criados desde o início do híbrido.
10. Para cada novo arquivo, confirmar caminho/alias legível pelo legado; se não existir, materializar cópia compatível via comando idempotente aprovado.
11. Não excluir arquivo central, alias, vínculo ou referência antiga.
12. Reconciliar blobs/links pendentes depois da estabilização.
13. Produzir relatório de causa, impacto, arquivos afetados e próximo gate.

## Arquivos criados durante o híbrido

Este é o ponto mais crítico do rollback. O módulo não pode ser declarado pronto para `hybrid` sem um teste que:

1. grave um arquivo pela nova infraestrutura;
2. confirme o hash e o vínculo;
3. desative o gerenciador;
4. leia o mesmo arquivo pelo contrato legado;
5. confirme mesmo conteúdo/nome;
6. reative o gerenciador sem criar duplicata.

Estratégias aceitas, em ordem de preferência:

1. storage key inicialmente compatível com o leitor legado;
2. alias central usado por adapter que também existe no modo off;
3. cópia write-through para namespace legado com reconciliação;
4. comando de materialização idempotente executado antes de concluir rollback.

Uma simples coluna `legacy_path` não prova compatibilidade; o arquivo precisa existir e o consumidor antigo precisa lê-lo.

## Falhas parciais e resposta

| Falha | Estado esperado | Ação |
|---|---|---|
| staging falha | nenhuma troca | remover temp parcial e retornar erro seguro |
| validação falha | anterior preservado | rejeitar/quarentenar conforme policy |
| promoção falha | anterior preservado | retry limitado; registrar erro |
| banco confirma rollback após promoção | blob candidato sem registro | compensar somente a chave recém-criada e confirmada como não catalogada |
| resultado do commit fica ambíguo | blob pode estar com ou sem registro | preservar o blob, consultar por operation key e reconciliar; nunca apagar sem confirmação |
| vínculo do domínio falha | central não atual | manter anterior; marcar pending link |
| evento/outbox falha em shadow | legado bem-sucedido | retry assíncrono e alerta |
| banco `chat` falha | central pending link | retry/reconciliar; não declarar enviado sem mensagem |
| arquivo central missing | fallback se autorizado | alertar integridade e bloquear se sem legado |
| path legado missing | central se válido | finding e investigação; sem exclusão |
| disco sem espaço | fail-closed antes da troca | rollback de módulo e alerta crítico |
| permissão negada | fail-closed antes da troca | corrigir owner/group; não ampliar para 0777 |

## Rollback de banco

- tabelas centrais não são dropadas em incidente;
- colunas nullable por módulo permanecem;
- o código em modo off deve ignorá-las;
- rollback de migration só é considerado em desenvolvimento/homologação sem dados relevantes;
- FKs/índices aditivos não podem ser removidos na mesma janela de incidente sem análise separada.

## Rollback de API/UI

- rotas novas do painel podem ser desabilitadas por feature flag/RBAC;
- rotas existentes não são renomeadas;
- frontend deve tolerar ausência dos campos novos enquanto o rollout não estiver completo;
- nenhum frontend deve depender exclusivamente de UUID central antes do gate do módulo.

## Backup e restauração

Antes de `hybrid`:

- backup consistente do banco principal;
- backup da conexão `chat` quando o módulo for migrado;
- backup do storage privado e aliases legados;
- registro da versão/configuração;
- teste de restore em ambiente isolado;
- conferência amostral por hash e vínculo.

Backup do banco sem storage, ou storage sem banco, não satisfaz o gate.

## Critérios para concluir rollback

- modo central desativado no módulo afetado;
- smoke legado aprovado;
- arquivos anteriores e criados no híbrido acessíveis;
- nenhum job mutável executando sem controle;
- operações parciais identificadas e preservadas;
- métricas estabilizadas;
- incidente e risco residual documentados;
- plano de correção aprovado antes de nova ativação.

## Critérios para desligar o fallback no futuro

O fallback somente poderá ser desligado em projeto e aprovação separados, quando:

- inventário e migração do módulo estiverem completos;
- fallback observado estiver em zero ou exceções formalmente aceitas por período definido;
- não houver consumer legado conhecido do path antigo;
- restauração e rollback da versão canônica estiverem testados;
- integridade integral/amostral aprovada conforme risco;
- backup conjunto verificado;
- suporte/operação treinados;
- aprovação explícita registrada.

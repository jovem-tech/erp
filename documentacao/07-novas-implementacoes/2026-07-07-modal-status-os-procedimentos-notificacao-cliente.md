# Overhaul do modal Alterar status da OS: procedimentos, diagnostico/solucao e notificacao ao cliente

## Contexto

- versao: `3.14.0.0`
- data: `2026-07-07`
- ambiente-alvo: `Ubuntu VPS` (reproduzido em `192.168.1.100`)
- area afetada: modal "Alterar status da OS" (`orders/_status_modal.blade.php`), acionado tanto na listagem de OS (`orders/index.blade.php`) quanto na tela de detalhe (`orders/show.blade.php`), e a cadeia backend que serve/persiste esses dados.

## Entrega

### 1. Card de equipamento com tipo + marca + modelo

- O card "Equipamento" do modal passou a exibir o resumo completo `tipo + marca + modelo` (ex.: "Smartphone Sony Xperia M4 Duas Aqua") em vez de apenas o tipo.
- Backend: `OrderWorkflowService::detailQuery()` ganhou eager-load de `equipment.type`, `equipment.brand` e `equipment.model` (evita N+1); `mapDetail()` passou a expor `equipamento_resumo_curto` reaproveitando o helper ja existente `resolveEquipmentShortSummary()`.
- Desktop: `OrderController::statusContext()` repassa `equipamento_resumo_curto` (com fallback para `equipamento_resumo_tecnico`).

### 2. Switch "Notificar o cliente" movido para o rodape

- O switch "Notificar o cliente sobre esta mudanca" saiu da coluna do formulario e foi para o rodape do modal (`.modal-footer`, com `margin-right:auto`), continuando dentro do `<form>` para ser enviado junto no submit.

### 3. Nova aba "Procedimentos" em 2 colunas

- O corpo do modal virou um par de abas Bootstrap ("Status" / "Procedimentos").
- **Coluna esquerda**: campo "Procedimentos executados" com botao proprio "Salvar procedimento" (cada clique cria uma entrada nova no historico, com data e tecnico responsavel), seguido de "Diagnostico do problema" e "Solucao aplicada".
- **Coluna direita**: historico dos procedimentos executados (mais recente primeiro, com data e autor).
- **Distincao importante de persistencia**:
  - "Procedimentos executados" e append-only: cada envio cria um registro em `os_procedimentos_historico` via novo endpoint `POST /api/v1/orders/{order}/procedures` (desktop: `POST /os/{order}/procedimentos`). O campo limpa apos salvar e o historico e re-renderizado.
  - "Diagnostico" e "Solucao" sao campos unicos da OS (`os.diagnostico_tecnico` / `os.solucao_aplicada`), salvos junto com o botao "Salvar status" (tem `name`, entram no submit do formulario principal).

### 4. Botao "Salvar status" sempre habilitado

- Antes o botao so liberava depois de selecionar um status de destino. Agora fica sempre habilitado, pois tambem serve para salvar diagnostico/solucao sem necessariamente trocar o status.
- Backend (`OrderWorkflowService::updateStatus()`): `status` virou opcional; quando ausente/vazio, mantem a etapa atual. Um "save sem troca de status" nao gera entrada no `os_status_historico`, nao dispara notificacao de status e nao recalcula margem — so persiste os campos tecnicos. Isso mantem a trilha de auditoria limpa.

### 5. Notificacao WhatsApp ao cliente na mudanca de status

- Quando o switch "Notificar o cliente" esta ativo **e** houve troca de status de fato, o sistema envia uma mensagem WhatsApp ao telefone do cliente com o novo status (e a observacao, se houver).
- **Causa raiz corrigida**: o checkbox `comunicar_cliente` era puramente visual — nunca era lido, validado nem repassado em nenhuma camada. Foi conectado ponta a ponta: `UpdateOrderStatusRequest` (+ validacao inline do desktop), ambos os controllers, `OrderService` (desktop) e `OrderWorkflowService::updateStatus()`.
- **Resiliencia de envio**: `sendStatusChangeClientNotification()` tenta primeiro o caminho da Central de Atendimento (`WhatsappMessagingService::sendSystemMessage()`, que registra a mensagem no inbox e depende do banco `chat`); se esse caminho falhar (ex.: banco `sistema_erp_chat` indisponivel/nao provisionado no ambiente), cai automaticamente para o **envio direto pela Evolution API** (`IntegrationSettingsService::sendDirectMessage()`) — o mesmo caminho do botao "Enviar teste" das Configuracoes. Assim a entrega acontece mesmo sem o inbox provisionado.

### 6. Correcao do fundo transparente do modal

- O modal renderizava sem fundo (a pagina aparecia atras dos paineis). Causa: `.modal-content` e `background: transparent` por padrao no `desktop.css`; so `.modal-content.modal-shell` recebe a superficie opaca/borda/sombra usada por todos os outros modais do sistema. Faltava a classe `modal-shell` neste modal — adicionada.

## Impactos

- **Migration aditiva**: `os_procedimentos_historico` (`CREATE TABLE`; `os_id`, `descricao`, `usuario_id`, `created_at` + indice). Nenhuma coluna/tabela removida ou alterada. Rodada com `--force` no dev (APP_ENV=production) mediante autorizacao.
- **Contrato de API**:
  - Novo: `POST /api/v1/orders/{order}/procedures` (append de procedimento executado).
  - `PATCH /api/v1/orders/{order}/status` mudou de forma retrocompativel: `status` passou de `required` para `nullable`, e passou a aceitar os campos opcionais `diagnostico_tecnico`, `solucao_aplicada` e `comunicar_cliente`. Consumidores atuais que sempre enviam `status` seguem funcionando.
  - Um endpoint intermediario `PATCH .../technical-notes`, criado no inicio desta mesma sessao (nunca versionado/publicado), foi substituido pelo endpoint de procedures e removido por completo (request, service, controller, rotas, JS) — sem deixar codigo morto.
- **Nova dependencia**: `OrderWorkflowService` passou a injetar `WhatsappMessagingService` e `IntegrationSettingsService` (sem dependencia circular; container resolve normalmente).
- **Deploy**: apos subir, rodar `php artisan route:clear` no backend (o cache de rotas `bootstrap/cache/routes-v7.php` precisa incluir a rota nova) e `migrate` da nova tabela.

## Validacao

- `php -l` em todos os arquivos PHP tocados (backend e desktop); `node --check` em `orders-status-modal.js`; `php artisan view:cache`/`view:clear` sem erros.
- Chrome headless (assets reais do servidor, login real) na tela de OS:
  - Card de equipamento exibindo "Smartphone Sony Xperia M4 Duas Aqua" (tipo+marca+modelo).
  - Switch de notificar presente no rodape e ausente da coluna do formulario.
  - Aba "Procedimentos" em 2 colunas; salvar um procedimento cria entrada no historico com data/tecnico e limpa o campo; reabrir o modal mantem o historico e os campos de diagnostico/solucao.
  - Submit com o select de status vazio: persiste `solucao_aplicada` sem alterar `os.status` nem gerar linha em `os_status_historico` (verificado no banco).
  - Botao "Salvar status" habilitado ja na abertura do modal.
- Notificacao ao cliente:
  - `IntegrationSettingsService::sendDirectMessage()` testado isoladamente para o numero de teste configurado → `ok:true`, HTTP 201, entregue pela Evolution API.
  - Fluxo completo de `sendStatusChangeClientNotification()` exercitado sobre a OS 3568 (cliente cujo telefone e o numero de teste): caminho do inbox falhou (banco `chat` inacessivel), o fallback disparou e a mensagem foi entregue — sem excecao e sem alterar o status da OS.
- Dados de teste criados durante a validacao foram revertidos (status, historico e entradas de procedimento de teste removidos).

## Pendencia de ambiente (nao-bloqueante)

- O banco `sistema_erp_chat` nao esta provisionado/acessivel ao usuario `erp_app` neste servidor de dev — por isso o caminho do inbox (Central de Atendimento) falha e o envio recai no fallback direto. Provisionar esse banco (`CREATE DATABASE` + `GRANT` + migrations de `database/migrations/chat/`) passou a ser opcional: so e necessario se as notificacoes de saida tambem devem aparecer no historico de conversas do inbox.

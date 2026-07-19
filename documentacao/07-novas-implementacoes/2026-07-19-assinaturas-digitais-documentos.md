# Assinaturas digitais e rastreabilidade documental

**Data:** 19/07/2026  
**Versão:** `4.26.3.0`
**Status:** migration aplicada e módulo ativo no ambiente de desenvolvimento LAN; não publicado na VPS de produção

## Objetivo

Vincular cada documento emitido pelo ERP a uma identidade verificável, mantendo separados o usuário que criou a operação, o usuário que assinou e o método utilizado. O módulo suporta assinatura interna cadastrada e rubrica do cliente pelo celular, tablet ou iPad.

## Arquitetura

- `usuario_assinaturas`: versões privadas da assinatura de cada usuário. Somente uma permanece ativa; versões antigas são preservadas para integridade histórica.
- `documento_solicitacoes_assinatura`: máquina de estados de pendências, signatário, papel, expiração, hashes e auditoria.
- `os_documentos`: registra signatário interno, hash, data e método, sem substituir o campo `gerado_por`.
- `SignatureImageService`: valida, decodifica, redimensiona e rasteriza PNG/JPG/WebP; arquivos SVG e URLs externas não são aceitos.
- `DocumentSignatureWorkflowService`: reautenticação, rate limit, criação de pendências, proteção contra OS alterada e finalização transacional.
- `PdfGenerationService`: injeta no bloco `assinatura` apenas imagens privadas já validadas pelo backend.

### Identificação exibida no documento

O bloco de assinatura usa os dados do **signatário efetivo**, e não o usuário que apenas gerou o arquivo:

- nome completo gravado no usuário que autenticou a assinatura;
- função funcional obtida de `equipe_membros.cargo`, com os indicadores de atuação e o perfil de acesso apenas como fallback;
- data em que a assinatura foi efetivamente realizada, independente da data de criação ou geração do documento;
- nome e data próprios para a assinatura do cliente.

As duas colunas reservam a mesma altura para imagem, linha, identificação e data. Assim, a linha do técnico e a linha do cliente permanecem alinhadas quando somente uma assinatura existe, quando ambas existem ou quando ainda estão pendentes.

## Fluxos

### Cadastro do usuário

Em **Perfil > Configurações**, o usuário pode importar uma assinatura escaneada ou desenhar com mouse, toque ou Apple Pencil. A senha atual é exigida para cadastrar ou substituir a assinatura.

Enquanto não houver assinatura ativa, o desktop exibe um aviso permanente com atalho para o cadastro. A gestão de usuários mostra o estado **Cadastrada/Pendente** sem carregar as imagens privadas.

### Emissão imediata

- **Assinar como eu:** usa a assinatura do usuário da sessão.
- **Assinar como outro usuário:** seleciona o signatário e exige e-mail e senha dele. A sessão não é trocada; criador e signatário ficam registrados separadamente.

### Assinatura pendente

O criador atribui o documento a outro usuário. O PDF só é emitido quando o responsável assina. Se a OS for alterada entre solicitação e assinatura, a pendência é recusada para impedir que alguém assine conteúdo diferente do revisado.

Antes da assinatura, o botão da pendência abre **Visualizar e analisar**. Essa tela renderiza o PDF A4 completo sem a imagem da assinatura e sem criar uma versão no acervo. Somente depois de a prévia carregar o usuário pode confirmar que leu todas as páginas e acionar **Assinar e emitir documento**.

O backend não confia apenas no estado do botão: registra o usuário revisor, data, snapshot da OS, fingerprint da versão publicada do template e fingerprints de IP/user-agent. A assinatura direta pela API é recusada sem revisão do mesmo usuário nos últimos 30 minutos. Uma alteração na OS ou a publicação de outra versão do template invalida a revisão.

As solicitações aparecem tanto na Central de Documentos quanto em **Perfil > Configurações > Documentos aguardando assinatura**. O responsável assina na própria sessão; o solicitante pode concluir no mesmo terminal somente após reautenticar o responsável.

#### Ciência multicanal da designação

Ao designar um documento para um usuário, o sistema cria três formas independentes de ciência:

- notificação em tempo real na caixa **Mensagens e documentos**, representada pelo ícone de carta ao lado do sino;
- e-mail para o endereço cadastrado do responsável;
- mensagem de WhatsApp para o telefone do usuário ou, como fallback, do seu cadastro funcional na equipe.

O sino permanece reservado aos avisos operacionais. Eventos `message.*` e `document.*` são direcionados exclusivamente à carta, com contador próprio. Ao abrir a notificação, ela é marcada como lida e o usuário é levado diretamente ao bloco **Assinaturas pendentes** da Central Documental da OS.

E-mail e WhatsApp são processados pela fila `default`, sem bloquear a criação da solicitação. Cada canal possui registro em `documento_assinatura_notificacoes`, com estado, quantidade de tentativas, provedor, referência e horário de entrega. Destinatários são armazenados somente de forma mascarada e com HMAC; falhas transitórias têm até três tentativas e uma rotina agendada recupera itens que não chegaram à fila.

A rotina também recupera solicitações de assinatura criadas antes da implantação da caixa de correspondências. O controle idempotente por solicitação e canal impede que o aviso interno, o e-mail ou o WhatsApp sejam duplicados durante reprocessamentos.

### Assinatura do cliente

O sistema cria um token aleatório de 64 caracteres, persiste apenas o SHA-256 e disponibiliza um link válido por sete dias. O cliente informa o nome, aceita o consentimento e desenha a rubrica. Um lock impede submissões simultâneas do mesmo link; após o uso, o token é invalidado.

## Segurança e privacidade

- armazenamento no disco privado, sem URL pública direta;
- limite de 2 MB e dimensões máximas de 4096 px;
- conversão para PNG remove metadados e elimina vetores/SVG executáveis;
- senha somente verificada por `Hash::check`, nunca armazenada em solicitações ou logs;
- rate limit separado para cadastro e reautenticação;
- IP e user-agent registrados somente como HMAC/SHA-256;
- hash da assinatura e do documento permite verificar integridade;
- token do cliente é de uso único e expira;
- locks por solicitação impedem duas emissões concorrentes, tanto no fluxo interno quanto no link do cliente;
- autorização específica por pendência, evitando conceder acesso amplo à OS.
- revisão obrigatória, vinculada ao usuário e ao snapshot da OS, validada novamente no endpoint de assinatura;
- prévias privadas usam `no-store`, não incluem a assinatura e não geram uma versão documental imutável;
- links enviados aos funcionários exigem autenticação normal no ERP e não carregam tokens de sessão;
- e-mail e telefone não são persistidos em texto aberto no histórico de entregas externas;

## Performance e escalabilidade

Os índices compostos atendem as consultas de pendências por usuário e por OS. A listagem administrativa usa `withExists`, evitando N+1 e sem ler os binários. Imagens são normalizadas para no máximo 1200 px de largura antes de entrarem no PDF, reduzindo memória do Dompdf. O fluxo é stateless, exceto pelo lock distribuído do cache, compatível com Redis em múltiplas instâncias.

A caixa de correspondências reutiliza `mobile_notifications`, filtrada por prefixos indexáveis de `tipo_evento`. Os canais externos são assíncronos, deduplicados por solicitação/canal e executados pelos workers existentes; após o sucesso, novas tentativas ignoram o canal já entregue. Isso permite escalabilidade horizontal sem aumentar a latência da geração documental.

## Operação e deploy

Executar as migrations `2026_07_19_000002_create_document_signature_infrastructure.php`, `2026_07_19_000003_create_document_signature_notification_deliveries.php`, `2026_07_19_000005_require_document_review_before_signature.php` e `2026_07_19_000006_bind_signature_review_to_pdf_template.php`, limpar caches do backend e do desktop e confirmar que o driver de cache compartilhado é Redis em produção. Os arquivos de assinatura devem fazer parte da rotina de backup do armazenamento privado, com acesso restrito ao usuário do serviço.

A administração deve conferir a coluna **Assinatura** e concluir o cadastro dos usuários ativos. Por padrão, `DOCUMENT_SIGNATURES_REQUIRED=true`: todo PDF atribuído a um usuário é recusado enquanto ele não tiver assinatura ativa. Processos puramente sistêmicos, sem ator humano, continuam permitidos e são identificados como **Sistema** na auditoria. Em uma janela de migração controlada, a exigência pode ser temporariamente desativada com `DOCUMENT_SIGNATURES_REQUIRED=false`.

## Validação executada

- cadastro, substituição e armazenamento privado da assinatura;
- rejeição de senha incorreta sem persistir arquivo;
- reautenticação com atribuição ao signatário correto sem trocar o ator da sessão;
- token do cliente armazenado em hash, uso único e consentimento auditado;
- bloqueio de documento humano sem assinatura ativa;
- bloqueio de assinatura direta sem prévia revisada e confirmação explícita;
- auditoria da revisão e entrega da prévia como PDF privado sem cache;
- renderização dos tipos PDF em A4 e 80 mm;
- identificação do signatário por nome, função e data efetiva da assinatura;
- alinhamento estável das duas linhas com uma ou duas assinaturas presentes;
- paridade da Central Documental e guarda contra geradores paralelos;
- 32 testes focados aprovados, com 260 asserções, nas suítes de assinatura, motor PDF e paridade da Central Documental.

A compilação dos templates Blade e a listagem de rotas também foram validadas.
A automação visual pelo navegador interno não conseguiu atravessar o certificado
HTTPS autoassinado do ambiente LAN; os endpoints públicos foram verificados por
requisição HTTPS com validação local controlada.

## Melhorias futuras

- certificado PDF com carimbo de tempo externo e ICP-Brasil;
- revogação administrativa de pendências e painel global de SLA;
- notificação automática do signatário e do cliente;
- verificação visual do hash na Central de Documentos;
- política de retenção e consentimento configurável pela LGPD.

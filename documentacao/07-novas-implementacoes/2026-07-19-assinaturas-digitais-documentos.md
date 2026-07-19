# Assinaturas digitais e rastreabilidade documental

**Data:** 19/07/2026  
**Versão:** `4.24.1.0`  
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

As solicitações aparecem tanto na Central de Documentos quanto em **Perfil > Configurações > Documentos aguardando assinatura**. O responsável assina na própria sessão; o solicitante pode concluir no mesmo terminal somente após reautenticar o responsável.

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

## Performance e escalabilidade

Os índices compostos atendem as consultas de pendências por usuário e por OS. A listagem administrativa usa `withExists`, evitando N+1 e sem ler os binários. Imagens são normalizadas para no máximo 1200 px de largura antes de entrarem no PDF, reduzindo memória do Dompdf. O fluxo é stateless, exceto pelo lock distribuído do cache, compatível com Redis em múltiplas instâncias.

## Operação e deploy

Executar a migration `2026_07_19_000002_create_document_signature_infrastructure.php`, limpar caches do backend e do desktop e confirmar que o driver de cache compartilhado é Redis em produção. Os arquivos de assinatura devem fazer parte da rotina de backup do armazenamento privado, com acesso restrito ao usuário do serviço.

A administração deve conferir a coluna **Assinatura** e concluir o cadastro dos usuários ativos. Por padrão, `DOCUMENT_SIGNATURES_REQUIRED=true`: todo PDF atribuído a um usuário é recusado enquanto ele não tiver assinatura ativa. Processos puramente sistêmicos, sem ator humano, continuam permitidos e são identificados como **Sistema** na auditoria. Em uma janela de migração controlada, a exigência pode ser temporariamente desativada com `DOCUMENT_SIGNATURES_REQUIRED=false`.

## Validação executada

- cadastro, substituição e armazenamento privado da assinatura;
- rejeição de senha incorreta sem persistir arquivo;
- reautenticação com atribuição ao signatário correto sem trocar o ator da sessão;
- token do cliente armazenado em hash, uso único e consentimento auditado;
- bloqueio de documento humano sem assinatura ativa;
- renderização dos tipos PDF em A4 e 80 mm;
- identificação do signatário por nome, função e data efetiva da assinatura;
- alinhamento estável das duas linhas com uma ou duas assinaturas presentes;
- paridade da Central Documental e guarda contra geradores paralelos;
- 24 testes focados aprovados, com 197 asserções, nas suítes de assinatura, motor PDF e paridade da Central Documental.

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

# Motor central e templates modernos de PDF

**Data:** 2026-07-18  
**Status:** implementado no ambiente de desenvolvimento LAN; ainda não promovido à VPS de produção

## Resultado

Todos os PDFs operacionais da OS passam pelo mesmo motor, usam templates
versionados e podem ser administrados em **Conhecimento > Modelos PDF**.
O antigo editor HTML deixou de ser uma fonte de emissão: suas rotas apenas
redirecionam para o motor central, preservando favoritos sem manter dois
catálogos concorrentes.

Os sete tipos cobertos são:

- `os_abertura`
- `os_orcamento`
- `os_laudo_tecnico`
- `os_cobranca_manutencao`
- `os_comprovante_entrega`
- `os_devolucao_sem_reparo`
- `os_encerramento`

Além desses tipos nativos, a página permite criar documentos personalizados
do zero ou clonar qualquer modelo existente. O novo documento nasce como
rascunho independente; a clonagem copia o rascunho atual da origem (ou a
versão publicada quando não há rascunho) e reinicia o versionamento em v1.
Alterar nome, descrição ou blocos da cópia nunca modifica o documento original.

## Criação e clonagem de documentos

O botão **Novo documento** solicita nome, descrição e uma fonte de dados
permitida:

- dados gerais da OS, cliente e equipamento;
- OS e orçamento, incluindo itens e totais;
- encerramento, recebimentos e garantia.

O botão **Clonar** fica disponível em cada linha do catálogo e pede somente o
novo nome e a descrição. O cabeçalho usa `{{ documento.nome }}`, portanto uma
cópia pode manter todo o layout e trocar apenas o nome sem editar o schema.

Documentos personalizados são manuais e só aparecem na Central Documental das
OS depois de publicados. A Central os gera em A4 e 80 mm, cria uma nova versão
imutável no acervo e registra o código, a versão e o hash do template usado.

Cada tipo tem cabeçalho, corpo e rodapé declarativos. A versão publicada é
imutável; alterações são feitas em rascunho e só afetam novas emissões depois
da publicação. PDFs já arquivados não são reescritos.

## Tema leve e moderno

O tema compartilhado usa superfícies claras, acento institucional discreto,
títulos de seção leves, tabelas com contraste moderado, totais destacados sem
blocos escuros e rodapé institucional compacto. A logo e os dados da empresa
continuam vindo das configurações do sistema.

O mesmo schema gera:

- A4, com layout compacto, paginação e cabeçalho institucional;
- 80 mm, monocromático e otimizado para impressão térmica. Grades de campos
  viram um par rótulo/valor por linha para evitar cortes e palavras ilegíveis.

O bloco `colunas` aceita até três colunas e larguras percentuais que totalizam
100%. O cabeçalho institucional A4 usa a proporção 25/50/25: logo da empresa à
esquerda, dados institucionais centralizados geometricamente e foto principal do
equipamento à direita. A foto é opcional; quando ausente, a terceira coluna é
mantida vazia para preservar a centralização.

Esse cabeçalho é uma regra central do motor, aplicada aos sete modelos nativos,
aos documentos personalizados e a toda criação ou clonagem futura. A migration
`2026_07_18_000015_standardize_pdf_template_headers.php` atualiza famílias já
existentes sem tocar no corpo ou no rodapé. Publicações antigas são arquivadas e
uma nova versão é criada; rascunhos permanecem rascunhos para impedir a
publicação acidental de outras edições em andamento.

No A4, o rodapé institucional fica em uma margem inferior reservada e a
numeração permanece abaixo dele. O rodapé deixa de participar do fluxo do corpo,
evitando páginas adicionais que continham apenas os metadados da emissão. O
layout de 80 mm mantém o rodapé em fluxo para preservar o comportamento térmico.

## Arquitetura

| Componente | Responsabilidade |
|---|---|
| `PdfTemplateRegistry` | Catálogo dos tipos, contextos, variáveis permitidas e gatilhos |
| `PdfDefaultTemplates` | Schemas padrão dos sete tipos |
| `PdfSchemaValidator` | Validação estrutural e de variáveis antes da publicação |
| `PdfTemplateRenderer` | Conversão segura dos blocos em HTML A4/80 mm |
| `PdfGenerationService` | Única entrada de geração, prévia, cache e metadados de auditoria |
| `OrderDocumentCenterService` | Catálogo, geração e persistência dos documentos da OS |

A migration `2026_07_18_000014_add_custom_pdf_template_support.php` identifica
famílias personalizadas, seu tipo-base seguro e a família de origem da cópia.
O registro do tipo-base é uma allowlist; o usuário não pode cadastrar classes,
SQL, URLs remotas ou código executável como fonte de dados.

Os geradores de abertura, orçamento e encerramento delegam exclusivamente ao
`PdfGenerationService`. O código não possui mais fallback visual em blades ou
HTML embutido. Se o template publicado estiver ausente ou inválido, a emissão
é bloqueada com erro explícito; isso evita entregar ao cliente um documento
diferente do modelo aprovado.

## Versionamento e auditoria

A migration `2026_07_18_000013_publish_light_pdf_templates_v2.php` promove o
tema moderno para v2 somente quando encontra a v1 original pelo hash conhecido.
Famílias arquivadas, personalizadas ou com rascunho são preservadas. A migration
é transacional, usa bloqueio de linha e possui rollback seguro.

Cada documento arquivado registra:

- código e ID do template;
- versão publicada;
- hash SHA-256 do schema;
- origem da emissão;
- arquivos A4 e 80 mm, com hash próprio.

O encerramento passou a aparecer no catálogo documental e abertura/encerramento
agora arquivam os dois formatos.

## Assinaturas do responsável e do cliente

O bloco `assinatura` aceita uma ou duas linhas e, no A4, distribui duas
assinaturas em colunas de 50%: responsável à esquerda e cliente à direita.
O editor apresenta campos separados, sem exigir edição de JSON. Novos blocos
nascem com `os.tecnico_nome` e `cliente.nome`; quando a OS não possuir técnico,
o primeiro campo pode usar `documento.usuario` como responsável pela emissão.

A migration `2026_07_19_000001_add_client_to_pdf_signature_blocks.php`
acrescenta o cliente a assinaturas existentes que possuíam apenas técnico ou
usuário emissor. Publicações são versionadas; rascunhos são atualizados sem
publicação automática, preservando outras alterações em andamento. O validador
limita o bloco a dois rótulos, valida as variáveis e impede conteúdo estrutural
inválido antes da publicação.

Em 19/07/2026, esse bloco foi integrado ao módulo de identidade de assinaturas.
Quando a emissão possui ator humano, o motor injeta a imagem privada e registra
ID da versão, hash e método. Com `DOCUMENT_SIGNATURES_REQUIRED=true`, nenhum PDF
atribuído a usuário é criado sem assinatura ativa. O fluxo completo está em
`2026-07-19-assinaturas-digitais-documentos.md`.

## Galeria de fotos de entrada

O bloco `fotos_entrada` (19/07/2026) exibe até 4 fotos de recepção/check-in da
OS (`os_fotos.tipo = 'recepcao'`) lado a lado, em qualquer área (cabeçalho,
corpo ou rodapé) de qualquer um dos sete tipos nativos ou de documentos
personalizados — não é exclusivo da abertura, que já tinha uma foto única do
equipamento no cabeçalho. Sem campos de configuração: o bloco sempre mostra o
que existir na OS no momento da geração.

Duas regras específicas desse bloco, só para o PDF:

- **paisagem sempre**: fotos em retrato são rotacionadas 90° via GD antes de
  virar base64. A rotação existe somente na montagem do contexto do PDF — o
  arquivo original em disco e qualquer outra tela do sistema continuam com a
  orientação em que a foto foi enviada;
- **sem corte**: cada foto ocupa uma caixa de tamanho fixo (`background-size:
  contain`, não `cover`) para caber inteira, já que o dompdf não suporta
  `object-fit`. Proporções diferentes deixam uma margem neutra dentro da
  caixa em vez de cortar parte da imagem.

`OrderWorkflowService::resolveEntryPhotosForPdf()` resolve os arquivos (mesmo
fallback gerenciado/legado do endpoint de fotos da OS, sem checagem de ator —
a autorização de gerar o documento já aconteceu numa camada acima) e
`OrderPdfContextFactory` aplica a mesma allowlist de MIME e limite de 2 MB já
usados pela foto do equipamento antes de rotacionar e embutir.

## Segurança

- variáveis escapadas por padrão contra XSS;
- HTML rico sanitizado por allowlist;
- imagens aceitas somente por token interno/base64, sem URL remota;
- `foto_equipamento_principal` reutiliza a resolução privada de arquivos do
  serviço de equipamentos, limita a 2 MB e permite apenas JPEG, PNG ou WebP;
- `fotos_entrada` aplica a mesma allowlist de MIME/tamanho por foto (até 4),
  ignorando silenciosamente (com log de aviso) qualquer arquivo fora do limite
  em vez de falhar a geração do documento inteiro;
- caminhos absolutos, bytes nulos e segmentos `..` são rejeitados antes de
  qualquer leitura da foto no storage local ou legado;
- dompdf com PHP e acesso remoto desativados;
- nenhum uso de dompdf permitido fora de `app/Services/Pdf`;
- publicação validada contra variáveis e coleções autorizadas;
- transações, `lockForUpdate` e idempotência nos fluxos de persistência;
- permissões independentes para visualizar, editar, publicar e restaurar.
- criação e clonagem exigem `conhecimento:editar` e validam tamanhos e tipos-base;
- códigos internos são gerados no servidor e protegidos por índice único;
- documentos personalizados reutilizam somente contextos registrados pelo sistema.

## Performance e escalabilidade

O schema publicado é cacheado por versão. O contexto completo é montado antes
do render, evitando consultas dentro de blocos e reduzindo risco de N+1. PDFs
continuam stateless durante a renderização e são persistidos somente após a
validação da saída. Gerações lentas são registradas em log estruturado.
Tokens de imagem são extraídos do schema antes da montagem do contexto; a foto
do equipamento só consulta o registro e lê o arquivo quando o modelo realmente
usa `foto_equipamento_principal`. A mesma extração cobre o bloco
`fotos_entrada`: os até 4 arquivos só são lidos e rotacionados quando o schema
realmente contém esse bloco, sem custo para os demais tipos documentais.

## Verificação

- 7 tipos renderizados em A4 e 80 mm;
- testes de paridade da Central Documental e metadados de auditoria;
- testes de bloqueio quando não há template publicado;
- guarda arquitetural contra geradores paralelos;
- testes do editor e do redirecionamento do catálogo legado;
- inspeção visual de abertura, orçamento e encerramento em A4/80 mm;
- verificação de paginação, dimensões e ausência de placeholders não resolvidos.
- testes de criação, clonagem, autorização e independência do schema;
- teste de publicação, catálogo e geração A4/80 mm de documento personalizado;
- inspeção visual de um documento criado do zero em A4 e 80 mm.
- bloqueio de documento atribuído a usuário sem assinatura cadastrada;
- renderização da assinatura do responsável e do cliente em paralelo.
- inspeção visual da galeria de fotos de entrada com dados reais (OS com 4
  fotos, incluindo uma em retrato): rotação para paisagem e ausência de corte
  confirmadas em PNG renderizado a partir do PDF gerado.

## Promoção controlada do Termo de Garantia

O Termo de Garantia aprovado no editor foi convertido no snapshot versionado
`2026_07_18_000016_seed_termo_garantia_template.php`. Em uma instalação que
ainda não possua esse documento, a migration cria a família personalizada com
fonte de dados `os_encerramento` e publica a primeira versão. O identificador
do tipo é estável e acompanha o código pelo fluxo `develop -> main -> VPS`.

A operação é idempotente: se o código ou outro Termo de Garantia personalizado
já existir no banco do destino, nenhuma versão é sobrescrita. O rollback também
só remove o seed quando ele ainda possui uma única versão com o hash original;
qualquer edição realizada na VPS preserva integralmente a família.

## Correções de depuração (19/07/2026)

Auditoria dirigida ao módulo de documentos/PDF encontrou e corrigiu:

- **cache de logo nunca invalidado**: `CompanyContextProvider` cacheava a logo
  por 10 min sem nenhum ponto de invalidação; trocar ou remover a logo em
  Configurações da Empresa não refletia nos PDFs até o cache expirar sozinho.
  `CompanyProfileService::storeLogo()`/`removeLogo()` agora chamam
  `forgetLogoCache()`;
- **teto de recursão ausente na prévia**: os limites de profundidade de
  `condicional`/`colunas` só eram checados no publish (`PdfSchemaValidator`);
  a prévia do editor renderiza rascunhos não publicados sem passar por essa
  validação. `PdfTemplateRenderer` ganhou um teto de segurança
  (`MAX_RENDER_DEPTH`) direto na recursão, independente de qualquer chamador;
- **formatação de data ausente em `recebimentos.data`** no comprovante de
  encerramento (ver migration `2026_07_18_000014_fix_encerramento_recebimentos_data_format.php`).

## Evolução futura

Novos documentos devem ser adicionados por descritor, contexto e template
declarativo no motor central. A tabela legada `os_pdf_templates` pode ser
removida em release posterior, após uma janela de retenção/backup dos dados.

# Feature Specification: Cadastro Completo de Equipamentos no Desktop

**Feature Branch**: `008-cadastro-equipamentos-desktop`
**Created**: 2026-06-24
**Status**: Approved
**Input**: User description: "Implementar no sistema-erp a mesma feature operacional de Novo Equipamento do sistema-hml, com paridade de fluxo e UX visivel, incluindo abas, quick-adds, senha por desenho ou texto, coleta tecnica remota, cor, fotos com camera/cropper e ate 4 fotos."

## User Scenarios & Testing

### User Story 1 - Cadastrar equipamento completo no desktop (Priority: P1)

Como atendente ou tecnico com permissao de cadastro,
quero abrir a tela de novo equipamento no desktop e concluir o cadastro completo em um unico fluxo,
para iniciar o atendimento com o mesmo padrao operacional ja usado no legado.

**Why this priority**: sem esse fluxo, o modulo de equipamentos continua incompleto e obriga o uso do legado para uma operacao central do ERP.

**Independent Test**: abrir `/equipamentos/novo`, preencher cliente, tipo, marca, modelo, serie ou IMEI, senha, cor, ao menos uma foto e campos tecnicos, enviar o formulario e chegar ao detalhe do equipamento recem-criado com a foto principal visivel.

**Acceptance Scenarios**:

1. **Given** um usuario com permissao `equipamentos:criar`, **When** acessa `/equipamentos/novo`, **Then** ve as abas `Informacoes`, `Cor` e `Fotos` com o shell visual do desktop.
2. **Given** um cadastro valido com ao menos uma foto, **When** envia o formulario, **Then** o backend cria o equipamento, armazena as fotos privadas e o desktop redireciona para o detalhe com mensagem de sucesso e a foto principal em destaque.
3. **Given** um equipamento do tipo desktop ou notebook, **When** seleciona a familia correspondente, **Then** o painel tecnico condicional e exibido e influencia o resumo tecnico salvo.
4. **Given** um equipamento ja cadastrado, **When** acesso a listagem e o detalhe, **Then** a miniatura principal aparece na primeira coluna da tabela e a foto principal aparece no canto superior direito do detalhe.

---

### User Story 1A - Editar equipamento existente no desktop (Priority: P1)

Como atendente ou tecnico com permissao de edicao,
quero abrir um equipamento ja cadastrado no mesmo layout operacional usado na criacao,
para atualizar dados, fotos e contexto tecnico sem reaprender outro fluxo.

**Why this priority**: o modulo fica incompleto sem manutencao operacional do cadastro durante o ciclo de vida do equipamento.

**Independent Test**: abrir a listagem em `/equipamentos`, acionar `Editar`, entrar em `/equipamentos/{id}/editar`, alterar campos operacionais, manter ou substituir fotos, salvar e confirmar o retorno do equipamento atualizado sem perder a foto principal.

**Acceptance Scenarios**:

1. **Given** um usuario com permissao `equipamentos:editar`, **When** acessa `/equipamentos/{id}/editar`, **Then** ve o mesmo shell visual e o mesmo formulario de `/equipamentos/novo`, agora preenchidos com os dados atuais do equipamento.
2. **Given** um equipamento com fotos existentes, **When** removo uma foto, adiciono outra e escolho qual sera a principal, **Then** o backend preserva de 1 a 4 fotos totais, remove os arquivos descartados e mantem exatamente uma foto principal.
3. **Given** um usuario com `equipamentos:editar` e sem `equipamentos:visualizar`, **When** entra no fluxo de edicao, **Then** continua conseguindo carregar foto privada, catalogos e metadados auxiliares necessarios ao formulario sem ganhar acesso indevido a quick-adds ou a telas nao autorizadas.

---

### User Story 2 - Criar catalogos e cliente rapido sem sair da tela (Priority: P1)

Como usuario operacional,
quero cadastrar cliente, marca e modelo rapidamente dentro do formulario de equipamento,
para nao interromper o fluxo quando o dado ainda nao existir.

**Why this priority**: o legado ja depende desse comportamento para manter velocidade de cadastro e reduzir abandono do fluxo.

**Independent Test**: abrir o formulario, usar os modais rapidos de cliente, marca e modelo, voltar ao formulario e continuar o cadastro sem reload completo da pagina.

**Acceptance Scenarios**:

1. **Given** que o cliente nao existe, **When** uso `+ Novo` ao lado do seletor de cliente, **Then** um cadastro rapido e salvo e o cliente passa a estar selecionado no formulario.
2. **Given** que a marca ou o modelo nao existem, **When** uso `+ Adicionar`, **Then** o catalogo e criado pela API central, a marca fica vinculada ao tipo selecionado e o modelo novo fica vinculado a marca e ao tipo atuais.
3. **Given** falha de validacao no quick-add, **When** o backend rejeita a operacao, **Then** a tela mostra mensagem clara e mantem o restante do formulario intacto.

---

### User Story 3 - Importar apoio tecnico, sugestoes e midia sem travar o fluxo (Priority: P2)

Como usuario de bancada,
quero importar dados tecnicos do coletor local legado, manter o apoio remoto quando necessario, receber sugestoes externas de modelo e usar camera/galeria com cropper,
para acelerar o preenchimento tecnico sem perder o fluxo operacional ja validado na bancada.

**Why this priority**: esse fluxo amplia produtividade, mas o cadastro manual ainda continua possivel se alguma integracao falhar.

**Independent Test**: ler um snapshot local em `C:\JovemTechBenchCollector`, executar a coleta local quando possivel, importar os dados no formulario, manter o pareamento remoto disponivel como apoio, buscar sugestoes externas de modelo e adicionar fotos por camera ou galeria.

**Acceptance Scenarios**:

1. **Given** que existe um snapshot local valido do coletor, **When** o usuario aciona a busca local no desktop, **Then** o formulario importa os campos tecnicos no contexto atual sem sair da tela.
2. **Given** que a busca externa de modelo falha, **When** tento consultar sugestoes, **Then** recebo retorno vazio sem bloquear o cadastro manual.
3. **Given** que a camera nao esta disponivel, **When** entro na aba de fotos, **Then** continuo conseguindo usar upload por galeria e preview local antes do envio.

## Edge Cases

- submissao com mais de 4 fotos deve ser bloqueada no backend e refletida de forma clara no desktop;
- submissao sem foto deve ser bloqueada antes do envio no desktop e validada novamente no backend;
- codigo de pareamento expirado ou ja consumido nao pode preencher o formulario quando o modo remoto for usado;
- indisponibilidade do executavel publicado nao pode bloquear a importacao do ultimo snapshot local ja existente;
- tipo de equipamento sem dependencia de catalogo nao pode exigir marca/modelo;
- foto privada sem autenticacao deve retornar erro padronizado;
- equipamentos legados sem foto continuam acessiveis na listagem e no detalhe com placeholder visual sem quebrar a tela;
- perda de conectividade com a API durante o quick-add nao pode apagar dados ja digitados no formulario;
- edicao que remova todas as fotos deve ser bloqueada localmente e novamente no backend;
- usuario com permissao de edicao, mas sem permissao de visualizacao, deve conseguir carregar o formulario e as fotos privadas do proprio fluxo de manutencao sem receber acesso ampliado a outras rotas;
- quick-add de cliente, marca e modelo nao pode aparecer como capacidade implicita para perfis que apenas editam equipamentos sem permissao de criacao correspondente.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST disponibilizar a rota desktop `/equipamentos/novo` com formulario completo organizado em abas `Informacoes`, `Cor` e `Fotos`.
- **FR-001a**: O sistema MUST disponibilizar a rota desktop `/equipamentos/{equipment}/editar`, reutilizando o mesmo layout, os mesmos componentes Blade e o mesmo JavaScript operacional do cadastro, apenas com contexto preenchido para edicao.
- **FR-002**: O sistema MUST expor `GET /api/v1/equipments/form-data` com catalogos, defaults, modos de senha, limite de fotos e metadados de apoio ao formulario.
- **FR-003**: O sistema MUST criar equipamentos por `POST /api/v1/equipments` aceitando payload multipart com campos operacionais e de 1 a 4 fotos.
- **FR-003a**: O sistema MUST atualizar equipamentos por `PUT/PATCH /api/v1/equipments/{equipment}` aceitando payload multipart com dados operacionais, novas fotos opcionais, sincronizacao de fotos existentes e definicao explicita da foto principal final.
- **FR-004**: O sistema MUST suportar quick-add de marca e modelo pelos endpoints `POST /api/v1/equipments/brands` e `POST /api/v1/equipments/models`, sempre recebendo o `tipo_id` selecionado para persistir o escopo do catalogo no fluxo atual.
- **FR-004a**: Quando a marca for criada antes de existir um modelo real vinculado, o backend MUST manter o escopo `tipo -> marca` de forma compativel com a tabela legada `equipamentos_catalogo_relacoes`, sem expor modelo tecnico ao usuario final.
- **FR-004b**: O desktop MUST manter os quick-adds de cliente, marca e modelo visiveis apenas quando o usuario tambem possuir a permissao de criacao exigida pelo dominio correspondente, mesmo dentro do fluxo de edicao.
- **FR-005**: O desktop MUST reutilizar `POST /api/v1/clients` para quick-add de cliente por meio de uma fachada same-origin no `frontends/desktop`.
- **FR-006**: O sistema MUST permitir senha de acesso por texto ou por desenho, convertendo o modo desenho em um valor persistivel e consistente.
- **FR-007**: O sistema MUST manter o campo visual unico `Nº Serie ou IMEI`, preenchendo `numero_serie` e aceitando `imei` quando vier de snapshot remoto.
- **FR-008**: O sistema MUST aplicar o default de `Desktop montado` com marca e modelo automaticos quando essa modalidade for selecionada.
- **FR-009**: O sistema MUST oferecer leitura local do snapshot do coletor em `C:\JovemTechBenchCollector` e tentativa de execucao automatica do agente quando o ERP estiver na mesma maquina Windows.
- **FR-009a**: O sistema MUST manter o fluxo remoto de pareamento por codigo como capacidade de apoio, com criacao, armazenamento temporario, leitura e consumo do snapshot.
- **FR-009b**: O desktop MUST exibir o cartao do coletor local somente quando o tipo selecionado pertencer a familia `desktop` ou `notebook`, mantendo o cadastro manual visivel para qualquer outro tipo de equipamento.
- **FR-010**: O sistema MUST expor `GET /api/v1/equipments/models/suggestions` com timeout curto, cache, rate limit e retorno vazio em falha externa.
- **FR-011**: O sistema MUST armazenar fotos do equipamento em storage privado e servi-las apenas por endpoint autenticado.
- **FR-011a**: No fluxo de edicao, o sistema MUST permitir manter, remover ou substituir fotos existentes sem expor URL publica, apagando arquivos removidos do storage privado e preservando a integridade da foto principal final.
- **FR-012**: O desktop MUST permitir upload por galeria, captura por camera, crop local, definicao da foto principal e remocao de preview antes do envio.
- **FR-012a**: O desktop MUST impedir a criacao do equipamento sem foto no submit local e o backend MUST validar novamente a obrigatoriedade da foto.
- **FR-012b**: O sistema MUST exibir a foto principal do equipamento no detalhe e uma miniatura principal na primeira coluna da listagem operacional.
- **FR-012c**: O desktop MUST reaproveitar a mesma grade visual da aba `Fotos` para combinar fotos existentes e novas durante a edicao, incluindo remocao, troca da principal e sincronizacao correta de indices e ids antes do submit.
- **FR-013**: O desktop MUST falhar com seguranca quando camera, coletor ou sugestoes externas estiverem indisponiveis, mantendo o cadastro manual utilizavel.
- **FR-014**: O sistema MUST registrar logs tecnicos e auditoria minima para criacao de equipamento, quick-adds, consumo de pareamento e falhas de midia.
- **FR-015**: A documentacao tecnica, nota de implementacao, README do desktop, contrato da API e versionamento compartilhado MUST ser atualizada junto com a feature.
- **FR-016**: Todo dropdown select do `frontends/desktop` MUST usar `Select2` como padrao visual e funcional, com excecoes tecnicas explicitamente documentadas quando necessario.

### Key Entities

- **Formulario de Equipamento**: representa o conjunto de dados operacionais, visuais e tecnicos usados para criar um equipamento completo no desktop.
- **Catalogo de Equipamento**: conjunto de tipos, marcas e modelos utilizados para padronizar o cadastro.
- **Snapshot Local do Coletor**: documento JSON gerado pelo agente legado em `C:\JovemTechBenchCollector`, lido e mapeado para preencher o formulario tecnico.
- **Pareamento do Coletor**: codigo temporario associado a um formulario atual, com TTL, snapshot bruto, snapshot normalizado e estado de consumo, mantido como apoio.
- **Foto de Equipamento**: arquivo privado vinculado ao equipamento, com indicacao de foto principal e ordem logica de preview.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Um usuario autorizado consegue concluir um cadastro manual de equipamento no desktop sem recorrer ao legado e sem acesso direto ao banco.
- **SC-002**: O formulario permanece utilizavel em falhas de camera, coletor ou sugestoes externas, sem impedir o cadastro manual do equipamento.
- **SC-003**: O quick-add de cliente, marca e modelo atualiza o formulario atual sem recarregar a pagina inteira.
- **SC-004**: O fluxo completo de equipamento com fotos e dados tecnicos pode ser validado no desktop e em viewport reduzido sem quebra visual ou overflow horizontal.
- **SC-005**: A foto principal fica visivel tanto na listagem quanto no detalhe sem acesso direto do browser ao storage privado do backend.
- **SC-006**: Um usuario autorizado consegue editar um equipamento existente no mesmo fluxo operacional de criacao, mantendo fotos e dados sincronizados sem regressao de RBAC.

## Assumptions

- O detalhe de equipamento ja existe no desktop e sera reutilizado como destino pos-criacao.
- O modal de novo cliente rapido usa apenas os campos minimos operacionais ja definidos na fase de clientes.
- O coletor local legado e o fluxo principal do formulario em ambiente Windows local; o coletor remoto entra apenas como apoio e nao cria equipamento sozinho nesta fase.
- O fluxo de abertura do cadastro de equipamento a partir de OS sera tratado em fase posterior.

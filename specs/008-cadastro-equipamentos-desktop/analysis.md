# Analysis - Cadastro Completo de Equipamentos no Desktop

## Consistência e segurança

- O backend central continua sendo a única fonte de verdade para catálogos, persistência, storage privado e integrações externas.
- O desktop continua sem acesso direto ao banco e sem expor token Bearer ao navegador.
- O coletor do cadastro de equipamentos foi realinhado para priorizar o fluxo local legado em `C:\JovemTechBenchCollector`, com execução automática apenas quando o ERP estiver na mesma máquina Windows.
- O cartão do coletor local agora é exibido somente para tipos das famílias `desktop` e `notebook`, reduzindo ruído visual em cadastros incompatíveis.
- O pareamento remoto continua existente, mas agora como apoio arquitetural e não como fluxo visual principal da tela.
- As fotos permanecem privadas e dependem de rota autenticada para leitura.
- O formulário mantém fallback manual para câmera, coletor e sugestões externas indisponíveis.
- Os dropdowns do desktop passaram a ser tratados como padrão Select2, mantendo a navegação mais consistente em formulários e modais.

## Pontos de verificação desta fase

- validar consistência entre `spec.md`, `plan.md` e `tasks.md`;
- validar limites de upload, permissões, leitura local do snapshot e consumo único do pareamento remoto de apoio;
- validar responsividade em desktop e mobile reduzido;
- validar documentação sincronizada com a implementação real.

## Resultado da revisão final

- Os artefatos da feature foram criados em `specs/008-cadastro-equipamentos-desktop/` e a governança mínima do Spec Kit ficou alinhada ao `sistema-erp`.
- O backend recebeu cobertura específica para `form-data`, quick-adds, criação completa com fotos privadas, sugestões externas com fallback seguro, leitura local do coletor e fluxo remoto de pareamento.
- O desktop recebeu cobertura para render da tela `/equipamentos/novo` e para o redirect de sucesso após submissão do cadastro.
- Durante a validação apareceram dois bugs reais no backend:
  - `foto_principal_index` estava indo indevidamente para o `insert` da tabela `equipamentos`;
  - `senha_tipo` e `senha_desenho` não entravam na normalização, impedindo persistência da senha por desenho.
- Ambos foram corrigidos em `backend/app/Services/EquipmentWorkflowService.php` e protegidos por teste automatizado.
- Durante a estabilização do frontend apareceu um bug silencioso no cadastro rápido de cliente dentro de `equipamentos/novo`: o botão do modal ficava visualmente correto, mas sem qualquer ação.
- A causa raiz foi de lifecycle do DOM, não de CSS: o layout do desktop renderizava `@stack('modals')` depois dos scripts, então `equipments-create.js` fazia o bind antes de os elementos do modal existirem.
- A correção foi centralizada no layout base `frontends/desktop/resources/views/layouts/app.blade.php`, movendo `@stack('modals')` para antes dos scripts e adicionando teste de regressão no `DesktopFrontendTest`.
- Em seguida apareceu um segundo bug real ao anexar fotos no cadastro: a API passava a responder `validation.required` para campos obrigatórios já preenchidos na tela.
- A causa raiz estava no cliente HTTP do desktop: `authenticatedMultipartRequest()` enviava corpo multipart com header `application/json` por herdar `->asJson()` de `baseRequest()`.
- A correção foi separar uma base dedicada para multipart em `frontends/desktop/app/Services/ApiClient.php` e cobrir o caso com teste automatizado de submissão com foto.
- O fluxo mantém as fronteiras arquiteturais definidas:
  - browser fala apenas com o desktop;
  - desktop fala com a API central;
  - fotos seguem privadas no backend;
- o coletor local fala com a bancada por snapshot em `C:\JovemTechBenchCollector`;
- o coletor remoto fala com a API via token dedicado e código temporário quando esse modo for necessário.

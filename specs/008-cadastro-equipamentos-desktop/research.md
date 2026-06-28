# Research - Cadastro Completo de Equipamentos no Desktop

## Decisão: Coletor local legado como fluxo principal

**Rationale**: a tela visível do `sistema-hml` já foi validada operacionalmente na bancada usando `C:\JovemTechBenchCollector`, então o desktop do ERP novo precisa preservar esse comportamento como primeira opção.

**Alternatives considered**:
- pareamento remoto como único fluxo: rejeitado porque quebra a paridade operacional imediata com o legado;
- upload manual de arquivo JSON: rejeitado por piorar o fluxo operacional.

## Decisão: Pareamento remoto preservado como apoio

**Rationale**: mantém uma trilha evolutiva multicanal para cenários futuros sem atrapalhar o uso principal local em Windows.

## Decisão: Sugestões externas centralizadas no backend

**Rationale**: evita expor chamadas externas no browser, permite cache, timeout, rate limit e fallback controlado.

**Alternatives considered**:
- buscar no browser: rejeitada por segurança, CORS e inconsistência entre canais;
- remover sugestões externas: rejeitada porque o legado já usa esse acelerador operacional.

## Decisão: Fotos com preview local, câmera e crop antes do envio

**Rationale**: preserva a UX do legado e reduz necessidade de retrabalho após upload.

**Alternatives considered**:
- upload bruto sem crop: rejeitada por perda de paridade operacional;
- edição de imagem no backend: rejeitada por piorar a experiência e aumentar processamento desnecessário.

## Decisão: Quick-adds same-origin no desktop

**Rationale**: mantém o browser sem acessar a API central diretamente, preservando o padrão arquitetural do desktop.

**Alternatives considered**:
- chamadas diretas do browser ao backend central: rejeitadas por quebrar a fronteira de segurança do desktop.

## Decisão: Select2 como padrão dos dropdowns do desktop

**Rationale**: normaliza a experiência visual e operacional dos selects em todo o `frontends/desktop`, evitando variações de widget entre telas e mantendo a busca em listas longas mais usável.

**Alternatives considered**:
- selects nativos em telas novas: rejeitados porque criariam inconsistência visual e operacional no desktop.

## Decisão: Coletor local visível apenas para tipos compatíveis

**Rationale**: o cartão de coleta só faz sentido para equipamentos da família `desktop` ou `notebook`; esconder o bloco em outros tipos reduz ruído visual e evita ação incompatível no fluxo.

**Alternatives considered**:
- manter o cartão sempre visível: rejeitado porque expõe uma ação sem contexto em tipos incompatíveis.

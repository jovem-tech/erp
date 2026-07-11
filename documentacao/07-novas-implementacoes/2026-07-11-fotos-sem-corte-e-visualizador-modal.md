# Fotos sem corte e visualizador modal no desktop

Data: 2026-07-11

## Objetivo

Eliminar o corte agressivo das fotos de equipamentos e OS nas telas operacionais do desktop, preservando a imagem completa nas miniaturas e oferecendo visualização ampliada com navegação entre fotos quando existir mais de uma imagem no mesmo contexto.

## Problema atacado

- A UI usava `object-fit: cover` em pontos críticos do desktop, o que ocultava partes importantes do equipamento.
- Em fluxos como detalhe da OS, baixa/adiantamento e detalhe do equipamento, o usuário não conseguia inspecionar a foto com precisão.
- O preview do cadastro/edição de equipamento mostrava a imagem local, mas sem um visualizador ampliado reutilizável.

## Implementação

### 1. Miniaturas passam a preservar a imagem inteira

Os principais pontos visuais passaram de `cover` para `contain`, com fundo neutro:

- detalhe da OS;
- grid de fotos da OS;
- listagens com miniatura de equipamento/OS;
- detalhe do equipamento;
- cards de foto do cadastro/edição de equipamento;
- contexto visual da baixa da OS;
- previews de foto no wizard/criação.

Isso reduz distorção operacional e evita esconder laterais, topo ou base do equipamento.

### 2. Modal único de visualização de fotos

Foi criado um modal compartilhado (`layouts/partials/photo-viewer-modal.blade.php`) para ampliar imagens:

- abre ao clicar na foto;
- suporta abrir o arquivo original em nova aba;
- possui contador de imagens;
- possui navegação anterior/próxima quando a foto pertence a um grupo com múltiplas imagens;
- mantém fallback seguro: sem JavaScript, o link continua abrindo a imagem normalmente.

### 3. Viewer conectado aos contextos mais usados

O visualizador foi conectado a:

- `orders/show.blade.php`
  - foto principal do equipamento;
  - aba `Fotos` da OS;
- `orders/closure.blade.php`
  - foto do equipamento no fluxo de baixa/adiantamento;
- `equipments/show.blade.php`
  - foto principal do equipamento, agrupando também as demais fotos do ativo;
- `equipments/create.blade.php` + `public/assets/js/equipments-create.js`
  - previews locais de fotos no cadastro/edição.

## Decisões técnicas

- O modal foi centralizado em `desktop.js` para evitar duplicação de lógica entre OS e equipamentos.
- A navegação por conjunto usa atributos `data-photo-viewer-group`, o que simplifica reaproveitamento em outras telas.
- O fallback sem JS foi preservado com `href` real nas âncoras, sem quebrar acessibilidade nem inspeção direta da imagem.

## Impacto funcional

- O usuário passa a ver a foto inteira nas miniaturas.
- O usuário consegue ampliar e navegar entre fotos relacionadas sem sair do fluxo atual.
- O desktop ganha um padrão reutilizável de visualização de imagens para futuras telas.

## Arquivos centrais

- `frontends/desktop/public/assets/css/desktop.css`
- `frontends/desktop/public/assets/js/desktop.js`
- `frontends/desktop/public/assets/js/equipments-create.js`
- `frontends/desktop/resources/views/orders/show.blade.php`
- `frontends/desktop/resources/views/orders/closure.blade.php`
- `frontends/desktop/resources/views/equipments/show.blade.php`
- `frontends/desktop/resources/views/equipments/create.blade.php`
- `frontends/desktop/resources/views/layouts/partials/photo-viewer-modal.blade.php`

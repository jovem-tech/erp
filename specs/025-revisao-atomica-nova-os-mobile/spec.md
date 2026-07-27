# Especificação: revisão e salvamento atômico da Nova OS mobile

**Branch de feature:** `codex/mobile-navigation`  
**Status:** concluída

## Objetivo

Transformar a abertura da OS no PWA em um rascunho inteiramente local, com
edição contextual dos cadastros selecionados, prazo automático, decisão
explícita sobre PDF e confirmação item a item antes do único salvamento.

## Requisitos funcionais

- **FR-001** — Cliente selecionado deve exibir `Editar` ao lado de `Trocar`.
- **FR-002** — Equipamento selecionado deve exibir `Editar` ao lado de `Trocar`.
- **FR-003** — Alterações de cliente/equipamento devem permanecer locais até o
  POST final da OS.
- **FR-004** — O checklist deve oferecer `Marcar tudo OK` e `Desmarcar tudo`.
- **FR-005** — Atendimento deve exigir prazo de 1, 3, 7, 15 ou 30 dias corridos
  e preencher automaticamente a previsão de entrega.
- **FR-006** — A etapa `Extras` não deve existir.
- **FR-007** — A decisão de gerar/enviar PDF deve ser um checkbox dentro do card
  `Extras` da revisão; desmarcado significa não gerar, persistir nem enviar o
  PDF.
- **FR-008** — A revisão deve conter cliente, equipamento, checklist quando
  aplicável, relato, atendimento, fotos e extras.
- **FR-009** — Cada card da revisão deve ter `Editar` e `Verificar`; verificado
  deve ter fundo verde.
- **FR-010** — `Salvar` só deve ser habilitado quando todos os campos
  obrigatórios e todos os cards da revisão estiverem confirmados.
- **FR-011** — Catálogos, cadastros, fotos, checklist e OS não podem sofrer
  escrita no backend antes do salvamento final.

## Segurança e integridade

- Edição de cliente exige `clientes:editar`.
- Edição de equipamento exige `equipamentos:editar`.
- O backend valida novamente equipamento × cliente para impedir IDOR.
- Atualizações e criação da OS compartilham transação e locks de linha.
- O prazo é recalculado no backend quando `prazo_entrega_dias` é informado.
- A criação continua idempotente.

## Critérios de aceite

- Nenhum endpoint mutável é chamado enquanto o operador apenas navega/edita.
- O POST final contém as alterações locais e cria uma única OS.
- Falha transacional não deixa cliente/equipamento parcialmente alterado.
- PDF desmarcado retorna `opening_document: null` e não cria `os_documentos`.
- Todos os cards confirmados ficam verdes e liberam `Salvar`.

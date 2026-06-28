---
name: sistema-erp-entrega-especificada
description: Fluxo oficial de desenvolvimento orientado a especificacoes do Sistema ERP. Use quando um agente de IA for abrir, implementar, revisar ou concluir features que precisam seguir a sequencia spec, plan, tasks, implementacao, validacao, documentacao e versionamento, com definicao de pronto clara e rastreabilidade em `specs/`.
---

# Sistema ERP Entrega Especificada

## Quick start

1. Ler `references/fluxo-de-entrega.md`.
2. Ler `references/criterios-de-pronto.md`.
3. Localizar a spec ativa em `specs/`.
4. Confirmar os documentos e testes que precisarao ser atualizados antes de editar o codigo.

## Como usar

- Use esta skill para planejar e executar features novas, migracoes, refactors estruturais e correcoes com impacto arquitetural.
- Se a tarefa tocar seguranca, ambiente, contratos ou mais de um frontend, usar junto com `$sistema-erp-governanca`.
- Se a tarefa exigir sincronizacao do contexto documental ou nota versionada, usar junto com `$sistema-erp-documentacao-viva`.

## Regra operacional

Nao tratar documentacao, spec e versionamento como pos-processo. Eles fazem parte da implementacao.

## Saida esperada

- mudanca entregue com rastreabilidade em `specs/`;
- validacao executada;
- documentacao atualizada;
- contexto vivo sincronizado quando aplicavel.

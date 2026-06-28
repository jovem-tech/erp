---
name: sistema-erp-governanca
description: Arquitetura, escopo, limites de seguranca e ambiente oficial do Sistema ERP modular. Use quando um agente de IA precisar entender a estrutura do repositorio, as fronteiras entre backend central, frontends e BFF legado, as obrigacoes de documentacao, ou antes de executar mudancas transversais neste projeto com producao alvo em Ubuntu VPS.
---

# Sistema ERP Governanca

## Quick start

1. Ler `AGENTS.md`.
2. Ler `references/arquitetura-e-escopo.md`.
3. Ler `references/ambientes-e-seguranca.md`.
4. Se a mudanca for ampla, ler `documentacao/04-governanca-ai/manifesto-do-sistema.md`.

## Regras mestras

1. Tratar `backend/` como fonte unica de verdade para negocio, autenticacao, autorizacao, storage privado e contratos.
2. Tratar `Ubuntu VPS` como ambiente oficial de producao; `Windows/XAMPP` existe apenas para desenvolvimento local.
3. Atualizar a documentacao afetada sempre que codigo, contrato, deploy ou comportamento operacional mudar.

## Workflow de decisao

- Se a tarefa muda arquitetura, segurança, ambiente, contratos ou integra mais de um modulo, usar esta skill primeiro.
- Se a tarefa for uma feature nova ou mudanca estrutural, combinar com `$sistema-erp-entrega-especificada`.
- Se a tarefa mexer em manifests, AGENTS ou notas versionadas, combinar com `$sistema-erp-documentacao-viva`.

## O que validar antes de concluir

- impacto em Linux/Ubuntu, case-sensitive e paths;
- impacto em storage privado, autenticacao e superficies publicas;
- impacto em `documentacao/`, `backend/openapi.yaml` e `specs/`.

---
name: sistema-erp-governanca
description: Arquitetura, escopo, limites de seguranca e ambiente oficial do Sistema ERP modular. Use quando um agente de IA precisar entender a estrutura do repositorio, as fronteiras entre backend central, frontends e BFF legado, as obrigacoes de documentacao, ou antes de executar mudancas transversais neste projeto com producao alvo em Ubuntu VPS.
---

# Sistema ERP Governanca

## Quick start

1. Ler `AGENTS.md` **por inteiro**, comecando pela secao "LEIA ISTO PRIMEIRO" (mandato
   valido para qualquer IA usada neste repositorio, nao so Claude).
2. Ler `documentacao/10-deploy/workflow-git-multiambiente.md` — em qual branch
   trabalhar (`develop`) e como uma mudanca chega a producao (`main`, VPS).
3. Ler `references/arquitetura-e-escopo.md`.
4. Ler `references/ambientes-e-seguranca.md`.
5. Se a mudanca for ampla, ler `documentacao/04-governanca-ai/manifesto-do-sistema.md`.

## Regras mestras

1. Tratar `backend/` como fonte unica de verdade para negocio, autenticacao, autorizacao, storage privado e contratos.
2. Tratar a VPS Contabo (`161.97.93.120`, branch `main`) como ambiente oficial de
   producao; `192.168.1.100` (branch `develop`) como unico ambiente de desenvolvimento.
   **`Windows/XAMPP` esta descontinuado — nunca desenvolver la.**
3. O repositorio `https://github.com/jovem-tech/erp` e' a fonte unica da verdade do
   codigo; qualquer alteracao nasce em `develop` e so chega a `main` por promocao
   deliberada (ver workflow-git-multiambiente.md).
4. Atualizar a documentacao afetada sempre que codigo, contrato, deploy ou comportamento operacional mudar.

## Workflow de decisao

- Se a tarefa muda arquitetura, segurança, ambiente, contratos ou integra mais de um modulo, usar esta skill primeiro.
- Se a tarefa for uma feature nova ou mudanca estrutural, combinar com `$sistema-erp-entrega-especificada`.
- Se a tarefa mexer em manifests, AGENTS ou notas versionadas, combinar com `$sistema-erp-documentacao-viva`.

## O que validar antes de concluir

- impacto em Linux/Ubuntu, case-sensitive e paths;
- impacto em storage privado, autenticacao e superficies publicas;
- impacto em `documentacao/`, `backend/openapi.yaml` e `specs/`.

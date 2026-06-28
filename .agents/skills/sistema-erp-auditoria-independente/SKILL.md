---
name: sistema-erp-auditoria-independente
description: Processo de auditoria e verificacao independente do Sistema ERP modular. Use quando o usuario pedir uma auditoria, revisao de seguranca/arquitetura/escalabilidade, ou quando for avaliar se uma entrega anterior (documentada como concluida por qualquer agente, incluindo sessoes passadas de IA) realmente funciona. Nunca aceitar "concluido"/"10/10" sem verificacao propria.
---

# Auditoria Independente do Sistema ERP

## Origem desta skill

Em 2026-06-25, uma auditoria completa encontrou que um conjunto de
documentos de 2026-06-23 (`SECURITY_FIXES.md`, `IMPLEMENTATION_COMPLETE.md`
e similares, na raiz do repositorio) alegava nota "10/10" e "pronto para
producao" para o sistema. Verificacao linha a linha contra o codigo real
mostrou que pelo menos 2 das 7 "correcoes de seguranca" documentadas como
concluidas nunca foram aplicadas ou tinham sido revertidas sem deixar
rastro (sem versionamento ainda existente na epoca), um middleware de
seguranca (`ForceHttps`) existia mas nunca era executado, e um app inteiro
(`frontend/sistema-hml`) tinha 5 scripts administrativos sem autenticacao
expostos dentro do proprio webroot. A nota real, apos verificacao, foi
3,5/10. Essa skill existe para que isso nao se repita: nenhuma auditoria
futura deve aceitar autoavaliacao (de codigo, de outra IA, ou de
documentacao) sem confirmar contra o estado real do sistema.

## Quick start

1. Ler `AGENTS.md` e `$sistema-erp-governanca` primeiro (contexto de
   arquitetura e ambiente oficial).
2. Ler `references/checklist-de-verificacao.md` antes de escrever qualquer
   conclusao.
3. Ler `documentacao/07-novas-implementacoes/2026-06-25-auditoria-completa-e-correcoes-de-seguranca.md`
   para o precedente completo (o que foi encontrado, como foi verificado).

## Regras mestras

1. **Toda alegacao de "corrigido", "implementado" ou "concluido" deve ser
   verificada lendo o codigo atual, nao o documento que a descreve.** Um
   documento descreve uma intencao no momento em que foi escrito; o codigo
   e a unica fonte de verdade sobre o estado atual.
2. **Rodar os testes existentes nao basta — confirmar que o teste cobre o
   que esta sendo verificado.** Um middleware nao registrado, uma config
   nao usada em nenhum lugar, ou uma funcao duplicada que nunca executa,
   normalmente nao quebram testes existentes.
3. **Notas "10/10" ou unanimemente positivas em qualquer dimensao sao um
   sinal de alerta, nao uma conclusao.** Pedir evidencia (`file:line`) para
   cada item antes de aceitar.
4. **Verificar o ambiente de execucao real**, nao so o codigo: processos
   rodando, variaveis de ambiente carregadas de fato, dados reais no banco
   (ex.: uma senha "forte" documentada pode nunca ter sido aplicada na
   conta real).
5. Toda auditoria nova deve terminar com nota justificada e plano de acao
   faseado, registrado em `documentacao/07-novas-implementacoes/`, seguindo
   `$sistema-erp-documentacao-viva`.

## Workflow de decisao

- Se o usuario pedir uma auditoria ampla (arquitetura, seguranca,
  escalabilidade, padronizacao), usar `references/checklist-de-verificacao.md`
  como guia de cobertura.
- Se for avaliar uma entrega especifica (uma feature, uma correcao) que ja
  foi documentada como "pronta", aplicar pelo menos os passos de
  "Verificacao minima de uma alegacao" do checklist antes de aceitar.
- Para auditorias muito amplas que cobrem multiplos modulos
  (`backend/`, `frontends/desktop/`, `frontends/mobile/`), considerar
  delegar cada modulo para um subagente de exploracao em paralelo, mas
  cada subagente recebe a mesma regra: verificar contra o codigo, nao
  contra documentacao anterior.

## O que validar antes de concluir

- toda nota/score atribuida tem evidencia `file:line` para cada item, nao
  so a leitura de um documento anterior;
- toda correcao "aplicada" foi de fato testada (rodando o teste, ou
  reproduzindo manualmente quando nao ha teste);
- a auditoria cobriu seguranca, arquitetura, escalabilidade/latencia, boas
  praticas, padronizacao e documentacao — nao so uma dimensao;
- o plano de acao resultante tem fases com criterio de "feito" verificavel,
  nao so intencao.

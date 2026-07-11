# Protocolo de Versionamento — Sistema ERP Jovem Tech

## ⚠️ LEIA ISTO ANTES DE GERAR, ALTERAR OU CORRIGIR QUALQUER CÓDIGO

Este documento é de leitura obrigatória para qualquer agente de IA (Claude,
Codex, ou outro) antes de iniciar trabalho neste repositório. Define como
classificar e registrar toda alteração de código, feita por IA ou manualmente.

---

## 1. Formato de versão

```
MAJOR.MINOR.PATCH.HOTFIX
```

Exemplo: `3.5.3.1`

| Posição | Nome   | Significado                                              | Ao incrementar, zera |
|---------|--------|-----------------------------------------------------------|----------------------|
| 1       | MAJOR  | Mudança estrutural/arquitetural grande                     | MINOR, PATCH, HOTFIX |
| 2       | MINOR  | Nova funcionalidade/módulo, não estrutural                 | PATCH, HOTFIX        |
| 3       | PATCH  | Correção de bug ou falha de implementação                  | HOTFIX               |
| 4       | HOTFIX | Ajuste pontual pequeno                                     | —                    |

Versão atual: ver arquivo `VERSION` na raiz do projeto (fonte única da verdade).

---

## 2. Critérios objetivos de classificação

Aplicar em ordem — pare no primeiro critério que bater.

### MAJOR (qualquer um destes)
- Migration com `DROP TABLE`, `DROP COLUMN`, ou alteração de FK/índice já existente.
- Mudança em contrato de rota já publicada (`/api/v1/...`) que quebra compatibilidade com consumidores atuais (desktop, PWA, site).
- Novo frontend inteiro, novo mecanismo de auth, ou migração de framework/stack (ex.: CI4 → Laravel).
- Alteração que toca módulos/domínios diferentes ao mesmo tempo (ex.: mais de 5 arquivos em áreas não relacionadas) na mesma entrega.

### MINOR (se nenhum critério MAJOR se aplicar)
- Migration aditiva (`CREATE TABLE`, `ADD COLUMN` nullable/com default), sem quebrar nada existente.
- Novo Controller, Service, Model, Job ou rota completamente nova (não é alteração de rota existente).
- Novo módulo dentro de `tenant_configs` ou novo segmento de negócio suportado.

### PATCH (se nenhum critério acima se aplicar)
- Alteração de lógica dentro de arquivo(s) já existente(s), sem criar arquivo novo.
- Correção de bug relatado, identificado em teste, ou encontrado em revisão.

### HOTFIX (todos estes precisam ser verdadeiros)
- Diff pequeno (heurística: menos de ~15 linhas alteradas no total).
- Nenhuma migration envolvida.
- Nenhum arquivo novo criado.
- Ajuste pontual: typo, texto de UI, validação simples, ajuste de CSS, etc.

**Regra de desempate:** se a alteração parecer estar entre dois níveis, classifique sempre no nível mais alto (mais conservador). É melhor superestimar o impacto do que subestimar.

---

## 3. Fluxo obrigatório para o agente de IA

1. **Antes de codar:** ler `VERSION` e as últimas entradas de `CHANGELOG.md` para saber onde o projeto está.
2. Fazer a alteração solicitada normalmente.
3. **Classificar a própria alteração** usando a seção 2 acima.
4. Rodar:
   ```bash
   ./scripts/bump-version.sh --tier=<major|minor|patch|hotfix> --desc="descrição curta da alteração"
   ```
   Se não houver acesso a shell no momento, atualizar manualmente `VERSION` e adicionar uma entrada no topo de `CHANGELOG.md` seguindo o mesmo formato.
   Se usar `./scripts/versionar.sh`, a sincronização de
   `documentacao/04-governanca-ai/manifesto-do-sistema.md` e
   `documentacao/04-governanca-ai/contexto-sistema.json` passa a acontecer no
   mesmo fluxo, desde que `scripts/bash/sync-agent-docs.sh` exista no projeto.
5. **Nunca pular as etapas 3 e 4** — toda alteração de código gera uma entrada de versão, mesmo que seja HOTFIX.
6. Sempre informar ao Otávio, ao final da tarefa, qual foi a nova versão e por quê.

---

## 4. Auditoria independente (detecção de alterações não registradas)

`scripts/classify-change.sh` analisa o diff real de um commit (ou do stage atual)
usando os mesmos critérios objetivos da seção 2, de forma independente da
autoclassificação do agente. Serve para:

- Detectar alterações feitas manualmente (fora do fluxo de IA) e que não passaram pelo protocolo.
- Conferir se a classificação que o agente deu bate com a análise objetiva do diff.
- Servir de segunda opinião quando a classificação do agente parecer inconsistente.

Uso:
```bash
./scripts/classify-change.sh --staged        # analisa o que está no stage
./scripts/classify-change.sh <commit-hash>    # analisa um commit específico
```

O script não decide sozinho — ele **sugere**. A decisão final e o registro em `CHANGELOG.md` continuam sendo responsabilidade do agente/dev no momento do commit.

---

## 5. Versionar sem IA (uso direto pelo usuário)

`scripts/versionar.sh` (atualizado em 2026-07-11) é a forma recomendada de rodar este fluxo **sem
depender de nenhum agente de IA**: reaproveita a mesma heurística de
`scripts/classify-change.sh` para sugerir o tier, pergunta a descrição, monta a lista de
arquivos sozinho, chama `scripts/bump-version.sh` por baixo e, quando disponível,
sincroniza também os artefatos gerados de governança para agentes — mesmo
resultado da seção 2, com um menu interativo em vez de precisar montar os flags
na mão.

```bash
./scripts/versionar.sh
```

Também aceita os mesmos flags de `bump-version.sh` para uso direto/automatizado:
`./scripts/versionar.sh --tier=minor --desc="descrição" [--files="a,b"]`.

Quando `--files` não for informado, o próprio `versionar.sh` detecta os arquivos
alterados e preenche automaticamente a linha `**Arquivos:**` do `CHANGELOG.md`
tanto no modo interativo quanto no modo direto por flags.

Ele grava `VERSION`/`CHANGELOG.md`/`shared/version.php` e, quando existir o
script de sincronização, também atualiza `manifesto-do-sistema.md` e
`contexto-sistema.json` — não dá `commit`/`push`. Publicar no GitHub (e promover `develop` → `main`) é o trabalho de
`scripts/bash/deploy-completo.sh` (ver `documentacao/10-deploy/workflow-git-multiambiente.md`),
que inclusive lê a última entrada do `CHANGELOG.md` para montar a mensagem do commit —
por isso a ordem recomendada é sempre `versionar.sh` primeiro, `deploy-completo.sh` depois.

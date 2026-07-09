# Manual de Publicação — Versionar e Deploy

Passo a passo para versionar uma mudança, publicá-la no GitHub e atualizar a VPS de
produção — sem depender de IA para nenhum desses passos. Todo comando abaixo é rodado
dentro de `/var/www/sistema-erp`, no servidor certo indicado em cada parte.

Ver também `workflow-git-multiambiente.md` (fluxo completo de branches) e
`VERSIONING.md` (critérios de classificação da versão), nesta mesma documentação.

## Visão geral: os 3 passos

| Passo | O que faz | Onde |
|---|---|---|
| 1. Versionar | Registra a mudança em `VERSION` e `CHANGELOG.md` | dev · `192.168.1.100` |
| 2. Publicar | Commita, envia `develop` ao GitHub e promove para `main` | dev · `192.168.1.100` |
| 3. Atualizar a VPS | Baixa o `main`, faz backup, migra o banco e reinicia os serviços | VPS · `161.97.93.120` |

Só o passo 3 muda a produção de fato. Os passos 1 e 2 rodam no ambiente de dev.

## Parte 1 — Versionar a mudança

**Onde:** servidor de dev — `ssh administrador@192.168.1.100`

```bash
cd /var/www/sistema-erp
./scripts/versionar.sh
```

1. O script olha o que mudou e sugere um nível — **major**, **minor**, **patch** ou
   **hotfix** — com o motivo. Aperte Enter para aceitar a sugestão ou digite o número
   do nível que preferir (1 a 4).
2. Digite uma descrição curta da entrega — ela vai para o `CHANGELOG.md` e, depois,
   vira a mensagem do commit na Parte 2.
3. Confira a descrição e a lista de arquivos detectados e digite `s` para confirmar.

Este script **não** commita nem publica nada — só grava `VERSION` e `CHANGELOG.md`. O
commit e o envio para o GitHub são feitos na Parte 2.

## Parte 2 — Publicar (commit + GitHub + promoção para main)

**Onde:** servidor de dev — `ssh administrador@192.168.1.100`

```bash
cd /var/www/sistema-erp
./scripts/bash/deploy-completo.sh
```

1. O script sincroniza `develop`, mostra o resumo do que vai ser commitado
   (`git status`/`git diff --stat`) e a mensagem do commit, tirada da última entrada
   do `CHANGELOG.md`.
2. Confira se a lista de arquivos é mesmo a mudança que você quer publicar. Se
   aparecer aviso de arquivo sensível (`.env`, chave, credencial), **não confirme** —
   veja "Se algo der errado" abaixo.
3. Digite `s` para confirmar. Qualquer outra tecla cancela sem alterar nada.
4. O script publica `develop`, promove para `main`, publica `main` e volta sozinho
   para `develop` no final, terminando com:

   ```
   DEPLOY_COMPLETO_OK (main em a1b2c3d, v3.17.2.0)
   Agora rode ./scripts/bash/deploy-producao.sh na VPS para publicar em produção.
   ```

## Parte 3 — Atualizar a VPS de produção

**Onde:** VPS de produção — `ssh root@161.97.93.120`

```bash
cd /var/www/sistema-erp
./scripts/bash/deploy-producao.sh
```

Sem perguntas no meio — o script faz, nesta ordem:

- Backup do banco (`/var/backups/sistema-erp/`)
- Atualização do código (`git pull` de `main`, sempre fast-forward)
- Instalação de dependências e migrações do banco
- Rebuild de cache e reinício do PHP-FPM/Supervisor

Termina com `DEPLOY_OK (a1b2c3d)`. Abra o sistema no navegador e confira se a tela que
motivou a mudança está do jeito esperado.

## Se algo der errado

**"Permissão negada" ao rodar um script** — rode `chmod +x nome-do-script.sh` uma vez e
tente de novo.

**Conflito ao promover develop para main** — o script para sozinho e mostra as duas
opções: `git merge --abort` para desistir agora (nada é alterado), ou resolver o
conflito manualmente e rodar `git commit` para concluir. Na dúvida, prefira desistir e
pedir ajuda antes de continuar.

**Aviso de arquivo sensível (.env, chave, credencial)** — o script para de propósito e
não commita nada. Revise à mão com `git status` e adicione só os arquivos certos com
`git add caminho/do/arquivo` antes de repetir o processo.

**"Nada pendente para commitar"** — normal, significa que já não havia nenhuma mudança
local. O script segue direto para publicar/promover o que já estava commitado.

**Aviso de que VERSION não bate com o CHANGELOG** — sinal de que a Parte 1
(`versionar.sh`) foi esquecida antes da Parte 2. Pode continuar, mas o ideal é
cancelar, rodar `versionar.sh` e repetir a Parte 2 depois.

## Cartão-resumo

```bash
# 1. Versionar — no dev, só quando a mudança merecer entrada no CHANGELOG
./scripts/versionar.sh

# 2. Publicar — no dev, commita, publica develop, promove e publica main
./scripts/bash/deploy-completo.sh

# 3. Atualizar a VPS — na VPS, o único que de fato muda a produção
./scripts/bash/deploy-producao.sh
```

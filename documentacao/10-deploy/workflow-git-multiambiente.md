# Fluxo Git Multiambiente — Sistema ERP

**Repositorio oficial:** `https://github.com/jovem-tech/erp` (privado).
**Vigente desde:** 2026-07-05. Adaptado do fluxo de producao usado historicamente pela
Jovem Tech em outro sistema (3 estagios: desktop → develop → homolog-vm → main → VPS),
condensado para **2 estagios** enquanto nao houver uma VM de homologacao dedicada.

## Quadro geral

| Ambiente / branch | Papel | O que faz | O que nao faz |
|---|---|---|---|
| `192.168.1.100` (branch `develop`) | Desenvolvimento | cria, corrige, documenta, testa com dados reais | nao publica direto na VPS |
| `develop` | branch de desenvolvimento | recebe os commits do dia a dia | nao vira producao direto |
| `main` | branch de producao | recebe apenas o que foi validado no dev | nao recebe trabalho cru |
| `161.97.93.120` (VPS, branch `main`) | Producao | executa a `main` em ambiente real, ao lado do legado | nao deve ser usada para desenvolver |

> **Nota:** quando houver uma VM de homologacao dedicada, reintroduzir o estagio
> `homolog-vm` entre `develop` e `main`, exatamente como no fluxo original (ver
> `git log` do repositorio para o historico desta decisao).

## Sequencia oficial

| Etapa | Origem | Destino | Acao |
|---|---|---|---|
| 1 | dev (`192.168.1.100`) | `develop` | desenvolver, commitar e dar push |
| 2 | `develop` (validado no dev) | `main` | promover o que foi testado |
| 3 | `main` | VPS | atualizar a producao |
| 4 | VPS | backup | fazer backup **antes** do update (sempre) |

## Mapa mental rapido

| Item | Regra |
|---|---|
| Onde eu programo? | `192.168.1.100` (unico ambiente de dev oficial) |
| Para onde vai o desenvolvimento? | branch `develop` |
| Quem vira producao? | branch `main` |
| Quem roda em cliente real? | VPS `161.97.93.120` |
| Quando faz backup da VPS? | **sempre antes** de atualizar |

## Comandos por ambiente

### 1. Desenvolvimento (`192.168.1.100`, branch `develop`)

```bash
ssh administrador@192.168.1.100
cd /var/www/sistema-erp
git checkout develop
git pull --ff-only origin develop
# ... editar, testar ...
git add .
git commit -m "mensagem"
git push origin develop
```

### 2. Promover develop → main (produção)

Revisão feita **antes** de mesclar (idealmente via Pull Request no GitHub, comparando
`develop` com `main`):

```bash
git checkout main
git pull --ff-only origin main
git merge --ff-only develop   # ou merge de PR pela interface do GitHub
git push origin main
```

Se `develop` e `main` divergiram (main recebeu hotfix direto), resolver o conflito
localmente ou via PR antes de forçar o merge — nunca com `git push --force` em `main`.

### 3. Atualizar a VPS (produção)

Executado **na própria VPS**, nunca da máquina do desenvolvedor:

```bash
ssh root@161.97.93.120
cd /var/www/sistema-erp
./scripts/bash/deploy-producao.sh
```

O script (`documentacao/10-deploy/deploy-producao-contabo-vps.md` descreve o runbook
completo) faz, nesta ordem: backup do banco → `git fetch` + `checkout main` +
`pull --ff-only` → `composer install` + `migrate` + rebuild de caches (backend e
desktop) → reload do PHP-FPM e restart do Supervisor.

### 4. Atualizar o ambiente de dev a qualquer momento

```bash
ssh administrador@192.168.1.100
cd /var/www/sistema-erp
./scripts/bash/atualizar-dev.sh
```

## Autenticação (Deploy Keys)

Cada servidor tem sua própria chave SSH dedicada ao repositório (não uma chave pessoal
nem um token compartilhado):

- **Dev (`192.168.1.100`):** `~/.ssh/id_ed25519_github`, cadastrada no GitHub com
  **write access** (pode dar push em `develop` e, quando aprovado, em `main`).
- **VPS (`161.97.93.120`):** `~/.ssh/id_ed25519_github_erp`, cadastrada **sem** write
  access (só `git pull`/`fetch` — produção nunca dá push).

Isso limita o raio de dano: se a VPS for comprometida, o invasor não consegue
escrever no repositório; se o dev for comprometido, o `main` (produção) ainda exige
uma promoção deliberada.

## Regra crítica de branch protection (configurar manualmente no GitHub)

Recomendado ativar em **Settings → Branches → Branch protection rules** para `main`:
exigir Pull Request com revisão antes do merge, e impedir push direto (exceto pela
promoção deliberada de `develop`). Isso não foi configurado automaticamente — requer
acesso à interface web do GitHub.

## Frase para memorizar

> Eu desenvolvo em `192.168.1.100`, envio para `develop`, promovo para `main` quando
> validado, e só então atualizo a VPS — sempre com backup antes.

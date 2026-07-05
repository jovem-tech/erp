# Deploy de producao em LAN, documentacao integrada ao desktop e protocolo de versionamento

**Data:** 2026-07-04
**Versao:** 3.6.0 (protocolo novo: 3.6.0.0)
**Modulo:** infraestrutura + `frontends/desktop` + `backend` + governanca

## Contexto

O sistema foi instalado pela primeira vez em um servidor de producao real
(Ubuntu Server 26.04 em LAN, `192.168.1.100`), executado de ponta a ponta por
agente de IA com validacao do administrador. O processo revelou problemas reais
de infraestrutura e dois bugs de codigo, e gerou tres necessidades:
documentar o deploy de forma repetivel, dar ao administrador acesso a
documentacao dentro do proprio sistema e formalizar o protocolo de versionamento.

## Entrega

### 1. Deploy de producao (backend + desktop no ar)

- backend Laravel publicado em `https://192.168.1.100` (Nginx + PHP-FPM 8.5 + TLS autoassinado);
- frontend desktop publicado em `https://192.168.1.100:8443`;
- banco `sistema_hml` migrado de MariaDB 10.4 (XAMPP) para MySQL 8.4 com correcao
  de colunas geradas; 5 usuarios, 3.598 OS e 1.303 clientes importados;
- Redis com senha, Supervisor (2 queue workers + Reverb) e cron do scheduler ativos;
- storage privado e uploads legados copiados; `LEGACY_PUBLIC_PATH` configurado.

### 2. Correcao de bug real em producao

- `App\Http\Middleware\ForceHttps` quebrava respostas `BinaryFileResponse`
  (logo da empresa, fotos de equipamentos) com HTTP 500 por chamar
  `$response->header()`; corrigido para `$response->headers->set()`.

### 3. Documentacao

- novo runbook completo `documentacao/10-deploy/deploy-producao-lan-ubuntu.md`
  com todos os passos, 11 problemas reais mapeados e checklist pos-deploy;
- `documentacao/02-infraestrutura-ambientes/linux-vps.md` atualizado com a
  realidade de versoes (PHP 8.5, MySQL 8.4) e variaveis obrigatorias de producao;
- indices (`documentacao/README.md`, `documentacao/10-deploy/README.md`) atualizados.

### 4. Aba Documentacao no desktop

- nova aba `Documentacao` em `Configuracoes > Sistema` (rota
  `/configuracoes/sistema?tab=documentacao`), protegida pela permissao
  `configuracoes.visualizar`;
- navegacao por categorias da pasta `documentacao/` com renderizacao de Markdown
  no proprio painel (CommonMark com `html_input=escape` e links inseguros bloqueados);
- leitura restrita por caminho canonicalizado dentro de `documentacao/` e apenas
  arquivos `.md` (sem traversal, sem dotfiles).

### 5. Protocolo de versionamento (novo)

- adotado o protocolo de 4 posicoes `MAJOR.MINOR.PATCH.HOTFIX` definido em
  `VERSIONING.md` (raiz do repo);
- `VERSION` na raiz e a fonte unica da verdade; `CHANGELOG.md` registra toda alteracao;
- `scripts/bump-version.sh` incrementa a versao e sincroniza `shared/version.php`;
- `scripts/classify-change.sh` audita diffs de forma independente;
- `AGENTS.md` passou a exigir a leitura de `VERSIONING.md` antes de qualquer mudanca.

## Arquitetura e decisoes

- o deploy manteve o banco compartilhado com o legado (`sistema_hml`) como fonte
  de dados unica, conforme o desenho da plataforma;
- TLS autoassinado foi aceito como solucao para LAN sem dominio publico, com o
  certificado registrado como CA confiavel do proprio servidor para chamadas
  server-to-server (desktop → API);
- a documentacao exibida no desktop e lida diretamente de `documentacao/` no
  filesystem do servidor — sem duplicacao de conteudo nem sincronizacao manual.

## Validacao

- `php -l` nos arquivos PHP alterados;
- `php artisan test` no frontend desktop (suite de configuracoes);
- checklist pos-deploy do runbook executado contra `192.168.1.100`
  (API 200, login invalido atravessa a stack, CORS ok, desktop 200, Supervisor RUNNING);
- `php scripts/php/sync-agent-docs.php` executado;
- `./scripts/bump-version.sh --tier=minor` registrado no `CHANGELOG.md`.

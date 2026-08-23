# Plano — Backup e restauração do sistema

Especificação: [`spec.md`](spec.md).
Arquitetura resultante: `documentacao/03-arquitetura-tecnica/backup-e-restauracao.md`.

## Restrições que moldaram o desenho

Levantadas na máquina, antes de escrever código. Cada uma eliminou um caminho
que pareceria óbvio:

| Restrição | Medida | Caminho que eliminou |
|---|---|---|
| `max_execution_time = 60` nos pools FPM | `/etc/php/8.5/fpm/pool.d/` | backup síncrono na requisição |
| `retry_after = 180` na conexão redis | `config/queue.php` | job em fila (rodaria duas vezes) |
| Supervisor consome só `documents,default` | `/etc/supervisor/conf.d/` | fila nova sem mexer como root |
| `memory_limit = 256M` | pool FPM | montar o pacote em memória |
| `ApiClient::download()` faz `$response->body()` | desktop | proxiar 130 MB pelo BFF |
| `client_max_body_size 25M` | nginx | restaurar por upload |
| `private/private/{os,os_documentos,...}` são `0700 www-data` | verificado com `ls` | rodar como `administrador` |
| `/var/backups/sistema-erp` é `root 755`, arquivos `644` | verificado | o painel **lê**, mas não apaga |
| `storage/app/backups` é `drwx------ administrador` | verificado | `www-data` nem lista — exige `chown` |
| 200 tabelas InnoDB, `gtid_mode=OFF` | `information_schema` | dúvida sobre `--single-transaction` |
| `erp_app` sem grant em `sistema_erp_chat` | `SHOW GRANTS` | exigir os dois bancos |

## Sequência de implementação

A ordem não é arbitrária: cada etapa só começa quando a anterior é verificável.

1. **`config/backup.php`** — todas as decisões (raízes, exclusões, cifra,
   retenção, interruptores) num lugar só, antes de qualquer serviço.
2. **Enums e models** — vocabulário do domínio.
3. **Migrations** — infraestrutura + módulo RBAC, guardadas por `hasTable`
   (o banco é legado e compartilhado).
4. **`ProcessRunner` primeiro.** A costura de teste vem antes do código que
   depende dela, não depois. `phpunit.xml` força sqlite, então `mysqldump` nunca
   roda na suíte; sem a indireção o subsistema nasceria intestável.
5. **Serviços de coleta** — dumper, walker, snapshot de configuração.
6. **Manifesto e cifra** — o contrato do pacote.
7. **Preflight e Runner** — orquestração.
8. **Descoberta e retenção** — o catálogo unificado.
9. **Comandos artisan** — o caminho de desastre real, antes da interface.
10. **API + rotas + RBAC.**
11. **BFF e interface.**
12. **Testes, documentação, versionamento.**

## Escolhas com alternativa descartada

### Cifra: `openssl enc` sobre ZIP-AES, `age` e `gpg`

| Opção | Por que não |
|---|---|
| `zip -e` | ZipCrypto quebrado; o `zip` do Debian não tem AES |
| `ZipArchive::EM_AES_256` | o `unzip` padrão do Linux **não abre**; exige 7z/WinZip |
| `age` | não instalado; adiciona binário para provisionar em dois servidores |
| `gpg --symmetric` | sob `www-data` precisa de `GNUPGHOME` gravável e tenta falar com o `gpg-agent` — fonte clássica de "funciona como eu, falha como o usuário do site" |
| **`openssl enc`** | presente em todo lugar, sem estado, sem agente, trabalha em stream, e a restauração é um pipe documentado |

A fraqueza do CBC (maleabilidade) é fechada pelo sha256 por membro no manifesto.

### Frase secreta por variável de ambiente, não por argumento

`-k` e `-pass pass:` colocam o segredo em `argv`, legível por qualquer usuário
da máquina com `ps aux`. `/proc/<pid>/environ` só é legível pelo mesmo uid.

### Manifesto em texto claro

Cifrar o manifesto obrigaria a frase secreta para listar backups na tela e para
aplicar retenção. Estrutura não é segredo; conteúdo é.

### Raízes por ID lógico

A alternativa (caminhos absolutos no manifesto) faria um pacote de produção
restaurado na bancada escrever no caminho de produção. Coberto por teste.

## Riscos aceitos, com mitigação

| Risco | Mitigação |
|---|---|
| Frase perdida = backup irrecuperável | aviso permanente na tela e confirmação explícita ao definir |
| Frase cifrada com `APP_KEY` no servidor | inerente a backup não assistido; protege as cópias que **saem** do servidor. Modo `manual` disponível, desabilitando a agenda |
| Pacote com todos os segredos num arquivo só | `0600`, fora do document root, entrega por URL assinada de 10 min |
| Backup incompleto passando por completo | walker denuncia todo diretório ilegível; manifesto declara o que foi omitido |
| Dump truncado passando na verificação | rodapé `-- Dump completed` além do `gzip -t` |

## Fora do escopo desta fase

Restauração, HD externo e nuvem — ver `spec.md`. A restauração vem antes da
nuvem porque um backup que nunca foi restaurado não é um backup.

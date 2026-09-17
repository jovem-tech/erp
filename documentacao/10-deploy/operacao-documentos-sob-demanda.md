# Operação dos documentos PDF sob demanda (Central Documental / orçamentos)

## O que mudou

Gerar um documento da OS (abertura, orçamento, laudo, cobrança, entrega,
devolução, encerramento) ou o PDF de um orçamento **não grava mais o PDF em
disco**. Cada versão grava um *snapshot* — JSON com os dados daquele instante e
o template usado (`os_documento_snapshots`, KBs) — e o PDF é renderizado quando
alguém abre, baixa, imprime, envia ou compartilha. "v1 — 13/09" continua
reproduzindo o que foi emitido em 13/09.

Só **assinatura formal** continua persistida em disco: `metodo_assinatura` em
`pendencia_sessao`, `pendencia_reautenticada` ou `cliente_link`. A rubrica
automática de emissão (`sessao`/`reautenticacao`) é reidratada do cadastro do
usuário no render.

**Todo PDF gerado — persistido ou efêmero — respeita um teto de tamanho**
(`document-rendering.max_bytes`, padrão **80 KB**): a Central Documental é
para consulta rápida, não substitui a foto original (já guardada em
`private/os`/`private/equipamentos`). Acima do teto, o motor chama o
Ghostscript em níveis crescentes de agressividade (`/screen` → recodificação
JPEG a 55 dpi/Q18); se mesmo assim não couber, entrega o menor resultado
possível e registra um aviso no log — nunca bloqueia a emissão. A logo da
empresa também passa a ser reduzida antes de embutir (era gravada no tamanho
de upload — 38 KB medidos em produção — para ser exibida a no máximo 150pt
de largura).

Ganhos independentes do modo (já valem no primeiro deploy):

- subsetting de fonte no dompdf (antes cada PDF embutia 1,4 MB de DejaVu:
  cupom 80mm de 860 KB → 26 KB);
- fotos passam pelo libvips em todo formato (não só AVIF), 1400 px/q72;
- galeria de fotos usa `<img>` em vez de `background-image` (o dompdf
  rasterizava a 96 dpi via GD: mais pesado e pior de imprimir);
- ZIP de "Baixar documentos" é temporário e apagado após o envio.

Configuração: `backend/config/document-rendering.php` (`.env`:
`DOCUMENT_RENDERING_*`).

## Pré-requisitos na VPS

- `ghostscript` (`/usr/bin/gs`) — **pacote novo desta entrega**; comprime
  qualquer PDF que ultrapasse o teto de 80 KB (não só o assinado). Sem ele o
  PDF é gravado como saiu do dompdf (log de aviso, nada quebra). Instalado por
  `scripts/bash/install-operational-photo-dependencies.sh`, que o deploy chama.

  **Importante:** o pacote `ghostscript` do Ubuntu roda `gs` sob um profile
  AppArmor (`/etc/apparmor.d/gs`, `abstractions/user-tmp`) que só permite
  escrita em `/tmp`, `/var/tmp` ou dentro do `$HOME` do usuário do processo
  — um diretório arbitrário do projeto (mesmo com permissões Unix corretas e
  o mesmo dono) é **negado pelo kernel**, não pelo PHP, e falha em silêncio
  (a única pista é `journalctl -k | grep apparmor.*gs` mostrando
  `DENIED ... profile="gs"`). Por isso `PdfCompressionService` grava os
  temporários do Ghostscript sempre em `sys_get_temp_dir()` (`/tmp`), nunca
  em `document-rendering.temp_directory` — não mexer nisso sem reler
  `tests/Unit/Services/Pdf/PdfCompressionServiceTest.php`, que trava essa
  regressão.
- `libvips-tools` (já exigido pelas fotos operacionais) — agora usado em todas
  as fotos embutidas em PDF, não só AVIF.
- `poppler-utils` — `pdftocairo` para miniaturas (como antes) e `pdftotext`
  para `documents:snapshot-check` e para os testes de versão.
- Nenhuma dependência PHP/Composer nova (`dompdf`, `symfony/process` já existiam).
- Diretórios criados em runtime pelo `www-data`:
  `storage/framework/cache/pdf-render` (cache) e
  `storage/framework/cache/pdf-tmp` (temporários). **Não criar à mão como
  outro usuário** — o `www-data` deixaria de conseguir escrever.

## Rollout

### Fase 0 — deploy normal (modo `dual`, padrão)

```bash
cd /var/www/sistema-erp/backend
php artisan migrate --path=database/migrations/2026_09_15_000001_create_os_documento_snapshots_table.php --force
php artisan config:clear && php artisan config:cache
```

(`migrate` geral continua bloqueado pela migration do chat; aplicar com
`--path`.) Em `dual` tudo ainda vai a disco **e** ganha snapshot — comportamento
idêntico ao atual, mas os PDFs já saem 5–10x menores.

### Fase 1 — validar o re-render (alguns dias em `dual`)

```bash
php artisan documents:snapshot-check --sample=50          # compara snapshot x arquivo emitido
php artisan documents:snapshot-check --order=3677 --format=80mm
```

`ok` = mesmas páginas, tamanho compatível e mesmo texto; `aviso` lista o motivo
(foto/logo alterada, template original indisponível, texto diverge). Zero
falhas é o critério para a fase 2.

### Fase 2 — ligar o modo `snapshot`

```bash
# .env
DOCUMENT_RENDERING_MODE=snapshot
php artisan config:clear && php artisan config:cache
```

Reverter = voltar para `dual`; o que já foi emitido em `snapshot` continua
sendo servido pelo resolver (nada se perde).

### Fase 3 — expurgar o passivo

Backup completo antes (`backup:executar --tipo=completo`). Sempre `--dry-run`
primeiro (é o padrão); `--execute` aplica.

```bash
php artisan documents:purge-legacy-binaries                       # dry-run
php artisan documents:purge-legacy-binaries --execute --before=2026-06-01 --limit=500
php artisan documents:purge-legacy-binaries --execute --budgets   # private/orcamentos
php artisan documents:purge-legacy-binaries --execute --zips      # zips residuais + temporários
```

Nunca são tocados: caminhos `fiscal/` (guarda legal), assinatura formal,
`managed_files` com `legal_hold`, tipos sem re-render. Documento antigo sem
snapshot passa a ser reconstituído com os dados **atuais** da OS
(`metadados_json.origem_reconstituicao = dados_atuais`) — diferença aceita na
decisão do projeto.

## Cache de render

`storage/framework/cache/pdf-render` — fora do backup (`framework` já é
excluído). TTL 7 dias (deslizante) e teto de 512 MB, aplicados diariamente às
02:20 por `documents:purge-render-cache` (agendado em `routes/console.php`).
Apagar o diretório inteiro custa só re-renders.

## Como ler o acervo agora

`os_documentos.arquivo` continua preenchido com o nome convencional
(`laudo_os26090009_v2_a4.pdf`) mesmo sem arquivo — é o nome do download.
`metadados_json.armazenamento` diz `disco` ou `snapshot`;
`hash_sha256`/`hash_sha1` atestam o binário **emitido** (`hash_semantica =
emissao`): um re-render nunca é byte-idêntico (dompdf grava `/ID` e
`CreationDate` novos), então nenhuma checagem de integridade pode comparar
re-render com esse hash.

Ordem de resolução ao abrir um formato (`DocumentBytesResolver`):
disco → cache de render → snapshot → dados atuais → indisponível.

O gerenciador `/arquivos` deixa de listar os PDFs sob demanda (só o que ocupa
disco é catalogado). Os documentos continuam na Central Documental da OS.

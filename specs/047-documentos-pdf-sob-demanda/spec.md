# Especificação 047 - Documentos PDF da OS renderizados sob demanda

## Problema

Cada "Gerar" na Central Documental renderizava A4 e 80mm e gravava os dois em
`storage/app/private/private/os_documentos/{os}/`; o orçamento gravava outro PDF em
`private/orcamentos/`. Um laudo A4 pesava 2,3 MB (fotos em resolução original e 1,4 MB
de fontes embutidas), e nada era expurgado. Ao longo dos anos o acervo cresceria sem
teto, duplicando fotos que já estão guardadas em `private/os` e `private/equipamentos`.

## Objetivo

Parar de armazenar o PDF de cada versão sem perder o histórico: cada versão guarda os
dados do instante da emissão e o template usado, e o PDF é renderizado quando lido.
O binário só fica em disco quando é prova (assinatura formal). Todo PDF gerado —
persistido ou efêmero — respeita um teto de tamanho fixo.

## Decisões de produto (fechadas com o usuário em 2026-09-15)

1. Snapshot por versão: "v1 — 13/09" reproduz o documento emitido em 13/09.
2. Persistir em disco apenas `metodo_assinatura` em `pendencia_sessao`,
   `pendencia_reautenticada` e `cliente_link`. A rubrica automática
   (`sessao`/`reautenticacao`) é reidratada no render e não persiste.
3. Escopo: documentos da OS, PDFs de orçamento, purga do passivo e ZIPs residuais.
4. PDFs sob demanda deixam de aparecer no gerenciador `/arquivos`.
5. Teto de **80 KB** em todo PDF gerado (persistido ou efêmero), "comprimido ao
   extremo" quando necessário — a Central Documental é para consulta, não substitui
   a foto original.

## Requisitos funcionais

- Gerar grava `os_documento_snapshots` (JSON sem base64; fotos, logo e rubricas por
  referência; datas ISO-8601; template pinado por `pdf_template_versoes.id`).
- Ler qualquer formato de qualquer versão sem arquivo: disco → cache → snapshot →
  dados atuais → indisponível. Listagens nunca renderizam (`isAvailable()`).
- Download, olho, impressão, ZIP, link público, envio WhatsApp/e-mail, PDF de
  abertura via inbox, orçamento público e miniatura funcionam com bytes.
- Assinatura formal grava o binário em disco e `hash_sha256` bate com o arquivo.
- Todo PDF (a4 e 80mm, persistido ou efêmero) respeita `document-rendering.max_bytes`
  (80 KB): acima do teto, Ghostscript em níveis crescentes (`/screen` → recodificação
  JPEG 55 dpi/Q18); sem couber mesmo assim, entrega o menor resultado e loga aviso —
  nunca bloqueia a emissão. Fotos e logo passam pelo libvips com dimensão/qualidade
  reduzidas antes do dompdf, para o Ghostscript ter menos trabalho.
- `metadados_json` registra `armazenamento` (`disco`|`snapshot`), `render_perfil`,
  `hash_snapshot` e `hash_semantica = emissao` (o hash atesta o binário emitido; um
  re-render nunca é byte-idêntico).
- Comandos: `documents:purge-render-cache` (agendado), `documents:snapshot-check`
  (diagnóstico), `documents:purge-legacy-binaries` (dry-run por padrão; nunca toca
  fiscal, assinatura formal, `legal_hold`, tipo sem re-render).
- Modo `dual` (padrão) grava disco e snapshot; `snapshot` grava só o snapshot.

## Requisitos não funcionais

- Ganhos independentes do modo: subsetting de fonte, fotos via libvips em todo
  formato, galeria com `<img>` (JPEG direto em vez de bitmap 96 dpi).
- Cache de render em `storage/framework/cache/pdf-render` (fora do backup), TTL
  deslizante de 7 dias e teto de 512 MB.
- Diretórios de runtime criados pelo `www-data`; nunca pré-criados por outro usuário.
- Dependência nativa nova: `ghostscript` (opcional, com fallback e aviso em log). O
  pacote roda `gs` sob um profile AppArmor que só permite escrever em `/tmp`,
  `/var/tmp` ou `$HOME` do usuário do processo — os temporários do Ghostscript vão
  sempre para `sys_get_temp_dir()`, nunca para um diretório do projeto.

## Fora de escopo

- Indicador visual "sob demanda" no desktop (o campo `storage` já vem na API).
- Reprocessar documentos antigos para criar snapshot retroativo (sem dados da época).
- Guarda fiscal (XML/PDF de nota) — intocada.

## Verificação

- `php artisan test` no backend (suite `OrderDocumentSnapshotTest`,
  `PurgeLegacyDocumentBinariesTest`, `PurgeRenderCacheTest`,
  `DocumentSnapshotSerializerTest`, `PdfCompressionServiceTest`, além das suítes
  existentes).
- Em produção, `documents:snapshot-check --sample=50` sem falhas antes de virar o modo.

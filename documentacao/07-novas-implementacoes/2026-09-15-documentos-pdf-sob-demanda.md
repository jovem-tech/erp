# Documentos PDF da OS renderizados sob demanda

A Central Documental da OS e o PDF do orçamento deixaram de guardar o binário de
cada versão gerada. Gerar um documento grava um *snapshot* — JSON com os dados
daquele instante e o template publicado usado (`os_documento_snapshots`, ~5 KB) — e
o PDF é renderizado quando alguém abre, baixa, imprime, envia ou compartilha.
"v1 — 13/09" continua reproduzindo o que foi emitido em 13/09, mesmo que a OS tenha
mudado depois.

Só a **assinatura formal** continua persistida em disco: `metodo_assinatura` em
`pendencia_sessao`, `pendencia_reautenticada` ou `cliente_link`. A rubrica automática
de emissão (`sessao`/`reautenticacao`, presente em quase todo documento) é reidratada
do cadastro do usuário na hora do render e não justifica guardar o binário.

**Todo PDF gerado — persistido ou efêmero — tem um teto de 80 KB**
(`document-rendering.max_bytes`). O peso dos PDFs era em grande parte artificial:
o dompdf estava com `enable_font_subsetting => false` (1,4 MB de fontes DejaVu em
todo documento), as fotos entravam em resolução original, a logo da empresa era
embutida no tamanho de upload (38 KB medidos em produção para caber em 150pt de
largura) e a galeria usava `background-image`, que o dompdf rasteriza via GD a
96 dpi. Corrigido tudo isso (subsetting ligado, fotos e logo via libvips,
galeria com `<img>`) mais o Ghostscript acima do teto (`/screen`, e se não
bastar, recodificação JPEG a 55 dpi/Q18): um laudo A4 sem foto ficou em ~48 KB;
o pior caso do sistema — 5 fotos (equipamento + 4 de entrada), que antes pesava
2,3 MB — ficou em ~77 KB. Acima do teto mesmo após a compressão máxima, o
documento é entregue do jeito que ficou e um aviso vai para o log — nunca
bloqueia a emissão.

Leitura passa por um único ponto, `DocumentBytesResolver`: arquivo em disco →
cache de render (`storage/framework/cache/pdf-render`, TTL 7 dias, teto 512 MB,
fora do backup) → re-render do snapshot → reconstituição com os dados atuais
(documento antigo sem snapshot cujo binário foi expurgado) → indisponível. Envio por
WhatsApp/e-mail, ZIP, link público, impressão, PDF de abertura via inbox e miniatura
funcionam sem arquivo em disco; o gerenciador `/arquivos` não cataloga mais os PDFs
efêmeros.

Rollout em fases, controlado por `DOCUMENT_RENDERING_MODE`: `dual` (padrão — grava
disco **e** snapshot, comportamento idêntico ao anterior) para validar com
`documents:snapshot-check`, depois `snapshot`. O passivo é expurgado por
`documents:purge-legacy-binaries` (dry-run por padrão; nunca toca caminhos fiscais,
assinatura formal, `legal_hold` ou tipos sem re-render). ZIPs de "Baixar documentos",
que ficavam para sempre em `os_documentos/*/zip`, viraram temporários.

Dependência nativa nova: `ghostscript` (opcional — sem ele o PDF é gravado sem a
compressão extra), incluído no instalador versionado que o deploy chama. Achado
relevante: o pacote `ghostscript` do Ubuntu roda `gs` sob um profile AppArmor que só
permite escrever em `/tmp`/`/var/tmp`/`$HOME` do usuário do processo — um diretório
qualquer do projeto é negado pelo kernel em silêncio, mesmo com as permissões Unix
corretas. Por isso o serviço de compressão grava seus temporários sempre no diretório
temporário do sistema, nunca dentro do projeto.

Migration `2026_09_15_000001_create_os_documento_snapshots_table` deve ser aplicada
com `--path`. Runbook: [operação dos documentos sob demanda](../10-deploy/operacao-documentos-sob-demanda.md);
especificação: [spec 047](../../specs/047-documentos-pdf-sob-demanda/spec.md).

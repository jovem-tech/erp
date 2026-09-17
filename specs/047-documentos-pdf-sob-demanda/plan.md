# Plano 047 - Documentos PDF sob demanda

Plano de implementação aprovado em 2026-09-15 (resumo; o texto completo ficou no plano
da sessão de IA e foi executado integralmente no código).

## Arquitetura

```
Gerar ──► PdfGenerationService::generate(capture_snapshot, render_profile)
             ├─ bytes A4 (fotos via libvips, fonte subsetted)
             └─ envelope snapshot (DocumentSnapshotSerializer)
        ──► OrderDocumentVersionWriter::persist()  [lockForUpdate na versão]
             ├─ os_documentos (+ metadados.armazenamento/hash_snapshot)
             ├─ os_documento_snapshots (sempre)
             ├─ os_documento_arquivos (a4 e 80mm, sem arquivo quando efêmero)
             ├─ disco só se DocumentPersistencePolicy (assinatura formal | modo dual)
             └─ pré-aquece PdfRenderCache

Ler ────► DocumentBytesResolver::resolve(order, doc, formato)
             disco → PdfRenderCache → renderSnapshot(envelope) → dados atuais → missing
```

## Fases

- **Fase 0** — subsetting de fonte; fotos via libvips em todo MIME
  (`OperationalPhotoPdfRenderer::forPdf`); galeria com `<img>`; ZIP temporário;
  `sendDirectMediaBytes` e `attachData`.
- **Fase 1** — tabela `os_documento_snapshots`; serializer/hydrator; `_refs` nas
  factories; `renderSnapshot()`; `DocumentBytesResolver` + `ResolvedDocumentFile`;
  `PdfRenderCache`; `DocumentPersistencePolicy`; `PdfCompressionService`;
  `OrderDocumentVersionWriter` (abertura, encerramento, orçamento e genéricos delegam);
  consumidores trocados para bytes.
- **Fase 2** — guard no `OrderDocumentFileObserver`; `OrderDocumentThumbnailService`
  sem `ManagedFile` (`PdfThumbnailRasterizer` compartilhado); agendamento da purga
  do cache; modo `snapshot` via `.env`.
- **Fase 3** — `documents:purge-legacy-binaries` + `ManagedFilePurgeService::retireReplacedBinary`.

## Riscos assumidos

- Re-render ≠ bytes emitidos (dompdf grava `/ID` e `CreationDate`): `hash_sha256` vale
  para o binário emitido, não para checar re-render.
- Foto/logo apagada depois → re-render sem ela, anotado em `divergencias`.
- Documento antigo sem snapshot expurgado → reconstituído com dados atuais.

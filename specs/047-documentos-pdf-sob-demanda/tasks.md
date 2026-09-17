# Tarefas 047

## Código (concluído em 2026-09-15)

- [x] Subsetting de fonte no `PdfGenerationService::renderPdfBytes()`
- [x] Fotos via libvips para todo MIME; PNG preserva alfa; perfis `padrao`/`assinado`
- [x] Galeria `fotos-entrada` com `<img>` (max-width/max-height)
- [x] ZIP temporário com `deleteFileAfterSend`
- [x] `IntegrationSettingsService::sendDirectMediaBytes()`; e-mail com `attachData()`
- [x] Migration `os_documento_snapshots` + espelho em `BuildsLegacyErpSchema`
- [x] `DocumentSnapshotSerializer` / `DocumentSnapshotHydrator`; `_refs` nas factories
- [x] `PdfGenerationService::renderSnapshot()` e `capture_snapshot`
- [x] `DocumentBytesResolver`, `ResolvedDocumentFile`, `PdfRenderCache`
- [x] `DocumentPersistencePolicy`, `PdfCompressionService`, `OrderDocumentVersionWriter`
- [x] Consumidores: API/Web controllers, envio, ZIP, link público, abertura via inbox,
      orçamento público, miniatura (`OrderDocumentThumbnailService`)
- [x] Guard no `OrderDocumentFileObserver`
- [x] Comandos `documents:purge-render-cache` (agendado 02:20), `documents:snapshot-check`,
      `documents:purge-legacy-binaries`; `ManagedFilePurgeService::retireReplacedBinary`
- [x] Testes: `OrderDocumentSnapshotTest`, `PurgeLegacyDocumentBinariesTest`,
      `PurgeRenderCacheTest`, `DocumentSnapshotSerializerTest`; `BudgetFlowTest` ajustado
- [x] Teto de 80 KB (`document-rendering.max_bytes`) em `renderPdfBytes()`, aplicado a
      todo PDF gerado (não só o assinado); `PdfCompressionService` com níveis
      escalonados (`/screen` → JPEG 55dpi/Q18); logo da empresa reduzida via libvips
      antes de embutir; perfis de foto `padrao`/`assinado` retunados (700/45, 600/38)
- [x] Achado e correção: `gs` roda sob AppArmor no Ubuntu (só permite escrever em
      `/tmp`/`/var/tmp`/`$HOME`) — `PdfCompressionService` passa a usar sempre
      `sys_get_temp_dir()`, nunca `document-rendering.temp_directory`; teste de
      regressão em `PdfCompressionServiceTest`
- [x] Documentação: runbook `operacao-documentos-sob-demanda.md`, deploy (ghostscript
      + AppArmor), consolidado em `07-novas-implementacoes`, `.env.example`

## Operação (pendente — depende do ambiente)

- [ ] Commit da entrega
- [ ] `queue:restart` após cada deploy (workers carregam classes que mudaram)
- [ ] Produção: pré-check (`which gs vips`), migration com `--path`, deploy em modo `dual`
- [ ] `documents:snapshot-check --sample=50` sem falhas → `DOCUMENT_RENDERING_MODE=snapshot`
- [ ] Backup completo → `documents:purge-legacy-binaries` (dry-run → `--execute` em lotes
      → `--budgets` → `--zips`)

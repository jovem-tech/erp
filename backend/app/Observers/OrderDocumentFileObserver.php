<?php

namespace App\Observers;

use App\DTO\Files\FileContext;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileOrigin;
use App\Models\OrderDocumentFile;
use App\Services\Files\FileStateMachine;
use App\Services\Files\LegacyCompatibleFileAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrderDocumentFileObserver
{
    public function __construct(
        private readonly LegacyCompatibleFileAdapter $fileManagerAdapter,
        private readonly FileStateMachine $states
    ) {}

    public function created(OrderDocumentFile $documentFile): void
    {
        $documentFile->loadMissing('document:id,os_id');
        $orderId = (int) ($documentFile->document?->os_id ?? 0);
        $storagePath = trim((string) ($documentFile->arquivo ?? ''));
        if ($orderId <= 0 || $storagePath === '') {
            return;
        }

        $managed = $this->fileManagerAdapter->synchronizeExisting(
            new FileContext(
                category: FileCategory::OrderPdf,
                origin: FileOrigin::Generated,
                operationKey: 'order-document-file:'.(int) $documentFile->id.':'.hash('sha256', $storagePath),
                subjectType: 'order',
                subjectId: $orderId,
                relation: 'document_file:'.(int) $documentFile->id,
                createdBy: $documentFile->document?->gerado_por !== null
                    ? (int) $documentFile->document->gerado_por
                    : null
            ),
            'local',
            $storagePath,
            'os_documento_arquivos',
            'arquivo',
            (string) $documentFile->id
        );

        if (
            $managed !== null
            && Schema::hasColumn($documentFile->getTable(), 'managed_file_uuid')
            && $documentFile->managed_file_uuid !== $managed->uuid
        ) {
            $documentFile->forceFill(['managed_file_uuid' => $managed->uuid])->saveQuietly();
        }

        $legacyHash = strtolower(trim((string) ($documentFile->hash_sha256 ?? '')));
        if ($managed !== null && preg_match('/^[a-f0-9]{64}$/', $legacyHash) === 1 && ! hash_equals($legacyHash, $managed->sha256)) {
            $this->states->markIntegrity($managed, FileIntegrityStatus::Corrupted);
            Log::warning('[FILE_MANAGER][DOCUMENT] Hash legado diverge do blob catalogado.', [
                'file_uuid' => $managed->uuid,
                'document_file_id' => (int) $documentFile->id,
            ]);
        }
    }
}

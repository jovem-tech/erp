<?php

namespace App\Services\Files;

use App\DTO\Files\FileContext;
use App\Enums\Files\FileCategory;
use App\Enums\Files\FileManagerMode;
use App\Enums\Files\FileOrigin;
use App\Models\Chat\MessageAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ChatFileManagerAdapter
{
    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly LegacyCompatibleFileAdapter $adapter
    ) {}

    public function synchronize(MessageAttachment $attachment, FileOrigin $origin): void
    {
        $category = FileCategory::ChatAttachment;
        $mode = $this->configuration->mode();
        if ($mode === FileManagerMode::Off || ! $this->configuration->isCategoryEnabled($category)) {
            return;
        }

        $storagePath = trim((string) ($attachment->storage_path ?? ''));
        $disk = trim((string) ($attachment->disk ?? ''));
        if ($storagePath === '' || $disk === '') {
            return;
        }

        try {
            $this->saveLinkState($attachment, 'pending_link');
            $managed = $this->adapter->synchronizeExisting(
                new FileContext(
                    category: $category,
                    origin: $origin,
                    operationKey: 'chat-attachment:'.(int) $attachment->id.':'.hash('sha256', $disk."\0".$storagePath),
                    subjectType: 'chat_attachment',
                    subjectId: (int) $attachment->id,
                    relation: 'message:'.(int) $attachment->mensagem_id,
                    metadata: ['source' => $origin->value]
                ),
                $disk,
                $storagePath,
                'mensagem_anexos',
                'storage_path',
                (string) $attachment->id
            );

            if ($managed === null) {
                if ($mode === FileManagerMode::Observe) {
                    $this->saveLinkState($attachment, 'observed');
                }

                return;
            }

            $attributes = [
                'metadata' => array_merge((array) $attachment->metadata, ['file_manager_state' => 'linked']),
            ];
            if (Schema::connection('chat')->hasColumn($attachment->getTable(), 'managed_file_uuid')) {
                $attributes['managed_file_uuid'] = $managed->uuid;
            }
            $attachment->forceFill($attributes)->saveQuietly();
        } catch (\Throwable $exception) {
            if (in_array($mode, [FileManagerMode::Observe, FileManagerMode::Shadow], true)) {
                Log::warning('[FILE_MANAGER][CHAT] Falha isolada na saga de vinculo.', [
                    'attachment_id' => (int) $attachment->id,
                    'error_type' => $exception::class,
                ]);

                return;
            }

            throw $exception;
        }
    }

    private function saveLinkState(MessageAttachment $attachment, string $state): void
    {
        $attachment->forceFill([
            'metadata' => array_merge((array) $attachment->metadata, ['file_manager_state' => $state]),
        ])->saveQuietly();
    }
}

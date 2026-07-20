<?php

namespace App\Services\Files;

use App\Enums\Files\FileOrigin;
use App\Models\Chat\MessageAttachment;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLink;
use Illuminate\Support\Facades\Schema;

class ChatFileReconciliationService
{
    public function __construct(private readonly ChatFileManagerAdapter $adapter) {}

    /**
     * @return array{processed: int, linked: int, pending: int, missing_catalog: int, stale_central_links: int, repaired: int}
     */
    public function reconcile(bool $apply = false, int $limit = 500): array
    {
        if ($apply && ! (bool) config('file-manager.kill_switches.allow_mutating_reconcile', false)) {
            throw new \RuntimeException('Reconciliacao mutavel desabilitada pelo kill switch.');
        }

        if (! Schema::connection('chat')->hasTable('mensagem_anexos')) {
            throw new \RuntimeException('Tabela de anexos do chat indisponivel.');
        }

        $limit = max(1, min(5000, $limit));
        $hasManagedUuid = Schema::connection('chat')->hasColumn('mensagem_anexos', 'managed_file_uuid');
        $query = MessageAttachment::query()->whereNotNull('storage_path')->orderBy('id')->limit($limit);
        $attachments = $query->get();
        $uuids = $hasManagedUuid
            ? $attachments->pluck('managed_file_uuid')->filter()->unique()->values()
            : collect();
        $managedByUuid = ManagedFile::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $result = [
            'processed' => 0,
            'linked' => 0,
            'pending' => 0,
            'missing_catalog' => 0,
            'stale_central_links' => 0,
            'repaired' => 0,
        ];

        foreach ($attachments as $attachment) {
            $result['processed']++;
            $uuid = $hasManagedUuid ? trim((string) ($attachment->managed_file_uuid ?? '')) : '';
            if ($uuid !== '' && $managedByUuid->has($uuid)) {
                $result['linked']++;

                continue;
            }

            if ($uuid !== '') {
                $result['missing_catalog']++;
            } else {
                $result['pending']++;
            }

            if (! $apply) {
                continue;
            }

            $source = (string) data_get($attachment->metadata, 'source', 'upload');
            $this->adapter->synchronize(
                $attachment,
                $source === FileOrigin::Upload->value ? FileOrigin::Upload : FileOrigin::Integration
            );
            if ($hasManagedUuid && trim((string) ($attachment->fresh()?->managed_file_uuid ?? '')) !== '') {
                $result['repaired']++;
            }
        }

        $centralAttachmentIds = ManagedFileLink::query()
            ->where('subject_type', 'chat_attachment')
            ->whereNull('unlinked_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('subject_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        if ($centralAttachmentIds->isNotEmpty()) {
            $existingIds = MessageAttachment::query()
                ->whereKey($centralAttachmentIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id);
            $result['stale_central_links'] = $centralAttachmentIds->diff($existingIds)->count();
        }

        return $result;
    }
}

<?php

namespace App\Services\Files;

use App\Enums\Files\ManagedFileAction;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileEvent;
use Illuminate\Support\Str;

class ManagedFileEventRecorder
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(
        ManagedFileAction $action,
        string $result,
        ?ManagedFile $file = null,
        ?int $actorId = null,
        ?string $module = null,
        array $context = []
    ): ManagedFileEvent {
        if (! in_array($result, ['success', 'denied', 'failed'], true)) {
            throw new \InvalidArgumentException('Resultado de evento invalido.');
        }

        $safeContext = [];
        foreach (['category', 'origin', 'relation', 'reason_code', 'reason', 'authorized_by', 'validation_reference', 'integrity_status', 'path_hash', 'size_bytes', 'detected_mime_type'] as $key) {
            if (array_key_exists($key, $context) && (is_scalar($context[$key]) || $context[$key] === null)) {
                $safeContext[$key] = is_string($context[$key])
                    ? mb_substr(trim($context[$key]), 0, 500)
                    : $context[$key];
            }
        }

        return ManagedFileEvent::query()->create([
            'event_uuid' => Str::uuid()->toString(),
            'file_id' => $file?->id,
            'actor_id' => $actorId,
            'action' => $action,
            'result' => $result,
            'module' => $module,
            'correlation_id' => request()?->header('X-Request-Id'),
            'context_json' => $safeContext !== [] ? $safeContext : null,
        ]);
    }
}

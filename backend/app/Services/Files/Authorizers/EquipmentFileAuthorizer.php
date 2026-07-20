<?php

namespace App\Services\Files\Authorizers;

use App\Contracts\Files\FileAuthorizer;
use App\Models\Equipment;
use App\Models\Files\ManagedFile;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;

class EquipmentFileAuthorizer implements FileAuthorizer
{
    public function __construct(private readonly RbacAuthorizationService $rbac) {}

    public function allows(User $actor, ManagedFile $file, string $ability): bool
    {
        $action = in_array($ability, ['metadata', 'download'], true) ? 'visualizar' : 'editar';
        if (! $this->rbac->allows($actor, 'equipamentos', $action)) {
            return false;
        }

        $equipmentIds = $file->links
            ->where('subject_type', 'equipment')
            ->whereNull('unlinked_at')
            ->pluck('subject_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        return $equipmentIds->isNotEmpty()
            && Equipment::query()->whereKey($equipmentIds)->exists();
    }
}

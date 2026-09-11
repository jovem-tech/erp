<?php

namespace App\Services\Files\Authorizers;

use App\Contracts\Files\FileAuthorizer;
use App\Models\Financeiro;
use App\Models\Files\ManagedFile;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;

class FinanceiroFileAuthorizer implements FileAuthorizer
{
    public function __construct(private readonly RbacAuthorizationService $rbac) {}

    public function allows(User $actor, ManagedFile $file, string $ability): bool
    {
        $action = in_array($ability, ['metadata', 'download'], true) ? 'visualizar' : 'editar';
        if (! $this->rbac->allows($actor, 'financeiro', $action)) {
            return false;
        }

        $financeiroIds = $file->links
            ->where('subject_type', 'financeiro')
            ->whereNull('unlinked_at')
            ->pluck('subject_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($financeiroIds->isEmpty()) {
            return false;
        }

        // Restoration is still authorized by RBAC and administrator step-up.
        // Let the lifecycle coordinator return the explicit 409 when the
        // financial parent no longer exists. Other operations remain hidden.
        if ($ability === 'restore') {
            return true;
        }

        return Financeiro::query()->whereKey($financeiroIds)->exists();
    }
}

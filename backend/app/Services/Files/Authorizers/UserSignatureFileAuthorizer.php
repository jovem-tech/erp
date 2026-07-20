<?php

namespace App\Services\Files\Authorizers;

use App\Contracts\Files\FileAuthorizer;
use App\Models\Files\ManagedFile;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Auth\RbacAuthorizationService;

class UserSignatureFileAuthorizer implements FileAuthorizer
{
    public function __construct(private readonly RbacAuthorizationService $rbac) {}

    public function allows(User $actor, ManagedFile $file, string $ability): bool
    {
        if (! in_array($ability, ['metadata', 'download'], true)) {
            return $this->rbac->allows($actor, 'arquivos', 'administrar');
        }

        $signatureIds = $file->links
            ->where('subject_type', 'user_signature')
            ->whereNull('unlinked_at')
            ->pluck('subject_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        return $signatureIds->isNotEmpty()
            && UserSignature::query()
                ->whereKey($signatureIds)
                ->where('usuario_id', (int) $actor->id)
                ->exists();
    }
}

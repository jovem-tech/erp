<?php

namespace App\Services\Files\Authorizers;

use App\Contracts\Files\FileAuthorizer;
use App\Models\Files\ManagedFile;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;

class ConfigurationFileAuthorizer implements FileAuthorizer
{
    public function __construct(private readonly RbacAuthorizationService $rbac) {}

    public function allows(User $actor, ManagedFile $file, string $ability): bool
    {
        $action = in_array($ability, ['metadata', 'download'], true) ? 'visualizar' : 'editar';

        return $this->rbac->allows($actor, 'configuracoes', $action);
    }
}

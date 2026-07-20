<?php

namespace App\Contracts\Files;

use App\Models\Files\ManagedFile;
use App\Models\User;

interface FileAuthorizer
{
    public function allows(User $actor, ManagedFile $file, string $ability): bool;
}

<?php

namespace App\Services\Files;

use App\Contracts\Files\FileAuthorizer;
use App\Enums\Files\FileOrigin;
use App\Models\Files\ManagedFile;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;

class FileAuthorizationRegistry
{
    /** @var array<string, FileAuthorizer> */
    private array $authorizers = [];

    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly RbacAuthorizationService $rbac
    ) {}

    public function register(string $subjectType, FileAuthorizer $authorizer): void
    {
        if (! $this->configuration->isSubjectTypeAllowed($subjectType)) {
            throw new \InvalidArgumentException('subject_type nao autorizado.');
        }

        $this->authorizers[$subjectType] = $authorizer;
    }

    public function allows(User $actor, ManagedFile $file, string $ability): bool
    {
        if (! in_array($ability, ['metadata', 'download', 'archive', 'trash', 'restore', 'purge', 'quarantine', 'release'], true)) {
            return false;
        }

        $file->loadMissing('links');

        foreach ($file->links->whereNull('unlinked_at') as $link) {
            $authorizer = $this->authorizers[(string) $link->subject_type] ?? null;
            if ($authorizer instanceof FileAuthorizer && $authorizer->allows($actor, $file, $ability)) {
                return true;
            }
        }

        // Arquivos legados catalogados podem ainda não possuir vínculo de domínio.
        // Somente administradores do módulo recebem acesso a esse fallback.
        if (
            $file->origin === FileOrigin::Legacy
            && $file->links->whereNull('unlinked_at')->isEmpty()
        ) {
            return $this->rbac->allows($actor, 'arquivos', 'administrar');
        }

        return false;
    }
}

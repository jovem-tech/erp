<?php

namespace App\Services\Files;

use App\Contracts\Files\FileAuthorizer;
use App\Enums\Files\FileLifecycleStatus;
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
        $activeLinks = $file->links->whereNull('unlinked_at');

        foreach ($activeLinks as $link) {
            $authorizer = $this->authorizers[(string) $link->subject_type] ?? null;
            if ($authorizer instanceof FileAuthorizer && $authorizer->allows($actor, $file, $ability)) {
                return true;
            }
        }

        // Arquivos legados podem nunca ter recebido vínculo. Arquivos na
        // lixeira também podem perder o vínculo ativo quando o documento de
        // origem é substituído. O fallback permanece restrito a administradores
        // do módulo e, na lixeira, somente à consulta e às ações terminais.
        if (
            $activeLinks->isEmpty()
            && (
                $file->origin === FileOrigin::Legacy
                || (
                    $file->lifecycle_status === FileLifecycleStatus::Trashed
                    && in_array($ability, ['metadata', 'download', 'restore', 'purge'], true)
                )
            )
        ) {
            return $this->rbac->allows($actor, 'arquivos', 'administrar');
        }

        return false;
    }
}

<?php

namespace App\DTO\Files;

use App\Enums\Files\FileCategory;
use App\Enums\Files\FileOrigin;

final readonly class FileContext
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public FileCategory $category,
        public FileOrigin $origin,
        public string $operationKey,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?string $relation = null,
        public ?int $createdBy = null,
        public array $metadata = []
    ) {
        if (
            $operationKey === ''
            || strlen($operationKey) > 120
            || preg_match('/^[A-Za-z0-9:._-]+$/', $operationKey) !== 1
        ) {
            throw new \InvalidArgumentException('operationKey invalida.');
        }

        $hasAnySubjectField = $subjectType !== null || $subjectId !== null || $relation !== null;
        $hasAllSubjectFields = $subjectType !== null && $subjectId !== null && $relation !== null;

        if ($hasAnySubjectField && ! $hasAllSubjectFields) {
            throw new \InvalidArgumentException('O vinculo exige subjectType, subjectId e relation.');
        }
    }
}

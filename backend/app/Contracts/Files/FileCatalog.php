<?php

namespace App\Contracts\Files;

use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\DTO\Files\StoredFileResult;
use App\Models\Files\ManagedFile;

interface FileCatalog
{
    public function findByOperationKey(string $operationKey): ?ManagedFile;

    public function register(FileDescriptor $descriptor, FileContext $context, StoredFileResult $stored): ManagedFile;
}

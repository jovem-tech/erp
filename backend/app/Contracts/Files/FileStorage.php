<?php

namespace App\Contracts\Files;

use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\DTO\Files\StoredFileResult;

interface FileStorage
{
    public function store(FileDescriptor $descriptor, FileContext $context, string $extension): StoredFileResult;

    /** @return resource */
    public function readStream(StoredFileResult $file);

    public function exists(string $disk, string $storageKey): bool;

    public function deleteForCompensation(string $disk, string $storageKey): void;
}

<?php

namespace App\Enums\Files;

enum FileIntegrityStatus: string
{
    case Unknown = 'unknown';
    case Valid = 'valid';
    case Missing = 'missing';
    case Corrupted = 'corrupted';
}

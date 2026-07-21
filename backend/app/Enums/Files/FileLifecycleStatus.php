<?php

namespace App\Enums\Files;

enum FileLifecycleStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Trashed = 'trashed';
    case Purged = 'purged';
}

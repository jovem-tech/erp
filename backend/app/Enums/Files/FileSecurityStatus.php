<?php

namespace App\Enums\Files;

enum FileSecurityStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Quarantined = 'quarantined';
    case Rejected = 'rejected';
}

<?php

namespace App\Enums\Files;

enum FileMigrationStatus: string
{
    case Native = 'native';
    case Legacy = 'legacy';
    case Cataloged = 'cataloged';
    case Migrating = 'migrating';
    case Migrated = 'migrated';
    case Failed = 'failed';
}

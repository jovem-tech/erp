<?php

namespace App\Enums\Files;

enum FileOrigin: string
{
    case Upload = 'upload';
    case Generated = 'generated';
    case Legacy = 'legacy';
    case Integration = 'integration';
}

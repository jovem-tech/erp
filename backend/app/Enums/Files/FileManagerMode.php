<?php

namespace App\Enums\Files;

enum FileManagerMode: string
{
    case Off = 'off';
    case Observe = 'observe';
    case Shadow = 'shadow';
    case Hybrid = 'hybrid';

    public function allowsCentralWrite(): bool
    {
        return $this === self::Hybrid;
    }

    public function observesLegacyFlow(): bool
    {
        return $this !== self::Off;
    }
}

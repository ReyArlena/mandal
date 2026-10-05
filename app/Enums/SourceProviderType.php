<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where app code comes from. GitHub only for now; others add a case here
 * and a class implementing App\Sources\SourceProvider.
 */
enum SourceProviderType: string implements HasLabel
{
    case GitHub = 'github';

    public function getLabel(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
        };
    }
}

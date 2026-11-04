<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Which builds of Mandal a hosted panel follows: its releases, or every
 * commit on the main branch as it lands.
 */
enum ReleaseChannel: string implements HasDescription, HasLabel
{
    case Stable = 'stable';
    case Development = 'development';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stable => 'Stable',
            self::Development => 'Development',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Stable => 'Releases: the newest version tag on GitHub. The default.',
            self::Development => 'Every commit on the main branch as it lands: unreleased changes, possibly broken ones.',
        };
    }

    /**
     * The branch the channel follows; releases are tags, not a branch.
     */
    public function branch(): ?string
    {
        return match ($this) {
            self::Stable => null,
            self::Development => 'main',
        };
    }
}

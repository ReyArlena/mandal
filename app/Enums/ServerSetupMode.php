<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ServerSetupMode: string implements HasDescription, HasLabel
{
    case Fresh = 'fresh';
    case Existing = 'existing';
    case Attach = 'attach';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fresh => 'Set up a new server',
            self::Existing => 'Connect to an existing Mandal server',
            self::Attach => 'Attach a server not set up by Mandal',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Fresh => 'A clean Ubuntu 24.04 or 26.04 server. Mandal creates its user, installs its CLI and provisions the stack.',
            self::Existing => 'A server another Mandal panel already manages. Only this panel\'s key is added; nothing is installed.',
            self::Attach => 'A server with an existing stack. Mandal installs its CLI only and shows what it finds.',
        };
    }
}

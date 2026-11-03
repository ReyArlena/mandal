<?php

namespace App\Filament\Clusters\Settings\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * The heading names the section (the tab above), not the sidebar page; the
 * page's own title stays in the browser tab and the sidebar.
 */
trait HasSettingsHeading
{
    public function getHeading(): string|Htmlable|null
    {
        return 'Settings';
    }
}

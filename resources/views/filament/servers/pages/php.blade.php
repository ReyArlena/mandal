<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">PHP versions could not be read</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="Installed" description="Each app chooses its own version; the CLI default applies to anyone running php on the server as another user.">
            @forelse ($this->installed as $version)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                    <span class="flex items-center gap-2">
                        <span class="font-medium">PHP {{ $version['version'] }}</span>
                        <span class="font-mono text-xs text-gray-500">{{ $version['package'] ?? '' }}</span>
                        @if ($version['default'] ?? false)
                            <x-filament::badge color="info">CLI default</x-filament::badge>
                        @endif
                    </span>
                    <span class="flex items-center gap-3 text-xs text-gray-500">
                        <span>{{ count($version['apps'] ?? []) === 0 ? 'No apps' : 'Used by ' . implode(', ', $version['apps']) }}</span>
                        @unless ($version['default'] ?? false)
                            {{ ($this->makeDefaultAction)(['version' => $version['version']]) }}
                            @if (count($version['apps'] ?? []) === 0)
                                {{ ($this->removeVersionAction)(['version' => $version['version']]) }}
                            @endif
                        @endunless
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-500">No PHP versions installed.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="Available" description="From the package source. Versions missing an extension Laravel needs are shown but cannot be installed yet.">
            <div class="flex flex-wrap gap-2">
                @foreach ($this->available as $version)
                    <x-filament::badge :color="($version['installed'] ?? false) ? 'success' : (($version['complete'] ?? false) ? 'gray' : 'warning')">
                        {{ $version['version'] }}{{ ($version['installed'] ?? false) ? ' (installed)' : (($version['complete'] ?? false) ? '' : ' (incomplete)') }}
                    </x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

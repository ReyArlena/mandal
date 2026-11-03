@php
    $releases = $this->releases();
    $archive = $this->archive();
@endphp

<x-filament-panels::page>
    <x-filament::section heading="Panel" description="Checked against the latest release on GitHub every hour.">
        <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
            <dt class="text-gray-500 dark:text-gray-400">Running</dt>
            <dd class="col-span-2 font-mono">{{ $archive->version() }} (build {{ $archive->hash() }})</dd>
            <dt class="text-gray-500 dark:text-gray-400">Latest release</dt>
            <dd class="col-span-2">
                @if ($releases->latest() === null)
                    <span class="text-gray-500">Not checked yet. Check now reads it from GitHub.</span>
                @elseif ($releases->available())
                    <span class="font-mono">{{ $releases->latest() }}</span>
                    <x-filament::badge color="info" class="ml-1">Available</x-filament::badge>
                @else
                    <span class="font-mono">{{ $releases->latest() }}</span>
                    <x-filament::badge color="success" class="ml-1">Current</x-filament::badge>
                @endif
            </dd>
        </dl>

        @if ($releases->available())
            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                @include('filament.settings.panel-update', [
                    'server' => $releases->hostingServer(),
                    'latest' => $releases->latest(),
                    'current' => $releases->current(),
                ])
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="CLI on servers" :description="'This panel ships mandal ' . $archive->version() . ' (build ' . $archive->hash() . '). Every connection records what a server runs.'">
        @forelse ($this->servers() as $server)
            @php
                [$state, $color] = $this->cliState($server);
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 text-sm last:border-0 dark:border-white/10">
                <div>
                    <a href="{{ \App\Filament\Resources\Servers\ServerResource::getUrl('view', ['record' => $server]) }}" class="font-medium hover:underline">{{ $server->name }}</a>
                    <span class="ml-2 text-gray-500">{{ $server->host }}</span>
                    @if ($server->cli_build !== null)
                        <span class="ml-2 font-mono text-xs text-gray-500">build {{ $server->cli_build }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$color">{{ $state }}</x-filament::badge>
                    @if ($state === 'Update required')
                        {{ ($this->updateCliAction)(['server' => $server->getKey()]) }}
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No servers yet.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

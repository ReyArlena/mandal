<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">Apps are unavailable</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            @if ($this->hasPendingTask())
                <p class="mb-4 text-sm text-gray-500" wire:poll.5s="loadApps">A task is running on this server; the list refreshes when it finishes.</p>
            @endif

            @forelse ($this->apps as $app)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 text-sm last:border-0 dark:border-white/10">
                    <div>
                        <a href="{{ $this->appUrl($app['name']) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $app['name'] }}</a>
                        <span class="ml-2 text-gray-500">{{ $app['domain'] ?? '' }}</span>
                        @if (! empty($app['aliases']))
                            <span class="ml-1 text-xs text-gray-400">+ {{ $app['aliases'] }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-3">
                        @if (($app['type'] ?? 'laravel') === 'docker')
                            <x-filament::badge color="info">Docker</x-filament::badge>
                            <span class="font-mono text-xs text-gray-500">{{ $app['image'] ?? '' }}</span>
                        @elseif (! empty($app['php']))
                            <x-filament::badge color="info">PHP {{ $app['php'] }}</x-filament::badge>
                        @endif
                        @if (! empty($app['repo']))
                            <span class="font-mono text-xs text-gray-500">{{ $app['repo'] }}@{{ $app['branch'] ?? 'main' }}</span>
                        @endif
                        @if (! empty($app['panel']))
                            <x-filament::badge color="warning">Panel</x-filament::badge>
                        @endif
                        @if (! empty($app['ssl']))
                            <x-filament::badge color="success">HTTPS</x-filament::badge>
                        @endif
                        @if (($app['type'] ?? 'laravel') === 'docker')
                            @php $running = collect($app['containers'] ?? [])->where('state', 'running')->count(); @endphp
                            <x-filament::badge :color="$running > 0 ? 'success' : 'gray'">{{ $running > 0 ? 'Running' : 'Stopped' }}</x-filament::badge>
                            {{ ($this->deployAppAction)(['name' => $app['name']])->label('Pull and restart')->link() }}
                        @else
                            @if (! empty($app['current_release']))
                                <x-filament::badge color="success">Deployed</x-filament::badge>
                            @else
                                <x-filament::badge color="gray">Not deployed</x-filament::badge>
                            @endif
                            @if (! empty($app['repo']))
                                {{ ($this->deployAppAction)(['name' => $app['name']])->link() }}
                            @endif
                        @endif
                        @if (empty($app['panel']))
                            {{ ($this->deleteAppAction)(['name' => $app['name']])->link() }}
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No apps yet. Use "New app" to create one.</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>

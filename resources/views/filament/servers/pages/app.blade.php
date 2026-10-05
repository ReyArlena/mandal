@php
    $app = $this->appData;
@endphp

<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">The app could not be read</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="Details">
                <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">Domain</dt>
                    <dd class="col-span-2">
                        <a href="{{ (empty($app['ssl']) ? 'http' : 'https') }}://{{ $app['domain'] ?? '' }}" target="_blank" rel="noopener" class="text-primary-600 underline dark:text-primary-400">{{ $app['domain'] ?? '' }}</a>
                        @if (! empty($app['aliases']))
                            <span class="ml-1 text-gray-500">+ {{ $app['aliases'] }}</span>
                        @endif
                        @if (! empty($app['ssl']))
                            <x-filament::badge color="success" class="ml-2">HTTPS</x-filament::badge>
                        @endif
                    </dd>
                    <dt class="text-gray-500 dark:text-gray-400">www</dt>
                    <dd class="col-span-2">{{ $app['www'] ?? 'redirect-to-naked' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">PHP</dt>
                    <dd class="col-span-2">{{ $app['php'] ?? '' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Repository</dt>
                    <dd class="col-span-2 font-mono">{{ ($app['repo'] ?? null) ?: 'none' }}@if (! empty($app['repo']))@{{ $app['branch'] ?? 'main' }} ({{ ($app['clone'] ?? 'ssh') === 'https' ? 'https' : 'deploy key' }})@endif</dd>
                    @if (! empty($app['repo']))
                        <dt class="text-gray-500 dark:text-gray-400">Auto deploy</dt>
                        <dd class="col-span-2">
                            @if ($webhook = $this->webhook())
                                <x-filament::badge color="success">On</x-filament::badge>
                                <span class="ml-1 text-xs text-gray-500">
                                    @if ($webhook->last_delivery_at)
                                        last delivery {{ $webhook->last_delivery_status }}, {{ $webhook->last_delivery_at->diffForHumans() }}
                                    @else
                                        waiting for the first push
                                    @endif
                                </span>
                            @else
                                <x-filament::badge color="gray">Off</x-filament::badge>
                            @endif
                        </dd>
                    @endif
                    <dt class="text-gray-500 dark:text-gray-400">Database</dt>
                    <dd class="col-span-2 font-mono">{{ ($app['database'] ?? null) ?: 'none' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">User / home</dt>
                    <dd class="col-span-2 font-mono">{{ $app['name'] }} / {{ $app['home'] ?? '' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Created</dt>
                    <dd class="col-span-2">{{ $app['created_at'] ?? '' }}</dd>
                </dl>
                @if (! empty($app['deploy_key']))
                    <div class="mt-4">
                        <p class="mb-1 text-xs font-medium uppercase text-gray-500">Deploy key (add to the repository as read-only)</p>
                        <pre class="overflow-x-auto rounded-lg bg-gray-950 p-3 font-mono text-xs text-gray-100 select-all">{{ $app['deploy_key'] }}</pre>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Releases" :description="($app['current_release'] ?? null) ? 'Current: ' . $app['current_release'] : 'Not deployed yet'">
                @forelse ($this->releases as $release)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <span class="flex items-center gap-2">
                            <span class="font-mono">{{ $release['release'] }}</span>
                            @if ($release['current'] ?? false)
                                <x-filament::badge color="success">Current</x-filament::badge>
                            @endif
                        </span>
                        <span class="flex items-center gap-3 text-xs text-gray-500">
                            @if (! empty($release['commit']))
                                <span class="font-mono">{{ substr($release['commit'], 0, 7) }}</span>
                            @endif
                            <span>{{ $release['branch'] ?? '' }}</span>
                            <span>{{ $release['deployed_at'] ?? '' }}</span>
                            @unless ($release['current'] ?? false)
                                {{ ($this->rollbackAppAction)(['name' => $app['name'], 'release' => $release['release']]) }}
                            @endunless
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No releases. Add the deploy key to the repository, then use Deploy.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Workers" description="Supervisor programs running artisan commands as the app user." class="lg:col-span-2">
                <div class="mb-3 flex gap-2">
                    {{ ($this->addWorkerAction)(['name' => $app['name']]) }}
                    @if ($this->workers !== [])
                        {{ ($this->restartWorkersAction)(['name' => $app['name']]) }}
                    @endif
                </div>
                @forelse ($this->workers as $worker)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <div>
                            <span class="font-medium">{{ $worker['worker'] }}</span>
                            <span class="ml-2 font-mono text-xs text-gray-500">{{ $worker['command'] }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-500">{{ $worker['numprocs'] }} process{{ $worker['numprocs'] == 1 ? '' : 'es' }}</span>
                            {{ ($this->removeWorkerAction)(['name' => $app['name'], 'worker' => $worker['worker']]) }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No workers. Queue jobs will not be processed until one is added.</p>
                @endforelse
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>

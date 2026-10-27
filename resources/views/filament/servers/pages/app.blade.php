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
                    @if ($this->hasDomain())
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
                    @if (! empty($app['panel']))
                        <dt class="text-gray-500 dark:text-gray-400">Role</dt>
                        <dd class="col-span-2"><x-filament::badge color="warning">Hosts this panel</x-filament::badge> <span class="ml-1 text-xs text-gray-500">It cannot be deleted from here, and the web firewall rules are protected.</span></dd>
                    @endif
                    <dt class="text-gray-500 dark:text-gray-400">www</dt>
                    <dd class="col-span-2">{{ $app['www'] ?? 'redirect-to-naked' }}</dd>
                    @endif
                    @if ($this->isDocker())
                        <dt class="text-gray-500 dark:text-gray-400">nginx</dt>
                        <dd class="col-span-2">
                            @if (! empty($app['proxy']))
                                Proxies the domain to <span class="font-mono">127.0.0.1:{{ $app['port'] ?? '' }}</span>
                            @else
                                <x-filament::badge color="gray">Not in front</x-filament::badge>
                                <span class="ml-1 text-xs text-gray-500">The compose file publishes its own ports.</span>
                            @endif
                        </dd>
                        <dt class="text-gray-500 dark:text-gray-400">Published ports</dt>
                        <dd class="col-span-2 font-mono">
                            @forelse ($app['ports'] ?? [] as $port)
                                <div>
                                    {{ $port['host_ip'] }}:{{ $port['published'] }} &rarr; {{ $port['target'] }}/{{ $port['protocol'] }}
                                    @if ($port['host_ip'] !== '127.0.0.1' && $port['host_ip'] !== '::1')
                                        <x-filament::badge color="warning" class="ml-1">public, bypasses ufw</x-filament::badge>
                                    @endif
                                </div>
                            @empty
                                <span class="font-sans text-gray-500">none</span>
                            @endforelse
                        </dd>
                        <dt class="text-gray-500 dark:text-gray-400">Project</dt>
                        <dd class="col-span-2 font-mono">{{ $app['home'] ?? '' }}</dd>
                    @else
                    <dt class="text-gray-500 dark:text-gray-400">PHP</dt>
                    <dd class="col-span-2">{{ $app['php'] ?? '' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Repository</dt>
                    <dd class="col-span-2 font-mono">{{ ($app['repo'] ?? null) ?: 'none' }}@if (! empty($app['repo'])){{ '@'.($app['branch'] ?? 'main') }} ({{ ($app['clone'] ?? 'ssh') === 'https' ? 'https' : 'deploy key' }})@endif</dd>
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
                    @endif
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

            @if ($this->isDocker())
            <x-filament::section heading="Containers">
                @forelse ($app['containers'] ?? [] as $container)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <span class="font-mono">{{ $container['name'] }}</span>
                        <span class="flex items-center gap-3 text-xs text-gray-500">
                            <span class="font-mono">{{ $container['image'] }}</span>
                            <x-filament::badge :color="($container['state'] ?? '') === 'running' ? 'success' : 'danger'">{{ $container['status'] ?? $container['state'] ?? '' }}</x-filament::badge>
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No containers are running for this app.</p>
                @endforelse
            </x-filament::section>
            @else
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

            @endif

            @php
                $backups = $this->backups;
                $lastBackup = $backups['last_run'] ?? null;
            @endphp
            <x-filament::section heading="Backups" :description="$this->backupSummary()" class="lg:col-span-2">
                <x-slot name="afterHeader">
                    <div class="flex items-center gap-3 text-xs text-gray-500">
                        @if ($lastBackup)
                            <span>
                                Last run {{ $lastBackup['at'] ?? '' }}
                                <x-filament::badge :color="($lastBackup['status'] ?? '') === 'ok' ? 'success' : 'danger'" class="ml-1">{{ $lastBackup['status'] ?? 'unknown' }}</x-filament::badge>
                                @if ($size = $this->lastBackupSize())
                                    <span class="ml-1">{{ $size }}</span>
                                @endif
                            </span>
                        @endif
                        <x-filament::badge :color="($backups['config']['enabled'] ?? false) ? 'success' : 'gray'">{{ ($backups['config']['enabled'] ?? false) ? 'Enabled' : 'Disabled' }}</x-filament::badge>
                    </div>
                </x-slot>
                @unless ($this->isDeployed())
                    <div class="mb-3 flex items-start gap-3 rounded-lg bg-warning-50 p-3 text-sm dark:bg-warning-400/10">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                        <p class="text-gray-700 dark:text-gray-300">{{ \App\Filament\Resources\Servers\Pages\ServerApp::NOT_DEPLOYED_NOTICE }}</p>
                    </div>
                @endunless
                <div class="mb-3 flex gap-2">
                    {{ ($this->backupSettingsAction)(['name' => $app['name']]) }}
                    @if ($this->backupsConfigured())
                        {{ ($this->backupNowAction)(['name' => $app['name']]) }}
                    @endif
                </div>
                @forelse ($backups['backups'] ?? [] as $file)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <span class="font-mono">{{ $file['file'] }}</span>
                        <span class="flex items-center gap-3 text-xs text-gray-500">
                            <span>{{ $this->bytes($file['size'] ?? 0) }}</span>
                            <span>{{ $file['modified'] ?? '' }}</span>
                            <x-filament::badge :color="($file['where'] ?? 'local') === 'local' ? 'gray' : 'info'">{{ $file['where'] ?? 'local' }}</x-filament::badge>
                            <a href="{{ $this->backupDownloadUrl($file['file']) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">
                                <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                                Download
                            </a>
                            {{ ($this->deleteAppBackupAction)(['name' => $app['name'], 'file' => $file['file'], 'where' => $file['where'] ?? 'local']) }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">
                        @if ($this->backupsConfigured())
                            No archives yet. The first one is made on schedule or with "Back up now".
                        @else
                            Not set up. Archives of the app's files, kept on the server and, optionally, in a destination from Settings.
                        @endif
                    </p>
                @endforelse
            </x-filament::section>

            @unless ($this->isDocker())
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
                            @unless (str_starts_with((string) ($worker['command'] ?? ''), 'schedule:') || str_contains((string) ($worker['command'] ?? ''), 'artisan schedule:'))
                                {{ ($this->restartWorkerAction)(['name' => $app['name'], 'worker' => $worker['worker']]) }}
                            @endunless
                            {{ ($this->removeWorkerAction)(['name' => $app['name'], 'worker' => $worker['worker']]) }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No workers. Queue jobs will not be processed until one is added.</p>
                @endforelse
            </x-filament::section>
            @endunless
        </div>
    @endif
</x-filament-panels::page>

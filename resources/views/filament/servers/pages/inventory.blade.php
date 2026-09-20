@php
    $system = $this->section('system');
    $mandal = $this->section('mandal');
    $services = $this->section('services');
    $php = $this->section('php');
    $nginx = $this->section('nginx');
    $database = $this->section('database');
    $cache = $this->section('cache');
    $js = $this->section('js');
    $supervisor = $this->section('supervisor');
    $certificates = $this->section('certificates');
    $firewall = $this->section('firewall');
    $fail2ban = $this->section('fail2ban');
    $docker = $this->section('docker');
    $apps = $this->section('apps');
@endphp

<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">Inventory is unavailable</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @elseif ($this->inventory !== null)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="System" :description="$system['os']['name'] ?? null">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">Host name</dt>
                    <dd class="font-mono">{{ $system['hostname'] ?? '' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Kernel</dt>
                    <dd class="font-mono">{{ $system['kernel'] ?? '' }} ({{ $system['arch'] ?? '' }})</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Uptime</dt>
                    <dd>{{ $this->duration($system['uptime_seconds'] ?? 0) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">CPUs</dt>
                    <dd>{{ $system['cpus'] ?? 0 }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Memory</dt>
                    <dd>{{ $this->bytes(($system['memory']['total_bytes'] ?? 0) - ($system['memory']['available_bytes'] ?? 0)) }} used of {{ $this->bytes($system['memory']['total_bytes'] ?? 0) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Disk</dt>
                    <dd>{{ $this->bytes($system['disk']['used_bytes'] ?? 0) }} used of {{ $this->bytes($system['disk']['total_bytes'] ?? 0) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Reboot</dt>
                    <dd>
                        @if ($system['reboot_required'] ?? false)
                            <x-filament::badge color="warning">Required</x-filament::badge>
                            @if (! empty($system['reboot_packages']))
                                <span class="ml-1 text-xs text-gray-500">{{ implode(', ', $system['reboot_packages']) }}</span>
                            @endif
                        @else
                            <x-filament::badge color="success">Not required</x-filament::badge>
                        @endif
                    </dd>
                    <dt class="text-gray-500 dark:text-gray-400">mandal</dt>
                    <dd>
                        <span class="font-mono">{{ $mandal['version'] ?? 'unknown' }}</span>
                        @if ($mandal['provisioned'] ?? false)
                            <x-filament::badge color="success" class="ml-1">Provisioned</x-filament::badge>
                        @else
                            <x-filament::badge color="gray" class="ml-1">Not provisioned</x-filament::badge>
                        @endif
                    </dd>
                </dl>
            </x-filament::section>

            <x-filament::section heading="Services">
                <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @forelse ($services as $service)
                        <li class="flex items-center justify-between py-2">
                            <span class="font-mono">{{ $service['unit'] }}</span>
                            @if (! ($service['installed'] ?? false))
                                <x-filament::badge color="gray">Not installed</x-filament::badge>
                            @elseif (($service['state'] ?? '') === 'active')
                                <x-filament::badge color="success">Active</x-filament::badge>
                            @else
                                <x-filament::badge color="danger">{{ $service['state'] ?: 'inactive' }}</x-filament::badge>
                            @endif
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">No services found.</li>
                    @endforelse
                </ul>
            </x-filament::section>

            <x-filament::section heading="Apps" :description="count($apps) . ' managed by Mandal'">
                @forelse ($apps as $app)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <div>
                            <span class="font-medium">{{ $app['name'] }}</span>
                            <span class="ml-2 text-gray-500">{{ $app['domain'] ?? '' }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-filament::badge color="gray">{{ $app['type'] ?? 'app' }}</x-filament::badge>
                            @if (! empty($app['php']))
                                <x-filament::badge color="info">PHP {{ $app['php'] }}</x-filament::badge>
                            @endif
                            @if (! empty($app['current_release']))
                                <span class="font-mono text-xs text-gray-500">{{ $app['current_release'] }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No apps yet.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="PHP" :description="($php['default'] ?? null) ? 'CLI default ' . $php['default'] : null">
                <div class="mb-3 flex flex-wrap gap-2">
                    @forelse ($php['versions'] ?? [] as $version)
                        <x-filament::badge color="info">{{ $version }}</x-filament::badge>
                    @empty
                        <span class="text-sm text-gray-500">PHP is not installed.</span>
                    @endforelse
                </div>
                @if (! empty($php['pools']))
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-gray-500">
                            <tr><th class="py-1 pr-3">Pool</th><th class="py-1 pr-3">PHP</th><th class="py-1 pr-3">User</th><th class="py-1">App</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($php['pools'] as $pool)
                                <tr>
                                    <td class="py-1 pr-3 font-mono">{{ $pool['name'] }}</td>
                                    <td class="py-1 pr-3">{{ $pool['php'] }}</td>
                                    <td class="py-1 pr-3 font-mono">{{ $pool['user'] }}</td>
                                    <td class="py-1">{{ $pool['managed_app'] ?? 'unmanaged' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>

            <x-filament::section heading="Sites (nginx)" :description="($nginx['installed'] ?? false) ? 'nginx ' . ($nginx['version'] ?? '') : 'nginx is not installed'">
                @forelse ($nginx['sites'] ?? [] as $site)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <div>
                            <span class="font-mono">{{ $site['name'] }}</span>
                            <span class="ml-2 text-gray-500">{{ implode(', ', $site['server_names'] ?? []) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($site['ssl'] ?? false)
                                <x-filament::badge color="success">SSL</x-filament::badge>
                            @endif
                            <x-filament::badge :color="($site['enabled'] ?? false) ? 'success' : 'gray'">{{ ($site['enabled'] ?? false) ? 'Enabled' : 'Disabled' }}</x-filament::badge>
                            <x-filament::badge :color="($site['managed_app'] ?? null) ? 'info' : 'gray'">{{ $site['managed_app'] ?? 'unmanaged' }}</x-filament::badge>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No sites found.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Database" :description="($database['installed'] ?? false) ? (($database['driver'] ?? '') . ' ' . ($database['version'] ?? '')) : 'Not installed'">
                <div class="flex flex-wrap gap-2 text-sm">
                    @forelse ($database['databases'] ?? [] as $name)
                        <x-filament::badge color="gray">{{ $name }}</x-filament::badge>
                    @empty
                        <span class="text-gray-500">No databases.</span>
                    @endforelse
                </div>
                @if (! empty($database['users']))
                    <p class="mt-3 text-xs text-gray-500">Users: {{ collect($database['users'])->map(fn ($u) => $u['user'] . '@' . $u['host'])->join(', ') }}</p>
                @endif
            </x-filament::section>

            <x-filament::section heading="Cache and tooling">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">Cache ({{ $cache['driver'] ?? '' }})</dt>
                    <dd>{{ ($cache['installed'] ?? false) ? (($cache['version'] ?: 'installed') . ', ' . ($cache['active'] ?? '')) : 'Not installed' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">JavaScript ({{ $js['driver'] ?? '' }})</dt>
                    <dd>{{ ($js['installed'] ?? false) ? ($js['version'] ?: 'installed') : 'Not installed' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Docker</dt>
                    <dd>{{ ($docker['installed'] ?? false) ? (($docker['version'] ?: 'installed') . ', ' . count($docker['containers'] ?? []) . ' containers') : 'Not installed' }}</dd>
                </dl>
            </x-filament::section>

            <x-filament::section heading="Workers (Supervisor)" :description="($supervisor['installed'] ?? false) ? null : 'Supervisor is not installed'">
                @forelse ($supervisor['programs'] ?? [] as $program)
                    <div class="border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <div class="flex items-center justify-between">
                            <span class="font-mono">{{ $program['name'] }}</span>
                            <span class="text-xs text-gray-500">{{ $program['numprocs'] }} x as {{ $program['user'] }}</span>
                        </div>
                        <div class="truncate font-mono text-xs text-gray-500">{{ $program['command'] }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No programs configured.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Certificates" :description="($certificates['installed'] ?? false) ? null : 'certbot is not installed'">
                @forelse ($certificates['certificates'] ?? [] as $certificate)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <span>{{ implode(', ', $certificate['domains'] ?? []) }}</span>
                        <span class="flex items-center gap-2">
                            <span class="text-xs text-gray-500">{{ $certificate['expiry'] }}</span>
                            <x-filament::badge :color="str_starts_with($certificate['status'] ?? '', 'VALID') ? 'success' : 'danger'">{{ $certificate['status'] }}</x-filament::badge>
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No certificates.</p>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Firewall (ufw)" :description="($firewall['installed'] ?? false) ? ucfirst($firewall['status'] ?? 'unknown') . ', incoming ' . ($firewall['default_incoming'] ?: 'unknown') : 'ufw is not installed'">
                <ul class="font-mono text-sm">
                    @forelse ($firewall['rules'] ?? [] as $rule)
                        <li class="py-0.5">{{ $rule }}</li>
                    @empty
                        <li class="font-sans text-gray-500">No rules.</li>
                    @endforelse
                </ul>
                @if (! empty($fail2ban['jails']))
                    <p class="mt-3 text-xs text-gray-500">fail2ban: {{ collect($fail2ban['jails'])->map(fn ($j) => $j['name'] . ' (' . $j['banned'] . ' banned)')->join(', ') }}</p>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>

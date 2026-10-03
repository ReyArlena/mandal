@php
    $status = $this->status;
    $pending = $status['pending'] ?? ['security' => [], 'other' => [], 'held' => []];
    $cliOutdated = $this->error === null && $this->cliOutdated();
@endphp

<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">Update status is unavailable</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="System" :description="$status['os']['name'] ?? null">
                <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">Reboot</dt>
                    <dd class="col-span-2">
                        @if ($status['reboot_required'] ?? false)
                            <x-filament::badge color="warning">Required</x-filament::badge>
                            @if (! empty($status['reboot_packages']))
                                <span class="ml-1 text-xs text-gray-500">{{ implode(', ', $status['reboot_packages']) }}</span>
                            @endif
                        @else
                            <x-filament::badge color="success">Not required</x-filament::badge>
                        @endif
                    </dd>
                    <dt class="text-gray-500 dark:text-gray-400">Kernel</dt>
                    <dd class="col-span-2 font-mono">
                        {{ $status['kernel']['running'] ?? '' }}
                        @if ($status['kernel']['newer_installed'] ?? false)
                            <span class="ml-1 font-sans text-xs text-warning-600">{{ $status['kernel']['newest'] }} installed, reboot to use it</span>
                        @endif
                    </dd>
                    <dt class="text-gray-500 dark:text-gray-400">Uptime</dt>
                    <dd class="col-span-2">{{ $this->duration($status['uptime_seconds'] ?? 0) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Automatic updates</dt>
                    <dd class="col-span-2">
                        @if (($status['unattended']['status'] ?? 'unknown') === 'ok')
                            <x-filament::badge color="success">OK</x-filament::badge>
                        @elseif (($status['unattended']['status'] ?? '') === 'failed')
                            <x-filament::badge color="danger">Failed</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">Not run yet</x-filament::badge>
                        @endif
                        <span class="ml-1 text-xs text-gray-500">{{ $status['unattended']['last'] ?? '' }}</span>
                    </dd>
                    @if (! empty($status['release_upgrade']))
                        <dt class="text-gray-500 dark:text-gray-400">Ubuntu</dt>
                        <dd class="col-span-2">Ubuntu {{ $status['release_upgrade'] }} is available. Release upgrades are done by hand with <code class="font-mono">do-release-upgrade</code>.</dd>
                    @endif
                </dl>
            </x-filament::section>

            <x-filament::section heading="Pending packages" :description="count($pending['security']) . ' security, ' . count($pending['other']) . ' other'">
                @forelse (array_merge($pending['security'], $pending['other']) as $package)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <span class="flex items-center gap-2">
                            <span class="font-mono">{{ $package['package'] }}</span>
                            @if ($package['security'] ?? false)
                                <x-filament::badge color="danger">security</x-filament::badge>
                            @endif
                        </span>
                        <span class="flex items-center gap-3 text-xs text-gray-500">
                            <span class="font-mono">{{ $package['current'] }} &rarr; {{ $package['candidate'] }}</span>
                            {{ ($this->updatePackageAction)(['package' => $package['package']]) }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Everything is up to date.</p>
                @endforelse
                @if (! empty($pending['held']))
                    <p class="mt-3 text-xs text-gray-500">Held back: {{ implode(', ', $pending['held']) }}</p>
                @endif
            </x-filament::section>

            <x-filament::section heading="Components" class="lg:col-span-2">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500">
                        <tr><th class="py-1 pr-3">Component</th><th class="py-1 pr-3">Installed</th><th class="py-1 pr-3">Latest</th><th class="py-1"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($status['components'] ?? [] as $component)
                            @php
                                $updatable = ($component['update_available'] ?? false) || ($component['id'] === 'mandal' && $cliOutdated);
                            @endphp
                            <tr>
                                <td class="py-2 pr-3">
                                    {{ $component['name'] }}
                                    @if (! empty($component['note']))
                                        <span class="block text-xs text-gray-500">{{ $component['note'] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 font-mono">{{ $component['installed'] ?: 'not installed' }}</td>
                                <td class="py-2 pr-3 font-mono">{{ $component['latest'] ?: ($component['id'] === 'mandal' ? app(\App\Mandal\CliArchive::class)->version() : '') }}</td>
                                <td class="py-2 text-right">
                                    @if ($updatable)
                                        {{ ($this->updateComponentAction)(['id' => $component['id'], 'name' => $component['name'], 'note' => $component['note'] ?? '']) }}
                                    @elseif ($component['latest'] ?? false)
                                        <x-filament::badge color="success">Current</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section heading="Recent package changes" class="lg:col-span-2">
                @forelse ($status['history'] ?? [] as $entry)
                    <div class="border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <span class="font-mono text-xs text-gray-500">{{ $entry['at'] }}</span>
                        <x-filament::badge color="gray" class="ml-1">{{ $entry['action'] }}</x-filament::badge>
                        <span class="ml-2 break-all text-xs">{{ \Illuminate\Support\Str::limit($entry['packages'], 300) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No package changes recorded.</p>
                @endforelse
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>

@php
    $fail2ban = $this->fail2ban;
@endphp

<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">fail2ban could not be read</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @elseif (! ($fail2ban['installed'] ?? false))
        <x-filament::section heading="fail2ban">
            <p class="text-sm text-gray-500">fail2ban is not installed. Provisioning again installs it and enables the sshd jail.</p>
        </x-filament::section>
    @else
        <x-filament::section heading="Jails" :description="'Each jail watches one service. Currently banned addresses are listed under it; bans expire on their own.'">
            <div class="mb-3">
                @if (($fail2ban['active'] ?? '') === 'active')
                    <x-filament::badge color="success">Active</x-filament::badge>
                @else
                    <x-filament::badge color="danger">{{ ucfirst($fail2ban['active'] ?: 'unknown') }}</x-filament::badge>
                @endif
            </div>
            @forelse ($fail2ban['jails'] ?? [] as $jail)
                <div class="border-b border-gray-200 py-3 text-sm first:pt-0 last:border-0 last:pb-0 dark:border-white/10">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-mono font-medium">{{ $jail['name'] }}</span>
                        <x-filament::badge :color="($jail['banned'] ?? 0) > 0 ? 'warning' : 'gray'">{{ $jail['banned'] ?? 0 }} banned</x-filament::badge>
                        <span class="text-xs text-gray-500">{{ $jail['failed'] ?? 0 }} failing now, {{ $jail['total_banned'] ?? 0 }} banned since start</span>
                    </div>
                    @if (! empty($jail['ips']))
                        <ul class="mt-2 divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($jail['ips'] as $ip)
                                <li class="flex items-center justify-between gap-3 py-1">
                                    <span class="font-mono">{{ $ip }}</span>
                                    {{ ($this->unbanAction)(['jail' => $jail['name'], 'ip' => $ip]) }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">No jails are enabled.</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>

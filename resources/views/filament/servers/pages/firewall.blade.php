@php
    $firewall = $this->firewall;
@endphp

<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">The firewall could not be read</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="ufw" :description="'Incoming ' . ($firewall['default_incoming'] ?: 'unknown') . ' by default, outgoing ' . ($firewall['default_outgoing'] ?: 'unknown')">
            <div class="mb-3">
                @if (($firewall['status'] ?? '') === 'active')
                    <x-filament::badge color="success">Active</x-filament::badge>
                @else
                    <x-filament::badge color="danger">{{ ucfirst($firewall['status'] ?? 'unknown') }}</x-filament::badge>
                @endif
            </div>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500">
                    <tr><th class="py-1 pr-3">#</th><th class="py-1 pr-3">To</th><th class="py-1 pr-3">Action</th><th class="py-1 pr-3">From</th><th class="py-1 pr-3">Comment</th><th class="py-1"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($firewall['rules'] ?? [] as $rule)
                        <tr>
                            <td class="py-2 pr-3 text-gray-500">{{ $rule['number'] }}</td>
                            <td class="py-2 pr-3 font-mono">{{ $rule['to'] }}@if ($rule['ipv6'] ?? false)<span class="ml-1 font-sans text-xs text-gray-400">v6</span>@endif</td>
                            <td class="py-2 pr-3"><x-filament::badge :color="str_starts_with($rule['action'], 'ALLOW') ? 'success' : 'danger'">{{ $rule['action'] }}</x-filament::badge></td>
                            <td class="py-2 pr-3 font-mono">{{ $rule['from'] }}</td>
                            <td class="py-2 pr-3 text-gray-500">{{ $rule['comment'] }}</td>
                            <td class="py-2 text-right">{{ ($this->deleteRuleAction)(['number' => $rule['number'], 'to' => $rule['to'], 'action' => $rule['action'], 'from' => $rule['from']]) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-2 text-gray-500">No rules.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Docker" description="Ports Docker publishes on every interface. They are reachable from the internet regardless of the rules above.">
            <div class="mb-3 flex items-start gap-3 rounded-lg bg-warning-50 p-3 text-sm dark:bg-warning-500/10">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <p class="text-gray-700 dark:text-gray-300">
                    Docker inserts its own iptables rules ahead of ufw, so a published container port is open even when no rule allows it, and a ufw rule cannot block it.
                    Bind a port to <span class="font-mono">127.0.0.1</span> in the compose file and put nginx in front unless it is meant to be public.
                </p>
            </div>
            @if (! ($firewall['docker']['active'] ?? false))
                <p class="text-sm text-gray-500">Docker is not running.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500">
                        <tr><th class="py-1 pr-3">Port</th><th class="py-1 pr-3">Container</th><th class="py-1 pr-3">Bound to</th><th class="py-1">In the container</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($firewall['docker']['exposed'] ?? [] as $port)
                            <tr>
                                <td class="py-2 pr-3 font-mono">{{ $port['port'] }}/{{ $port['protocol'] }}</td>
                                <td class="py-2 pr-3 font-mono">{{ $port['container'] }}</td>
                                <td class="py-2 pr-3 font-mono">{{ $port['host'] }}</td>
                                <td class="py-2 font-mono">{{ $port['target'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-gray-500">No container publishes a port past the firewall.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>

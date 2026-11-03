@php
    $sshd = $this->sshd;
    $own = $this->ownFingerprint();
    $yesNo = fn (?string $value): string => match ($value) {
        'yes' => 'Yes',
        'no' => 'No',
        null, '' => 'Unknown',
        default => $value,
    };
@endphp

<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">SSH could not be read</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section heading="sshd" description="Provisioning disables password and root logins and keeps key logins. These settings are read from the running sshd and changed on the server, not here.">
            @if (! ($sshd['installed'] ?? false))
                <p class="text-sm text-gray-500">sshd is not installed.</p>
            @else
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    @if (($sshd['active'] ?? '') === 'active')
                        <x-filament::badge color="success">Active</x-filament::badge>
                    @else
                        <x-filament::badge color="danger">{{ ucfirst($sshd['active'] ?: 'unknown') }}</x-filament::badge>
                    @endif
                    @if ($sshd['hardened'] ?? false)
                        <x-filament::badge color="success">Hardened by Mandal</x-filament::badge>
                    @else
                        <x-filament::badge color="warning">Not hardened</x-filament::badge>
                    @endif
                </div>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs uppercase text-gray-500">Port</dt><dd class="font-mono">{{ $sshd['port'] ?? 22 }}</dd></div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Password logins</dt>
                        <dd>
                            @if (($sshd['password_authentication'] ?? null) === 'yes')
                                <x-filament::badge color="warning">Allowed</x-filament::badge>
                            @else
                                {{ $yesNo($sshd['password_authentication'] ?? null) }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500">Root login</dt>
                        <dd>
                            @if (($sshd['permit_root_login'] ?? null) === 'yes')
                                <x-filament::badge color="warning">Allowed</x-filament::badge>
                            @else
                                {{ $yesNo($sshd['permit_root_login'] ?? null) }}
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-xs uppercase text-gray-500">Key logins</dt><dd>{{ $yesNo($sshd['pubkey_authentication'] ?? null) }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500">Attempts per connection</dt><dd>{{ $sshd['max_auth_tries'] ?? 'Unknown' }}</dd></div>
                </dl>
            @endif
        </x-filament::section>

        <x-filament::section heading="Keys" description="Keys allowed to run mandal on this server, from the mandal user's authorized_keys.">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500">
                    <tr><th class="py-1 pr-3">Label</th><th class="py-1 pr-3">Type</th><th class="py-1 pr-3">Fingerprint</th><th class="py-1"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($this->keys as $key)
                        <tr>
                            <td class="py-2 pr-3">
                                <span class="font-medium">{{ $key['comment'] !== '' ? $key['comment'] : 'Unlabelled' }}</span>
                                @if ($key['fingerprint'] === $own)
                                    <x-filament::badge color="info" class="ml-1">This panel</x-filament::badge>
                                @endif
                            </td>
                            <td class="py-2 pr-3 font-mono text-gray-500">{{ $key['type'] }}</td>
                            <td class="py-2 pr-3 font-mono text-xs">{{ $key['fingerprint'] }}</td>
                            <td class="py-2 text-right">
                                @if ($key['fingerprint'] !== $own)
                                    {{ ($this->removeKeyAction)(['fingerprint' => $key['fingerprint'], 'comment' => $key['comment']]) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-gray-500">No keys.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>

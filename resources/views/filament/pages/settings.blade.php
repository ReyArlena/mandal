<x-filament-panels::page>
    <x-filament::section heading="General">
        <x-slot name="afterHeader">
            {{ $this->publicUrlAction }}
        </x-slot>
        <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
            <dt class="text-gray-500 dark:text-gray-400">Public URL</dt>
            <dd class="col-span-2">
                @if ($this->publicUrl())
                    <span class="font-mono">{{ $this->publicUrl() }}</span>
                @else
                    <span class="text-gray-500">Not set. Automatic deploys on push are unavailable until the panel has a public address.</span>
                @endif
            </dd>
            <dt class="text-gray-500 dark:text-gray-400">Version</dt>
            <dd class="col-span-2 font-mono">{{ app(\App\Mandal\CliArchive::class)->version() }} (build {{ app(\App\Mandal\CliArchive::class)->hash() }})</dd>
        </dl>
    </x-filament::section>

    <x-filament::section heading="Source providers" description="Tokens let the panel add deploy keys and webhooks to your repositories.">
        <x-slot name="afterHeader">
            {{ $this->addGitHubAction }}
        </x-slot>
        @forelse ($this->credentials() as $credential)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 text-sm last:border-0 dark:border-white/10">
                <div>
                    <span class="font-medium">{{ $credential->provider->getLabel() }}</span>
                    <span class="ml-2 text-gray-500">{{ $credential->name }}{{ $credential->account ? ' (' . $credential->account . ')' : '' }}</span>
                </div>
                <div class="flex items-center gap-3">
                    @if ($credential->isExpired())
                        <x-filament::badge color="danger">Expired {{ $credential->expires_at->diffForHumans() }}</x-filament::badge>
                    @elseif ($credential->expiresSoon())
                        <x-filament::badge color="warning">Expires {{ $credential->expires_at->diffForHumans() }}</x-filament::badge>
                    @elseif ($credential->expires_at)
                        <span class="text-xs text-gray-500">Expires {{ $credential->expires_at->toDateString() }}</span>
                    @else
                        <span class="text-xs text-gray-500">No expiry</span>
                    @endif
                    {{ ($this->removeCredentialAction)(['id' => $credential->id]) }}
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No tokens yet. Add a GitHub token to let the panel add deploy keys and webhooks for you; without one, deploy keys are added by hand.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Backup destinations" description="Where database and app backups can be sent. Keys are stored encrypted and copied to a server only when a backup there uses the destination.">
        <x-slot name="afterHeader">
            {{ $this->addDestinationAction }}
        </x-slot>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 text-sm dark:border-white/10">
            <div>
                <span class="font-medium">Local disk</span>
                <span class="ml-2 text-gray-500">/var/backups/mandal on each server, always available</span>
            </div>
            <x-filament::badge color="gray">built in</x-filament::badge>
        </div>
        @forelse ($this->destinations() as $destination)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 text-sm last:border-0 dark:border-white/10">
                <div>
                    <span class="font-medium">{{ $destination->name }}</span>
                    <span class="ml-2 font-mono text-gray-500">{{ $destination->remotePath(null, '') }}</span>
                    <span class="block text-xs text-gray-500">{{ $destination->endpoint }}{{ $destination->region !== 'auto' ? ', ' . $destination->region : '' }}</span>
                </div>
                <div class="flex items-center gap-3">
                    {{ ($this->editDestinationAction)(['id' => $destination->id]) }}
                    {{ ($this->removeDestinationAction)(['id' => $destination->id]) }}
                </div>
            </div>
        @empty
            <p class="pt-3 text-sm text-gray-500">No S3 destinations yet. Add one to keep backups off the server too.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

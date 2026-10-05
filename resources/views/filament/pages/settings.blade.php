<x-filament-panels::page>
    <x-filament::section heading="General">
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
</x-filament-panels::page>

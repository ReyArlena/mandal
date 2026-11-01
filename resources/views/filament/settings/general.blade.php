<x-filament-panels::page>
    <x-filament::section>
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
</x-filament-panels::page>

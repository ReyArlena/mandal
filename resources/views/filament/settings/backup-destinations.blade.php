<x-filament-panels::page>
    <x-filament::section>
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

<x-filament-panels::page>
    <x-filament::actions :actions="$this->getToolbarActions()" />

    {{ $this->content }}

    @include('filament.servers.partials.inventory')
</x-filament-panels::page>

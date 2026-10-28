{{-- Databases and Backups share one server tab; these tabs move between them. --}}
@php
    use App\Filament\Resources\Servers\ServerResource;
@endphp

<x-filament::tabs>
    <x-filament::tabs.item tag="a" :href="ServerResource::getUrl('databases', ['record' => $server])" :active="$active === 'databases'" icon="heroicon-o-circle-stack">
        Databases
    </x-filament::tabs.item>
    <x-filament::tabs.item tag="a" :href="ServerResource::getUrl('backups', ['record' => $server])" :active="$active === 'backups'" icon="heroicon-o-archive-box">
        Backups
    </x-filament::tabs.item>
</x-filament::tabs>

{{-- The open server's sections as a tab bar above the page title. --}}
@php
    $items = \App\Filament\ServerNavigation::items();
@endphp

@if ($items)
    <x-filament::tabs contained label="Server sections" class="fi-server-tabs" style="scrollbar-width: none">
        @foreach ($items as $item)
            <x-filament::tabs.item tag="a" :href="$item->getUrl()" :active="$item->isActive()" :icon="$item->getIcon()">
                {{ $item->getLabel() }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
@endif

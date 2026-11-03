{{-- The open server's sections, or Servers and Settings, as a tab bar above the page title. --}}
@php
    $items = \App\Filament\ServerNavigation::items();
    $label = \App\Filament\ServerNavigation::currentServer() ? 'Server sections' : 'Panel sections';
@endphp

@if ($items)
    <x-filament::tabs contained :label="$label" class="fi-server-tabs" style="scrollbar-width: none">
        @foreach ($items as $item)
            <x-filament::tabs.item tag="a" :href="$item->getUrl()" :active="$item->isActive()" :icon="$item->getIcon()">
                {{ $item->getLabel() }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
@endif

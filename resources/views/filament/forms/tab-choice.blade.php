{{-- A choice between a few options shown as Filament tabs, like the server
     sections above the page title. The value is entangled live so fields
     that depend on it appear and disappear straight away. --}}
@php
    use Illuminate\Support\Js;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{ state: $wire.$entangle(@js($getStatePath())).live }">
        <x-filament::tabs contained :label="$getLabel()" class="fi-tab-choice">
            @foreach ($options as $value => $label)
                <x-filament::tabs.item
                    :alpine-active="'state === ' . Js::from($value)"
                    :icon="$icons[$value] ?? null"
                    x-on:click="state = {{ Js::from($value) }}"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </div>
</x-dynamic-component>

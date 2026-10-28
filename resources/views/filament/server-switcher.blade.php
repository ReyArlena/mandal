@php
    use App\Filament\Resources\Servers\ServerResource;
    use App\Filament\ServerNavigation;
    use Filament\Support\Icons\Heroicon;

    $current = ServerNavigation::currentServer();
    $servers = ServerNavigation::servers();
@endphp

<div class="flex min-w-0 items-center gap-x-3 sm:ms-3">
    <span class="hidden text-gray-300 sm:inline dark:text-gray-600" aria-hidden="true">/</span>

    <x-filament::dropdown placement="bottom-start" teleport>
        <x-slot name="trigger">
            <button
                type="button"
                class="flex items-center gap-x-1.5 rounded-lg px-2 py-1 text-sm font-medium text-gray-700 outline-none transition hover:bg-gray-50 focus-visible:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
            >
                @if ($current)
                    <x-filament::icon :icon="Heroicon::Server" class="h-4 w-4" :style="'color: var(--'.$current->statusColor().'-500)'" />
                    <span class="max-w-32 truncate sm:max-w-none">{{ $current->name }}</span>
                @else
                    <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">Choose a server</span>
                @endif
                <x-filament::icon :icon="Heroicon::ChevronUpDown" class="h-4 w-4 text-gray-400" />
            </button>
        </x-slot>

        @if ($servers->isNotEmpty())
            <x-filament::dropdown.list>
                @foreach ($servers as $server)
                    <x-filament::dropdown.list.item
                        :href="ServerNavigation::switchUrl($server)"
                        tag="a"
                        :icon="Heroicon::Server"
                        :icon-color="$server->statusColor()"
                        :color="$current?->is($server) ? 'primary' : 'gray'"
                        :tooltip="$server->status()"
                    >
                        {{ $server->name }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        @endif

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item :href="ServerResource::getUrl('index')" tag="a" :icon="Heroicon::OutlinedServerStack">
                All servers
            </x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item :href="ServerResource::getUrl('create')" tag="a" :icon="Heroicon::OutlinedPlus">
                Add server
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>

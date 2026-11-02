<x-filament-panels::page>
    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">Databases are unavailable</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section :heading="$this->engine()">
            @forelse ($this->databases() as $database)
                @php($users = $this->usersOf($database['name']))
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 text-sm last:border-0 dark:border-white/10">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono font-medium">{{ $database['name'] }}</span>
                        @if ($database['app'] ?? null)
                            <x-filament::badge color="info">app {{ $database['app'] }}</x-filament::badge>
                        @endif
                        <span class="text-xs text-gray-500">{{ $this->bytes($database['size'] ?? 0) }}, {{ $database['tables'] ?? 0 }} {{ Str::plural('table', $database['tables'] ?? 0) }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @forelse ($users as $user)
                            <x-filament::badge color="gray">{{ $user }}</x-filament::badge>
                        @empty
                            <span class="text-xs text-gray-500">No users</span>
                        @endforelse
                        {{ ($this->accessAction)(['name' => $database['name']]) }}
                        {{ ($this->importAction)(['name' => $database['name']]) }}
                        <x-filament::dropdown placement="bottom-end">
                            <x-slot name="trigger">
                                <x-filament::link tag="button" icon="heroicon-o-arrow-down-tray">Export</x-filament::link>
                            </x-slot>
                            <x-filament::dropdown.list>
                                <x-filament::dropdown.list.item tag="a" :href="$this->exportUrl($database['name'], 'sql')" icon="heroicon-o-document-text">
                                    .sql
                                </x-filament::dropdown.list.item>
                                <x-filament::dropdown.list.item tag="a" :href="$this->exportUrl($database['name'], 'zst')" icon="heroicon-o-archive-box">
                                    .sql.zst (compressed)
                                </x-filament::dropdown.list.item>
                            </x-filament::dropdown.list>
                        </x-filament::dropdown>
                        {{ ($this->backupDatabaseAction)(['name' => $database['name']]) }}
                        @unless ($database['app'] ?? null)
                            {{ ($this->deleteDatabaseAction)(['name' => $database['name']]) }}
                        @endunless
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No databases yet. Apps get one when they are created, or use "New database".</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>

@php
    use App\Backups\BackupTargetForm;

    $status = $this->status;
    $last = $status['last_run'] ?? null;
    $local = $status['local'] ?? [];
    $remote = $status['remote'] ?? null;
@endphp

<x-filament-panels::page>
    @include('filament.servers.partials.database-tabs', ['server' => $this->getRecord(), 'active' => 'backups'])

    @if ($this->error)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">Backups are unavailable</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @elseif (! ($status['configured'] ?? false))
        <x-filament::section heading="Backups are not set up yet" description="Every database on the server is included. Choose how often to keep copies on the server and, optionally, in a destination from Settings.">
            <p class="text-sm text-gray-500">Use "Set up backups" above to get started. Servers provisioned with this version start with a nightly local backup; older ones need their settings saved again after the update.</p>
        </x-filament::section>
    @else
        <x-filament::section heading="Schedule">
            <x-slot name="afterHeader">
                <x-filament::badge :color="($status['enabled'] ?? false) ? 'success' : 'gray'">{{ ($status['enabled'] ?? false) ? 'Enabled' : 'Disabled' }}</x-filament::badge>
            </x-slot>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                <dt class="text-gray-500 dark:text-gray-400">Local</dt>
                <dd>
                    @if ($local['enabled'] ?? false)
                        {{ ucfirst(BackupTargetForm::describeSchedule($local['schedule'] ?? null)) }}, keeps {{ $local['keep'] ?? 0 }}
                        <span class="block font-mono text-xs text-gray-500">{{ $local['schedule'] ?? '' }}</span>
                    @else
                        <x-filament::badge color="gray">Off</x-filament::badge>
                    @endif
                </dd>
                <dt class="text-gray-500 dark:text-gray-400">Remote</dt>
                <dd>
                    @if ($remote && ($remote['name'] ?? '') !== '')
                        {{ \App\Models\BackupDestination::query()->where('slug', $remote['name'])->value('name') ?? $remote['name'] }}: {{ BackupTargetForm::describeSchedule($remote['schedule'] ?? null) }}, keeps {{ $remote['keep'] ?? 0 }}
                        <span class="block font-mono text-xs text-gray-500">s3://{{ $remote['bucket'] ?? '' }}{{ ($remote['prefix'] ?? '') !== '' ? '/' . $remote['prefix'] : '' }}{{ ($remote['path'] ?? '') !== '' ? '/' . $remote['path'] : '' }}/db/</span>
                    @else
                        <x-filament::badge color="gray">None</x-filament::badge>
                    @endif
                </dd>
                <dt class="text-gray-500 dark:text-gray-400">Last run</dt>
                <dd class="sm:col-span-3">
                    @if ($last)
                        <x-filament::badge :color="($last['status'] ?? '') === 'ok' ? 'success' : 'danger'">{{ $last['status'] ?? 'unknown' }}</x-filament::badge>
                        <span class="ml-1 text-xs text-gray-500">{{ $last['at'] ?? '' }} {{ $last['rest'] ?? '' }}</span>
                    @else
                        <span class="text-gray-500">Never</span>
                    @endif
                </dd>
            </dl>
        </x-filament::section>

        @forelse ($this->backups as $entry)
            <x-filament::section :heading="$entry['database']" :description="count($entry['local']) . ' local, ' . count($entry['remote']) . ' remote'">
                @php
                    $files = collect($entry['local'])->map(fn ($f) => $f + ['where' => 'local'])
                        ->concat(collect($entry['remote'])->map(fn ($f) => $f + ['where' => 'remote']))
                        ->sortByDesc('file')
                        ->values();
                @endphp
                @forelse ($files as $file)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                        <span class="font-mono">{{ $file['file'] }}</span>
                        <span class="flex items-center gap-3 text-xs text-gray-500">
                            <span>{{ $this->bytes($file['size'] ?? 0) }}</span>
                            <span>{{ $file['modified'] ?? '' }}</span>
                            <x-filament::badge :color="$file['where'] === 'local' ? 'gray' : 'info'">{{ $file['where'] }}</x-filament::badge>
                            {{ ($this->restoreAction)(['database' => $entry['database'], 'file' => $file['file']]) }}
                            {{ ($this->deleteBackupAction)(['database' => $entry['database'], 'file' => $file['file'], 'where' => $file['where']]) }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No backups yet.</p>
                @endforelse
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-500">No databases to back up.</p>
            </x-filament::section>
        @endforelse
    @endif
</x-filament-panels::page>

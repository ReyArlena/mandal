@php
    $status = $this->status;
    $last = $status['last_run'] ?? null;
@endphp

<x-filament-panels::page>
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
        <x-filament::section heading="Backups are not set up yet" description="Choose a schedule and, optionally, an S3-compatible bucket. Every database on the server is included.">
            <p class="text-sm text-gray-500">Use "Set up backups" above to get started.</p>
        </x-filament::section>
    @else
        <x-filament::section heading="Schedule">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                <dt class="text-gray-500 dark:text-gray-400">Runs</dt>
                <dd class="font-mono">{{ $status['schedule'] }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">Keeps</dt>
                <dd>{{ $status['keep_local'] }} local, {{ $status['keep_remote'] }} remote</dd>
                <dt class="text-gray-500 dark:text-gray-400">Remote</dt>
                <dd>
                    @if ($status['remote']['enabled'] ?? false)
                        <span class="font-mono">{{ $status['remote']['bucket'] }}{{ ($status['remote']['path'] ?? '') !== '' ? '/' . $status['remote']['path'] : '' }}</span>
                        <span class="block text-xs text-gray-500">{{ $status['remote']['endpoint'] }}</span>
                    @else
                        <x-filament::badge color="gray">Local only</x-filament::badge>
                    @endif
                </dd>
                <dt class="text-gray-500 dark:text-gray-400">Last run</dt>
                <dd>
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
                        ->unique('file')
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

@php
    $task = $this->task();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div @if (! $task->isFinished()) wire:poll.2s @endif>
        <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
            <x-filament::badge :color="$task->status->getColor()">{{ $task->status->getLabel() }}</x-filament::badge>
            <span class="text-gray-500 dark:text-gray-400">
                Started {{ $task->started_at?->diffForHumans() ?? 'not yet' }}
                @if ($task->finished_at)
                    , finished {{ $task->finished_at->diffForHumans() }}
                @endif
                @if ($task->exit_code !== null)
                    , exit code {{ $task->exit_code }}
                @endif
            </span>
            @if (! $task->isFinished())
                <x-filament::loading-indicator class="h-4 w-4" />
            @endif
        </div>

        <pre
            class="max-h-[70vh] overflow-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100"
            @if (! $task->isFinished()) x-data x-init="$el.scrollTop = $el.scrollHeight" @endif
        >{{ $task->output !== '' ? $task->output : 'Waiting for output...' }}</pre>
        </div>
    </x-filament::section>
</x-filament-panels::page>

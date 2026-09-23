@php
    $tasks = $this->tasks();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div @if ($this->hasRunningTask()) wire:poll.5s @endif>
        @forelse ($tasks as $task)
            <a
                href="{{ \App\Filament\Resources\Servers\ServerResource::getUrl('task', ['record' => $this->getRecord(), 'task' => $task]) }}"
                class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 text-sm last:border-0 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5"
            >
                <span class="flex items-center gap-3">
                    <x-filament::badge :color="$task->status->getColor()">{{ $task->status->getLabel() }}</x-filament::badge>
                    <span class="font-medium">{{ $task->type->getLabel() }} #{{ $task->id }}</span>
                </span>
                <span class="text-gray-500 dark:text-gray-400">
                    {{ $task->user?->name ?? 'System' }},
                    {{ $task->created_at->diffForHumans() }}
                    @if ($task->finished_at && $task->started_at)
                        ({{ $task->started_at->diffInSeconds($task->finished_at) }}s)
                    @endif
                </span>
            </a>
        @empty
            <p class="text-sm text-gray-500">No tasks have run on this server yet.</p>
        @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>

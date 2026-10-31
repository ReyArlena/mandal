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
            {{-- Pushed right with an inline style, so it does not depend on
                 which utility classes the theme build kept. --}}
            <div class="flex items-center gap-2" style="margin-inline-start: auto">
                @if ($logsUrl = $this->containerLogsUrl())
                    <x-filament::button tag="a" :href="$logsUrl" size="xs" icon="heroicon-o-document-text">
                        Follow container logs
                    </x-filament::button>
                @endif
                <x-filament::button
                    size="xs"
                    color="gray"
                    icon="heroicon-o-clipboard-document"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText(document.getElementById('task-output').innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                >
                    <span x-text="copied ? 'Copied' : 'Copy output'">Copy output</span>
                </x-filament::button>
            </div>
        </div>

        {{-- The output box keeps one height and follows new lines while the
             reader is at the bottom. Scrolling up stops following and shows
             a button to catch up again. --}}
        <div
            class="relative"
            x-data="{
                following: true,
                atBottom() { return this.$refs.output.scrollHeight - this.$refs.output.scrollTop - this.$refs.output.clientHeight < 8 },
                jump() { this.$refs.output.scrollTop = this.$refs.output.scrollHeight; this.following = true },
                init() {
                    this.jump();
                    new MutationObserver(() => { if (this.following) this.jump() })
                        .observe(this.$refs.output, { childList: true, characterData: true, subtree: true });
                },
            }"
        >
            <pre
                id="task-output"
                x-ref="output"
                x-on:scroll="following = atBottom()"
                class="h-[70vh] overflow-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100"
            >{{ $task->output !== '' ? \App\Support\Terminal::plain($task->output) : 'Waiting for output...' }}</pre>
            <button
                type="button"
                x-show="! following"
                x-cloak
                x-on:click="jump()"
                class="absolute bottom-4 right-6 rounded-full bg-primary-600 px-3 py-1.5 text-xs font-medium text-white shadow-lg hover:bg-primary-500"
            >Jump to present &darr;</button>
        </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>

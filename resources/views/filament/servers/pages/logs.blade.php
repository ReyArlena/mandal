<x-filament-panels::page>
    @if ($this->error && $this->sources === [])
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium">Logs are unavailable</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $this->error }}</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-4">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="source">
                            @foreach ($this->groupedSources() as $group => $sources)
                                <optgroup label="{{ $group }}">
                                    @foreach ($sources as $item)
                                        <option value="{{ $item['id'] }}">{{ $item['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div class="md:col-span-3">
                    <x-filament::input.wrapper>
                        <x-filament::input type="search" wire:model.live.debounce.500ms="search" placeholder="Filter text" />
                    </x-filament::input.wrapper>
                </div>
                <div class="md:col-span-2">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="lines">
                            @foreach (\App\Filament\Resources\Servers\Pages\ServerLogs::LINE_OPTIONS as $option)
                                <option value="{{ $option }}">{{ $option }} lines</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div class="md:col-span-2">
                    @if ($this->isLaravel())
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="level">
                                @foreach (\App\Filament\Resources\Servers\Pages\ServerLogs::LEVELS as $option)
                                    <option value="{{ $option }}">{{ $option === '' ? 'Any level' : ucfirst($option) }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @elseif ($this->filtersBySince())
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="since">
                                @foreach (\App\Filament\Resources\Servers\Pages\ServerLogs::SINCE_OPTIONS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif
                </div>
                <div class="flex items-center gap-2 md:col-span-1">
                    <x-filament::button size="sm" :color="$this->live ? 'success' : 'gray'" wire:click="toggleLive" :icon="$this->live ? 'heroicon-m-pause' : 'heroicon-m-play'">
                        {{ $this->live ? 'Live' : 'Tail' }}
                    </x-filament::button>
                </div>
            </div>

            @if ($this->error)
                <p class="mt-3 text-sm text-danger-600 dark:text-danger-400">{{ $this->error }}</p>
            @endif

            <div class="mt-4" @if ($this->live) wire:poll.3s="poll" @endif>
                @if (($this->result['exists'] ?? true) === false)
                    <p class="text-sm text-gray-500">This log does not exist yet on the server.</p>
                @elseif ($this->isLaravel())
                    @forelse ($this->result['entries'] ?? [] as $entry)
                        <details class="border-b border-gray-200 py-2 text-sm last:border-0 dark:border-white/10">
                            <summary class="flex cursor-pointer items-start gap-3">
                                <span class="shrink-0 font-mono text-xs text-gray-500">{{ $entry['at'] ?? '' }}</span>
                                <x-filament::badge :color="match (strtolower($entry['level'] ?? '')) { 'emergency', 'alert', 'critical', 'error' => 'danger', 'warning' => 'warning', 'notice', 'info' => 'info', default => 'gray' }">{{ strtolower($entry['level'] ?? '') }}</x-filament::badge>
                                <span class="min-w-0 break-words">{{ \Illuminate\Support\Str::limit($entry['message'] ?? '', 300) }}</span>
                            </summary>
                            <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-gray-950 p-3 font-mono text-xs text-gray-100">{{ $entry['text'] ?? '' }}</pre>
                        </details>
                    @empty
                        <p class="text-sm text-gray-500">No entries match.</p>
                    @endforelse
                @else
                    <pre class="max-h-[70vh] overflow-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100"
                        x-data x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">@forelse ($this->result['lines'] ?? [] as $line){{ $line }}
@empty{{ 'No lines match.' }}@endforelse</pre>
                @endif
                @if ($this->result['truncated'] ?? false)
                    <p class="mt-2 text-xs text-gray-500">Output was cut at 512 KB; narrow it with a filter.</p>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

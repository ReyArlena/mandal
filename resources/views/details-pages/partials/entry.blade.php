{{-- One game server's card on a details page. --}}
@php
    use App\Enums\GameStatus;

    $status = $entry->showsStatus() ? $entry->currentStatus() : null;
    $style = $status ? $styles[$status->value] : null;
@endphp

<section @class([
    'rounded-2xl border bg-ctp-base/80 p-5 shadow-xl shadow-black/20 backdrop-blur sm:p-6',
    $style['card'] ?? 'border-ctp-surface0',
])>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="flex items-center gap-3 text-lg font-semibold text-ctp-text">
                @if ($status)
                    <span class="relative flex size-4 shrink-0 items-center justify-center {{ $style['icon'] }}">
                        @if ($status === GameStatus::Online)
                            <span class="absolute inline-flex size-3 animate-ping rounded-full bg-ctp-green opacity-40"></span>
                            @svg('phosphor-circle-fill', 'relative size-3')
                        @elseif ($status === GameStatus::Paused)
                            @svg('phosphor-circle-fill', 'size-3')
                        @else
                            @svg('phosphor-circle-dashed', 'size-4')
                        @endif
                    </span>
                @endif
                <span class="truncate">{{ $entry->name }}</span>
            </h2>

            @if (filled($entry->version))
                <p @class(['mt-1 text-sm text-ctp-subtext0', 'pl-7' => $status])>Version {{ $entry->version }}</p>
            @endif
        </div>

        @if ($status)
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $style['pill'] }}">
                @svg(match ($status) { GameStatus::Online => 'phosphor-circle-fill', GameStatus::Paused => 'phosphor-pause-fill', GameStatus::Unknown => 'phosphor-circle-dashed' }, $status === GameStatus::Online ? 'size-2' : 'size-3')
                {{ $status->getLabel() }}
            </span>
        @endif
    </div>

    <dl class="mt-4 divide-y divide-ctp-surface0 overflow-hidden rounded-xl border border-ctp-surface0 bg-ctp-mantle">
        @foreach (['Address' => $entry->address, 'Password' => $entry->password] as $label => $value)
            @continue (blank($value))
            <div class="flex items-center gap-3 px-4 py-3">
                <dt class="w-20 shrink-0 text-xs font-medium uppercase tracking-wider text-ctp-overlay1">{{ $label }}</dt>
                <dd class="min-w-0 flex-1 break-all font-mono text-sm text-ctp-text">{{ $value }}</dd>
                <button type="button" data-copy="{{ $value }}" title="Copy {{ strtolower($label) }}" aria-label="Copy {{ strtolower($label) }}"
                    class="group shrink-0 rounded-lg p-1.5 text-ctp-overlay1 transition hover:bg-ctp-surface0 hover:text-ctp-text focus-visible:outline-2 focus-visible:outline-ctp-red">
                    <svg data-icon="copy" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
                    <svg data-icon="done" class="hidden size-4 text-ctp-green" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </button>
            </div>
        @endforeach
    </dl>
</section>

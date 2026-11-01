@php
    use App\Enums\GameStatus;

    // Online first, then unknown, then those without a status; paused ones
    // are folded away below. Each group keeps the order set in the panel.
    $statusOf = fn ($entry) => $entry->showsStatus() ? $entry->currentStatus() : null;
    $paused = $page->entries->filter(fn ($entry) => $statusOf($entry) === GameStatus::Paused)->values();
    $shown = $page->entries
        ->reject(fn ($entry) => $statusOf($entry) === GameStatus::Paused)
        ->sortBy(fn ($entry) => match ($statusOf($entry)) {
            GameStatus::Online => 0,
            GameStatus::Unknown => 1,
            default => 2,
        })
        ->values();

    $styles = [
        GameStatus::Online->value => ['icon' => 'text-ctp-green', 'pill' => 'bg-ctp-green/10 text-ctp-green ring-ctp-green/25', 'card' => 'border-ctp-green/20'],
        GameStatus::Paused->value => ['icon' => 'text-ctp-peach', 'pill' => 'bg-ctp-peach/10 text-ctp-peach ring-ctp-peach/25', 'card' => 'border-ctp-surface0'],
        GameStatus::Unknown->value => ['icon' => 'text-ctp-overlay0', 'pill' => 'bg-ctp-surface0/60 text-ctp-subtext0 ring-ctp-surface1', 'card' => 'border-ctp-surface0'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="bg-ctp-crust">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $page->title ?: $page->name }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap">
    @vite('resources/css/details-page.css')
</head>
<body class="relative flex min-h-screen flex-col bg-ctp-crust font-sans text-ctp-text antialiased selection:bg-ctp-red/30">
    {{-- A soft glow behind the header --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-96 overflow-hidden">
        <div class="absolute left-1/2 top-[-12rem] h-[28rem] w-[48rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-ctp-red/15 via-ctp-mauve/10 to-ctp-red/5 blur-3xl"></div>
    </div>

    {{-- my-auto centres the page vertically when it is shorter than the window. --}}
    <main class="relative mx-auto my-auto w-full max-w-2xl px-4 py-12 sm:py-20">
        @if (filled($page->title) || filled($page->intro))
            <header>
                @if (filled($page->title))
                    <h1 class="text-3xl font-semibold tracking-tight text-ctp-text sm:text-4xl">{{ $page->title }}</h1>
                @endif

                @if (filled($page->intro))
                    <p @class(['whitespace-pre-line border-l-2 border-ctp-surface1 pl-4 text-ctp-subtext0', 'mt-4' => filled($page->title)])>{{ $page->intro }}</p>
                @endif
            </header>
        @endif

        @if ($page->entries->isEmpty())
            <p @class(['rounded-2xl border border-dashed border-ctp-surface1 p-8 text-center text-ctp-subtext0', 'mt-10' => filled($page->title) || filled($page->intro)])>No servers listed yet.</p>
        @else
            @if ($shown->isNotEmpty())
                <div @class(['space-y-4', 'mt-10' => filled($page->title) || filled($page->intro)])>
                    @foreach ($shown as $entry)
                        @include('details-pages.partials.entry', ['entry' => $entry, 'styles' => $styles])
                    @endforeach
                </div>
            @endif

            @if ($paused->isNotEmpty())
                {{-- A checkbox rather than <details>, so the toggle can sit below the servers it opens. --}}
                <div @class(['mt-4' => $shown->isNotEmpty(), 'mt-10' => $shown->isEmpty() && (filled($page->title) || filled($page->intro))])>
                    <input type="checkbox" id="paused-servers" class="peer sr-only" @checked($shown->isEmpty())>

                    <div class="hidden space-y-4 peer-checked:block">
                        @foreach ($paused as $entry)
                            @include('details-pages.partials.entry', ['entry' => $entry, 'styles' => $styles])
                        @endforeach
                    </div>

                    <label for="paused-servers" class="flex cursor-pointer items-center justify-center gap-1.5 rounded-md text-sm text-ctp-overlay1 transition select-none mt-2 peer-checked:mt-6 peer-focus-visible:outline-2 peer-focus-visible:outline-ctp-red hover:text-ctp-text">
                        <span class="[.peer:checked~label_&]:hidden">Show paused servers ({{ $paused->count() }})</span>
                        <span class="hidden [.peer:checked~label_&]:inline">Hide paused servers</span>
                        @svg('phosphor-caret-down', 'size-3.5 transition [.peer:checked~label_&]:rotate-180')
                    </label>
                </div>
            @endif
        @endif

        @if (! $page->hide_branding || ! $page->hide_credit)
            <footer class="mt-16 flex flex-col items-center gap-5 text-center text-xs text-ctp-overlay0">
                <div aria-hidden="true" class="mb-3 h-px w-16 bg-ctp-surface1/60"></div>

                @unless ($page->hide_branding)
                    <a href="https://github.com/ReyArlena/mandal" target="_blank" rel="noopener noreferrer" aria-label="Mandal on GitHub" class="h-4 text-ctp-overlay1/40 transition hover:text-ctp-red">
                        {!! str_replace(['<svg ', 'fill="white"'], ['<svg style="height: 100%; width: auto" ', 'fill="currentColor"'], file_get_contents(public_path('logo.svg'))) !!}
                    </a>
                @endunless

                @unless ($page->hide_credit)
                    <p class="inline-flex items-center gap-1.5">
                        Made with @svg('phosphor-heart-fill', 'size-3.5') by
                        <a href="https://github.com/ReyArlena" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 transition hover:text-ctp-red">
                            @svg('phosphor-github-logo-fill', 'size-3.5') ReyArlena
                        </a>
                    </p>
                @endunless
            </footer>
        @endif
    </main>

    <script>
        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-copy]');

            if (! button) {
                return;
            }

            try {
                await navigator.clipboard.writeText(button.dataset.copy);
            } catch {
                return;
            }

            button.querySelector('[data-icon=copy]').classList.add('hidden');
            button.querySelector('[data-icon=done]').classList.remove('hidden');

            setTimeout(() => {
                button.querySelector('[data-icon=copy]').classList.remove('hidden');
                button.querySelector('[data-icon=done]').classList.add('hidden');
            }, 1500);
        });
    </script>
</body>
</html>

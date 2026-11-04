@php
    use App\Enums\ReleaseChannel;

    $releases = $this->releases();
    $archive = $this->archive();
    $channel = $releases->channel();
    $deployed = $releases->deployed();
    $update = $releases->pending();
    $host = $this->hostingServer();
@endphp

<x-filament-panels::page>
    <x-filament::section heading="Panel" description="Checked against GitHub every hour.">
        <dl class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
            <dt class="text-gray-500 dark:text-gray-400">Release channel</dt>
            <dd class="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="font-medium">{{ $channel->getLabel() }}</span>
                <span class="text-gray-500">{{ $channel->getDescription() }}</span>
                {{ $this->releaseChannelAction }}
            </dd>
            <dt class="text-gray-500 dark:text-gray-400">Running</dt>
            <dd class="col-span-2">
                <span class="font-mono">{{ $archive->version() }} (build {{ $archive->hash() }})</span>
                @if ($deployed === null)
                    <span class="ml-1 text-gray-500">from a checkout, not a deploy</span>
                @elseif (\App\Mandal\PanelReleases::isTag($deployed['ref']))
                    <span class="ml-1 text-gray-500">release <span class="font-mono">{{ $deployed['ref'] }}</span></span>
                @else
                    <span class="ml-1 text-gray-500">development build <span class="font-mono">{{ $deployed['ref'].'@'.substr($deployed['commit'], 0, 7) }}</span></span>
                @endif
                @if ($deployed !== null && $deployed['deployed_at'] !== null && ($at = rescue(fn () => \Illuminate\Support\Carbon::parse($deployed['deployed_at']), report: false)) !== null)
                    <span class="text-gray-500" title="{{ $at->toDayDateTimeString() }}">, deployed {{ $at->diffForHumans() }}</span>
                @endif
            </dd>
            <dt class="text-gray-500 dark:text-gray-400">Latest release</dt>
            <dd class="col-span-2">
                @if ($releases->latest() === null)
                    <span class="text-gray-500">Not checked yet. Check now reads it from GitHub.</span>
                @else
                    <span class="font-mono">{{ $releases->latest() }}</span>
                    @if ($channel === ReleaseChannel::Stable)
                        @if ($update !== null)
                            <x-filament::badge color="info" class="ml-1">Available</x-filament::badge>
                        @else
                            <x-filament::badge color="success" class="ml-1">Current</x-filament::badge>
                        @endif
                    @endif
                @endif
            </dd>
            @if ($channel === ReleaseChannel::Development)
                <dt class="text-gray-500 dark:text-gray-400">Latest development build</dt>
                <dd class="col-span-2">
                    @if ($releases->latestCommit() === null)
                        <span class="text-gray-500">Not checked yet. Check now reads the {{ $channel->branch() }} branch from GitHub.</span>
                    @else
                        <span class="font-mono">{{ $channel->branch().'@'.substr($releases->latestCommit(), 0, 7) }}</span>
                        @if ($update !== null)
                            <x-filament::badge color="info" class="ml-1">Available</x-filament::badge>
                        @elseif ($deployed !== null)
                            <x-filament::badge color="success" class="ml-1">Current</x-filament::badge>
                        @endif
                    @endif
                </dd>
            @endif
        </dl>

        @if ($update !== null)
            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                @if ($host !== null)
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p>
                            <span class="font-medium">{{ $update->label() }}</span> is available; this panel runs
                            <span class="font-mono">{{ $releases->running() }}</span>.
                            The update runs as a task on {{ $host->name }}; the panel is unavailable for a few seconds while it switches over.
                        </p>
                        {{ $this->updatePanelAction }}
                    </div>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        Or, signed in to {{ $host->name }} as the <code class="font-mono">{{ $host->ssh_user ?? 'mandal' }}</code> user, run
                        <code class="font-mono select-all">{{ $update->command() }}</code>.
                    </p>
                @else
                    @include('filament.settings.panel-update', ['update' => $update, 'running' => $releases->running()])
                @endif
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="CLI on servers" :description="'This panel ships mandal ' . $archive->version() . ' (build ' . $archive->hash() . '). Every connection records what a server runs.'">
        @forelse ($this->servers() as $server)
            @php
                [$state, $color] = $this->cliState($server);
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-3 text-sm last:border-0 dark:border-white/10">
                <div>
                    <a href="{{ \App\Filament\Resources\Servers\ServerResource::getUrl('view', ['record' => $server]) }}" class="font-medium hover:underline">{{ $server->name }}</a>
                    <span class="ml-2 text-gray-500">{{ $server->host }}</span>
                    @if ($server->cli_build !== null)
                        <span class="ml-2 font-mono text-xs text-gray-500">build {{ $server->cli_build }}</span>
                    @endif
                    @if ($server->panel_app !== null)
                        <span class="ml-2 text-xs text-gray-500">hosts this panel</span>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$color">{{ $state }}</x-filament::badge>
                    @if ($state === 'Update required')
                        {{ ($this->updateCliAction)(['server' => $server->getKey()]) }}
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No servers yet.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

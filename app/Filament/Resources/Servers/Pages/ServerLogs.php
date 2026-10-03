<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Ssh\SshException;
use BackedEnum;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Reads server and app logs through the CLI's named sources.
 */
class ServerLogs extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServerResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $title = 'Logs';

    protected string $view = 'filament.servers.pages.logs';

    public const array LINE_OPTIONS = [100, 200, 500, 1000, 2000];

    public const array LEVELS = ['', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public const array SINCE_OPTIONS = ['' => 'Any time', '15m' => 'Last 15 minutes', '1h' => 'Last hour', '6h' => 'Last 6 hours', '1d' => 'Last day', '7d' => 'Last week'];

    /** @var list<array<string, mixed>> */
    public array $sources = [];

    #[Url]
    public string $source = '';

    #[Url]
    public string $app = '';

    #[Url]
    public int $lines = 200;

    #[Url]
    public string $search = '';

    #[Url]
    public string $level = '';

    #[Url]
    public string $since = '';

    public bool $live = false;

    /** @var array<string, mixed> */
    public array $result = [];

    public ?string $error = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->loadSources();

        if ($this->source === '' && $this->sources !== []) {
            $this->source = (string) $this->sources[0]['id'];
        }

        if ($this->source !== '') {
            $this->read();
        }
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = $this->getRecord();

        return $server;
    }

    public function loadSources(): void
    {
        $this->error = null;
        $server = $this->server();

        if ($server->connected_at === null) {
            $this->error = 'This server has not been connected yet.';

            return;
        }

        try {
            $result = app(MandalClient::class)->run($server, 'logs:sources', timeoutSeconds: 30);
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->sources = $result->successful() ? $result->json() : [];

        if ($this->app !== '' && preg_match('/^[a-z][a-z0-9-]{1,31}$/', $this->app)) {
            $this->sources = array_values(array_filter($this->sources, fn (array $source): bool => ($source['app'] ?? '') === $this->app));
        }
    }

    public function updatedSource(): void
    {
        $this->result = [];
        $this->read();
    }

    public function updatedLines(): void
    {
        $this->read();
    }

    public function updatedSearch(): void
    {
        $this->read();
    }

    public function updatedLevel(): void
    {
        $this->read();
    }

    public function updatedSince(): void
    {
        $this->read();
    }

    public function toggleLive(): void
    {
        $this->live = ! $this->live;

        if ($this->live) {
            $this->read();
        }
    }

    /**
     * Fetch lines written since the last read and append them.
     */
    public function poll(): void
    {
        if (! $this->live || $this->source === '' || empty($this->result['cursor'])) {
            return;
        }

        $fresh = $this->fetch(after: (string) $this->result['cursor']);

        if ($fresh === null) {
            return;
        }

        $key = ($fresh['kind'] ?? '') === 'laravel' ? 'entries' : 'lines';
        $merged = array_merge($this->result[$key] ?? [], $fresh[$key] ?? []);
        $this->result = $fresh + ['cursor' => $fresh['cursor']];
        $this->result[$key] = array_slice($merged, -$this->lines);
    }

    public function read(): void
    {
        $result = $this->fetch();

        if ($result !== null) {
            $this->result = $result;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(?string $after = null): ?array
    {
        $this->error = null;

        if (! $this->isKnownSource($this->source)) {
            $this->error = 'Unknown log source.';

            return null;
        }

        $this->lines = in_array($this->lines, self::LINE_OPTIONS, true) ? $this->lines : 200;
        $this->level = in_array($this->level, self::LEVELS, true) ? $this->level : '';
        $this->since = array_key_exists($this->since, self::SINCE_OPTIONS) ? $this->since : '';
        $this->search = mb_substr(str_replace(["\n", "\r"], ' ', $this->search), 0, 200);

        $arguments = ['logs:read', '--source='.$this->source, '--lines='.$this->lines];

        if ($this->search !== '') {
            $arguments[] = '--grep='.escapeshellarg($this->search);
        }

        if ($this->level !== '') {
            $arguments[] = '--level='.$this->level;
        }

        if ($this->since !== '') {
            $arguments[] = '--since='.$this->since;
        }

        if ($after !== null && $after !== '' && preg_match('/^[A-Za-z0-9=;:_.-]{1,200}$/', $after)) {
            $arguments[] = '--after='.escapeshellarg($after);
        }

        try {
            $result = app(MandalClient::class)->run($this->server(), implode(' ', $arguments), timeoutSeconds: 60);
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();

            return null;
        }

        if ($result->failed()) {
            $this->error = trim($result->errorOutput ?: $result->output);

            return null;
        }

        return $result->json();
    }

    private function isKnownSource(string $id): bool
    {
        foreach ($this->sources as $source) {
            if (($source['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function groupedSources(): array
    {
        $groups = [];

        foreach ($this->sources as $source) {
            $group = ($source['scope'] ?? 'server') === 'app' ? 'App: '.$source['app'] : 'Server';
            $groups[$group][] = $source;
        }

        ksort($groups);

        return $groups;
    }

    public function isLaravel(): bool
    {
        return ($this->result['kind'] ?? '') === 'laravel';
    }

    public function isJournal(): bool
    {
        return ($this->result['kind'] ?? '') === 'journal';
    }
}

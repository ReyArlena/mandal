<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\Concerns\ManagesApps;
use App\Filament\Resources\Servers\ServerResource;
use App\Mandal\MandalClient;
use App\Models\Server;
use App\Models\Webhook;
use App\Sources\AutoDeploy;
use App\Ssh\SshException;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One app on a server: details, releases and workers, with every action.
 */
class ServerApp extends Page
{
    use InteractsWithRecord;
    use ManagesApps;

    protected static string $resource = ServerResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.servers.pages.app';

    #[Locked]
    public string $appName = '';

    /** @var array<string, mixed> */
    public array $appData = [];

    /** @var list<array<string, mixed>> */
    public array $releases = [];

    /** @var list<array<string, mixed>> */
    public array $workers = [];

    public ?string $error = null;

    public function webhook(): ?Webhook
    {
        return app(AutoDeploy::class)->webhook($this->server(), $this->appName);
    }

    public function mount(int|string $record, string $app): void
    {
        $this->record = $this->resolveRecord($record);

        if (! preg_match(self::APP_NAME_PATTERN, $app)) {
            throw new NotFoundHttpException;
        }

        $this->appName = $app;

        $this->loadApp();

        if ($this->appData === [] && $this->error === null) {
            throw new NotFoundHttpException;
        }
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = $this->getRecord();

        return $server;
    }

    protected function appChanged(): void
    {
        $this->loadApp(fresh: true);
    }

    public function loadApp(bool $fresh = false): void
    {
        $this->error = null;
        $mandal = app(MandalClient::class);
        $server = $this->server();

        try {
            $apps = $mandal->inventory($server, $fresh)['apps'] ?? [];
            $this->appData = collect($apps)->firstWhere('name', $this->appName) ?? [];

            if ($this->appData === []) {
                return;
            }

            $releases = $mandal->run($server, 'app:releases --name='.$this->appName, timeoutSeconds: 30);
            $this->releases = $releases->successful() ? array_reverse($releases->json()) : [];

            $workers = $mandal->run($server, 'worker:list --app='.$this->appName, timeoutSeconds: 30);
            $this->workers = $workers->successful() ? $workers->json() : [];
        } catch (SshException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    public function getTitle(): string
    {
        return $this->appName;
    }

    public function getBreadcrumbs(): array
    {
        $server = $this->server();

        return [
            ServerResource::getUrl() => 'Servers',
            ServerResource::getUrl('view', ['record' => $server]) => $server->name,
            ServerResource::getUrl('apps', ['record' => $server]) => 'Apps',
            $this->appName,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->deployAppAction()->arguments(['name' => $this->appName])->visible(fn (): bool => ! empty($this->appData['repo'])),
            $this->enableAutoDeployAction()
                ->arguments(['name' => $this->appName, 'repo' => (string) ($this->appData['repo'] ?? '')])
                ->visible(fn (): bool => ! empty($this->appData['repo']) && $this->webhook() === null),
            $this->disableAutoDeployAction()
                ->arguments(['name' => $this->appName])
                ->visible(fn (): bool => $this->webhook() !== null),
            $this->requestCertificateAction()->arguments(['name' => $this->appName])->visible(fn (): bool => empty($this->appData['ssl'])),
            $this->editEnvAction()->arguments(['name' => $this->appName]),
            $this->deleteAppAction()->arguments(['name' => $this->appName]),
        ];
    }
}
